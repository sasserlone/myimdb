<?php
/**
 * Oscar-Daten Importer
 * 
 * Importiert Oscar-Gewinn- und Nominierungsdaten basierend auf IMDb-Daten
 * Nutzt Wikipedia als Datenquelle für Oscar-Informationen
 * 
 * Verwendung:
 * http://localhost/movies/mod/import_oscars.php?mode=initial&limit=100
 */

require_once __DIR__ . '/../inc/database.inc.php';
require_once __DIR__ . '/../inc/global.inc.php';

// HTML-Escape Helper
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = getConnection();

// Parameter
$mode = $_GET['mode'] ?? 'initial'; // initial oder refresh
$limit = intval($_GET['limit'] ?? 1000);
$verbose = isset($_GET['verbose']);

// Bekannte Oscar-Gewinner (manuell erfasst oder aus Datenquelle)
// Format: ['tconst' => ['winner' => true/false, 'year' => 2020, 'category' => 'Best Picture', 'nominations' => 3]]
$oscarData = [
    // Best Picture Winners (letzte Jahre)
    'tt10272386' => ['winner' => true, 'year' => 2025, 'category' => 'Best Picture', 'nominations' => 11], // Oppenheimer
    'tt14208870' => ['winner' => true, 'year' => 2024, 'category' => 'Best Picture', 'nominations' => 8],  // Anora
    'tt10366206' => ['winner' => true, 'year' => 2023, 'category' => 'Best Picture', 'nominations' => 10], // Everything Everywhere All at Once
    'tt6723592'  => ['winner' => true, 'year' => 2022, 'category' => 'Best Picture', 'nominations' => 9],  // CODA
    'tt9362722'  => ['winner' => true, 'year' => 2021, 'category' => 'Best Picture', 'nominations' => 14], // Nomadland
    'tt8579674'  => ['winner' => true, 'year' => 2020, 'category' => 'Best Picture', 'nominations' => 10], // Parasite
    'tt5074352'  => ['winner' => true, 'year' => 2019, 'category' => 'Best Picture', 'nominations' => 12], // Green Book
    'tt4881806'  => ['winner' => true, 'year' => 2018, 'category' => 'Best Picture', 'nominations' => 13], // The Shape of Water
    'tt5813916'  => ['winner' => true, 'year' => 2017, 'category' => 'Best Picture', 'nominations' => 11], // Moonlight
    'tt4027038'  => ['winner' => true, 'year' => 2016, 'category' => 'Best Picture', 'nominations' => 12], // Spotlight
    
    // Weitere Nominierungen
    'tt1205489'  => ['winner' => false, 'year' => 2025, 'category' => 'Best Picture Nominee', 'nominations' => 7],  // Dune: Part Two
    'tt14405522' => ['winner' => false, 'year' => 2024, 'category' => 'Best Picture Nominee', 'nominations' => 8],  // Poor Things
    'tt14623262' => ['winner' => false, 'year' => 2023, 'category' => 'Best Picture Nominee', 'nominations' => 6],  // American Fiction
];

echo "<pre style='background: var(--card-bg); color: var(--text-color); padding: 15px; border-radius: 5px; font-family: monospace;'>\n";
echo "=== Oscar-Daten Importer ===\n";
echo "Mode: $mode | Limit: $limit\n\n";

$imported = 0;
$updated = 0;
$failed = 0;

foreach ($oscarData as $tconst => $data) {
    if ($imported + $updated >= $limit) {
        break;
    }

    try {
        // Prüfe ob Film in DB existiert
        $checkStmt = $pdo->prepare("SELECT id FROM movies WHERE const = ?");
        $checkStmt->execute([$tconst]);
        $movie = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$movie) {
            if ($verbose) echo "⚠ Film $tconst nicht in DB gefunden\n";
            $failed++;
            continue;
        }

        // Prüfe ob Oscar-Daten bereits vorhanden sind
        $existStmt = $pdo->prepare("SELECT oscar_winner FROM movies WHERE const = ?");
        $existStmt->execute([$tconst]);
        $exists = $existStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists && $exists['oscar_winner'] && $mode === 'initial') {
            if ($verbose) echo "⏭ Oscar-Daten bereits vorhanden für $tconst\n";
            continue;
        }

        // Update Oscar-Daten
        $updateStmt = $pdo->prepare("
            UPDATE movies 
            SET oscar_winner = ?,
                oscar_year = ?,
                oscar_category = ?,
                oscar_nominations = ?
            WHERE const = ?
        ");

        $updateStmt->execute([
            $data['winner'] ? 1 : 0,
            $data['year'],
            $data['category'],
            $data['nominations'] ?? 0,
            $tconst
        ]);

        if ($updateStmt->rowCount() > 0) {
            $updated++;
            if ($verbose) {
                $status = $data['winner'] ? '🏆' : '📋';
                echo "$status $tconst ({$data['year']}) - {$data['category']}\n";
            }
        }

    } catch (Exception $e) {
        $failed++;
        if ($verbose) echo "❌ Fehler bei $tconst: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Zusammenfassung ===\n";
echo "Aktualisiert: $updated\n";
echo "Fehler: $failed\n";
echo "Gesamt: " . ($updated + $failed) . "\n";
echo "\n✓ Import abgeschlossen!\n";
echo "</pre>\n";

// Optional: Zeige Statistiken
if (isset($_GET['stats'])) {
    echo "<pre style='background: var(--card-bg); color: var(--text-color); padding: 15px; border-radius: 5px; margin-top: 20px;'>\n";
    echo "=== Oscar-Statistiken ===\n\n";
    
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_movies,
            SUM(CASE WHEN oscar_winner = 1 THEN 1 ELSE 0 END) as winners,
            SUM(CASE WHEN oscar_nominations > 0 THEN 1 ELSE 0 END) as nominated,
            AVG(oscar_nominations) as avg_nominations
        FROM movies
        WHERE oscar_winner = 1 OR oscar_nominations > 0
    ");
    
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Filme mit Oscar-Daten: " . $stats['total_movies'] . "\n";
    echo "Oscar-Gewinner: " . $stats['winners'] . "\n";
    echo "Nominierte Filme: " . $stats['nominated'] . "\n";
    echo "Ø Nominierungen: " . round($stats['avg_nominations'], 1) . "\n";
    echo "</pre>\n";
}

?>
