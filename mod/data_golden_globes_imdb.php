<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';

// ****************************************************************************
// IMDb-Matching für bereits importierte Einträge
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_imdb'])) {
    set_time_limit(600);
    
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
            $processed = 0;
            $stmtUpdate = $pdo->prepare('UPDATE golden_globe_nominations SET imdb_const = ? WHERE id = ?');
            
            foreach ($unmatchedEntries as $entry) {
                $film = $entry['film'];
                $yearFilm = (int)$entry['year_film'];
                $id = $entry['id'];
                
                // Exakter Match
                $stmtMatch = $pdo->prepare('
                    SELECT const FROM movies 
                    WHERE (LOWER(title) = LOWER(?) OR LOWER(original_title) = LOWER(?))
                    AND (
                        (year BETWEEN ? AND ?)
                        OR (year <= ? AND (year_2 IS NULL OR year_2 >= ?))
                    )
                    AND title_type != "Fernsehepisode"
                    LIMIT 1
                ');
                $stmtMatch->execute([$film, $film, $yearFilm - 2, $yearFilm + 2, $yearFilm + 2, $yearFilm - 2]);
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
                        AND (
                            (year BETWEEN ? AND ?)
                            OR (year <= ? AND (year_2 IS NULL OR year_2 >= ?))
                        )
                        AND title_type != "Fernsehepisode"
                        LIMIT 1
                    ');
                    
                    try {
                        $searchPattern = '%' . strtolower($cleanFilm) . '%';
                        $stmtFuzzy->execute([$searchPattern, $searchPattern, $yearFilm - 2, $yearFilm + 2, $yearFilm + 2, $yearFilm - 2]);
                        $fuzzyMatch = $stmtFuzzy->fetch(PDO::FETCH_ASSOC);
                        if ($fuzzyMatch) {
                            $stmtUpdate->execute([$fuzzyMatch['const'], $id]);
                            $matchedCount++;
                        }
                    } catch (Exception $e) {
                        // Skip
                    }
                }
                
                $processed++;
                if ($processed % 20 === 0) {
                    echo "<!-- Verarbeitet: $processed / " . count($unmatchedEntries) . " -->\n";
                    flush();
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
            <h2>🔗 Golden Globe IMDb-Verknüpfungen</h2>
            <p class="text-muted">Verknüpfe Golden Globe-Einträge mit IMDb-Filmen aus der Datenbank</p>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
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
            
            <?php if ($stats['total_nominations'] > 0): ?>
                <form method="POST" class="mt-3">
                    <div class="info-box">
                        <strong>ℹ️ Hinweis:</strong><br>
                        • Sucht nach Filmen in der movies-Tabelle anhand des Titels<br>
                        • Aktualisiert nur Einträge ohne bestehende IMDb-Verknüpfung<br>
                        • Verwendet exaktes und Fuzzy-Matching (±2 Jahre Toleranz)<br>
                        • Schließt TV-Episoden aus der Suche aus
                    </div>
                    
                    <div class="button-group mt-4">
                        <button type="submit" name="match_imdb" class="import-btn btn-primary">
                            🔗 IMDb-Verknüpfungen erstellen
                        </button>
                        <a href="?mod=golden_globes" class="import-btn btn-secondary">
                            📋 Golden Globe-Daten anzeigen
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-info">
                    Keine Golden Globe-Daten vorhanden. Bitte importieren Sie zunächst Daten.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
