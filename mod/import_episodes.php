<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';

// ****************************************************************************
// CSV Import verarbeiten (Episodes CSV)
// Format:
// tconst	parentTconst	seasonNumber	episodeNumber
// tt0031458	tt32857063	\N	\N
// tt0041951	tt0041038	1	9
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    // Validierung
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Fehler beim Datei-Upload: ' . $file['error'];
    } elseif ($file['type'] !== 'text/csv' && $file['type'] !== 'application/vnd.ms-excel' && $file['type'] !== 'text/plain') {
        $error = 'Bitte wählen Sie eine CSV-Datei aus.';
    } else {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new Exception('Datei konnte nicht geöffnet werden.');
            }
            
            // Header-Zeile lesen (wird ignoriert)
            $header = fgetcsv($handle, 0, "\t");
            if (!$header) {
                throw new Exception('CSV-Datei ist leer oder ungültig.');
            }
            
            $imported = 0;
            $skipped = 0;
            $errors = [];
            
            // Prepared Statement vorbereiten
            $stmtCheck = $pdo->prepare('SELECT id FROM episodes WHERE tconst = ?');
            $stmtInsert = $pdo->prepare(
                'INSERT INTO episodes (tconst, parent_tconst, season_number, episode_number)
                 VALUES (?, ?, ?, ?)'
            );
            
            // Daten importieren
            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                // Zeile überspringen wenn zu wenig Spalten
                if (count($row) < 4) {
                    $skipped++;
                    continue;
                }
                
                try {
                    $tconst = trim($row[0] ?? '');
                    $parentTconst = trim($row[1] ?? '');
                    $seasonNumber = trim($row[2] ?? '');
                    $episodeNumber = trim($row[3] ?? '');
                    
                    // Erforderliches Feld prüfen
                    if (empty($tconst)) {
                        $skipped++;
                        continue;
                    }
                    
                    // Prüfe ob Episode bereits existiert
                    $stmtCheck->execute([$tconst]);
                    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $skipped++;
                        continue;
                    }
                    
                    // \N in NULL konvertieren
                    $parentTconstVal = ($parentTconst === '\\N' || empty($parentTconst)) ? null : $parentTconst;
                    $seasonNumberVal = ($seasonNumber === '\\N' || empty($seasonNumber)) ? null : (int)$seasonNumber;
                    $episodeNumberVal = ($episodeNumber === '\\N' || empty($episodeNumber)) ? null : (int)$episodeNumber;
                    
                    // Episode einfügen
                    $stmtInsert->execute([
                        $tconst,
                        $parentTconstVal,
                        $seasonNumberVal,
                        $episodeNumberVal
                    ]);
                    
                    $imported++;
                    
                } catch (Exception $e) {
                    $errors[] = 'Fehler in Zeile: ' . implode('\t', $row) . ' - ' . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            $pdo->commit();
            
            $message = "✓ Import abgeschlossen: $imported Episoden importiert, $skipped übersprungen.";
            
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

// ****************************************************************************
// Tabelle erstellen wenn nicht vorhanden
// ****************************************************************************

try {
    $pdo = getConnection();
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS episodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tconst VARCHAR(20) NOT NULL UNIQUE,
            parent_tconst VARCHAR(20),
            season_number INT,
            episode_number INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tconst (tconst),
            INDEX idx_parent_tconst (parent_tconst)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
} catch (Exception $e) {
    // Tabelle existiert bereits oder anderer Fehler
}

?>

<div id="import-module">
    <div class="row">
        <div class="col-md-8">
            <h2>📺 IMDb Episodes CSV Import</h2>
            <p class="text-muted">Importiere Episode-zu-Serie Zuordnungen</p>
            
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
                
                <input type="file" id="csvFile" name="csv_file" class="file-input" accept=".csv,.tsv,.txt">
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
                    • TSV-Format (Tab-separiert): tconst, parentTconst, seasonNumber, episodeNumber<br>
                    • \N wird als NULL interpretiert<br>
                    • Erforderlich: tconst (Episode ID)<br>
                    • Doppelte Einträge werden übersprungen
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
