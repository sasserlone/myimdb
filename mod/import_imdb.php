<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';
$pipelineOutput = '';

// Logs-Verzeichnis sicherstellen und vorhandene Logs einsammeln
$logsDir = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'logs';
if ($logsDir === false) {
    $logsDir = __DIR__ . '/../logs';
}
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
// $logFiles wird jetzt rein per JS geladen, nicht mehr per PHP beim Seitenladen
$logFiles = [];

// ****************************************************************************
// IMDb Datasets Download und Entpacken
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_imdb_datasets'])) {
    @set_time_limit(0);
    @ignore_user_abort(true);
    
    $downloadDir = __DIR__ . '/../downloads';
    if (!is_dir($downloadDir)) {
        @mkdir($downloadDir, 0777, true);
    }
    
    $datasets = [
        'title.episode' => 'https://datasets.imdbws.com/title.episode.tsv.gz',
        'title.principals' => 'https://datasets.imdbws.com/title.principals.tsv.gz',
        'name.basics' => 'https://datasets.imdbws.com/name.basics.tsv.gz'
    ];
    
    $downloadedFiles = [];
    $downloadErrors = [];
    
    foreach ($datasets as $name => $url) {
        $gzFile = $downloadDir . DIRECTORY_SEPARATOR . $name . '.tsv.gz';
        $tsvFile = $downloadDir . DIRECTORY_SEPARATOR . $name . '.tsv';
        
        // Download
        $message .= "Lade $name herunter...<br>";
        $ch = curl_init($url);
        $fp = fopen($gzFile, 'w');
        
        if ($fp === false) {
            $downloadErrors[] = "Konnte Datei nicht erstellen: $gzFile";
            continue;
        }
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // 1 Stunde Timeout
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        fclose($fp);
        curl_close($ch);
        
        if ($result === false || $httpCode !== 200) {
            $downloadErrors[] = "Download fehlgeschlagen für $name (HTTP $httpCode)";
            @unlink($gzFile);
            continue;
        }
        
        $message .= "✓ $name heruntergeladen (" . round(filesize($gzFile) / 1024 / 1024, 2) . " MB)<br>";
        
        // Entpacken
        $message .= "Entpacke $name...<br>";
        
        $gzHandle = gzopen($gzFile, 'rb');
        $outHandle = fopen($tsvFile, 'wb');
        
        if ($gzHandle === false || $outHandle === false) {
            $downloadErrors[] = "Konnte $name nicht entpacken";
            if ($gzHandle) gzclose($gzHandle);
            if ($outHandle) fclose($outHandle);
            continue;
        }
        
        while (!gzeof($gzHandle)) {
            $buffer = gzread($gzHandle, 4096);
            fwrite($outHandle, $buffer);
        }
        
        gzclose($gzHandle);
        fclose($outHandle);
        
        @unlink($gzFile); // Komprimierte Datei löschen
        
        $message .= "✓ $name entpackt (" . round(filesize($tsvFile) / 1024 / 1024, 2) . " MB)<br>";
        $downloadedFiles[$name] = $tsvFile;
    }
    
    if (!empty($downloadErrors)) {
        $error = implode('<br>', $downloadErrors);
    } else {
        $message .= "<br><strong>✓ Alle Dateien erfolgreich heruntergeladen und entpackt!</strong><br>";
        $message .= "Die Dateien befinden sich in: <code>" . htmlspecialchars($downloadDir) . "</code><br>";
        $message .= "Du kannst nun die Pipeline mit diesem Ordner starten.";
    }
}

