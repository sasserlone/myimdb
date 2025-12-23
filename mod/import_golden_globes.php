<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';

// ****************************************************************************
// IMDb-Matching für bereits importierte Einträge
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_imdb'])) {
    try {
        $pdo = getConnection();
        
        // Alle Einträge ohne imdb_const aber mit Film-Titel laden
        $stmtUnmatched = $pdo->query('
            SELECT id, film, year_film 
            FROM golden_globe_nominations 
            WHERE film IS NOT NULL 
            AND film != "" 
            AND imdb_const IS NULL
        ');
        $unmatchedEntries = $stmtUnmatched->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($unmatchedEntries)) {
            $message = "✓ Alle Einträge mit Film-Titel haben bereits eine IMDb-Verknüpfung.";
        } else {
            $pdo->beginTransaction();
            
            $matchedCount = 0;
            $stmtUpdate = $pdo->prepare('UPDATE golden_globe_nominations SET imdb_const = ? WHERE id = ?');
            
            foreach ($unmatchedEntries as $entry) {
                $film = $entry['film'];
                $yearFilm = (int)$entry['year_film'];
                $id = $entry['id'];
                
                // Exakter Match
                $stmtMatch = $pdo->prepare('
                    SELECT const FROM movies 
                    WHERE (LOWER(title) = LOWER(?) OR LOWER(original_title) = LOWER(?))
                    AND year BETWEEN ? AND ?
                    AND title_type != "Fernsehepisode"
                    LIMIT 1
                ');
                $stmtMatch->execute([$film, $film, $yearFilm - 2, $yearFilm + 2]);
                $match = $stmtMatch->fetch(PDO::FETCH_ASSOC);
                
                if ($match) {
                    $stmtUpdate->execute([$match['const'], $id]);
                    $matchedCount++;
                } else {
                    // Fuzzy-Match
                    $cleanFilm = preg_replace('/^(the|a|an)\s+/i', '', $film);
                    $cleanFilm = preg_replace('/[^\w\s]/', '', $cleanFilm);
                    
                    $stmtFuzzy = $pdo->prepare('
                        SELECT const, title FROM movies 
                        WHERE (LOWER(REPLACE(REPLACE(REPLACE(LOWER(title), "the ", ""), "a ", ""), "an ", "")) LIKE ?
                               OR LOWER(REPLACE(REPLACE(REPLACE(LOWER(original_title), "the ", ""), "a ", ""), "an ", "")) LIKE ?)
                        AND year BETWEEN ? AND ?
                        AND title_type != "Fernsehepisode"
                        LIMIT 1
                    ');
                    
                    try {
                        $searchPattern = '%' . strtolower($cleanFilm) . '%';
                        $stmtFuzzy->execute([$searchPattern, $searchPattern, $yearFilm - 2, $yearFilm + 2]);
                        $fuzzyMatch = $stmtFuzzy->fetch(PDO::FETCH_ASSOC);
                        if ($fuzzyMatch) {
                            $stmtUpdate->execute([$fuzzyMatch['const'], $id]);
                            $matchedCount++;
                        }
                    } catch (Exception $e) {
                        // Skip
                    }
                }
            }
            
            $pdo->commit();
            
            $totalUnmatched = count($unmatchedEntries);
            $stillUnmatched = $totalUnmatched - $matchedCount;
            
            $message = "✓ IMDb-Matching abgeschlossen:<br>";
            $message .= "• Neue Verknüpfungen: $matchedCount<br>";
            $message .= "• Nicht gefunden: $stillUnmatched<br>";
            $message .= "• Match-Rate: " . round($matchedCount / $totalUnmatched * 100, 1) . "%";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'IMDb-Matching fehlgeschlagen: ' . $e->getMessage();
    }
}

// ****************************************************************************
// Golden Globe Awards Import (CSV)
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_import'])) {
    try {
        $pdo = getConnection();
        
        $csvFile = __DIR__ . '/../db/golden-globe-awards.csv';
        if (!file_exists($csvFile)) {
            throw new Exception("golden-globe-awards.csv nicht gefunden!");
        }
        
        $pdo->beginTransaction();
        
        // Tabellen erstellen falls nicht vorhanden
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS golden_globe_category (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                german VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS golden_globe_nominations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year_film INT NOT NULL,
                year_award INT NOT NULL,
                ceremony INT NOT NULL,
                category_id INT NOT NULL,
                nominee VARCHAR(255) NOT NULL,
                film VARCHAR(255) DEFAULT NULL,
                imdb_const VARCHAR(32) DEFAULT NULL,
                winner TINYINT NOT NULL DEFAULT 0,
                INDEX idx_category (category_id),
                INDEX idx_year_film (year_film),
                INDEX idx_year_award (year_award),
                INDEX idx_imdb_const (imdb_const),
                INDEX idx_winner (winner)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        // Tabellen leeren
        $pdo->exec('DELETE FROM golden_globe_nominations');
        $pdo->exec('DELETE FROM golden_globe_category');
        $pdo->exec('ALTER TABLE golden_globe_nominations AUTO_INCREMENT = 1');
        $pdo->exec('ALTER TABLE golden_globe_category AUTO_INCREMENT = 1');
        
        // CSV einlesen
        $handle = fopen($csvFile, 'r');
        if (!$handle) {
            throw new Exception("CSV-Datei konnte nicht geöffnet werden!");
        }
        
        // Header überspringen
        $header = fgetcsv($handle);
        
        // Cache für Kategorien
        $categoryCache = [];
        $stmtCategory = $pdo->prepare('INSERT INTO golden_globe_category (name) VALUES (?)');
        $stmtNomination = $pdo->prepare('
            INSERT INTO golden_globe_nominations 
            (year_film, year_award, ceremony, category_id, nominee, film, imdb_const, winner) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $processedCount = 0;
        $errorCount = 0;
        $matchedCount = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            try {
                if (count($row) < 7) {
                    $errorCount++;
                    continue;
                }
                
                $yearFilm = (int)$row[0];
                $yearAward = (int)$row[1];
                $ceremony = (int)$row[2];
                $category = trim($row[3]);
                $nominee = trim($row[4]);
                $film = !empty($row[5]) ? trim($row[5]) : null;
                $win = strtolower($row[6]) === 'true' ? 1 : 0;
                
                if (empty($category) || empty($nominee)) {
                    $errorCount++;
                    continue;
                }
                
                // Category-ID ermitteln oder erstellen
                if (!isset($categoryCache[$category])) {
                    $stmtCategory->execute([$category]);
                    $categoryCache[$category] = $pdo->lastInsertId();
                }
                $categoryId = $categoryCache[$category];
                
                // IMDb-Const matching (nur wenn Film vorhanden)
                $imdbConst = null;
                if (!empty($film)) {
                    // Versuche Film in movies-Tabelle zu finden
                    // Strategie: Exakter Titel-Match mit Jahrestoleranz ±2 Jahre
                    $stmtMatch = $pdo->prepare('
                        SELECT const FROM movies 
                        WHERE (LOWER(title) = LOWER(?) OR LOWER(original_title) = LOWER(?))
                        AND year BETWEEN ? AND ?
                        AND title_type != "Fernsehepisode"
                        LIMIT 1
                    ');
                    $stmtMatch->execute([$film, $film, $yearFilm - 2, $yearFilm + 2]);
                    $match = $stmtMatch->fetch(PDO::FETCH_ASSOC);
                    
                    if ($match) {
                        $imdbConst = $match['const'];
                        $matchedCount++;
                    } else {
                        // Fallback: Fuzzy-Match ohne "The", Kommas, etc.
                        $cleanFilm = preg_replace('/^(the|a|an)\s+/i', '', $film);
                        $cleanFilm = preg_replace('/[^\w\s]/', '', $cleanFilm);
                        
                        $stmtFuzzy = $pdo->prepare('
                            SELECT const FROM movies 
                            WHERE (LOWER(REGEXP_REPLACE(REGEXP_REPLACE(title, "^(the|a|an) ", "", "i"), "[^a-zA-Z0-9 ]", "")) = LOWER(?)
                                   OR LOWER(REGEXP_REPLACE(REGEXP_REPLACE(original_title, "^(the|a|an) ", "", "i"), "[^a-zA-Z0-9 ]", "")) = LOWER(?))
                            AND year BETWEEN ? AND ?
                            AND title_type != "Fernsehepisode"
                            LIMIT 1
                        ');
                        
                        // Wenn REGEXP_REPLACE nicht verfügbar, einfacher Ansatz
                        try {
                            $stmtFuzzy->execute([$cleanFilm, $cleanFilm, $yearFilm - 2, $yearFilm + 2]);
                            $fuzzyMatch = $stmtFuzzy->fetch(PDO::FETCH_ASSOC);
                            if ($fuzzyMatch) {
                                $imdbConst = $fuzzyMatch['const'];
                                $matchedCount++;
                            }
                        } catch (Exception $e) {
                            // REGEXP nicht verfügbar, skip fuzzy matching
                        }
                    }
                }
                
                // Nominierung einfügen
                $stmtNomination->execute([
                    $yearFilm,
                    $yearAward,
                    $ceremony,
                    $categoryId,
                    $nominee,
                    $film,
                    $imdbConst,
                    $win
                ]);
                
                $processedCount++;
                
            } catch (Exception $e) {
                $errorCount++;
            }
        }
        
        fclose($handle);
        
        $pdo->commit();
        
        $message = "✓ Import abgeschlossen: $processedCount Golden Globe Einträge importiert.";
        $message .= "<br>Kategorien: " . count($categoryCache);
        $message .= "<br>IMDb-Verknüpfungen: $matchedCount von $processedCount (" . round($matchedCount / $processedCount * 100, 1) . "%)";
        
        if ($errorCount > 0) {
            $message .= "<br><br><strong>Fehler:</strong> $errorCount Einträge konnten nicht verarbeitet werden.";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Import fehlgeschlagen: ' . $e->getMessage();
    }
}

// Aktuelle Statistiken laden
$pdo = getConnection();
try {
    $statsStmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM golden_globe_nominations) as total_nominations,
            (SELECT COUNT(*) FROM golden_globe_nominations WHERE winner = 1) as total_winners,
            (SELECT COUNT(DISTINCT year_award) FROM golden_globe_nominations) as total_years,
            (SELECT COUNT(*) FROM golden_globe_category) as total_categories
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = ['total_nominations' => 0, 'total_winners' => 0, 'total_years' => 0, 'total_categories' => 0];
}

?>

<div id="import-module">
    <div class="row">
        <div class="col-md-8">
            <h2>🎭 Golden Globe Awards Import</h2>
            <p class="text-muted">Importiere Golden Globe Daten aus CSV-Datei</p>
            
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="message error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <!-- Aktuelle Statistiken -->
            <div class="info-box mb-4">
                <strong>📊 Aktuelle Datenbank:</strong><br>
                • Nominierungen: <?php echo number_format($stats['total_nominations'] ?? 0, 0, ',', '.'); ?><br>
                • Gewinner: <?php echo number_format($stats['total_winners'] ?? 0, 0, ',', '.'); ?><br>
                • Jahre: <?php echo $stats['total_years'] ?? 0; ?><br>
                • Kategorien: <?php echo $stats['total_categories'] ?? 0; ?><br>
                <?php if (isset($stats['total_nominations']) && $stats['total_nominations'] > 0): ?>
                    <?php
                    $linkedStmt = $pdo->query('SELECT COUNT(*) FROM golden_globe_nominations WHERE imdb_const IS NOT NULL');
                    $linkedCount = $linkedStmt->fetchColumn();
                    $linkPercent = $stats['total_nominations'] > 0 ? round($linkedCount / $stats['total_nominations'] * 100, 1) : 0;
                    ?>
                    • IMDb-Verknüpfungen: <?php echo number_format($linkedCount, 0, ',', '.'); ?> (<?php echo $linkPercent; ?>%)
                <?php endif; ?>
            </div>
            
            <form method="POST">
                <div class="info-box">
                    <strong>⚠️ Wichtig:</strong><br>
                    • Datei: <code>db/golden-globe-awards.csv</code><br>
                    • Alle vorhandenen Golden Globe-Daten werden gelöscht<br>
                    • IMDb-Verknüpfungen werden automatisch per Film-Titel-Matching erstellt<br>
                    • Es werden nur Gewinner importiert (keine komplette Nominierungsliste)
                </div>
                
                <div class="button-group mt-4">
                    <button type="submit" name="start_import" class="import-btn btn-primary" 
                            onclick="return confirm('Alle vorhandenen Golden Globe-Daten werden gelöscht. Fortfahren?')">
                        🎭 Golden Globe-Import starten
                    </button>
                    <a href="?mod=golden_globes" class="import-btn btn-secondary">
                        📋 Golden Globe-Daten anzeigen
                    </a>
                </div>
            </form>
            
            <?php if ($stats['total_nominations'] > 0): ?>
                <hr class="my-4">
                
                <h4>🔗 IMDb-Verknüpfungen aktualisieren</h4>
                <p class="text-muted">Verknüpfe bereits importierte Golden Globe-Einträge nachträglich mit Filmen aus der Datenbank.</p>
                
                <form method="POST" class="mt-3">
                    <div class="info-box">
                        <strong>ℹ️ Hinweis:</strong><br>
                        • Sucht nach Filmen in der movies-Tabelle anhand des Titels<br>
                        • Aktualisiert nur Einträge ohne bestehende IMDb-Verknüpfung<br>
                        • Verwendet exaktes und Fuzzy-Matching (±2 Jahre Toleranz)
                    </div>
                    
                    <div class="button-group mt-3">
                        <button type="submit" name="match_imdb" class="import-btn btn-info">
                            🔗 IMDb-Verknüpfungen erstellen
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
