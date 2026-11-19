<?php
// Live progress for render_builder_assets.py (the Cake Builder's 3D images).
// Open: http://localhost/cl-test/blender_cake_kit/render_status.php
// It works it out from the image files as they appear, so it needs nothing from Blender.

$outDir = __DIR__ . '/../invtest/assets/cake-renders';

// Every shape the renderer makes, in the order it makes them, with roughly how many images each has.
$generic = ['round-1', 'round-2', 'square-1', 'square-2', 'rectangle-1', 'rectangle-2', 'bento-1', 'mini-1', 'mini-2'];
$planned = [];
foreach ($generic as $key) {
    $planned[] = ['key' => $key, 'dir' => $key, 'expected' => str_starts_with($key, 'bento') ? 32 : 30];
}
$sizesFile = __DIR__ . '/cake_sizes.txt';
if (is_file($sizesFile)) {
    foreach (file($sizesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) continue;
        $dir = str_replace(['@', '/'], ['_at_', '-'], str_replace(':', '@', $line));
        $planned[] = ['key' => str_replace(':', ' · ', $line), 'dir' => $dir, 'expected' => str_starts_with($line, 'bento') ? 32 : 30];
    }
}

// The renderer writes its own progress here (render_builder_assets.py, class Progress). It knows exactly
// which images the current run makes, so it also works for partial runs (--only, --pieces, --rigs).
function progressFileData(string $file): ?array
{
    if (!is_file($file)) return null;
    $p = json_decode((string)file_get_contents($file), true);
    if (!is_array($p) || empty($p['rows'])) return null;
    $total = array_sum(array_column($p['rows'], 'expected'));
    $done = array_sum(array_column($p['rows'], 'done'));
    $elapsed = max(1, $p['updated'] - $p['started']);
    $perImage = $done > 0 ? $elapsed / $done : 7.0;
    $finished = !empty($p['finished']);
    $current = null;
    if (!$finished) {
        foreach ($p['rows'] as $r) {
            if ($r['done'] < $r['expected']) { $current = $r['name']; break; }
        }
    }
    return [
        'now' => time(),
        'started' => (int)$p['started'],
        'lastWrite' => (int)$p['updated'],
        'done' => $finished ? $total : $done,
        'total' => $total,
        'perImage' => round($perImage, 2),
        'remaining' => $finished ? 0 : (int)round(max(0, $total - $done) * $perImage),
        'current' => $current,
        'rows' => array_map(fn($r) => $r + ['last' => 0], $p['rows']),
    ];
}

