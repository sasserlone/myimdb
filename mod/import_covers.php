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

// Hilfsfunktion: Cover + Metadaten herunterladen und speichern
// $hasLocalFile: wenn bereits ein lokales Cover existiert, wird das Bild nicht erneut geladen
function downloadCover($tconst, $title, $pdo, $coverDir, $hasLocalFile = false, $existingPosterUrl = null) {
    global $omdb_apikey;
    
    // Dateiname basierend auf tconst
    $filename = $coverDir . '/' . $tconst . '.jpg';

    // OMDb API immer abfragen, um Metadaten zu holen
    $url = 'http://www.omdbapi.com/?apikey=' . urlencode($omdb_apikey) . '&i=' . urlencode($tconst);
    $response = @file_get_contents($url);
    if (!$response) {
        return array(false, 'OMDb API nicht erreichbar');
    }

    $data = json_decode($response, true);
    if (!is_array($data) || (isset($data['Response']) && $data['Response'] === 'False')) {
        $msg = isset($data['Error']) ? $data['Error'] : 'OMDb Antwort ungültig';
        return array(false, $msg);
    }

    // Felder holen und N/A filtern
    $posterUrl = null;
    if (!empty($existingPosterUrl)) {
        $posterUrl = $existingPosterUrl;
    } elseif (isset($data['Poster']) && $data['Poster'] !== 'N/A') {
        $posterUrl = $data['Poster'];
    }

    $plot = (isset($data['Plot']) && $data['Plot'] !== 'N/A') ? $data['Plot'] : null;
    $language = (isset($data['Language']) && $data['Language'] !== 'N/A') ? $data['Language'] : null;
    $country = (isset($data['Country']) && $data['Country'] !== 'N/A') ? $data['Country'] : null;
    $metascore = null;
    if (isset($data['Metascore']) && is_numeric($data['Metascore'])) {
        $metascore = (int)$data['Metascore'];
    }
    $metacriticScore = null;
    $rottenTomatoes = null;
    if (!empty($data['Ratings']) && is_array($data['Ratings'])) {
        foreach ($data['Ratings'] as $rating) {
            if (!isset($rating['Source'], $rating['Value'])) continue;
            if ($rating['Source'] === 'Metacritic') {
                // Format z.B. "74/100"
                $parts = explode('/', $rating['Value']);
                if (isset($parts[0]) && is_numeric($parts[0])) {
                    $metacriticScore = (int)$parts[0];
                }
            }
            if ($rating['Source'] === 'Rotten Tomatoes') {
                // Format z.B. "94%"
                $val = rtrim($rating['Value'], '%');
                if (is_numeric($val)) {
                    $rottenTomatoes = (int)$val;
                }
            }
        }
    }

    // Bild herunterladen, falls keine lokale Datei vorhanden und Poster-URL existiert
    if (!$hasLocalFile) {
        if (empty($posterUrl)) {
            return array(false, 'Kein Poster verfügbar');
        }
        $imageData = @file_get_contents($posterUrl);
        if (!$imageData) {
            return array(false, 'Bild konnte nicht heruntergeladen werden');
        }
        if (!@file_put_contents($filename, $imageData)) {
            return array(false, 'Datei konnte nicht gespeichert werden');
        }
    }

    // URL + Metadaten in DB speichern (nur fehlende Werte überschreiben)
    $stmt = $pdo->prepare('UPDATE movies SET 
        poster_url = COALESCE(NULLIF(poster_url, ""), :poster_url),
        plot = COALESCE(:plot, plot),
        language = COALESCE(:language, language),
        country = COALESCE(:country, country),
        metascore = COALESCE(:metascore, metascore),
        metacritic_score = COALESCE(:metacritic_score, metacritic_score),
        rotten_tomatoes = COALESCE(:rotten_tomatoes, rotten_tomatoes)
        WHERE const = :const');
    $stmt->execute([
        ':poster_url' => $posterUrl,
        ':plot' => $plot,
        ':language' => $language,
        ':country' => $country,
        ':metascore' => $metascore,
        ':metacritic_score' => $metacriticScore,
        ':rotten_tomatoes' => $rottenTomatoes,
        ':const' => $tconst
    ]);

    return array(true, 'OK');
}

// Verarbeite Batch-Import bei POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_import') {
    // Modus: refresh (bereits verarbeitete aktualisieren) oder initial (noch nicht verarbeitete)
    $mode = isset($_GET['mode']) ? trim($_GET['mode']) : (isset($_POST['mode']) ? trim($_POST['mode']) : 'refresh');
    if (!in_array($mode, ['refresh', 'initial'])) { $mode = 'refresh'; }
    // Optionales Limit für Batch-Lauf
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 1000;
    // Auswahl basierend auf Modus
    if ($mode === 'refresh') {
        // Bereits verarbeitete aktualisieren, älteste zuerst
        $stmt = $pdo->query('SELECT id, const, title, poster_url, plot, language, country, metascore, metacritic_score, rotten_tomatoes, omdb_fetched_at 
                             FROM movies 
                             WHERE title_type != "Fernsehepisode" AND omdb_fetched_at IS NOT NULL
                             ORDER BY omdb_fetched_at ASC, year DESC, title ASC');
    } else {
        // Noch nicht verarbeitete erstmalig laden
        $stmt = $pdo->query('SELECT id, const, title, poster_url, plot, language, country, metascore, metacritic_score, rotten_tomatoes, omdb_fetched_at 
                             FROM movies 
                             WHERE title_type != "Fernsehepisode" AND omdb_fetched_at IS NULL
                             ORDER BY year DESC, title ASC');
    }
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;
    $errors = [];

    // Filter: Alle, außer bereits als Dummy markierte
    $pending = [];
    foreach ($movies as $movie) {
        if ($movie['poster_url'] === './cover/dummy.jpg') {
            $skipCount++;
            continue;
        }
        $pending[] = $movie;
    }
    // Batch-Limit anwenden
    if (count($pending) > $limit) {
        $pending = array_slice($pending, 0, $limit);
    }

    $totalCount = count($pending);

    // Nichts zu tun
    if ($totalCount === 0) {
        echo '<div class="alert alert-success">Keine Filme ohne lokale Datei gefunden.</div>';
        echo '<a href="?mod=import_covers" class="btn btn-primary">Zurück</a>';
        exit;
    }
    
    echo '<div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">';
    echo '<div class="card-body">';
    echo '<h5 class="card-title">Batch-Import läuft (' . ($mode === 'refresh' ? 'Refresh' : 'Initial') . ')...</h5>';
    echo '<div class="progress mb-3">';
    echo '<div class="progress-bar" id="progressBar" role="progressbar" style="width: 0%"></div>';
    echo '</div>';
    echo '<div id="status" style="color: var(--text-color);"></div>';
    echo '</div>';
    echo '</div>';
    
    // Flush output
    if (function_exists('ob_flush')) {
        ob_flush();
        flush();
    }
    
    foreach ($pending as $index => $movie) {
        $percent = (int)(($index + 1) / $totalCount * 100);
        
        $existingPoster = !empty($movie['poster_url']) ? $movie['poster_url'] : null;
        $coverFile = $coverDir . '/' . $movie['const'] . '.jpg';
        $hasLocalFile = file_exists($coverFile);
        list($success, $msg) = downloadCover($movie['const'], $movie['title'], $pdo, $coverDir, $hasLocalFile, $existingPoster);
        if ($success) {
            $successCount++;
            // Verarbeitung markiert (damit nicht erneut geladen wird)
            $stmtMark = $pdo->prepare('UPDATE movies SET omdb_fetched_at = NOW() WHERE const = ?');
            $stmtMark->execute([$movie['const']]);
        } else {
            // Wenn kein Poster verfügbar -> als übersprungen werten, nicht als Fehler
            if ($msg === 'Kein Poster verfügbar') {
                $skipCount++;
                // Trotzdem Metadaten als verarbeitet markieren, um unnötige weitere OMDb-Requests zu vermeiden
                $stmtMark = $pdo->prepare('UPDATE movies SET omdb_fetched_at = NOW() WHERE const = ?');
                $stmtMark->execute([$movie['const']]);
            } else {
                $errorCount++;
                $errors[] = "{$movie['title']}: $msg";
                // Dummy-Cover markieren, damit dieser Film nicht erneut versucht wird
                $stmtDummy = $pdo->prepare('UPDATE movies SET poster_url = "./cover/dummy.jpg" WHERE const = ?');
                $stmtDummy->execute([$movie['const']]);
                // Fehlerhafte Verarbeitung NICHT als fetched markieren, damit später erneut versucht werden kann
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

// Zähle tatsächlich zu importierende Filme (ohne lokale Dateien)
// Modus-Auswahl für Anzeige
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'refresh';
if (!in_array($mode, ['refresh', 'initial'])) { $mode = 'refresh'; }

// Zu verarbeitende Filme gem. Modus (Dummy ausnehmen)
if ($mode === 'refresh') {
    $stmtCount = $pdo->query('SELECT COUNT(*) FROM movies WHERE title_type != "Fernsehepisode" AND omdb_fetched_at IS NOT NULL AND (poster_url IS NULL OR poster_url != "./cover/dummy.jpg")');
} else {
    $stmtCount = $pdo->query('SELECT COUNT(*) FROM movies WHERE title_type != "Fernsehepisode" AND omdb_fetched_at IS NULL AND (poster_url IS NULL OR poster_url != "./cover/dummy.jpg")');
}
$noCoverCount = (int)$stmtCount->fetchColumn();

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
                    <strong>Zu verarbeitende Filme (<?php echo h($mode === 'refresh' ? 'Refresh' : 'Initial'); ?>):</strong> <?php echo $noCoverCount; ?> / <?php echo $totalCount; ?>
                </p>
                <p class="card-text">
                    <small style="color: var(--text-color);">Cover werden im Verzeichnis <code>./cover/</code> gespeichert, mit dem tconst als Dateiname (z.B. <code>tt0111161.jpg</code>)</small>
                </p>
                <div class="btn-group" role="group">
                    <a href="?mod=import_covers&mode=refresh" class="btn btn-sm <?php echo $mode === 'refresh' ? 'btn-primary' : 'btn-outline-primary'; ?>">Refresh</a>
                    <a href="?mod=import_covers&mode=initial" class="btn btn-sm <?php echo $mode === 'initial' ? 'btn-primary' : 'btn-outline-primary'; ?>">Initial</a>
                </div>
            </div>
        </div>

        <?php if ($noCoverCount > 0): ?>
            <form method="post">
                <input type="hidden" name="action" value="batch_import">
                <input type="hidden" name="mode" value="<?php echo h($mode); ?>">
                <button type="submit" class="btn btn-lg btn-success" onclick="return confirm('Alle <?php echo $noCoverCount; ?> Cover herunterladen? Dies kann einige Minuten dauern (1 Sekunde Verzögerung pro Film).');">
                    <i class="bi bi-download"></i> Alle <?php echo $noCoverCount; ?> Cover jetzt importieren
                </button>
                <small class="d-block mt-2" style="color: var(--text-color);">
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

