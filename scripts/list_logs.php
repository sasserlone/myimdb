<?php
// Liefert Liste aller verfuegbaren Log-Dateien als JSON (fuer Dropdown-Auto-Refresh)

header('Content-Type: application/json; charset=utf-8');

$baseDir = realpath(__DIR__ . '/../logs');
if ($baseDir === false || !is_dir($baseDir)) {
    echo json_encode([]);
    exit;
}

$logs = [];
foreach (glob($baseDir . DIRECTORY_SEPARATOR . 'imdb_pipeline_*.log') as $f) {
    $logs[] = basename($f);
}
rsort($logs); // Neueste zuerst

echo json_encode($logs, JSON_UNESCAPED_UNICODE);
