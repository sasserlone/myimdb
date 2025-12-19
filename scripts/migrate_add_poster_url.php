<?php
// Migrations-Skript: Fügt poster_url Spalte zur movies-Tabelle hinzu (falls nicht vorhanden)

require_once __DIR__ . '/../inc/database.inc.php';

$pdo = getConnection();

try {
    // Prüfe ob Spalte bereits existiert
    $stmt = $pdo->query("SHOW COLUMNS FROM movies LIKE 'poster_url'");
    if ($stmt->rowCount() === 0) {
        // Spalte hinzufügen
        $pdo->exec('ALTER TABLE movies ADD COLUMN poster_url VARCHAR(1024) DEFAULT NULL');
        echo "Spalte 'poster_url' erfolgreich zur movies-Tabelle hinzugefügt.\n";
    } else {
        echo "Spalte 'poster_url' existiert bereits.\n";
    }
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
