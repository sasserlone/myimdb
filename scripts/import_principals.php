<?php
// CLI-Importer fuer title.principals.tsv - importiert nur Principals fuer Filme, die bereits in der DB vorhanden sind.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript ist nur fuer die CLI gedacht.\n");
    exit(1);
}

require_once __DIR__ . '/../inc/database.inc.php';

if ($argc < 2) {
    fwrite(STDERR, "Verwendung: php scripts/import_principals.php /pfad/zu/title.principals.tsv\n");
    exit(1);
}

$filePath = $argv[1];
if (!is_readable($filePath)) {
    fwrite(STDERR, "Datei nicht lesbar: {$filePath}\n");
    exit(1);
}

$pdo = getConnection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Sicherstellen, dass die Ziel-Tabelle existiert (falls Import-Skript direkt ausgefuehrt wird)
$pdo->exec('CREATE TABLE IF NOT EXISTS movie_principals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    movie_id INT NOT NULL,
    ordering INT DEFAULT NULL,
    nconst VARCHAR(20) NOT NULL,
    category VARCHAR(50) DEFAULT NULL,
    job VARCHAR(255) DEFAULT NULL,
    characters TEXT DEFAULT NULL,
    UNIQUE KEY ux_movie_nconst_order (movie_id, nconst, ordering),
    INDEX idx_mp_movie (movie_id),
    INDEX idx_mp_nconst (nconst),
    INDEX idx_mp_category (category),
    CONSTRAINT fk_mp_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

// Mapping tconst -> movie_id laden (nur bestehende Filme)
$tconstToId = [];
$stmt = $pdo->query('SELECT id, const FROM movies');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $tconstToId[$row['const']] = (int)$row['id'];
}

if (empty($tconstToId)) {
    fwrite(STDERR, "Keine Filme in der Datenbank gefunden.\n");
    exit(1);
}

fwrite(STDOUT, 'Geladene Filme: ' . count($tconstToId) . "\n");

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
$required = ['tconst','ordering','nconst','category','job','characters'];
foreach ($required as $col) {
    if (!isset($headerMap[$col])) {
        fwrite(STDERR, "Spalte fehlt: {$col}\n");
        exit(1);
    }
}

$insertSql = 'INSERT INTO movie_principals (movie_id, ordering, nconst, category, job, characters)
              VALUES (?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE category = VALUES(category), job = VALUES(job), characters = VALUES(characters)';
$insertStmt = $pdo->prepare($insertSql);

// Hilfsfunktion: Characters-String bereinigen (JSON-Array zu komma-getrennter Liste)
function cleanCharacters($raw) {
    if ($raw === null || $raw === '' || $raw === '\\N') {
        return null;
    }
    // Versuchen, JSON zu dekodieren
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        // JSON-Array gefunden: Komma-getrennte Liste erstellen
        return implode(', ', $decoded);
    }
    // Falls kein JSON: Rohen String zurückgeben
    return $raw;
}

$pdo->beginTransaction();
$batchSize = 5000;
$inBatch = 0;
$imported = 0;
$skippedNotFound = 0;
$skippedInvalid = 0;

while (($row = fgetcsv($handle, 0, "\t")) !== false) {
    $tconst = $row[$headerMap['tconst']] ?? '';
    if (!isset($tconstToId[$tconst])) {
        $skippedNotFound++;
        continue;
    }

    $movieId = $tconstToId[$tconst];
    $orderingRaw = $row[$headerMap['ordering']] ?? '\\N';
    $ordering = ($orderingRaw !== '\\N' && $orderingRaw !== '') ? (int)$orderingRaw : null;
    $nconst = $row[$headerMap['nconst']] ?? '';
    if ($nconst === '') {
        $skippedInvalid++;
        continue;
    }
    $categoryRaw = $row[$headerMap['category']] ?? '\\N';
    $category = $categoryRaw !== '\\N' ? $categoryRaw : null;
    $jobRaw = $row[$headerMap['job']] ?? '\\N';
    $job = $jobRaw !== '\\N' ? $jobRaw : null;
    $charactersRaw = $row[$headerMap['characters']] ?? '\\N';
    $characters = cleanCharacters($charactersRaw);

    $insertStmt->execute([$movieId, $ordering, $nconst, $category, $job, $characters]);
    $imported++;
    $inBatch++;

    if ($inBatch >= $batchSize) {
        $pdo->commit();
        $pdo->beginTransaction();
        fwrite(STDOUT, "Importiert: {$imported} | Uebersprungen (nicht gefunden): {$skippedNotFound}\n");
        $inBatch = 0;
    }
}

$pdo->commit();
fclose($handle);

fwrite(STDOUT, "Fertig. Importiert: {$imported}, Uebersprungen (nicht gefunden): {$skippedNotFound}, Uebersprungen (ungueltig): {$skippedInvalid}\n");
