<?php
require_once __DIR__ . '/../inc/database.inc.php';

$pdo = getConnection();

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_series_id'])) {
    $seriesId = (int)$_POST['delete_series_id'];
    try {
        $stmt = $pdo->prepare('DELETE FROM movies WHERE id = ?');
        $stmt->execute([$seriesId]);
        // Redirect to avoid form resubmission
        header('Location: ?mod=series' . (isset($_GET['page']) ? '&page=' . (int)$_GET['page'] : ''));
        exit;
    } catch (Exception $e) {
        $deleteError = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Pagination-Parameter
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage <= 0) $perPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page <= 0) $page = 1;
$offset = ($page - 1) * $perPage;

$q = '';
// Optional: suche per Titel
if (!empty($_GET['q'])) {
    $q = trim($_GET['q']);
}

// Filter-Parameter
$filterComplete = isset($_GET['filter_complete']) ? (int)$_GET['filter_complete'] : 0;
$filterPartial = isset($_GET['filter_partial']) ? (int)$_GET['filter_partial'] : 0;
$filterNew = isset($_GET['filter_new']) ? (int)$_GET['filter_new'] : 0;

// Reset filter if requested
if (isset($_GET['filter_reset'])) {
    $filterComplete = 0;
    $filterPartial = 0;
    $filterNew = 0;
}

$pdo = getConnection();

// Build filter condition based on toggles
$filterConditions = [];
if ($filterComplete || $filterPartial || $filterNew) {
    // Build filter based on episode counts
    if ($filterComplete) {
        $filterConditions[] = '(COALESCE(e.episode_count, 0) = COALESCE(em.episode_movies_count, 0) AND COALESCE(e.episode_count, 0) > 0)';
    }
    if ($filterPartial) {
        $filterConditions[] = '(COALESCE(e.episode_count, 0) > 0 AND COALESCE(e.episode_count, 0) > COALESCE(em.episode_movies_count, 0))';
    }
    if ($filterNew) {
        $filterConditions[] = '(COALESCE(e.episode_count, 0) = 0)';
    }
}

// Build WHERE clause with filters
$whereClause = 'm.title_type IN ("Fernsehserie", "Miniserie")';
if (!empty($filterConditions)) {
    $whereClause .= ' AND (' . implode(' OR ', $filterConditions) . ')';
}

// Gesamtanzahl ermitteln (nur Serien und Miniserien)
$countSql = '
    SELECT COUNT(DISTINCT m.id) FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE parent_tconst IS NOT NULL
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT parent_tconst, COUNT(*) AS episode_count
        FROM episodes
        WHERE parent_tconst IS NOT NULL
        GROUP BY parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.parent_tconst IS NOT NULL
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE ' . $whereClause;

if ($q !== '') {
    $countSql .= ' AND m.title LIKE ?';
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute(["%$q%"]);
} else {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute();
}
$total = (int)$stmtCount->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

// Daten laden (nur Serien und Miniserien) - mit Staffel- und Episodenzählung
$sqlBase = '
    SELECT m.*, 
           COALESCE(s.season_count, 0) AS season_count, 
           COALESCE(e.episode_count, 0) AS episode_count,
           COALESCE(em.episode_movies_count, 0) AS episode_movies_count
    FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE parent_tconst IS NOT NULL
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT parent_tconst, COUNT(*) AS episode_count
        FROM episodes
        WHERE parent_tconst IS NOT NULL
        GROUP BY parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.parent_tconst IS NOT NULL
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE ' . $whereClause;

