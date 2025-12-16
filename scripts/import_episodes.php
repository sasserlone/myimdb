<?php
// CLI-Importer fuer title.episode.tsv - importiert nur Episoden fuer Serien, die bereits in der DB vorhanden sind

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript ist nur fuer die CLI gedacht.\n");
    exit(1);
}

require_once __DIR__ . '/../inc/database.inc.php';

if ($argc < 2) {
    fwrite(STDERR, "Verwendung: php scripts/import_episodes.php /pfad/zu/title.episode.tsv\n");
    exit(1);
}

$filePath = $argv[1];
if (!is_readable($filePath)) {
    fwrite(STDERR, "Datei nicht lesbar: {$filePath}\n");
    exit(1);
}

$pdo = getConnection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Mapping parent_tconst -> verfügbar (nur Serien die bereits in movies sind)
$availableParents = [];
$stmt = $pdo->query('SELECT const FROM movies WHERE title_type IN ("Fernsehserie", "Miniserie")');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $availableParents[$row['const']] = true;
}

if (empty($availableParents)) {
    fwrite(STDERR, "Keine Serien in der Datenbank gefunden.\n");
    exit(1);
}

fwrite(STDOUT, 'Geladene Serien: ' . count($availableParents) . "\n");

$handle = fopen($filePath, 'r');
if (!$handle) {
    fwrite(STDERR, "Datei konnte nicht geoeffnet werden.\n");
    exit(1);
}

// Header einlesen
$header = fgetcsv($handle, 0, "\t");
if (!$header) {
    fwrite(STDERR, "Leere oder ungueltige TSV-Datei.\n");
    exit(1);
}
$headerMap = array_flip($header);
$required = ['tconst','parentTconst','seasonNumber','episodeNumber'];
foreach ($required as $col) {
    if (!isset($headerMap[$col])) {
        fwrite(STDERR, "Spalte fehlt: {$col}\n");
        exit(1);
    }
}

// Sicherstellen, dass die Ziel-Tabelle existiert
$pdo->exec('CREATE TABLE IF NOT EXISTS episodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tconst VARCHAR(20) NOT NULL,
    parent_tconst VARCHAR(20) NOT NULL,
    season_number INT DEFAULT NULL,
    episode_number INT DEFAULT NULL,
    visible TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_tconst (tconst),
    INDEX idx_parent (parent_tconst),
    INDEX idx_season (parent_tconst, season_number),
    INDEX idx_visible (visible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$insertSql = 'INSERT INTO episodes (tconst, parent_tconst, season_number, episode_number, visible)
              VALUES (?, ?, ?, ?, 1)
              ON DUPLICATE KEY UPDATE season_number = VALUES(season_number), episode_number = VALUES(episode_number)';
$insertStmt = $pdo->prepare($insertSql);

$pdo->beginTransaction();
$batchSize = 5000;
$inBatch = 0;
$imported = 0;
$skippedNotFound = 0;
$skippedInvalid = 0;

while (($row = fgetcsv($handle, 0, "\t")) !== false) {
    $tconst = $row[$headerMap['tconst']] ?? '';
    $parentTconst = $row[$headerMap['parentTconst']] ?? '';
    
    if (!isset($availableParents[$parentTconst])) {
        $skippedNotFound++;
        continue;
    }

    if ($tconst === '') {
        $skippedInvalid++;
        continue;
    }

    $seasonRaw = $row[$headerMap['seasonNumber']] ?? '\\N';
    $seasonNumber = ($seasonRaw !== '\\N' && $seasonRaw !== '') ? (int)$seasonRaw : null;
    
    $episodeRaw = $row[$headerMap['episodeNumber']] ?? '\\N';
    $episodeNumber = ($episodeRaw !== '\\N' && $episodeRaw !== '') ? (int)$episodeRaw : null;

    $insertStmt->execute([$tconst, $parentTconst, $seasonNumber, $episodeNumber]);
    $imported++;
    $inBatch++;

    if ($inBatch >= $batchSize) {
        $pdo->commit();
        $pdo->beginTransaction();
        fwrite(STDOUT, "Importiert: {$imported} | Uebersprungen (Serie nicht gefunden): {$skippedNotFound}\n");
        $inBatch = 0;
    }
}

$pdo->commit();
fclose($handle);

fwrite(STDOUT, "Fertig. Importiert: {$imported}, Uebersprungen (Serie nicht gefunden): {$skippedNotFound}, Uebersprungen (ungueltig): {$skippedInvalid}\n");
