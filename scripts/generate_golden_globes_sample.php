<?php
/**
 * Golden Globe CSV Generator - Manuelle Extraktion
 * 
 * Generiert golden-globe-awards.csv aus strukturierten Daten
 * Basiert auf: https://en.wikipedia.org/wiki/List_of_Golden_Globe_winners
 */

$outputFile = __DIR__ . '/../db/golden-globe-awards-new.csv';

// Strukturierte Daten aus Wikipedia (nur Film-Kategorien)
// Format: [Jahr, Zeremonie, Kategorie, Nominierte, Film, Gewinner]
$data = [
    // Basierend auf der Wikipedia-Tabelle - hier nur als Beispiel die neuesten Jahre
    // 2024-2025
    [2024, 2025, 82, 'Best Motion Picture – Drama', 'The Brutalist', 'The Brutalist', true],
    [2024, 2025, 82, 'Best Motion Picture – Musical or Comedy', 'Emilia Pérez', 'Emilia Pérez', true],
    [2024, 2025, 82, 'Best Performance by an Actor in a Motion Picture – Drama', 'Adrien Brody', 'The Brutalist', true],
    [2024, 2025, 82, 'Best Performance by an Actor in a Motion Picture – Musical or Comedy', 'Sebastian Stan', 'A Different Man', true],
    [2024, 2025, 82, 'Best Performance by an Actress in a Motion Picture – Drama', 'Fernanda Torres', 'I\'m Still Here', true],
    [2024, 2025, 82, 'Best Performance by an Actress in a Motion Picture – Musical or Comedy', 'Demi Moore', 'The Substance', true],
    [2024, 2025, 82, 'Best Director – Motion Picture', 'Brady Corbet', 'The Brutalist', true],
    
    // 2023-2024
    [2023, 2024, 81, 'Best Motion Picture – Drama', 'Oppenheimer', 'Oppenheimer', true],
    [2023, 2024, 81, 'Best Motion Picture – Musical or Comedy', 'Poor Things', 'Poor Things', true],
    [2023, 2024, 81, 'Best Performance by an Actor in a Motion Picture – Drama', 'Cillian Murphy', 'Oppenheimer', true],
    [2023, 2024, 81, 'Best Performance by an Actor in a Motion Picture – Musical or Comedy', 'Paul Giamatti', 'The Holdovers', true],
    [2023, 2024, 81, 'Best Performance by an Actress in a Motion Picture – Drama', 'Lily Gladstone', 'Killers of the Flower Moon', true],
    [2023, 2024, 81, 'Best Performance by an Actress in a Motion Picture – Musical or Comedy', 'Emma Stone', 'Poor Things', true],
    [2023, 2024, 81, 'Best Director – Motion Picture', 'Christopher Nolan', 'Oppenheimer', true],
    
    // 2022-2023
    [2022, 2023, 80, 'Best Motion Picture – Drama', 'The Fabelmans', 'The Fabelmans', true],
    [2022, 2023, 80, 'Best Motion Picture – Musical or Comedy', 'The Banshees of Inisherin', 'The Banshees of Inisherin', true],
    [2022, 2023, 80, 'Best Performance by an Actor in a Motion Picture – Drama', 'Austin Butler', 'Elvis', true],
    [2022, 2023, 80, 'Best Performance by an Actor in a Motion Picture – Musical or Comedy', 'Colin Farrell', 'The Banshees of Inisherin', true],
    [2022, 2023, 80, 'Best Performance by an Actress in a Motion Picture – Drama', 'Cate Blanchett', 'Tár', true],
    [2022, 2023, 80, 'Best Performance by an Actress in a Motion Picture – Musical or Comedy', 'Michelle Yeoh', 'Everything Everywhere All at Once', true],
    [2022, 2023, 80, 'Best Director – Motion Picture', 'Steven Spielberg', 'The Fabelmans', true],
];

echo "💾 Generiere CSV-Datei mit " . count($data) . " Einträgen...\n";

$fp = fopen($outputFile, 'w');
if (!$fp) {
    die("❌ Fehler beim Erstellen der CSV-Datei\n");
}

// Header schreiben
fputcsv($fp, ['year_film', 'year_award', 'ceremony', 'category', 'nominee', 'film', 'win']);

// Daten schreiben
foreach ($data as $row) {
    fputcsv($fp, [
        $row[0], // year_film
        $row[1], // year_award
        $row[2], // ceremony
        $row[3], // category
        $row[4], // nominee
        $row[5], // film
        $row[6] ? 'True' : 'False' // win
    ]);
}

fclose($fp);

echo "✅ CSV-Datei erstellt: $outputFile\n";
echo "📊 " . count($data) . " Golden Globe Einträge geschrieben\n\n";

echo "ℹ️  HINWEIS:\n";
echo "   Die aktuelle CSV enthält nur die neuesten Jahre als Beispiel.\n";
echo "   Für eine vollständige Datenbank sollten alle Jahre von 1944-2025 eingepflegt werden.\n";
echo "   Die alte CSV-Datei bleibt erhalten unter: db/golden-globe-awards.csv\n\n";

echo "🔄 Nächste Schritte:\n";
echo "   1. Prüfe die neue CSV: db/golden-globe-awards-new.csv\n";
echo "   2. Erweitere die Daten nach Bedarf\n";
echo "   3. Ersetze die alte Datei oder importiere die neue mit import_golden_globes.php\n";
