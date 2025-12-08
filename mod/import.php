<?php

$message = '';
$error = '';

// ****************************************************************************
// CSV Import verarbeiten
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
                    $existing = $stmt->fetch();
                    
                    if ($existing) {
                        $skipped++;
                        continue;
                    }
                    
                    // Film in Datenbank einfügen
                    $stmt = $pdo->prepare('
                        INSERT INTO movies 
                        (const, your_rating, date_rated, title, original_title, url, title_type, 
                         imdb_rating, runtime_mins, year, genres, num_votes, release_date, directors)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    
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
                    
                    $imported++;
                    
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

// ****************************************************************************
// Tabelle erstellen wenn nicht vorhanden
// ****************************************************************************

try {
    $pdo = getConnection();
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS movies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            const VARCHAR(20) UNIQUE NOT NULL,
            your_rating INT,
            date_rated DATE,
            title VARCHAR(255) NOT NULL,
            original_title VARCHAR(255),
            url VARCHAR(255),
            title_type VARCHAR(50),
            imdb_rating FLOAT,
            runtime_mins INT,
            year INT,
            genres TEXT,
            num_votes INT,
            release_date DATE,
            directors TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_const (const),
            INDEX idx_title (title),
            INDEX idx_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
} catch (Exception $e) {
    // Tabelle existiert bereits oder anderer Fehler
}

?>

<style>
    #import-module .message {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
        border-left: 4px solid #4caf50;
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    #import-module .message.error {
        border-left-color: #f44336;
        background-color: #ffebee;
        color: #c62828;
    }
    
    #import-module .drop-zone {
        border: 2px dashed #667eea;
        border-radius: 8px;
        padding: 40px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background-color: #f8f9ff;
        margin-bottom: 20px;
    }
    
    #import-module .drop-zone:hover {
        border-color: #764ba2;
        background-color: #f0f2ff;
    }
    
    #import-module .drop-zone.drag-over {
        border-color: #4caf50;
        background-color: #e8f5e9;
        transform: scale(1.02);
    }
    
    #import-module .drop-zone-icon {
        font-size: 48px;
        margin-bottom: 15px;
    }
    
    #import-module .drop-zone-text {
        font-size: 16px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 500;
    }
    
    #import-module .drop-zone-subtext {
        font-size: 13px;
        color: #999;
    }
    
    #import-module .file-input {
        display: none;
    }
    
    #import-module .divider {
        text-align: center;
        margin: 25px 0;
        color: #999;
        font-size: 13px;
    }
    
    #import-module .divider::before,
    #import-module .divider::after {
        content: '';
        display: inline-block;
        width: 30%;
        height: 1px;
        background: #ddd;
        vertical-align: middle;
    }
    
    #import-module .divider::before {
        margin-right: 10px;
    }
    
    #import-module .divider::after {
        margin-left: 10px;
    }
    
    #import-module .button-group {
        display: flex;
        gap: 10px;
    }
    
    #import-module .import-btn {
        flex: 1;
        padding: 12px 24px;
        font-size: 14px;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    #import-module .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    #import-module .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }
    
    #import-module .btn-secondary {
        background: #f5f5f5;
        color: #333;
    }
    
    #import-module .btn-secondary:hover {
        background: #e9e9e9;
    }
    
    #import-module .file-name {
        margin-top: 15px;
        padding: 10px;
        background: #f5f5f5;
        border-radius: 6px;
        font-size: 13px;
        color: #666;
        display: none;
    }
    
    #import-module .file-name.show {
        display: block;
    }
    
    #import-module .info-box {
        background: #f0f7ff;
        border-left: 4px solid #2196f3;
        padding: 15px;
        border-radius: 6px;
        font-size: 13px;
        color: #1565c0;
        margin-top: 20px;
    }
</style>

<div id="import-module">
    <div class="row">
        <div class="col-md-8">
            <h2>🎬 IMDb CSV Import</h2>
            <p class="text-muted">Importiere deine IMDb-Bewertungen in die Datenbank</p>
            
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
                    • CSV-Format von IMDb-Bewertungen<br>
                    • Doppelte Einträge (nach Const) werden übersprungen<br>
                    • Erforderlich: Const und Title
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
        
        if (!dropZone) return; // Sicherheit: nur wenn Element existiert
        
        // Drag & Drop Events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('drag-over');
            });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('drag-over');
            });
        });
        
        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                csvFile.files = files;
                handleFileSelect();
            }
        });
        
        // Click auf Drop Zone
        dropZone.addEventListener('click', () => {
            csvFile.click();
        });
        
        // File Input Change
        csvFile.addEventListener('change', handleFileSelect);
        
        function handleFileSelect() {
            if (csvFile.files.length > 0) {
                const file = csvFile.files[0];
                fileName.textContent = '✓ Datei ausgewählt: ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                fileName.classList.add('show');
                submitBtn.disabled = false;
            } else {
                fileName.classList.remove('show');
                submitBtn.disabled = true;
            }
        }
        
        // Form Submit
        importForm.addEventListener('submit', (e) => {
            if (csvFile.files.length === 0) {
                e.preventDefault();
                alert('Bitte wählen Sie eine CSV-Datei aus.');
            }
        });
    });
</script>
