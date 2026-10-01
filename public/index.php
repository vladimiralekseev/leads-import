<?php

use App\Db;

$config = require __DIR__ . '/../bootstrap.php';

$dbError = null;
$imports = [];
$leadsCount = 0;
try {
    $db = Db::connect($config['db']);
    $imports = $db->query('SELECT * FROM imports ORDER BY id DESC LIMIT 15')->fetchAll();
    $leadsCount = (int)$db->query('SELECT COUNT(*) FROM leads')->fetchColumn();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$statusLabels = [
    'uploading'  => 'Завантаження',
    'queued'     => 'В черзі',
    'processing' => 'Обробка',
    'done'       => 'Готово',
    'failed'     => 'Помилка',
];
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Імпорт заявок</title>
    <style>
        :root { --bg:#f5f6f8; --card:#fff; --text:#1d2330; --muted:#6b7280; --line:#e5e7eb; --accent:#2563eb; --ok:#16a34a; --err:#dc2626; }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); }
        .wrap { max-width:960px; margin:0 auto; padding:32px 16px; }
        h1 { margin:0 0 4px; font-size:26px; }
        .sub { color:var(--muted); margin:0 0 24px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:20px; }
        .drop { display:block; border:2px dashed var(--line); border-radius:8px; padding:28px; text-align:center; cursor:pointer; transition:.15s; }
        .drop.over, .drop:hover { border-color:var(--accent); background:#f0f5ff; }
        .drop input { display:none; }
        .drop b { color:var(--accent); }
        button { background:var(--accent); color:#fff; border:0; border-radius:6px; padding:10px 18px; font-size:15px; cursor:pointer; margin-top:14px; }
        button:disabled { opacity:.5; cursor:default; }
        .bar { height:10px; background:#eef0f3; border-radius:5px; overflow:hidden; margin:14px 0 8px; }
        .bar > div { height:100%; width:0; background:var(--accent); transition:width .3s; }
        .bar.done > div { background:var(--ok); }
        .bar.fail > div { background:var(--err); }
        #progress { display:none; }
        .stats { display:flex; gap:24px; flex-wrap:wrap; color:var(--muted); font-size:14px; }
        .stats b { color:var(--text); }
        .msg { margin-top:10px; font-weight:500; }
        .msg.err { color:var(--err); } .msg.ok { color:var(--ok); }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); white-space:nowrap; }
        th { color:var(--muted); font-weight:500; }
        td.name { white-space:normal; word-break:break-all; }
        .tag { display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px; background:#eef0f3; }
        .tag.done { background:#dcfce7; color:#166534; } .tag.failed { background:#fee2e2; color:#991b1b; }
        .tag.processing, .tag.queued { background:#dbeafe; color:#1e40af; }
        .table-scroll { overflow-x:auto; }
        .alert { background:#fee2e2; color:#991b1b; padding:12px 14px; border-radius:8px; margin-bottom:20px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Імпорт заявок</h1>
    <p class="sub">Завантажте .xlsx з заявками. Файл обробляється частинами, тож працює і для 100 000+ рядків при max_execution_time = <?= (int)ini_get('max_execution_time') ?> с.
        У базі зараз: <b id="leadsCount"><?= number_format($leadsCount, 0, '.', ' ') ?></b> заявок.</p>

    <?php if ($dbError): ?>
        <div class="alert">Немає з'єднання з БД: <?= h($dbError) ?>. Перевірте config.php і що виконано database/schema.sql.</div>
    <?php endif; ?>

    <div class="card">
        <form id="form">
            <label class="drop" id="drop">
                <input type="file" id="file" accept=".xlsx">
                <div id="dropText"><b>Оберіть файл</b> або перетягніть його сюди</div>
            </label>
            <button type="submit" id="start" disabled>Імпортувати</button>
        </form>

        <div id="progress">
            <div class="bar" id="bar"><div></div></div>
            <div class="stats">
                <span>Етап: <b id="stage">—</b></span>
                <span>Оброблено: <b id="processed">0</b> / <b id="total">0</b></span>
                <span>Записано: <b id="imported">0</b></span>
                <span>Помилок: <b id="failed">0</b></span>
                <span>Час: <b id="elapsed">0 с</b></span>
            </div>
            <div class="msg" id="msg"></div>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Останні імпорти</h3>
        <div class="table-scroll">
        <table>
            <thead><tr><th>#</th><th>Файл</th><th>Статус</th><th>Рядків</th><th>Записано</th><th>Помилок</th><th>Кроків</th><th>Тривалість</th></tr></thead>
            <tbody>
            <?php foreach ($imports as $i): ?>
                <tr>
                    <td><?= (int)$i['id'] ?></td>
                    <td class="name"><?= h($i['original_name']) ?></td>
                    <td><span class="tag <?= h($i['status']) ?>" title="<?= h($i['error_message']) ?>"><?= h($statusLabels[$i['status']] ?? $i['status']) ?></span></td>
                    <td><?= number_format((int)$i['total_rows'], 0, '.', ' ') ?></td>
                    <td><?= number_format((int)$i['imported_rows'], 0, '.', ' ') ?></td>
                    <td><?= (int)$i['failed_rows'] ?></td>
                    <td><?= (int)$i['steps'] ?></td>
                    <td><?= $i['started_at'] && $i['finished_at'] ? (strtotime($i['finished_at']) - strtotime($i['started_at'])) . ' с' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$imports): ?>
                <tr><td colspan="8" style="color:var(--muted)">Ще не було імпортів</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
(() => {
    const $ = id => document.getElementById(id);
    const fileInput = $('file'), drop = $('drop'), startBtn = $('start');
    const fmt = n => Number(n).toLocaleString('uk-UA');
    let file = null, t0 = 0, timer = null;

    function pick(f) {
        if (!f) return;
        if (!/\.xlsx$/i.test(f.name)) { alert('Потрібен файл .xlsx'); return; }
        file = f;
        $('dropText').innerHTML = '<b>' + f.name.replace(/[<>&]/g, '') + '</b> — ' + (f.size / 1048576).toFixed(2) + ' МБ';
        startBtn.disabled = false;
    }
    fileInput.addEventListener('change', () => pick(fileInput.files[0]));
    ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('over'); }));
    drop.addEventListener('drop', ev => pick(ev.dataTransfer.files[0]));

    async function api(action, data, attempts = 4) {
        for (let i = 1; ; i++) {
            try {
                const res = await fetch('api.php?action=' + action, { method: 'POST', body: data });
                const json = await res.json().catch(() => ({ error: 'Некоректна відповідь сервера (HTTP ' + res.status + ')' }));
                if (!res.ok || json.error) {
                    const err = new Error(json.error || ('HTTP ' + res.status));
                    err.fatal = res.status === 422;
                    throw err;
                }
                return json;
            } catch (e) {
                // мережеві збої та 5xx повторюємо — кроки ідемпотентні
                if (e.fatal || i >= attempts) throw e;
                await new Promise(r => setTimeout(r, 1000 * i));
            }
        }
    }
    const fd = obj => { const f = new FormData(); for (const k in obj) f.append(k, obj[k]); return f; };

    function setBar(percent, cls) {
        $('bar').className = 'bar' + (cls ? ' ' + cls : '');
        $('bar').firstElementChild.style.width = percent + '%';
    }
    function show(s) {
        $('processed').textContent = fmt(s.processed); $('total').textContent = fmt(s.total);
        $('imported').textContent = fmt(s.imported); $('failed').textContent = fmt(s.failed);
        setBar(s.percent);
    }
    function msg(text, cls) { $('msg').textContent = text; $('msg').className = 'msg ' + (cls || ''); }

    $('form').addEventListener('submit', async ev => {
        ev.preventDefault();
        if (!file) return;
        startBtn.disabled = true; fileInput.disabled = true;
        $('progress').style.display = 'block'; msg('');
        t0 = Date.now();
        timer = setInterval(() => $('elapsed').textContent = Math.round((Date.now() - t0) / 1000) + ' с', 500);

        try {
            // 1. Завантаження шматками
            $('stage').textContent = 'завантаження файлу';
            const init = await api('init', fd({ name: file.name, size: file.size }));
            const id = init.id, chunk = init.chunk_size;
            for (let offset = 0; offset < file.size; offset += chunk) {
                const f = fd({ id, offset });
                f.append('chunk', file.slice(offset, offset + chunk), 'chunk');
                await api('chunk', f);
                setBar(Math.min(100, (offset + chunk) / file.size * 100));
            }

            // 2. Перевірка файлу та підрахунок рядків
            $('stage').textContent = 'перевірка файлу';
            const done = await api('complete', fd({ id }));
            $('total').textContent = fmt(done.total);
            setBar(0);

            // 3. Обробка кроками, доки не буде done
            $('stage').textContent = 'запис у базу';
            let s;
            do {
                s = await api('step', fd({ id }));
                show(s);
                if (s.busy) await new Promise(r => setTimeout(r, 1500));
            } while (s.status === 'processing' || s.status === 'queued');

            if (s.status === 'done') {
                setBar(100, 'done');
                $('stage').textContent = 'готово';
                msg('Імпорт завершено: записано ' + fmt(s.imported) + ' з ' + fmt(s.total) + ' рядків за ' + s.steps + ' кроків.' + (s.failed ? ' Пропущено з помилками: ' + fmt(s.failed) + '.' : ''), 'ok');
            } else {
                throw new Error(s.error || 'Імпорт завершився зі статусом ' + s.status);
            }
        } catch (e) {
            setBar(100, 'fail');
            $('stage').textContent = 'помилка';
            msg(e.message, 'err');
        } finally {
            clearInterval(timer);
            fileInput.disabled = false;
            startBtn.textContent = 'Оновити сторінку';
            startBtn.disabled = false;
            startBtn.onclick = e => { e.preventDefault(); location.reload(); };
        }
    });
})();
</script>
</body>
</html>
