<?php

use App\Db;
use App\Importer;
use App\Uploader;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ignore_user_abort(true);

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['action'] ?? '') !== 'status') {
    respond(['error' => 'Method not allowed'], 405);
}

try {
    $db = Db::connect($config['db']);
    $action = $_GET['action'] ?? '';
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    switch ($action) {
        case 'init':
            respond((new Uploader($db, $config))->init((string)($_POST['name'] ?? ''), (int)($_POST['size'] ?? 0)));

        case 'chunk':
            respond((new Uploader($db, $config))->chunk($id, (int)($_POST['offset'] ?? -1), $_FILES['chunk'] ?? []));

        case 'complete':
            respond((new Uploader($db, $config))->complete($id));

        case 'step':
            respond((new Importer($db, $config))->step($id));

        case 'status':
            respond((new Importer($db, $config))->status($id));

        default:
            respond(['error' => 'Unknown action'], 400);
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log((string)$e);
    respond(['error' => $e->getMessage()], 500);
}