// ****************************************************************************
// IMDb TSV Pipeline (Episodes -> Principals -> Names)
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_imdb_pipeline'])) {
    // Lange Laufzeit zulassen
    @set_time_limit(0);
    @ignore_user_abort(true);

    $episodePath = trim($_POST['episode_path'] ?? '');
    $principalsPath = trim($_POST['principals_path'] ?? '');
    $namesPath = trim($_POST['names_path'] ?? '');

    // Optional: hochgeladene Dateien entgegennehmen (nur fuer kleinere Dateien sinnvoll)
    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    $handleUpload = function($fieldName) use ($uploadDir) {
        if (!isset($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) return '';
        if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return '';
        $name = basename($_FILES[$fieldName]['name']);
        $target = $uploadDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . $name;
        if (@move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
            return realpath($target) ?: $target;
        }
        return '';
    };

    // Falls ein Upload vorhanden ist, diesen Pfad bevorzugen
    $epUpload = $handleUpload('episode_file');
    if ($epUpload !== '') $episodePath = $epUpload;
    $prUpload = $handleUpload('principals_file');
    if ($prUpload !== '') $principalsPath = $prUpload;
    $nmUpload = $handleUpload('names_file');
    if ($nmUpload !== '') $namesPath = $nmUpload;

    // Basis-Ordner-Variante: ein Eingabefeld fuer alle drei Dateien
    $baseDir = trim($_POST['base_dir'] ?? '');
    if ($baseDir !== '') {
        $baseDir = rtrim($baseDir, "\\/");
        $findInDir = function(string $dir, array $candidates): string {
            foreach ($candidates as $name) {
                $p = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_readable($p)) return $p;
            }
            return '';
        };
        // Suche typische Dateinamen (tsv bevorzugt, csv als Fallback)
        $episodePathCandidate = $findInDir($baseDir, ['title.episode.tsv', 'title.episode.csv']);
        $principalsPathCandidate = $findInDir($baseDir, ['title.principals.tsv', 'title.principals.csv']);
        $namesPathCandidate = $findInDir($baseDir, ['name.basics.tsv', 'name.basics.csv']);
        if ($episodePathCandidate !== '') $episodePath = $episodePathCandidate;
        if ($principalsPathCandidate !== '') $principalsPath = $principalsPathCandidate;
        if ($namesPathCandidate !== '') $namesPath = $namesPathCandidate;
    }

    // PHP-Binary: explizit auf XAMPP php.exe setzen
    $phpBin = 'C:\\xampp\\php\\php.exe';
    if (!is_file($phpBin)) {
        $error = 'PHP binary nicht gefunden: ' . $phpBin;
        return; // Abbruch
    }

    $scriptsDir = realpath(__DIR__ . '/../scripts');
    $episodesScript = $scriptsDir . DIRECTORY_SEPARATOR . 'import_episodes.php';
    $principalsScript = $scriptsDir . DIRECTORY_SEPARATOR . 'import_principals.php';
    $namesScript = $scriptsDir . DIRECTORY_SEPARATOR . 'import_names.php';
    $pipelineScript = $scriptsDir . DIRECTORY_SEPARATOR . 'pipeline_runner.php';

    $errs = [];
    if ($episodePath === '' || !is_readable($episodePath)) $errs[] = 'Episoden-Datei nicht lesbar: ' . htmlspecialchars($episodePath);
    if ($principalsPath === '' || !is_readable($principalsPath)) $errs[] = 'Principals-Datei nicht lesbar: ' . htmlspecialchars($principalsPath);
    if ($namesPath === '' || !is_readable($namesPath)) $errs[] = 'Names-Datei nicht lesbar: ' . htmlspecialchars($namesPath);
    if (!is_file($episodesScript) || !is_file($principalsScript) || !is_file($namesScript)) $errs[] = 'Importskripte nicht gefunden.';

    if (!empty($errs)) {
        $error = implode("\n", $errs);
    } else {
        // Pipeline mit Logdatei
        $logsDir = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
        $logFile = $logsDir . DIRECTORY_SEPARATOR . 'imdb_pipeline_' . date('Ymd_His') . '.log';
        // Logdatei vorab erstellen
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Pipeline wird gestartet...\n", FILE_APPEND);

        // Erstelle ein temporäres Batch-Skript, das die Pipeline startet
        // (robuster als direktes proc_open unter Windows mit komplexen Pfaden)
        $batchFile = $logsDir . DIRECTORY_SEPARATOR . 'run_pipeline_' . uniqid() . '.bat';
        $batchContent = '@echo off' . PHP_EOL
                      . 'setlocal enabledelayedexpansion' . PHP_EOL
                      . '"' . $phpBin . '" "' . $pipelineScript . '" --episodes="' . $episodePath . '" --principals="' . $principalsPath . '" --names="' . $namesPath . '" --log="' . $logFile . '"' . PHP_EOL;
        
        if (file_put_contents($batchFile, $batchContent) === false) {
            $error = 'Konnte Batch-Datei nicht erstellen.';
            @file_put_contents($logFile, "[FEHLER] Batch-Datei konnte nicht erstellt werden\n", FILE_APPEND);
        } else {
            // Starte das Batch-Skript direkt
            $descriptorspec = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            
            $proc = @proc_open($batchFile, $descriptorspec, $pipes);
            if (is_resource($proc)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($proc);
                
                $allOutput = trim($stdout . $stderr);
                if ($allOutput !== '') {
                    @file_put_contents($logFile, $allOutput . "\n", FILE_APPEND);
                }
                
                if ($exitCode === 0) {
                    $message = '✓ IMDb-Import-Pipeline erfolgreich abgeschlossen. Log: ' . htmlspecialchars($logFile);
                } else {
                    @file_put_contents($logFile, "[FEHLER] Exitcode: $exitCode\n", FILE_APPEND);
                    $error = 'Import-Pipeline Fehler (Exitcode ' . $exitCode . '). Siehe Log: ' . htmlspecialchars($logFile);
                }
                
                // Batch-Datei aufräumen
                @unlink($batchFile);
            } else {
                $error = 'Konnte Batch-Skript nicht starten.';
                @file_put_contents($logFile, "[FEHLER] Batch-Skript konnte nicht gestartet werden\n", FILE_APPEND);
                @unlink($batchFile);
            }
        }
        $pipelineOutput = "Log-Datei: $logFile\nLade die Seite neu und waehle das Log zum Ansehen.";
    }
}

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

    // genres-Tabelle
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS genres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_genre_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    // Join-Tabelle movies_genres
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS movies_genres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movie_id INT NOT NULL,
            genre_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_movie_genre (movie_id, genre_id),
            INDEX idx_movie_id (movie_id),
            INDEX idx_genre_id (genre_id),
            CONSTRAINT fk_mg_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
            CONSTRAINT fk_mg_genre FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    // Schauspieler/Principal-Zuordnungen
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS movie_principals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movie_id INT NOT NULL,
            ordering INT DEFAULT NULL,
            nconst VARCHAR(20) NOT NULL,
            category VARCHAR(50) DEFAULT NULL,
            job VARCHAR(255) DEFAULT NULL,
            characters TEXT DEFAULT NULL,
            UNIQUE KEY ux_movie_nconst_order (movie_id, nconst, ordering),
            INDEX idx_mp_movie (movie_id),
            INDEX idx_mp_nconst (nconst),
            INDEX idx_mp_category (category),
            CONSTRAINT fk_mp_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
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
        <div class="col-md-4">
            <h2>⚙️ IMDb TSV Pipeline</h2>
            <p class="text-muted">Episoden → Principals → Namen nacheinander importieren</p>

            <!-- Download Button -->
            <form method="POST" class="mb-3">
                <button type="submit" name="download_imdb_datasets" class="btn btn-success btn-sm w-100" 
                        onclick="return confirm('Downloads können mehrere Minuten dauern. Fortfahren?')">
                    ⬇️ IMDb Datasets automatisch herunterladen
                </button>
                <small class="text-muted d-block mt-1">Lädt automatisch von datasets.imdbws.com herunter und entpackt in den downloads/ Ordner</small>
            </form>

            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">IMDb TSV Ordner</label>
                    <input type="text" class="form-control form-control-sm" name="base_dir" id="base_dir" 
                           placeholder="C:\\Pfad\\zum\\Ordner" 
                           value="<?php echo isset($_POST['base_dir']) ? h($_POST['base_dir']) : realpath(__DIR__ . '/../downloads');?>">
                    <small class="text-muted">Erwartet: <code>title.episode.tsv</code>, <code>title.principals.tsv</code> und <code>name.basics.tsv</code> in diesem Ordner. CSV wird als Fallback auch erkannt.</small>
                </div>
                <details class="mb-2">
                    <summary class="text-muted" style="cursor:pointer">Optional: einzelne Dateien hochladen (nur für kleinere Dateien)</summary>
                    <div class="mt-2 d-flex flex-column gap-2">
                        <input type="file" class="form-control form-control-sm" name="episode_file" id="episode_file" accept=".tsv,.csv">
                        <input type="file" class="form-control form-control-sm" name="principals_file" id="principals_file" accept=".tsv,.csv">
                        <input type="file" class="form-control form-control-sm" name="names_file" id="names_file" accept=".tsv,.csv">
                    </div>
                </details>
                <button type="submit" class="btn btn-sm btn-primary" name="run_imdb_pipeline" value="1">▶ Import starten</button>
            </form>

            <?php if (!empty($pipelineOutput)): ?>
                <div class="mt-3">
                    <h6>Import-Ausgabe</h6>
                    <pre style="max-height: 300px; overflow:auto; background:#111; color:#0f0; padding:10px; border-radius:6px;"><?php echo h($pipelineOutput); ?></pre>
                </div>
            <?php endif; ?>

            <hr>
            <h5>📜 Log ansehen (live)</h5>
            <div class="mb-2 d-flex gap-2 align-items-center">
                <select id="logSelect" class="form-select form-select-sm" style="width:auto; max-width:100%">
                    <option value="">(lade Logs...)</option>
                </select>
                <button type="button" id="btnLiveStart" class="btn btn-sm btn-outline-primary">Live ansehen</button>
                <button type="button" id="btnLiveStop" class="btn btn-sm btn-outline-secondary" disabled>Stop</button>
            </div>
            <pre id="logViewer" style="max-height: 320px; overflow:auto; background:#111; color:#0f0; padding:10px; border-radius:6px; white-space: pre-wrap;"></pre>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.getElementById('dropZone');
        // Sync file pickers with text inputs (show file name for UX only)
        const sync = (fileInputId, textInputId) => {
            const fi = document.getElementById(fileInputId);
            const ti = document.getElementById(textInputId);
            if (!fi || !ti) return;
            fi.addEventListener('change', () => {
                if (fi.files && fi.files.length) {
                    // Zeige nur Dateinamen, Pfad bleibt serverseitig
                    ti.value = fi.files[0].name;
                }
            });
        };
        sync('episode_file','episode_path');
        sync('principals_file','principals_path');
        sync('names_file','names_path');

        // Live Log Viewer
        const logSelect = document.getElementById('logSelect');
        const btnLiveStart = document.getElementById('btnLiveStart');
        const btnLiveStop = document.getElementById('btnLiveStop');
        const logViewer = document.getElementById('logViewer');
        let liveTimer = null;
        let offset = 0;

        // Funktion zum Refresh des Log-Dropdowns
        function refreshLogList() {
            fetch('scripts/list_logs.php')
                .then(r => r.ok ? r.json() : Promise.reject())
                .then(logs => {
                    const currentValue = logSelect.value;
                    logSelect.innerHTML = '';
                    if (logs.length === 0) {
                        logSelect.innerHTML = '<option value="">(keine Logs)</option>';
                    } else {
                        logs.forEach(log => {
                            const opt = document.createElement('option');
                            opt.value = log;
                            opt.textContent = log;
                            logSelect.appendChild(opt);
                        });
                        // Auto-select neuestes wenn gerade live läuft
                        if (liveTimer) {
                            logSelect.value = logs[0];
                        }
                    }
                })
                .catch(e => console.error('Log-Refresh fehlgeschlagen:', e));
        }

        // Initial laden beim Page-Load
        refreshLogList();
        
        // Alle 2 Sekunden Auto-Refresh wenn Live läuft
        setInterval(() => {
            if (liveTimer) refreshLogList();
        }, 2000);

        function atBottom(el) {
            return (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 10);
        }

        function fetchLogChunk() {
            if (!logSelect || !logViewer) return;
            const file = logSelect.value;
            if (!file) return;
            const nearBottom = atBottom(logViewer);
            fetch('scripts/log_tail.php?file=' + encodeURIComponent(file) + '&offset=' + offset)
                .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
                .then(data => {
                    if (typeof data.size === 'number') {
                        if (data.start !== undefined && data.start < offset) {
                            // Datei wurde rotiert/gekürzt -> neu aufsetzen
                            logViewer.textContent = '';
                        }
                        offset = data.size;
                    }
                    if (data.chunk) {
                        logViewer.textContent += data.chunk;
                        if (nearBottom) {
                            logViewer.scrollTop = logViewer.scrollHeight;
                        }
                    }
                })
                .catch(() => {});
        }

        function startLive() {
            if (!logSelect || !logSelect.value) {
                // Vor dem Start Logs neu laden
                refreshLogList();
                setTimeout(() => {
                    if (logSelect.value) {
                        _doStartLive();
                    }
                }, 500);
            } else {
                _doStartLive();
            }
        }

        function _doStartLive() {
            offset = 0;
            if (logViewer) logViewer.textContent = '';
            if (btnLiveStart) btnLiveStart.disabled = true;
            if (btnLiveStop) btnLiveStop.disabled = false;
            fetchLogChunk();
            liveTimer = setInterval(fetchLogChunk, 2000);
        }

        function stopLive() {
            if (btnLiveStart) btnLiveStart.disabled = false;
            if (btnLiveStop) btnLiveStop.disabled = true;
            if (liveTimer) clearInterval(liveTimer);
            liveTimer = null;
        }

        if (btnLiveStart && btnLiveStop) {
            btnLiveStart.addEventListener('click', startLive);
            btnLiveStop.addEventListener('click', stopLive);
        }
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
