<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';

// ****************************************************************************
// Oscar-Nominations Import (JSON)
// Importiert Oscar-Daten aus db/oscar-nominations.json
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_import'])) {
    try {
        $pdo = getConnection();
        
        $jsonFile = __DIR__ . '/../db/oscar-nominations.json';
        if (!file_exists($jsonFile)) {
            throw new Exception("oscar-nominations.json nicht gefunden!");
        }
        
        $jsonContent = file_get_contents($jsonFile);
        $nominations = json_decode($jsonContent, true);
        
        if (!$nominations) {
            throw new Exception("JSON konnte nicht gelesen werden!");
        }
        
        $totalEntries = count($nominations);
        
        $pdo->beginTransaction();
        
        // Tabelle leeren (oscar_category bleibt gefüllt)
        $pdo->exec('DELETE FROM oscar_nominations');
        
        // Auto-Increment zurücksetzen
        $pdo->exec('ALTER TABLE oscar_nominations AUTO_INCREMENT = 1');
        
        // Cache für Kategorien laden (nicht einfügen, nur nachschlagen)
        $categoryCache = [];
        $categoryStmt = $pdo->query('SELECT id, name FROM oscar_category');
        while ($row = $categoryStmt->fetch(PDO::FETCH_ASSOC)) {
            $categoryCache[$row['name']] = (int)$row['id'];
        }
        
        $stmtNomination = $pdo->prepare('
            INSERT INTO oscar_nominations (category_id, year, imdb_const, tmdb_id, nominated, winner) 
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
                
                // Category-ID aus Cache holen
                if (!isset($categoryCache[$category])) {
                    // Kategorie nicht gefunden - überspringe diesen Eintrag
                    $errorCount++;
                    continue;
                }
                $categoryId = $categoryCache[$category];
                
                // Nominierte Namen zusammenführen
                $nominatedStr = implode(', ', $nominees);
                
                // Für jeden Film einen Eintrag erstellen
                if (!empty($movies)) {
                    foreach ($movies as $movie) {
                        $imdbId = $movie['imdb_id'] ?? '';
                        $tmdbId = $movie['tmdb_id'] ?? '';
                        
                        $stmtNomination->execute([
                            $categoryId,
                            $year,
                            $imdbId,
                            $tmdbId,
                            $nominatedStr,
                            $won ? 1 : 0
                        ]);
                        
                        $processedCount++;
                    }
                } else {
                    // Kein Film zugeordnet, trotzdem Nominierung speichern
                    $stmtNomination->execute([
                        $categoryId,
                        $year,
                        '',
                        '',
                        $nominatedStr,
                        $won ? 1 : 0
                    ]);
                    
                    $processedCount++;
                }
                
            } catch (Exception $e) {
                $errorCount++;
            }
        }
        
        $pdo->commit();
        
        $message = "✓ Import abgeschlossen: $processedCount Nominierungen importiert aus $totalEntries JSON-Einträgen.";
        $message .= "<br>Kategorien gefunden: " . count($categoryCache);
        
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
$statsStmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM oscar_nominations) as total_nominations,
        (SELECT COUNT(*) FROM oscar_nominations WHERE winner = 1) as total_winners,
        (SELECT COUNT(DISTINCT year) FROM oscar_awards) as total_years,
        (SELECT COUNT(*) FROM oscar_category) as total_categories
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

?>

<div id="import-module">nomination
    <div class="row">
        <div class="col-md-8">
            <h2>🏆 Oscar-Nominierungen Import</h2>
            <p class="text-muted">Importiere Oscar-Daten aus JSON-Datei</p>
            
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
                • Kategorien: <?php echo $stats['total_categories'] ?? 0; ?>
            </div>
            
            <form method="POST">
                <div class="info-box">
                    <strong>⚠️ Wichtig:</strong><br>
                    • Datei: <code>db/oscar-nominations.json</code><br>
                    • Alle vorhandenen Oscar-Daten werden gelöscht<br>
                    • Import kann einige Minuten dauern<br>
                    • Datenbank wird während des Imports gesperrt
                </div>
                
                <div class="button-group mt-4">
                    <button type="submit" name="start_import" class="import-btn btn-primary" 
                            onclick="return confirm('Alle vorhandenen Oscar-Daten werden gelöscht. Fortfahren?')">
                        🏆 Oscar-Import starten
                    </button>
                    <a href="?mod=oscars" class="import-btn btn-secondary">
                        📋 Oscar-Daten anzeigen
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