function statusData(array $planned, string $outDir): array
{
    $fromRenderer = progressFileData(__DIR__ . '/render_progress.json');
    if ($fromRenderer !== null) return $fromRenderer;

    $times = [];
    $rows = [];
    foreach ($planned as $p) {
        $files = glob("$outDir/{$p['dir']}/*.webp") ?: [];
        $done = count($files);
        $last = 0;
        foreach ($files as $f) {
            $t = filemtime($f);
            $times[] = $t;
            $last = max($last, $t);
        }
        $rows[] = ['name' => $p['key'], 'done' => min($done, $p['expected']), 'expected' => $p['expected'], 'last' => $last];
    }
    sort($times);
    $total = array_sum(array_column($rows, 'expected'));
    $done = array_sum(array_column($rows, 'done'));
    // Speed from the most recent images (ignores long pauses between runs).
    $recent = array_slice($times, -40);
    $gaps = [];
    for ($i = 1; $i < count($recent); $i++) {
        $g = $recent[$i] - $recent[$i - 1];
        if ($g < 120) $gaps[] = $g;
    }
    $perImage = count($gaps) ? array_sum($gaps) / count($gaps) : 7.0;
    $lastWrite = $times ? end($times) : 0;
    $current = null;
    foreach ($rows as $r) {
        if ($r['done'] < $r['expected']) { $current = $r['name']; break; }
    }
    return [
        'now' => time(),
        'started' => $times ? $times[0] : 0,
        'lastWrite' => $lastWrite,
        'done' => $done,
        'total' => $total,
        'perImage' => round($perImage, 2),
        'remaining' => (int)round(max(0, $total - $done) * $perImage),
        'current' => $current,
        'rows' => $rows,
    ];
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(statusData($planned, $outDir));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cake Render Progress</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { background: #fbf6f2; }
        .clock { font-variant-numeric: tabular-nums; font-size: 2.6rem; font-weight: 700; line-height: 1; }
        .progress { height: 1.4rem; }
        .shape-row { font-variant-numeric: tabular-nums; }
        .shape-row .progress { height: 0.5rem; }
        .is-current { background: #fff1f4; }
    </style>
</head>
<body>
<main class="container py-4" style="max-width: 820px">
    <h1 class="h3 mb-1">Cake Builder 3D images: rendering</h1>
    <p class="text-body-secondary mb-4" id="status-line">Checking…</p>

    <div class="row g-3 mb-4">
        <div class="col-6">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <div class="text-body-secondary small mb-1">Time elapsed</div>
                <div class="clock" id="elapsed">--:--:--</div>
            </div></div>
        </div>
        <div class="col-6">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <div class="text-body-secondary small mb-1">Time left (estimate)</div>
                <div class="clock text-danger-emphasis" id="left">--:--:--</div>
                <div class="small text-body-secondary mt-1" id="eta"></div>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
            <span id="count">0 of 0 images</span><span id="percent">0%</span>
        </div>
        <div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated bg-danger" id="bar" style="width: 0%"></div></div>
        <div class="small text-body-secondary mt-2" id="speed"></div>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th class="ps-3">Cake shape · size</th><th style="width: 45%">Images</th><th class="pe-3 text-end">Status</th></tr></thead>
            <tbody id="rows"></tbody>
        </table>
    </div></div>
</main>

<script>
(() => {
    let data = null;
    let fetchedAt = 0;
    const $ = (id) => document.getElementById(id);
    const hms = (s) => {
        s = Math.max(0, Math.round(s));
        return [Math.floor(s / 3600), Math.floor((s % 3600) / 60), s % 60].map((n) => String(n).padStart(2, '0')).join(':');
    };

    function tick() {
        if (!data) return;
        const now = data.now + (Date.now() - fetchedAt) / 1000;
        const finished = data.done >= data.total;
        const stalled = !finished && now - data.lastWrite > 180;
        $('elapsed').textContent = data.started ? hms((finished ? data.lastWrite : now) - data.started) : '--:--:--';
        // Count down between checks, starting from the latest estimate.
        const left = finished ? 0 : Math.max(0, data.remaining - (now - data.now));
        $('left').textContent = hms(left);
        $('eta').textContent = finished ? 'All done' : `Finishes around ${new Date((now + left) * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
        $('status-line').textContent = finished
            ? 'Finished. Reload the Cake Builder to see every shape in 3D.'
            : stalled
                ? 'No new image for over 3 minutes. Blender may be starting the next batch, or it stopped.'
                : `Rendering now: ${data.current}`;
    }

    function draw() {
        const pct = data.total ? Math.round((data.done / data.total) * 100) : 0;
        $('count').textContent = `${data.done} of ${data.total} images`;
        $('percent').textContent = `${pct}%`;
        $('bar').style.width = `${pct}%`;
        $('speed').textContent = `About ${data.perImage.toFixed(1)} s per image`;
        $('rows').replaceChildren(...data.rows.map((r) => {
            const tr = document.createElement('tr');
            tr.className = 'shape-row' + (r.name === data.current ? ' is-current' : '');
            const p = Math.round((r.done / r.expected) * 100);
            const state = r.done >= r.expected ? '✅ Done' : r.name === data.current ? '⏳ Rendering' : r.done ? 'Partly done' : 'Waiting';
            tr.innerHTML = `<td class="ps-3"></td><td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><div class="progress-bar bg-danger" style="width:${p}%"></div></div><span class="small text-body-secondary">${r.done}/${r.expected}</span></div></td><td class="pe-3 text-end small">${state}</td>`;
            tr.firstChild.textContent = r.name;
            return tr;
        }));
    }

    async function refresh() {
        try {
            const res = await fetch('?json=1', { cache: 'no-store' });
            data = await res.json();
            fetchedAt = Date.now();
            draw();
            tick();
        } catch (e) {
            $('status-line').textContent = 'Could not read the progress (is XAMPP running?)';
        }
    }

    refresh();
    setInterval(refresh, 3000);
    setInterval(tick, 1000);
})();
</script>
</body>
</html>
