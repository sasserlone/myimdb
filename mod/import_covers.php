<?php
require_once __DIR__ . '/../inc/database.inc.php';
require_once __DIR__ . '/../inc/global.inc.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Cover-Verzeichnis erstellen, falls nicht vorhanden
$coverDir = __DIR__ . '/../cover';
if (!is_dir($coverDir)) {
    mkdir($coverDir, 0755, true);
}

$pdo = getConnection();

// Hilfsfunktion: Cover herunterladen und speichern
// Optional: vorhandene Poster-URL wiederverwenden, ansonsten OMDb abfragen
function downloadCover($tconst, $title, $pdo, $coverDir, $existingPosterUrl = null) {
    global $omdb_apikey;
    
    // Dateiname basierend auf tconst
    $filename = $coverDir . '/' . $tconst . '.jpg';
    
    // Falls schon eine Poster-URL existiert, direkt verwenden, sonst OMDb anfragen
    if (!empty($existingPosterUrl)) {
        $posterUrl = $existingPosterUrl;
    } else {
        $url = 'http://www.omdbapi.com/?apikey=' . urlencode($omdb_apikey) . '&i=' . urlencode($tconst);
        
        $response = @file_get_contents($url);
        if (!$response) {
            return array(false, 'OMDb API nicht erreichbar');
        }
        
        $data = json_decode($response, true);
        if (!isset($data['Poster']) || $data['Poster'] === 'N/A') {
            return array(false, 'Kein Poster verfügbar');
        }
        
        $posterUrl = $data['Poster'];
    }
    
    // Bild herunterladen
    $imageData = @file_get_contents($posterUrl);
    if (!$imageData) {
        return array(false, 'Bild konnte nicht heruntergeladen werden');
    }
    
    // Datei speichern
    if (!@file_put_contents($filename, $imageData)) {
        return array(false, 'Datei konnte nicht gespeichert werden');
    }
    
    // URL in DB speichern, falls noch nicht gesetzt
    $stmt = $pdo->prepare('UPDATE movies SET poster_url = COALESCE(NULLIF(poster_url, ""), ?) WHERE const = ?');
    $stmt->execute([$posterUrl, $tconst]);
    
    return array(true, 'OK');
}

