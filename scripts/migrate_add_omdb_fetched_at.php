<?php
// Migration: add omdb_fetched_at marker to movies to avoid reprocessing

require_once __DIR__ . '/../inc/database.inc.php';

$pdo = getConnection();

try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM movies LIKE 'omdb_fetched_at'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec('ALTER TABLE movies ADD COLUMN omdb_fetched_at DATETIME NULL');
        echo "Spalte 'omdb_fetched_at' hinzugefügt.\n";
    } else {
        echo "Spalte 'omdb_fetched_at' existiert bereits.\n";
    }
    echo "Migration abgeschlossen.\n";
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
