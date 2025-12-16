<?php
// CLI-Importer fuer name.basics.tsv - importiert nur Schauspieler, die in movie_principals vorkommen

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript ist nur fuer die CLI gedacht.\n");
    exit(1);
}

require_once __DIR__ . '/../inc/database.inc.php';

if ($argc < 2) {
    fwrite(STDERR, "Verwendung: php scripts/import_names.php /pfad/zu/name.basics.tsv\n");
    exit(1);
}

$filePath = $argv[1];
if (!is_readable($filePath)) {
    fwrite(STDERR, "Datei nicht lesbar: {$filePath}\n");
    exit(1);
}

$pdo = getConnection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Alle nconst-Werte aus movie_principals laden (nur die sind relevant)
$requiredNconsts = [];
$stmt = $pdo->query('SELECT DISTINCT nconst FROM movie_principals');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $requiredNconsts[$row['nconst']] = true;
}

if (empty($requiredNconsts)) {
    fwrite(STDERR, "Keine Principals in der Datenbank gefunden. Importiere zuerst mit import_principals.php\n");
    exit(1);
}

fwrite(STDOUT, 'Gesuchte nconsts: ' . count($requiredNconsts) . "\n");

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
$required = ['nconst','primaryName','birthYear','deathYear','primaryProfession','knownForTitles'];
foreach ($required as $col) {
    if (!isset($headerMap[$col])) {
        fwrite(STDERR, "Spalte fehlt: {$col}\n");
        exit(1);
    }
}

// Sicherstellen, dass die Ziel-Tabelle existiert
$pdo->exec('CREATE TABLE IF NOT EXISTS actors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nconst VARCHAR(20) NOT NULL UNIQUE,
    primary_name VARCHAR(255) NOT NULL,
    birth_year INT DEFAULT NULL,
    death_year INT DEFAULT NULL,
    primary_profession TEXT DEFAULT NULL,
    known_for_titles TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nconst (nconst),
    INDEX idx_name (primary_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$insertSql = 'INSERT INTO actors (nconst, primary_name, birth_year, death_year, primary_profession, known_for_titles)
              VALUES (?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE primary_name = VALUES(primary_name), birth_year = VALUES(birth_year), 
                                       death_year = VALUES(death_year), primary_profession = VALUES(primary_profession),
                                       known_for_titles = VALUES(known_for_titles)';
$insertStmt = $pdo->prepare($insertSql);

$pdo->beginTransaction();
$batchSize = 5000;
$inBatch = 0;
$imported = 0;
$skippedNotNeeded = 0;
$skippedInvalid = 0;

while (($row = fgetcsv($handle, 0, "\t")) !== false) {
    $nconst = $row[$headerMap['nconst']] ?? '';
    
    if (!isset($requiredNconsts[$nconst])) {
        $skippedNotNeeded++;
        continue;
    }

    $primaryName = $row[$headerMap['primaryName']] ?? '';
    if ($primaryName === '') {
        $skippedInvalid++;
        continue;
    }

    $birthYearRaw = $row[$headerMap['birthYear']] ?? '\\N';
    $birthYear = ($birthYearRaw !== '\\N' && $birthYearRaw !== '') ? (int)$birthYearRaw : null;
    
    $deathYearRaw = $row[$headerMap['deathYear']] ?? '\\N';
    $deathYear = ($deathYearRaw !== '\\N' && $deathYearRaw !== '') ? (int)$deathYearRaw : null;
    
    $professionRaw = $row[$headerMap['primaryProfession']] ?? '\\N';
    $profession = $professionRaw !== '\\N' ? $professionRaw : null;
    
    $titlesRaw = $row[$headerMap['knownForTitles']] ?? '\\N';
    $titles = $titlesRaw !== '\\N' ? $titlesRaw : null;

    $insertStmt->execute([$nconst, $primaryName, $birthYear, $deathYear, $profession, $titles]);
    $imported++;
    $inBatch++;

    if ($inBatch >= $batchSize) {
        $pdo->commit();
        $pdo->beginTransaction();
        fwrite(STDOUT, "Importiert: {$imported}\n");
        $inBatch = 0;
    }
}

$pdo->commit();
fclose($handle);

fwrite(STDOUT, "Fertig. Importiert: {$imported}, Uebersprungen (nicht benoetigt): {$skippedNotNeeded}, Uebersprungen (ungueltig): {$skippedInvalid}\n");
