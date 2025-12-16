<?php
// Liefert Log-Teilstuecke als JSON zur Live-Ansicht

header('Content-Type: application/json; charset=utf-8');

$baseDir = realpath(__DIR__ . '/../logs');
if ($baseDir === false) {
    echo json_encode(['error' => 'logs directory missing']);
    exit;
}

$file = $_GET['file'] ?? '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Nur Basename zulassen und Namensmuster prfen
$baseName = basename($file);
if ($baseName !== $file) {
    echo json_encode(['error' => 'invalid file']);
    exit;
}
if (!preg_match('/^imdb_pipeline_\d{8}_\d{6}\.log$/', $baseName)) {
    echo json_encode(['error' => 'invalid name']);
    exit;
}

$path = $baseDir . DIRECTORY_SEPARATOR . $baseName;
if (!is_file($path)) {
    echo json_encode(['error' => 'file not found']);
    exit;
}

$size = filesize($path);
if ($size === false) $size = 0;

$start = $offset;
if ($offset > $size) {
    // Datei rotiert/gekrzt: springe auf Ende - 20KB
    $start = max(0, $size - 20000);
}

$length = $size - $start;
$chunk = '';
if ($length > 0) {
    $chunk = file_get_contents($path, false, null, $start, $length);
    if ($chunk === false) $chunk = '';
}

echo json_encode([
    'size' => $size,
    'start' => $start,
    'chunk' => $chunk,
], JSON_UNESCAPED_UNICODE);