if ($q !== '') {
    $stmt = $pdo->prepare($sqlBase . ' AND m.title LIKE ? ORDER BY m.title ASC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, "%$q%");
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt = $pdo->prepare($sqlBase . ' ORDER BY m.title ASC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$series = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Serien</h2>
            <form class="d-flex" method="get" style="gap:.5rem">
                <input type="hidden" name="mod" value="series">
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Titel" value="<?php echo h($q); ?>">
                <button class="btn btn-sm btn-primary ms-2">Suchen</button>
            </form>
        </div>

        <!-- Filter Buttons -->
        <div class="mb-3">
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="mod" value="series">
                <input type="hidden" name="q" value="<?php echo h($q); ?>">
                <button type="submit" class="btn btn-sm <?php echo $filterComplete ? 'btn-primary' : 'btn-outline-primary'; ?>" name="filter_complete" value="<?php echo $filterComplete ? '0' : '1'; ?>">
                    Vollständig
                </button>
                <button type="submit" class="btn btn-sm <?php echo $filterPartial ? 'btn-primary' : 'btn-outline-primary'; ?>" name="filter_partial" value="<?php echo $filterPartial ? '0' : '1'; ?>">
                    Aktuell
                </button>
                <button type="submit" class="btn btn-sm <?php echo $filterNew ? 'btn-primary' : 'btn-outline-primary'; ?>" name="filter_new" value="<?php echo $filterNew ? '0' : '1'; ?>">
                    Neu
                </button>
                <?php if ($filterComplete || $filterPartial || $filterNew): ?>
                    <button type="submit" class="btn btn-sm btn-outline-secondary" name="filter_reset" value="1">Reset</button>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Const</th>
                        <th>Titel</th>
                        <th>Jahr</th>
                        <th class="text-end">IMDb</th>
                        <th class="text-end">Votes</th>
                        <th class="text-end">MyRate</th>
                        <th class="text-end">Laufzeit</th>
                        <th>Genres</th>
                        <th class="text-end">Staffeln</th>
                        <th class="text-end">Episoden</th>
                        <th>Type</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($series)): ?>
                        <tr><td colspan="13" class="text-center">Keine Serien gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($series as $i => $s): ?>
                            <tr>
                                <td><?php echo h($offset + $i + 1); ?></td>
                                <td><?php echo h($s['const']); ?></td>
                                <td><?php echo h($s['title']); ?></td>
                                <td><?php echo h($s['year']); ?></td>
                                <td class="text-end numeric"><?php echo $s['imdb_rating'] !== null ? h($s['imdb_rating']) : ''; ?></td>
                                <td class="text-end numeric"><?php echo ($s['num_votes'] !== null && $s['num_votes'] !== '') ? h(number_format((int)$s['num_votes'], 0, ',', '.')) : ''; ?></td>
                                <td class="text-end numeric"><?php echo $s['your_rating'] !== null ? h($s['your_rating']) : ''; ?></td>
                                <td class="text-end numeric"><?php echo $s['runtime_mins'] !== null ? h($s['runtime_mins']) . ' min' : ''; ?></td>
                                <td style="max-width:220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($s['genres']); ?></td>
                                <td class="text-end numeric"><?php echo isset($s['season_count']) ? h($s['season_count']) : '0'; ?></td>
                                <td class="text-end numeric"><?php 
                                    $episodeCount = isset($s['episode_count']) ? (int)$s['episode_count'] : 0;
                                    $episodeMoviesCount = isset($s['episode_movies_count']) ? (int)$s['episode_movies_count'] : 0;
                                    echo h($episodeMoviesCount . '/' . $episodeCount);
                                ?></td>
                                <td style="max-width:180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($s['title_type']); ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo '?mod=serie&const=' . urlencode($s['const']); ?>">Episoden</a>
                                    <?php if (!empty($s['url'])): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h($s['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="delete_series_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Wirklich löschen? Die Serie und alle verknüpften Daten werden gelöscht.');">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination mit Ellipsis -->
        <nav aria-label="Seiten">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '?mod=series';
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                if ($filterComplete) $baseUrl .= '&filter_complete=1';
                if ($filterPartial) $baseUrl .= '&filter_partial=1';
                if ($filterNew) $baseUrl .= '&filter_new=1';
                
                // Previous-Link
                if ($page > 1):
                    ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=1&per_page=' . $perPage; ?>">&laquo;</a></li><?php
                endif;
                
                // Bestimme welche Seiten angezeigt werden
                $delta = 2; // Seiten um aktuelle herum
                $start = max(1, $page - $delta);
                $end = min($pages, $page + $delta);
                
                // Erste Seite(n)
                if ($start > 1):
                    ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=1&per_page=' . $perPage; ?>">1</a></li><?php
                    if ($start > 2):
                        ?><li class="page-item disabled"><span class="page-link">...</span></li><?php
                    endif;
                endif;
                
                // Seiten im Bereich
                for ($p = $start; $p <= $end; $p++):
                    $active = $p === $page ? ' active' : '';
                    ?>
                    <li class="page-item<?php echo $active; ?>">
                        <a class="page-link" href="<?php echo $baseUrl . '&page=' . $p . '&per_page=' . $perPage; ?>"><?php echo $p; ?></a>
                    </li>
                    <?php
                endfor;
                
                // Letzte Seite(n)
                if ($end < $pages):
                    if ($end < $pages - 1):
                        ?><li class="page-item disabled"><span class="page-link">...</span></li><?php
                    endif;
                    ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=' . $pages . '&per_page=' . $perPage; ?>"><?php echo $pages; ?></a></li><?php
                endif;
                
                // Next-Link
                if ($page < $pages):
                    ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=' . ($page + 1) . '&per_page=' . $perPage; ?>">&raquo;</a></li><?php
                endif;
                ?>
            </ul>
        </nav>

    </div>
</div>
