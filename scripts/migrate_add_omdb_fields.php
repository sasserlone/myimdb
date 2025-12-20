<?php
// Migration: add OMDb metadata fields to movies table
// Fields: plot, language, country, metascore, metacritic_score, rotten_tomatoes

require_once __DIR__ . '/../inc/database.inc.php';

$pdo = getConnection();

$columns = [
    'plot' => 'ALTER TABLE movies ADD COLUMN plot TEXT NULL',
    'language' => 'ALTER TABLE movies ADD COLUMN language VARCHAR(255) NULL',
    'country' => 'ALTER TABLE movies ADD COLUMN country VARCHAR(255) NULL',
    'metascore' => 'ALTER TABLE movies ADD COLUMN metascore INT NULL',
    'metacritic_score' => 'ALTER TABLE movies ADD COLUMN metacritic_score INT NULL',
    'rotten_tomatoes' => 'ALTER TABLE movies ADD COLUMN rotten_tomatoes INT NULL'
];

try {
    foreach ($columns as $name => $ddl) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM movies LIKE ?');
        $stmt->execute([$name]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec($ddl);
            echo "Spalte '{$name}' hinzugefügt.\n";
        } else {
            echo "Spalte '{$name}' existiert bereits.\n";
        }
    }
    echo "Migration abgeschlossen.\n";
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
