<?php

require_once __DIR__ . '/../inc/database.inc.php';

echo "Oscar-Import startet...\n";

$jsonFile = __DIR__ . '/../db/oscar-nominations.json';
if (!file_exists($jsonFile)) {
    die("Fehler: oscar-nominations.json nicht gefunden!\n");
}

$jsonContent = file_get_contents($jsonFile);
$nominations = json_decode($jsonContent, true);

if (!$nominations) {
    die("Fehler: JSON konnte nicht gelesen werden!\n");
}

echo "JSON-Datei gelesen: " . count($nominations) . " Einträge gefunden.\n";

$pdo = getConnection();
$pdo->beginTransaction();

try {
    // Tabellen leeren
    echo "Leere Tabellen...\n";
    $pdo->exec('DELETE FROM oscar_nominations');
    $pdo->exec('DELETE FROM oscar_awards');
    $pdo->exec('DELETE FROM oscar_category');
    
    // Auto-Increment zurücksetzen
    $pdo->exec('ALTER TABLE oscar_nominations AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE oscar_awards AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE oscar_category AUTO_INCREMENT = 1');
    
    // Cache für bereits eingefügte Awards und Kategorien
    $awardCache = [];
    $categoryCache = [];
    
    $stmtAward = $pdo->prepare('INSERT INTO oscar_awards (year) VALUES (?)');
    $stmtCategory = $pdo->prepare('INSERT INTO oscar_category (name) VALUES (?)');
    $stmtNomination = $pdo->prepare('
        INSERT INTO oscar_nominations (award_id, category_id, imdb_const, tmdb_id, nominated, winner) 
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $processedCount = 0;
    $errorCount = 0;
    
    foreach ($nominations as $entry) {
        try {
            $year = $entry['year'] ?? '';
            $category = $entry['category'] ?? '';
            $nominees = $entry['nominees'] ?? [];
            $movies = $entry['movies'] ?? [];
            $won = $entry['won'] ?? false;
            
            if (empty($year) || empty($category)) {
                $errorCount++;
                continue;
            }
            
            // Award-ID ermitteln oder erstellen
            if (!isset($awardCache[$year])) {
                $stmtAward->execute([$year]);
                $awardCache[$year] = $pdo->lastInsertId();
            }
            $awardId = $awardCache[$year];
            
            // Category-ID ermitteln oder erstellen
            if (!isset($categoryCache[$category])) {
                $stmtCategory->execute([$category]);
                $categoryCache[$category] = $pdo->lastInsertId();
            }
            $categoryId = $categoryCache[$category];
            
            // Nominierte Namen zusammenführen
            $nominatedStr = implode(', ', $nominees);
            
            // Für jeden Film einen Eintrag erstellen
            if (!empty($movies)) {
                foreach ($movies as $movie) {
                    $imdbId = $movie['imdb_id'] ?? '';
                    $tmdbId = $movie['tmdb_id'] ?? '';
                    
                    // Konvertiere imdb_id zu const (entferne "tt" falls vorhanden)
                    $imdbConst = $imdbId;
                    
                    $stmtNomination->execute([
                        $awardId,
                        $categoryId,
                        $imdbConst,
                        $tmdbId,
                        $nominatedStr,
                        $won ? 1 : 0
                    ]);
                    
                    $processedCount++;
                }
            } else {
                // Kein Film zugeordnet, trotzdem Nominierung speichern
                $stmtNomination->execute([
                    $awardId,
                    $categoryId,
                    '',
                    '',
                    $nominatedStr,
                    $won ? 1 : 0
                ]);
                
                $processedCount++;
            }
            
            if ($processedCount % 1000 == 0) {
                echo "Verarbeitet: $processedCount Nominierungen...\n";
            }
            
        } catch (Exception $e) {
            echo "Fehler bei Eintrag: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    
    $pdo->commit();
    
    echo "\n=== Import abgeschlossen ===\n";
    echo "Verarbeitete Nominierungen: $processedCount\n";
    echo "Fehler: $errorCount\n";
    echo "Jahre (Awards): " . count($awardCache) . "\n";
    echo "Kategorien: " . count($categoryCache) . "\n";
    echo "\nImport erfolgreich!\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Fehler beim Import: " . $e->getMessage() . "\n");
}

?>
