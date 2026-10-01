<?php

namespace App;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Uploader
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function init(string $name, int $size): array
    {
        $name = trim(basename($name));
        if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('Потрібен файл у форматі .xlsx');
        }
        if ($size <= 0 || $size > (int)$this->config['max_file_size']) {
            throw new InvalidArgumentException('Некоректний розмір файлу');
        }

        $dir = $this->config['storage_dir'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не вдалося створити каталог для файлів');
        }

        $path = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Каталог storage/uploads недоступний для запису');
        }

        $this->db->prepare(
            "INSERT INTO imports (original_name, file_path, file_size, status) VALUES (?, ?, ?, 'uploading')"
        )->execute([mb_substr($name, 0, 255), $path, $size]);

        return [
            'id'         => (int)$this->db->lastInsertId(),
            'chunk_size' => (int)$this->config['upload_chunk_size'],
        ];
    }

    public function chunk(int $id, int $offset, array $file): array
    {
        $import = $this->get($id, 'uploading');

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Помилка завантаження шматка (код ' . ($file['error'] ?? '?') . ')');
        }

        clearstatcache(true, $import['file_path']);
        $current = filesize($import['file_path']);
        $chunkSize = filesize($file['tmp_name']);

        if ($offset + $chunkSize <= $current) {
            return ['received' => $current]; // вже маємо цей шматок
        }
        if ($offset !== $current) {
            throw new RuntimeException("Неочікуваний offset $offset, очікувався $current");
        }
        if ($current + $chunkSize > (int)$import['file_size']) {
            throw new RuntimeException('Отримано більше даних, ніж заявлений розмір файлу');
        }

        $in = fopen($file['tmp_name'], 'rb');
        $out = fopen($import['file_path'], 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        clearstatcache(true, $import['file_path']);

        return ['received' => filesize($import['file_path'])];
    }

    public function complete(int $id): array
    {
        $import = $this->get($id, 'uploading');

        clearstatcache(true, $import['file_path']);
        if (filesize($import['file_path']) !== (int)$import['file_size']) {
            throw new RuntimeException('Файл завантажено не повністю');
        }

        try {
            $reader = new XlsxReader($import['file_path']);
            new LeadMapper($reader->header()); // перевірка заголовка
            $total = max(0, $reader->countRows() - 1);
        } catch (\Throwable $e) {
            $this->db->prepare("UPDATE imports SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute([$e->getMessage(), $id]);
            throw new InvalidArgumentException($e->getMessage());
        }

        $this->db->prepare("UPDATE imports SET status = 'queued', total_rows = ? WHERE id = ?")
            ->execute([$total, $id]);

        return ['total' => $total];
    }

    private function get(int $id, string $status): array
    {
        $stmt = $this->db->prepare('SELECT * FROM imports WHERE id = ?');
        $stmt->execute([$id]);
        $import = $stmt->fetch();
        if (!$import) {
            throw new InvalidArgumentException('Імпорт не знайдено');
        }
        if ($import['status'] !== $status) {
            throw new InvalidArgumentException('Невірний стан імпорту: ' . $import['status']);
        }

        return $import;
    }
}
