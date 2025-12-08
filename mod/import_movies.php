<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';

// ****************************************************************************
// CSV Import verarbeiten (Movies CSV)
// Format:
// Position,Const,Created,Modified,Description,Title,Original Title,URL,Title Type,IMDb Rating,Runtime (mins),Year,Genres,Num Votes,Release Date,Directors,Your Rating,Date Rated
// Position wird ignoriert
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    // Validierung
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Fehler beim Datei-Upload: ' . $file['error'];
    } elseif ($file['type'] !== 'text/csv' && $file['type'] !== 'application/vnd.ms-excel') {
        $error = 'Bitte wählen Sie eine CSV-Datei aus.';
    } else {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new Exception('Datei konnte nicht geöffnet werden.');
            }
            
            // Header-Zeile lesen
            $header = fgetcsv($handle);
            if (!$header) {
                throw new Exception('CSV-Datei ist leer oder ungültig.');
            }

            // Normalisiere Header (kleinbuchstaben)
            $header = array_map('trim', $header);
            $headerLower = array_change_key_case(array_flip($header), CASE_LOWER);

            $imported = 0;
            $skipped = 0;
            $errors = [];
            
            // Daten importieren
            while (($row = fgetcsv($handle)) !== false) {
                // Zeile überspringen wenn zu wenig Spalten
                if (count($row) < count($header)) {
                    $skipped++;
                    continue;
                }
                
                // Daten extrahieren (basierend auf CSV-Format)
                $data = array_combine($header, $row);
                
                // Spaltennamen normalisieren
                $data = array_change_key_case($data, CASE_LOWER);
                
                try {
                    // Erforderliche Felder prüfen
                    $const = trim($data['const'] ?? '');
                    $title = trim($data['title'] ?? '');
                    
                    if (empty($const) || empty($title)) {
                        $skipped++;
                        continue;
                    }
                    
                    // Prüfe ob Film bereits existiert
                    $stmt = $pdo->prepare('SELECT id FROM movies WHERE const = ?');
                    $stmt->execute([$const]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        // Film existiert bereits: hole ID und verknüpfe trotzdem Genres
                        $movieId = (int)$existing['id'];
                        $skipped++;
                    } else {
                        // Film in Datenbank einfügen
                        $stmt = $pdo->prepare(
                            'INSERT INTO movies 
                            (const, your_rating, date_rated, title, original_title, url, title_type, 
                             imdb_rating, runtime_mins, year, genres, num_votes, release_date, directors)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );

                        $stmt->execute([
                            $const,
                            !empty($data['your rating']) ? (int)$data['your rating'] : null,
                            !empty($data['date rated']) ? $data['date rated'] : null,
                            $title,
                            trim($data['original title'] ?? ''),
                            trim($data['url'] ?? ''),
                            trim($data['title type'] ?? ''),
                            !empty($data['imdb rating']) ? (float)$data['imdb rating'] : null,
                            !empty($data['runtime (mins)']) ? (int)$data['runtime (mins)'] : null,
                            !empty($data['year']) ? (int)$data['year'] : null,
                            trim($data['genres'] ?? ''),
                            !empty($data['num votes']) ? (int)str_replace(',', '', $data['num votes']) : null,
                            !empty($data['release date']) ? $data['release date'] : null,
                            trim($data['directors'] ?? '')
                        ]);

                        $movieId = (int)$pdo->lastInsertId();
                        $imported++;
                    }

                    // --- Genres verarbeiten und Verknüpfungen anlegen ---
                    static $stmtFindGenre, $stmtInsertGenre, $stmtInsertMovieGenre;
                    if (!$stmtFindGenre) {
                        $stmtFindGenre = $pdo->prepare('SELECT id FROM genres WHERE name = ?');
                        $stmtInsertGenre = $pdo->prepare('INSERT INTO genres (name) VALUES (?)');
                        $stmtInsertMovieGenre = $pdo->prepare('INSERT INTO movies_genres (movie_id, genre_id) VALUES (?, ?)');
                    }

                    $genresStr = trim($data['genres'] ?? '');
                    if ($genresStr !== '') {
                        $genresStr = trim($genresStr, '"');
                        $parts = array_filter(array_map('trim', explode(',', $genresStr)), function($v){ return $v !== ''; });
                        foreach ($parts as $gname) {
                            // Suche Genre
                            $stmtFindGenre->execute([$gname]);
                            $g = $stmtFindGenre->fetch(PDO::FETCH_ASSOC);
                            if ($g) {
                                $genreId = (int)$g['id'];
                            } else {
                                $stmtInsertGenre->execute([$gname]);
                                $genreId = (int)$pdo->lastInsertId();
                            }

                            // Verknüpfung einfügen (bei doppelten Einträgen ignorieren)
                            try {
                                $stmtInsertMovieGenre->execute([$movieId, $genreId]);
                            } catch (Exception $e) {
                                // Ignoriere Duplicate-Key Fehler
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    $errors[] = 'Fehler in Zeile: ' . implode(' | ', $row) . ' - ' . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            $pdo->commit();
            
            $message = "✓ Import abgeschlossen: $imported Filme importiert, $skipped übersprungen.";
            
            if (!empty($errors)) {
                $message .= '<br><br><strong>Fehler:</strong><br>' . implode('<br>', array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= '<br>... und ' . (count($errors) - 5) . ' weitere Fehler';
                }
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Import fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

// Hinweis: Tabellen (`movies`, `genres`, `movies_genres`) werden bereits in `import_ratings.php` erstellt.

?>

<div id="import-module">
    <div class="row">
        <div class="col-md-8">
            <h2>🎬 IMDb Movies CSV Import</h2>
            <p class="text-muted">Importiere Filme (Position wird ignoriert)</p>
            
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="message error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <!-- Drag & Drop Zone -->
                <div class="drop-zone" id="dropZone">
                    <div class="drop-zone-icon">📁</div>
                    <div class="drop-zone-text">CSV-Datei hierher ziehen</div>
                    <div class="drop-zone-subtext">oder klicken zum Durchsuchen</div>
                </div>
                
                <input type="file" id="csvFile" name="csv_file" class="file-input" accept=".csv">
                <div class="file-name" id="fileName"></div>
                
                <div class="divider">oder</div>
                
                <!-- Button Upload -->
                <div class="button-group">
                    <button type="button" class="import-btn btn-secondary" onclick="document.getElementById('csvFile').click()">
                        📁 Datei wählen
                    </button>
                    <button type="submit" class="import-btn btn-primary" id="submitBtn" disabled>
                        📤 Importieren
                    </button>
                </div>
                
                <div class="info-box">
                    <strong>ℹ️ Hinweise:</strong><br>
                    • CSV-Format: Position,Const,Created,Modified,Description,Title,...<br>
                    • Position-Spalte wird nicht gespeichert<br>
                    • Doppelte Einträge (nach Const) werden übersprungen (Genres werden dennoch verknüpft)<br>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.getElementById('dropZone');
        const csvFile = document.getElementById('csvFile');
        const fileName = document.getElementById('fileName');
        const submitBtn = document.getElementById('submitBtn');
        const importForm = document.getElementById('importForm');
        
        if (!dropZone) return;
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }
        ['dragenter', 'dragover'].forEach(eventName => dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over')));
        ['dragleave', 'drop'].forEach(eventName => dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over')));
        dropZone.addEventListener('drop', (e) => { const files = e.dataTransfer.files; if (files.length>0){ csvFile.files = files; handleFileSelect(); } });
        dropZone.addEventListener('click', () => csvFile.click());
        csvFile.addEventListener('change', handleFileSelect);
        function handleFileSelect(){ if (csvFile.files.length>0){ const file = csvFile.files[0]; fileName.textContent = '✓ Datei ausgewählt: ' + file.name + ' (' + (file.size/1024).toFixed(2) + ' KB)'; fileName.classList.add('show'); submitBtn.disabled = false;} else { fileName.classList.remove('show'); submitBtn.disabled = true; } }
        importForm.addEventListener('submit', (e) => { if (csvFile.files.length === 0) { e.preventDefault(); alert('Bitte wählen Sie eine CSV-Datei aus.'); } });
    });
</script>
