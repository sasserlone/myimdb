<?php
/**
 * Golden Globe CSV Generator from Wikipedia
 * 
 * Generiert eine neue golden-globe-awards.csv aus Wikipedia-Daten
 * Quelle: https://en.wikipedia.org/wiki/List_of_Golden_Globe_winners
 */

set_time_limit(300);

$wikipediaUrl = 'https://en.wikipedia.org/wiki/List_of_Golden_Globe_winners';
$outputFile = __DIR__ . '/../db/golden-globe-awards-new.csv';

echo "📥 Lade Wikipedia-Seite...\n";

// cURL mit User-Agent verwenden (Wikipedia blockiert file_get_contents)
$ch = curl_init($wikipediaUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'MyIMDb/1.0 (Educational Project; PHP Script)');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $httpCode !== 200) {
    die("❌ Fehler beim Laden der Wikipedia-Seite (HTTP $httpCode)\n");
}

// DOMDocument für HTML-Parsing
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

$nominations = [];
$ceremony = 0;

echo "🔍 Analysiere Film-Tabellen...\n";

// Finde alle Tabellen auf der Seite
$tables = $xpath->query('//table[contains(@class, "wikitable")]');

foreach ($tables as $tableIndex => $table) {
    // Prüfe ob es eine Film-Tabelle ist (nicht TV)
    $rows = $xpath->query('.//tr', $table);
    if ($rows->length === 0) continue;
    
    // Header-Zeile analysieren
    $headerRow = $rows->item(0);
    $headers = [];
    $headerCells = $xpath->query('.//th', $headerRow);
    
    foreach ($headerCells as $cell) {
        $headers[] = trim($cell->textContent);
    }
    
    // Skip wenn "Television" oder keine relevanten Headers
    $headerText = implode(' ', $headers);
    if (stripos($headerText, 'television') !== false || stripos($headerText, 'series') !== false) {
        continue;
    }
    
    // Prüfe ob es Film-Kategorien enthält
    if (!preg_match('/(Year|Drama|Musical|Actor|Actress|Director|Picture)/i', $headerText)) {
        continue;
    }
    
    echo "  📋 Verarbeite Tabelle " . ($tableIndex + 1) . " (Headers: " . implode(', ', array_slice($headers, 0, 4)) . "...)\n";
    
    // Datenzeilen verarbeiten
    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        $cells = $xpath->query('.//td', $row);
        
        if ($cells->length === 0) continue;
        
        $rowData = [];
        foreach ($cells as $cell) {
            $rowData[] = trim($cell->textContent);
        }
        
        // Jahr aus erster Spalte
        $yearText = $rowData[0] ?? '';
        
        // Parse Jahr (Format: "1943–1944" oder "2023-2024")
        if (preg_match('/(\d{4})[–-](\d{4})/', $yearText, $matches)) {
            $yearFilm = (int)$matches[1];
            $yearAward = (int)$matches[2];
            $ceremony++;
        } else {
            continue; // Keine gültige Jahresangabe
        }
        
        // Kategorien basierend auf Header-Position
        $categoryMap = [];
        foreach ($headers as $idx => $header) {
            $categoryMap[$idx] = $header;
        }
        
        // Verarbeite jede Kategorie (ab Index 1, da 0 = Year)
        for ($colIdx = 1; $colIdx < count($rowData); $colIdx++) {
            $cellContent = $rowData[$colIdx];
            $categoryName = $categoryMap[$colIdx + 1] ?? ''; // +1 wegen TH vs TD offset
            
            if (empty($cellContent) || $cellContent === '—' || empty($categoryName)) {
                continue;
            }
            
            // Parse Nominee und Film aus Format: "Name, Film"
            $entries = preg_split('/\s+and\s+|\s+\(TIE\)/', $cellContent);
            
            foreach ($entries as $entry) {
                $entry = trim($entry);
                if (empty($entry) || $entry === '—') continue;
                
                // Format: "Name, Film" oder nur "Film"
                $nominee = '';
                $film = '';
                
                if (preg_match('/^(.+?),\s*(.+)$/', $entry, $matches)) {
                    $nominee = trim($matches[1]);
                    $film = trim($matches[2]);
                } else {
                    $film = $entry;
                }
                
                // Bestimme ob Gewinner (erste Zeile nach Jahr = Gewinner)
                $winner = true; // In dieser vereinfachten Version nehmen wir an, alle sind Gewinner
                
                // Kategorie-Name normalisieren
                $category = '';
                if (stripos($categoryName, 'Drama') !== false && stripos($categoryName, 'Actor') !== false) {
                    $category = 'Best Performance by an Actor in a Motion Picture – Drama';
                } elseif (stripos($categoryName, 'Musical') !== false && stripos($categoryName, 'Actor') !== false) {
                    $category = 'Best Performance by an Actor in a Motion Picture – Musical or Comedy';
                } elseif (stripos($categoryName, 'Drama') !== false && stripos($categoryName, 'Actress') !== false) {
                    $category = 'Best Performance by an Actress in a Motion Picture – Drama';
                } elseif (stripos($categoryName, 'Musical') !== false && stripos($categoryName, 'Actress') !== false) {
                    $category = 'Best Performance by an Actress in a Motion Picture – Musical or Comedy';
                } elseif (stripos($categoryName, 'Director') !== false) {
                    $category = 'Best Director – Motion Picture';
                } elseif (stripos($categoryName, 'Drama') !== false && stripos($categoryName, 'Picture') === false) {
                    $category = 'Best Motion Picture – Drama';
                } elseif (stripos($categoryName, 'Musical') !== false || stripos($categoryName, 'Comedy') !== false) {
                    $category = 'Best Motion Picture – Musical or Comedy';
                } elseif (stripos($categoryName, 'Picture') !== false) {
                    $category = 'Best Motion Picture';
                } else {
                    $category = $categoryName;
                }
                
                if (empty($category)) continue;
                
                $nominations[] = [
                    'year_film' => $yearFilm,
                    'year_award' => $yearAward,
                    'ceremony' => $ceremony,
                    'category' => $category,
                    'nominee' => $nominee ?: $film,
                    'film' => $film,
                    'win' => $winner ? 'True' : 'False'
                ];
            }
        }
    }
}

