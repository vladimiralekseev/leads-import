<?php

namespace App;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

final class Importer
{
    private PDO $db;
    private array $config;

    /** @var array<int, PDOStatement> */
    private array $insertStatements = [];

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function step(int $importId): array
    {
        $started = microtime(true);
        $deadline = $started + $this->timeBudget();

        $lockName = 'leads_import_' . $importId;
        $got = (int)$this->db->query('SELECT GET_LOCK(' . $this->db->quote($lockName) . ', 0)')->fetchColumn();
        if ($got !== 1) {
            return $this->status($importId) + ['busy' => true];
        }

        try {
            $import = $this->find($importId);
            if ($import === null) {
                throw new InvalidArgumentException('Імпорт не знайдено');
            }
            if (!in_array($import['status'], ['queued', 'processing'], true)) {
                return $this->status($importId);
            }

            $this->db->prepare(
                "UPDATE imports SET status = 'processing', started_at = COALESCE(started_at, NOW()), steps = steps + 1 WHERE id = ?"
            )->execute([$importId]);

            try {
                $this->process($import, $deadline);
            } catch (Throwable $e) {
                $this->db->prepare("UPDATE imports SET status = 'failed', error_message = ? WHERE id = ?")
                    ->execute([$e->getMessage(), $importId]);
            }
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(' . $this->db->quote($lockName) . ')');
        }

        return $this->status($importId) + ['step_seconds' => round(microtime(true) - $started, 2)];
    }

    private function process(array $import, float $deadline): void
    {
        $id = (int)$import['id'];
        $reader = new XlsxReader($import['file_path']);
        $mapper = new LeadMapper($reader->header());

        $batchSize = max(1, (int)$this->config['batch_size']);
        $maxErrors = (int)$this->config['max_stored_errors'];
        $storedErrors = (int)$this->db->query('SELECT COUNT(*) FROM import_errors WHERE import_id = ' . $id)->fetchColumn();

        $cursor = (int)$import['processed_rows'];
        $rows = [];
        $errors = [];
        $passed = 0;

        foreach ($reader->rows($cursor + 1) as [$rowNumber, $cells]) {
            $passed++;

            if (!$mapper->isEmptyRow($cells)) {
                try {
                    $rows[] = $mapper->map($cells);
                } catch (InvalidArgumentException $e) {
                    $errors[] = [$rowNumber, $e->getMessage()];
                }
            }

            if ($passed >= $batchSize) {
                $this->flush($id, $rows, $errors, $passed, $storedErrors, $maxErrors);
                $rows = $errors = [];
                $passed = 0;

                if (microtime(true) >= $deadline) {
                    return;
                }
            }
        }

        $this->flush($id, $rows, $errors, $passed, $storedErrors, $maxErrors);

        $this->db->prepare(
            "UPDATE imports SET status = 'done', finished_at = NOW(), total_rows = GREATEST(total_rows, processed_rows) WHERE id = ?"
        )->execute([$id]);
    }

    private function flush(int $importId, array $rows, array $errors, int $passed, int &$storedErrors, int $maxErrors): void
    {
        if ($passed === 0) {
            return;
        }

        $this->db->beginTransaction();
        try {
            if ($rows) {
                $params = [];
                foreach ($rows as $row) {
                    foreach ($row as $v) {
                        $params[] = $v;
                    }
                    $params[] = $importId;
                }
                $this->insertStatement(count($rows))->execute($params);
            }

            $toStore = array_slice($errors, 0, max(0, $maxErrors - $storedErrors));
            if ($toStore) {
                $sql = 'INSERT INTO import_errors (import_id, excel_row, message) VALUES '
                    . implode(',', array_fill(0, count($toStore), '(?,?,?)'));
                $params = [];
                foreach ($toStore as [$rowNumber, $message]) {
                    array_push($params, $importId, $rowNumber, mb_substr($message, 0, 500));
                }
                $this->db->prepare($sql)->execute($params);
                $storedErrors += count($toStore);
            }

            $this->db->prepare(
                'UPDATE imports SET processed_rows = processed_rows + ?, imported_rows = imported_rows + ?, failed_rows = failed_rows + ? WHERE id = ?'
            )->execute([$passed, count($rows), count($errors), $importId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertStatement(int $count): PDOStatement
    {
        if (isset($this->insertStatements[$count])) {
            return $this->insertStatements[$count];
        }

        $columns = array_keys(LeadMapper::FIELDS);
        $columns[] = 'import_id';

        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $updates = [];
        foreach ($columns as $col) {
            if ($col !== 'external_id') {
                $updates[] = "`$col` = VALUES(`$col`)";
            }
        }

        $sql = 'INSERT INTO leads (`' . implode('`,`', $columns) . '`) VALUES '
            . implode(',', array_fill(0, $count, $placeholders))
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        // повний батч кешуємо, «хвіст» (останній неповний батч) — ні
        $stmt = $this->db->prepare($sql);
        if ($count === (int)$this->config['batch_size']) {
            $this->insertStatements[$count] = $stmt;
        }

        return $stmt;
    }

    private function timeBudget(): float
    {
        $budget = (float)$this->config['step_time_budget'];
        $limit = (int)ini_get('max_execution_time');
        if ($limit > 0) {
            $budget = min($budget, max(2, $limit - (float)$this->config['step_time_reserve']));
        }

        return $budget;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM imports WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function status(int $id): array
    {
        $import = $this->find($id);
        if ($import === null) {
            return ['error' => 'Імпорт не знайдено'];
        }

        $total = (int)$import['total_rows'];
        $processed = (int)$import['processed_rows'];

        return [
            'id'        => (int)$import['id'],
            'name'      => $import['original_name'],
            'status'    => $import['status'],
            'total'     => $total,
            'processed' => $processed,
            'imported'  => (int)$import['imported_rows'],
            'failed'    => (int)$import['failed_rows'],
            'steps'     => (int)$import['steps'],
            'percent'   => $total > 0 ? min(100, round($processed / $total * 100, 1)) : ($import['status'] === 'done' ? 100 : 0),
            'error'     => $import['error_message'],
            'started_at'  => $import['started_at'],
            'finished_at' => $import['finished_at'],
        ];
    }
}
