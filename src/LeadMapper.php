<?php

namespace App;

use InvalidArgumentException;

final class LeadMapper
{
    public const FIELDS = [
        'external_id'     => 64,
        'created_at'      => null,
        'first_name'      => 100,
        'last_name'       => 100,
        'phone'           => 32,
        'email'           => 255,
        'city'            => 100,
        'source'          => 100,
        'utm_campaign'    => 100,
        'product'         => 255,
        'budget_uah'      => null,
        'status'          => 32,
        'manager'         => 100,
        'comment'         => 65535,
        'next_contact_at' => null,
    ];

    private const DATE_FIELDS = ['created_at', 'next_contact_at'];

    /** @var array<string,int> */
    private array $map = [];

    public function __construct(array $headerCells)
    {
        foreach ($headerCells as $col => $title) {
            $key = strtolower(trim((string)$title));
            $key = preg_replace('/[^a-z0-9]+/', '_', $key);
            if (array_key_exists($key, self::FIELDS) && !isset($this->map[$key])) {
                $this->map[$key] = $col;
            }
        }

        if (!isset($this->map['external_id'])) {
            throw new InvalidArgumentException(
                'У заголовку файлу немає колонки external_id. Знайдено: ' . implode(', ', $headerCells)
            );
        }
    }

    public function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $v) {
            if (trim((string)$v) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, mixed>
     * @throws InvalidArgumentException
     */
    public function map(array $cells): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $maxLen) {
            $raw = isset($this->map[$field]) ? ($cells[$this->map[$field]] ?? null) : null;
            $raw = $raw === null ? null : trim((string)$raw);
            if ($raw === '') {
                $raw = null;
            }

            if (in_array($field, self::DATE_FIELDS, true)) {
                $value = self::toDateTime($raw);
            } elseif ($field === 'budget_uah') {
                $value = self::toDecimal($raw);
            } elseif ($field === 'phone') {
                $value = self::toPhone($raw);
            } elseif ($field === 'status') {
                $value = $raw === null ? null : mb_strtolower($raw);
            } elseif ($field === 'email') {
                $value = $raw === null ? null : mb_strtolower($raw);
            } else {
                $value = $raw;
            }

            if (is_string($value) && $maxLen !== null && mb_strlen($value) > $maxLen) {
                $value = mb_substr($value, 0, $maxLen);
            }

            $out[] = $value;
        }

        if ($out[0] === null) {
            throw new InvalidArgumentException('Порожній external_id');
        }

        return $out;
    }

    public static function toDateTime(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        if (is_numeric($v)) {
            $serial = (float)$v;
            // 1 = 1900-01-01; > 2958465 це вже 9999 рік
            if ($serial <= 0 || $serial > 2958465) {
                return null;
            }
            $ts = (int)round(($serial - 25569) * 86400);

            return gmdate('Y-m-d H:i:s', $ts);
        }

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $v);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $ts = strtotime($v);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    public static function toDecimal(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
        if (!is_numeric($v)) {
            return null;
        }
        $n = round((float)$v, 2);
        if (abs($n) >= 1e10) {
            return null;
        }

        return number_format($n, 2, '.', '');
    }

    public static function toPhone(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('/^\d(\.\d+)?E\+?\d+$/i', $v)) {
            $v = sprintf('%.0f', (float)$v);
        }
        $digits = preg_replace('/\D+/', '', $v);

        if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            return '+' . $digits;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '+38' . $digits;
        }
        if (strlen($digits) === 9) {
            return '+380' . $digits;
        }

        return $digits !== '' ? (str_starts_with(trim($v), '+') ? '+' : '') . $digits : null;
    }
}