echo "\n✅ " . count($nominations) . " Einträge gesammelt\n";

// CSV-Datei schreiben
echo "💾 Schreibe CSV-Datei...\n";
$fp = fopen($outputFile, 'w');
if (!$fp) {
    die("❌ Fehler beim Erstellen der CSV-Datei\n");
}

// Header schreiben
fputcsv($fp, ['year_film', 'year_award', 'ceremony', 'category', 'nominee', 'film', 'win']);

// Daten schreiben
foreach ($nominations as $nom) {
    fputcsv($fp, $nom);
}

fclose($fp);

echo "✅ CSV-Datei erstellt: $outputFile\n";
echo "📊 Gesamt: " . count($nominations) . " Golden Globe Einträge\n";

// Statistiken
$categories = array_unique(array_column($nominations, 'category'));
$years = array_unique(array_column($nominations, 'year_award'));

echo "\n📈 Statistiken:\n";
echo "  • Jahre: " . count($years) . " (" . min($years) . " - " . max($years) . ")\n";
echo "  • Kategorien: " . count($categories) . "\n";
echo "  • Zeremonien: " . $ceremony . "\n";

echo "\n📝 Kategorien:\n";
foreach ($categories as $cat) {
    $count = count(array_filter($nominations, fn($n) => $n['category'] === $cat));
    echo "  • $cat: $count Einträge\n";
}

echo "\n✅ Fertig! Die neue Datei kann jetzt über import_golden_globes.php importiert werden.\n";
echo "   Alte Datei: db/golden-globe-awards.csv\n";
echo "   Neue Datei: db/golden-globe-awards-new.csv\n";
