<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';
$pdo = getConnection();

// ****************************************************************************
// year_2 für Serien/Miniserien aktualisieren
// ****************************************************************************

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_year2'])) {
    try {
        set_time_limit(300); // 5 Minuten
        
        $pdo->beginTransaction();
        
        // Alle Serien und Miniserien laden
        $stmtSeries = $pdo->query("
            SELECT id, `const`, title, year, year_2, title_type 
            FROM movies 
            WHERE title_type IN ('Fernsehserie', 'Miniserie')
        ");
        $allSeries = $stmtSeries->fetchAll(PDO::FETCH_ASSOC);
        
        $message = "🔄 Aktualisiere year_2 für " . count($allSeries) . " Serien/Miniserien...<br><br>";
        
        $stmtUpdate = $pdo->prepare('UPDATE movies SET year_2 = ? WHERE id = ?');
        $stmtLastEpisode = $pdo->prepare("
            SELECT m.year 
            FROM episodes e
            INNER JOIN movies m ON m.const = e.tconst
            WHERE e.parent_tconst = ?
            AND e.visible = 1
            ORDER BY 
                COALESCE(e.season_number, 999) DESC, 
                COALESCE(e.episode_number, 999) DESC
            LIMIT 1
        ");
        
        $updatedCount = 0;
        $unchangedCount = 0;
        $noEpisodesCount = 0;
        
        foreach ($allSeries as $series) {
            $seriesConst = $series['const'];
            $seriesId = $series['id'];
            $currentYear2 = $series['year_2'];
            
            // Letzte Episode ermitteln
            $stmtLastEpisode->execute([$seriesConst]);
            $lastEpisodeYear = $stmtLastEpisode->fetchColumn();
            
            if ($lastEpisodeYear !== false && $lastEpisodeYear !== null) {
                // year_2 aktualisieren, wenn es sich geändert hat
                if ($currentYear2 != $lastEpisodeYear) {
                    $stmtUpdate->execute([$lastEpisodeYear, $seriesId]);
                    $updatedCount++;
                } else {
                    $unchangedCount++;
                }
            } else {
                // Keine Episoden gefunden
                $noEpisodesCount++;
            }
        }
        
        $pdo->commit();
        
        $message .= "✓ Aktualisierung abgeschlossen:<br>";
        $message .= "• Aktualisiert: $updatedCount<br>";
        $message .= "• Unverändert: $unchangedCount<br>";
        $message .= "• Keine Episoden: $noEpisodesCount<br>";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Fehler bei der Aktualisierung: ' . $e->getMessage();
    }
}

// ****************************************************************************
// Statistiken laden
// ****************************************************************************

$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM movies WHERE title_type IN ('Fernsehserie', 'Miniserie')) as total_series,
        (SELECT COUNT(*) FROM movies WHERE title_type IN ('Fernsehserie', 'Miniserie') AND year_2 IS NOT NULL) as series_with_year2,
        (SELECT COUNT(*) FROM movies WHERE title_type IN ('Fernsehserie', 'Miniserie') AND year_2 IS NULL) as series_without_year2
")->fetch(PDO::FETCH_ASSOC);

// Beispiele laden
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$whereClause = "WHERE title_type IN ('Fernsehserie', 'Miniserie')";
if ($filter === 'with_year2') {
    $whereClause .= " AND year_2 IS NOT NULL";
} elseif ($filter === 'without_year2') {
    $whereClause .= " AND year_2 IS NULL";
}

$totalStmt = $pdo->query("SELECT COUNT(*) FROM movies $whereClause");
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT m.id, m.const, m.title, m.year, m.year_2, m.title_type,
           (SELECT COUNT(*) FROM episodes WHERE parent_tconst = m.const AND visible = 1) as episode_count
    FROM movies m
    $whereClause
    ORDER BY m.title ASC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$series = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>📅 Serien year_2 aktualisieren</h2>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Statistiken -->
        <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4">
                        <h5 class="card-title mb-1">Serien/Miniserien gesamt</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo number_format($stats['total_series'], 0, ',', '.'); ?></p>
                    </div>
                    <div class="col-md-4">
                        <h5 class="card-title mb-1">Mit year_2</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold; color: #28a745;"><?php echo number_format($stats['series_with_year2'], 0, ',', '.'); ?></p>
                    </div>
                    <div class="col-md-4">
                        <h5 class="card-title mb-1">Ohne year_2</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold; color: #dc3545;"><?php echo number_format($stats['series_without_year2'], 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aktualisierungs-Button -->
        <form method="POST" class="mb-4">
            <div class="card" style="background: var(--card-bg); border-color: var(--table-border);">
                <div class="card-body">
                    <h5 class="card-title">🔄 year_2 aktualisieren</h5>
                    <p class="card-text">
                        Setzt für alle Serien und Miniserien die Spalte <code>year_2</code> auf das Jahr der letzten Episode.<br>
                        <small class="text-muted">Die letzte Episode wird anhand der höchsten Staffel- und Episodennummer ermittelt.</small>
                    </p>
                    <button type="submit" name="update_year2" class="btn btn-primary" onclick="return confirm('year_2 für alle Serien/Miniserien aktualisieren?')">
                        ▶️ Aktualisierung starten
                    </button>
                </div>
            </div>
        </form>

        <!-- Filter -->
        <div class="mb-3">
            <a href="?mod=data_year&filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">Alle</a>
            <a href="?mod=data_year&filter=with_year2" class="btn btn-sm <?php echo $filter === 'with_year2' ? 'btn-success' : 'btn-outline-success'; ?>">Mit year_2</a>
            <a href="?mod=data_year&filter=without_year2" class="btn btn-sm <?php echo $filter === 'without_year2' ? 'btn-danger' : 'btn-outline-danger'; ?>">Ohne year_2</a>
        </div>

        <!-- Serien-Liste -->
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Titel</th>
                        <th>Typ</th>
                        <th class="text-center">Jahr (Start)</th>
                        <th class="text-center">Jahr (Ende)</th>
                        <th class="text-center">Episoden</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($series)): ?>
                        <tr><td colspan="7" class="text-center">Keine Einträge gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($series as $i => $s): ?>
                            <tr>
                                <td><?php echo h($offset + $i + 1); ?></td>
                                <td>
                                    <a href="?mod=serie&const=<?php echo urlencode($s['const']); ?>"><?php echo h($s['title']); ?></a>
                                </td>
                                <td><?php echo h($s['title_type']); ?></td>
                                <td class="text-center"><?php echo $s['year'] !== null ? h($s['year']) : '—'; ?></td>
                                <td class="text-center">
                                    <?php if ($s['year_2'] !== null): ?>
                                        <span style="color: #28a745; font-weight: bold;"><?php echo h($s['year_2']); ?></span>
                                    <?php else: ?>
                                        <span style="opacity: 0.5;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo number_format($s['episode_count'], 0, ',', '.'); ?></td>
                                <td class="text-center">
                                    <?php if ($s['year_2'] !== null): ?>
                                        <span style="color: #28a745;" title="year_2 gesetzt">✓</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;" title="year_2 fehlt">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav aria-label="Seiten" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                    $baseUrl = '?mod=data_year&filter=' . urlencode($filter);
                    
                    for ($p = 1; $p <= $pages; $p++):
                        $active = $p === $page ? ' active' : '';
                        ?>
                        <li class="page-item<?php echo $active; ?>">
                            <a class="page-link" href="<?php echo $baseUrl . '&page=' . $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>
