<?php
/**
 * Migration: Füge Oscar-Spalten zur movies Tabelle hinzu
 * - oscar_winner (BOOLEAN): Hat den Oscar gewonnen?
 * - oscar_nominations (INT): Anzahl der Nominierungen
 * - oscar_year (INT): Jahr der Gewinn/Nominierung
 * - oscar_category (VARCHAR): Kategorie (Best Picture, Best Director, etc.)
 */

require_once __DIR__ . '/../inc/database.inc.php';

$pdo = getConnection();

try {
    // Prüfe ob Spalten bereits existieren
    $stmt = $pdo->query("SHOW COLUMNS FROM movies");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    // Füge oscar_winner Spalte hinzu
    if (!in_array('oscar_winner', $columns)) {
        $pdo->exec("ALTER TABLE movies ADD COLUMN oscar_winner BOOLEAN DEFAULT FALSE AFTER rotten_tomatoes");
        echo "✓ Spalte 'oscar_winner' hinzugefügt\n";
    }

    // Füge oscar_nominations Spalte hinzu
    if (!in_array('oscar_nominations', $columns)) {
        $pdo->exec("ALTER TABLE movies ADD COLUMN oscar_nominations INT DEFAULT 0 AFTER oscar_winner");
        echo "✓ Spalte 'oscar_nominations' hinzugefügt\n";
    }

    // Füge oscar_year Spalte hinzu
    if (!in_array('oscar_year', $columns)) {
        $pdo->exec("ALTER TABLE movies ADD COLUMN oscar_year INT DEFAULT NULL AFTER oscar_nominations");
        echo "✓ Spalte 'oscar_year' hinzugefügt\n";
    }

    // Füge oscar_category Spalte hinzu
    if (!in_array('oscar_category', $columns)) {
        $pdo->exec("ALTER TABLE movies ADD COLUMN oscar_category VARCHAR(255) DEFAULT NULL AFTER oscar_year");
        echo "✓ Spalte 'oscar_category' hinzugefügt\n";
    }

    echo "\nMigration erfolgreich abgeschlossen!\n";

} catch (PDOException $e) {
    echo "Fehler bei der Migration: " . $e->getMessage() . "\n";
    exit(1);
}

?>
