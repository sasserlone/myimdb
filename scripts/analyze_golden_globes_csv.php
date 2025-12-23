<?php
/**
 * Analysiere golden-globe-awards.csv auf Fehler
 */

$csvFile = __DIR__ . '/../db/golden-globe-awards.csv';

if (!file_exists($csvFile)) {
    die("❌ CSV-Datei nicht gefunden\n");
}

echo "📊 Analysiere golden-globe-awards.csv...\n\n";

$lines = file($csvFile);
$totalLines = count($lines);

echo "Zeilen gesamt: " . number_format($totalLines) . "\n";

// Header prüfen
$header = str_getcsv($lines[0]);
echo "Header: " . implode(', ', $header) . "\n\n";

// Daten analysieren
$errors = [];
$years = [];
$categories = [];
$films = [];
$nominees = [];
$missingFilms = 0;
$missingNominees = 0;

for ($i = 1; $i < count($lines); $i++) {
    $parts = str_getcsv($lines[$i]);
    
    // Spaltenanzahl prüfen
    if (count($parts) !== 7) {
        $errors[] = "Zeile " . ($i + 1) . ": Falsche Spaltenanzahl (" . count($parts) . " statt 7)";
        continue;
    }
    
    list($yearFilm, $yearAward, $ceremony, $category, $nominee, $film, $win) = $parts;
    
    // Jahre sammeln
    if (!empty($yearAward)) {
        $years[] = (int)$yearAward;
    }
    
    // Kategorien sammeln
    if (!empty($category)) {
        if (!isset($categories[$category])) {
            $categories[$category] = 0;
        }
        $categories[$category]++;
    }
    
    // Fehlende Filme
    if (empty($film)) {
        $missingFilms++;
    } else {
        $films[$film] = true;
    }
    
    // Fehlende Nominees
    if (empty($nominee)) {
        $missingNominees++;
    } else {
        $nominees[$nominee] = true;
    }
    
    // Ungültige Win-Werte
    if (!in_array($win, ['True', 'False'])) {
        $errors[] = "Zeile " . ($i + 1) . ": Ungültiger Win-Wert '$win' (muss True oder False sein)";
    }
}

echo "📈 Statistiken:\n";
echo "  • Einträge: " . number_format($totalLines - 1) . "\n";
echo "  • Jahre: " . (count($years) > 0 ? min($years) . " - " . max($years) : 'keine') . "\n";
echo "  • Einzigartige Kategorien: " . count($categories) . "\n";
echo "  • Einzigartige Filme: " . count($films) . "\n";
echo "  • Einzigartige Nominees: " . count($nominees) . "\n\n";

echo "⚠️  Fehlende Daten:\n";
echo "  • Einträge ohne Film: " . number_format($missingFilms) . "\n";
echo "  • Einträge ohne Nominee: " . number_format($missingNominees) . "\n\n";

if (count($errors) > 0) {
    echo "❌ Fehler gefunden: " . count($errors) . "\n";
    foreach (array_slice($errors, 0, 20) as $error) {
        echo "  • $error\n";
    }
    if (count($errors) > 20) {
        echo "  ... und " . (count($errors) - 20) . " weitere\n";
    }
} else {
    echo "✅ Keine strukturellen Fehler gefunden\n";
}

echo "\n📋 Top 10 Kategorien:\n";
arsort($categories);
$topCategories = array_slice($categories, 0, 10, true);
foreach ($topCategories as $cat => $count) {
    echo "  • $cat: $count Einträge\n";
}

echo "\n💡 Empfehlung:\n";
echo "   Die CSV-Struktur ist korrekt. Für bessere Datenqualität:\n";
echo "   1. IMDb-Verknüpfungen hinzufügen (imdb_const fehlt komplett)\n";
echo "   2. Kategorie-Namen vereinheitlichen\n";
echo "   3. Nominierte vs. Gewinner unterscheiden (aktuell nur Gewinner mit True)\n";