// Verarbeite Batch-Import bei POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_import') {
    // Hole alle Nicht-Episoden (wir entscheiden später, was wirklich fehlt)
    $stmt = $pdo->query('SELECT id, const, title, poster_url FROM movies WHERE title_type != "Fernsehepisode" AND (POSTER_URL IS NULL OR POSTER_URL = "") ORDER BY year DESC, title ASC');
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;
    $errors = [];

    // Vorab: Filme mit bestehender Datei herausfiltern (werden als übersprungen gezählt)
    $pending = [];
    foreach ($movies as $movie) {
        $coverFile = $coverDir . '/' . $movie['const'] . '.jpg';
        if (file_exists($coverFile)) {
            $skipCount++;
            continue;
        }
        $pending[] = $movie;
    }

    $totalCount = count($pending);

    // Nichts zu tun
    if ($totalCount === 0) {
        echo '<div class="alert alert-success">Keine Filme ohne Cover gefunden.</div>';
        echo '<a href="?mod=import_covers" class="btn btn-primary">Zurück</a>';
        exit;
    }
    
    echo '<div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">';
    echo '<div class="card-body">';
    echo '<h5 class="card-title">Batch-Import läuft...</h5>';
    echo '<div class="progress mb-3">';
    echo '<div class="progress-bar" id="progressBar" role="progressbar" style="width: 0%"></div>';
    echo '</div>';
    echo '<div id="status" class="text-muted"></div>';
    echo '</div>';
    echo '</div>';
    
    // Flush output
    if (function_exists('ob_flush')) {
        ob_flush();
        flush();
    }
    
    foreach ($pending as $index => $movie) {
        $percent = (int)(($index + 1) / $totalCount * 100);
        
        // Prüfe ob bereits geladen (DB oder Datei)
        $coverFile = $coverDir . '/' . $movie['const'] . '.jpg';
        if (file_exists($coverFile)) {
            $skipCount++;
        } else {
            $existingPoster = !empty($movie['poster_url']) ? $movie['poster_url'] : null;
            list($success, $msg) = downloadCover($movie['const'], $movie['title'], $pdo, $coverDir, $existingPoster);
            if ($success) {
                $successCount++;
            } else {
                // Wenn kein Poster verfügbar -> als übersprungen werten, nicht als Fehler
                if ($msg === 'Kein Poster verfügbar') {
                    $skipCount++;
                } else {
                    $errorCount++;
                    $errors[] = "{$movie['title']}: $msg";
                }
            }
        }
        
        // Status aktualisieren
        echo '<script>
            document.getElementById("progressBar").style.width = "' . $percent . '%";
            document.getElementById("status").innerHTML = "' . ($index + 1) . ' / ' . $totalCount . ' verarbeitet (' . $successCount . ' erfolgreich, ' . $skipCount . ' übersprungen, ' . $errorCount . ' Fehler)";
        </script>';
        
        if (function_exists('ob_flush')) {
            ob_flush();
            flush();
        }
        
        // Rate-Limiting: 1 Sekunde zwischen Requests
        sleep(1);
    }
    
    // Abschluss-Nachricht
    echo '<div class="alert alert-info mt-3">';
    echo 'Batch-Import abgeschlossen!<br>';
    echo 'Erfolgreich: <strong>' . $successCount . '</strong><br>';
    echo 'Übersprungen: <strong>' . $skipCount . '</strong><br>';
    echo 'Fehler: <strong>' . $errorCount . '</strong>';
    if (!empty($errors)) {
        echo '<br><br><strong>Fehler-Details:</strong><br>';
        echo implode('<br>', array_slice($errors, 0, 10));
        if (count($errors) > 10) {
            echo '<br>... und ' . (count($errors) - 10) . ' weitere Fehler';
        }
    }
    echo '</div>';
    
    echo '<a href="?mod=import_covers" class="btn btn-primary">Zurück zum Cover-Import</a>';
    
    exit;
}

// Zähle Filme ohne Cover (ohne Episoden)
$stmtNoCover = $pdo->query('SELECT COUNT(*) FROM movies WHERE (poster_url IS NULL OR poster_url = "") AND title_type != "Fernsehepisode"');
$noCoverCount = (int)$stmtNoCover->fetchColumn();

$stmtTotal = $pdo->query('SELECT COUNT(*) FROM movies WHERE title_type != "Fernsehepisode"');
$totalCount = (int)$stmtTotal->fetchColumn();

?>

<div class="row">
    <div class="col-12">
        <h2>Cover importieren</h2>

        <div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <h5 class="card-title">Status</h5>
                <p class="card-text">
                    <strong>Filme ohne Cover:</strong> <?php echo $noCoverCount; ?> / <?php echo $totalCount; ?>
                </p>
                <p class="card-text">
                    <small class="text-muted">Cover werden im Verzeichnis <code>./cover/</code> gespeichert, mit dem tconst als Dateiname (z.B. <code>tt0111161.jpg</code>)</small>
                </p>
            </div>
        </div>

        <?php if ($noCoverCount > 0): ?>
            <form method="post">
                <input type="hidden" name="action" value="batch_import">
                <button type="submit" class="btn btn-lg btn-success" onclick="return confirm('Alle <?php echo $noCoverCount; ?> Cover herunterladen? Dies kann einige Minuten dauern (1 Sekunde Verzögerung pro Film).');">
                    <i class="bi bi-download"></i> Alle <?php echo $noCoverCount; ?> Cover jetzt importieren
                </button>
                <small class="d-block mt-2 text-muted">
                    Hinweis: Der Import lädt etwa <?php echo $noCoverCount; ?> Bilder herunter. Mit 1 Sekunde Verzögerung pro Film dauert dies ca. <?php echo ceil($noCoverCount / 60); ?> Minute(n).
                </small>
            </form>
        <?php else: ?>
            <div class="alert alert-success">
                Alle <?php echo $totalCount; ?> Filme haben bereits Cover!
            </div>
        <?php endif; ?>

    </div>
</div>

