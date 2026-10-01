<?php

$config = [
    'db' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'leads_import',
        'user'     => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    ],

    // Каталог для завантажених файлів (поза public/)
    'storage_dir' => __DIR__ . '/storage/uploads',

    // Скільки рядків вставляти одним multi-row INSERT
    'batch_size' => 1000,

    // Бюджет часу на один HTTP-крок обробки (секунди).
    // Реально береться min(цей бюджет, max_execution_time - запас).
    'step_time_budget' => 20,
    'step_time_reserve' => 8,

    // Розмір шматка при завантаженні файлу з браузера.
    // Менше за дефолтні upload_max_filesize=2M / post_max_size=8M.
    'upload_chunk_size' => 1024 * 1024,

    'max_file_size' => 200 * 1024 * 1024,

    // Скільки помилок валідації зберігати на один імпорт
    'max_stored_errors' => 1000,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
