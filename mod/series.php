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
$genreFilter = '';
if (!empty($_GET['genre'])) {
    $genreFilter = (int)$_GET['genre'];
}

// View-Modus (table oder gallery)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'table';
if (!in_array($viewMode, ['table', 'gallery'])) {
    $viewMode = 'table';
}

// Reset filter if requested
if (isset($_GET['filter_reset'])) {
    $filterComplete = 0;
    $filterPartial = 0;
    $filterNew = 0;
    $genreFilter = 0;
}

$pdo = getConnection();

// Get all distinct genres from genres table (for series only)
$genreStmt = $pdo->query('
    SELECT DISTINCT g.id, g.name 
    FROM genres g
    INNER JOIN movies_genres mg ON mg.genre_id = g.id
    INNER JOIN movies m ON m.id = mg.movie_id
    WHERE m.title_type IN ("Fernsehserie", "Miniserie")
    ORDER BY g.name
');
$allGenres = [];
while ($row = $genreStmt->fetch(PDO::FETCH_ASSOC)) {
    $allGenres[$row['id']] = $row['name'];
}

// Build filter condition based on toggles
$filterConditions = [];
if ($filterComplete || $filterPartial || $filterNew) {
    // Build filter based on episode counts
    if ($filterComplete) {
        // Vollständig: alle Episoden sind als Filme vorhanden
        $filterConditions[] = '(e.episode_count IS NOT NULL AND e.episode_count > 0 AND em.episode_movies_count IS NOT NULL AND e.episode_count = em.episode_movies_count)';
    }
    if ($filterPartial) {
        // Aktuell: es gibt Episoden, aber nicht alle sind als Filme vorhanden
        $filterConditions[] = '(e.episode_count IS NOT NULL AND e.episode_count > 0 AND em.episode_movies_count IS NOT NULL AND em.episode_movies_count > 0 AND em.episode_movies_count < e.episode_count)';
    }
    if ($filterNew) {
        // Neu: Es gibt Episoden, aber KEINE dieser Episoden ist als Film in movies vorhanden
        // Anzeige-Fall z.B.: episodeMoviesCount = 0 und episodeCount > 0 (wie 0/4)
        $filterConditions[] = '((e.episode_count IS NOT NULL AND e.episode_count > 0) AND (em.episode_movies_count IS NULL OR em.episode_movies_count = 0))';
    }
}

// Build WHERE clause with base filter (title_type only)
$whereClause = 'm.title_type IN ("Fernsehserie", "Miniserie")';
if ($q !== '') {
    $whereClause .= ' AND m.title LIKE ?';
}
if ($genreFilter !== '') {
    $whereClause .= ' AND EXISTS (SELECT 1 FROM movies_genres mg2 WHERE mg2.movie_id = m.id AND mg2.genre_id = ?)';
}

// Build additional WHERE conditions for episode filters
$filterWhereClause = '';
if (!empty($filterConditions)) {
    $filterWhereClause = ' AND (' . implode(' OR ', $filterConditions) . ')';
}

// Gesamtanzahl ermitteln (nur Serien und Miniserien)
$countSql = '
    SELECT COUNT(DISTINCT m.id) FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_count, COALESCE(SUM(mov.runtime_mins), 0) AS episode_runtime_sum
        FROM episodes ep
        LEFT JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count, COALESCE(SUM(mov.runtime_mins), 0) AS episode_movies_runtime_sum
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE ' . $whereClause . $filterWhereClause;

if ($q !== '') {
    $stmtCount = $pdo->prepare($countSql);
    if ($genreFilter !== '') {
        $stmtCount->execute(["%$q%", $genreFilter]);
    } else {
        $stmtCount->execute(["%$q%"]);
    }
} else {
    $stmtCount = $pdo->prepare($countSql);
    if ($genreFilter !== '') {
        $stmtCount->execute([$genreFilter]);
    } else {
        $stmtCount->execute();
    }
}
$total = (int)$stmtCount->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

// Summen über alle gefilterten Serien (unabhängig von der aktuellen Seite)
$sumSql = '
    SELECT 
        COALESCE(SUM(e.episode_count), 0) AS sum_episode_count,
        COALESCE(SUM(em.episode_movies_count), 0) AS sum_episode_movies_count,
        COALESCE(SUM(e.episode_runtime_sum), 0) AS sum_episode_runtime,
        COALESCE(SUM(em.episode_movies_runtime_sum), 0) AS sum_episode_movies_runtime,
        COALESCE(SUM(GREATEST(e.episode_count - COALESCE(em.episode_movies_count, 0), 0) * COALESCE(m.runtime_mins, 0)), 0) AS sum_open_runtime
    FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_count, COALESCE(SUM(mov.runtime_mins), 0) AS episode_runtime_sum
        FROM episodes ep
        LEFT JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count, COALESCE(SUM(mov.runtime_mins), 0) AS episode_movies_runtime_sum
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE ' . $whereClause . $filterWhereClause;

if ($q !== '') {
    $stmtSum = $pdo->prepare($sumSql);
    if ($genreFilter !== '') {
        $stmtSum->execute(["%$q%", $genreFilter]);
    } else {
        $stmtSum->execute(["%$q%"]);
    }
} else {
    $stmtSum = $pdo->prepare($sumSql);
    if ($genreFilter !== '') {
        $stmtSum->execute([$genreFilter]);
    } else {
        $stmtSum->execute();
    }
}
$sumRow = $stmtSum->fetch(PDO::FETCH_ASSOC);
$totalEpisodeCount = (int)($sumRow['sum_episode_count'] ?? 0);
$totalMovieCount = (int)($sumRow['sum_episode_movies_count'] ?? 0);
$totalEpisodeRuntime = (int)($sumRow['sum_episode_runtime'] ?? 0);
$totalMovieRuntime = (int)($sumRow['sum_episode_movies_runtime'] ?? 0);
$openRuntime = (int)($sumRow['sum_open_runtime'] ?? 0);

// Daten laden (nur Serien und Miniserien) - mit Staffel- und Episodenzählung
// $sqlBase variable removed - queries now inline below

if ($q !== '') {
    $stmt = $pdo->prepare('SELECT m.*, 
           COALESCE(s.season_count, 0) AS season_count, 
           COALESCE(e.episode_count, 0) AS episode_count,
           COALESCE(em.episode_movies_count, 0) AS episode_movies_count
    FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT parent_tconst, COUNT(*) AS episode_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE m.title_type IN ("Fernsehserie", "Miniserie") AND m.title LIKE ?' . ((int)$genreFilter !== 0 ? ' AND EXISTS (SELECT 1 FROM movies_genres mg2 WHERE mg2.movie_id = m.id AND mg2.genre_id = ?)' : '') . $filterWhereClause . ' ORDER BY m.title ASC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, "%$q%");
    $paramIdx = 2;
    if ((int)$genreFilter !== 0) {
        $stmt->bindValue($paramIdx++, (int)$genreFilter, PDO::PARAM_INT);
    }
    $stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIdx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt = $pdo->prepare('SELECT m.*, 
           COALESCE(s.season_count, 0) AS season_count, 
           COALESCE(e.episode_count, 0) AS episode_count,
           COALESCE(em.episode_movies_count, 0) AS episode_movies_count
    FROM movies m
    LEFT JOIN (
        SELECT parent_tconst, COUNT(DISTINCT season_number) AS season_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) s ON s.parent_tconst = m.const
    LEFT JOIN (
        SELECT parent_tconst, COUNT(*) AS episode_count
        FROM episodes
        WHERE visible = 1
        GROUP BY parent_tconst
    ) e ON e.parent_tconst = m.const
    LEFT JOIN (
        SELECT ep.parent_tconst, COUNT(*) AS episode_movies_count
        FROM episodes ep
        INNER JOIN movies mov ON mov.const = ep.tconst
        WHERE ep.visible = 1
        GROUP BY ep.parent_tconst
    ) em ON em.parent_tconst = m.const
    WHERE m.title_type IN ("Fernsehserie", "Miniserie")' . ((int)$genreFilter !== 0 ? ' AND EXISTS (SELECT 1 FROM movies_genres mg2 WHERE mg2.movie_id = m.id AND mg2.genre_id = ?)' : '') . $filterWhereClause . ' ORDER BY m.title ASC LIMIT ? OFFSET ?');
    $paramIdx = 1;
    if ((int)$genreFilter !== 0) {
        $stmt->bindValue($paramIdx++, (int)$genreFilter, PDO::PARAM_INT);
    }
    $stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIdx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$series = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatMinutesToHours($minutes) {
    $minutes = max(0, (int)$minutes);
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return number_format($hours, 0, ',', '.') . ':' . str_pad((string)$mins, 2, '0', STR_PAD_LEFT);
}

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Serien</h2>
            <div class="btn-group" role="group">
                <a href="?mod=series&view=table<?php echo ($q !== '' ? '&q=' . urlencode($q) : '') . ((int)$genreFilter !== 0 ? '&genre=' . (int)$genreFilter : '') . ($filterComplete ? '&filter_complete=1' : '') . ($filterPartial ? '&filter_partial=1' : '') . ($filterNew ? '&filter_new=1' : ''); ?>" class="btn btn-sm <?php echo $viewMode === 'table' ? 'btn-primary' : 'btn-outline-primary'; ?>" title="Tabellenansicht">
                    <i class="bi bi-list"></i> Tabelle
                </a>
                <a href="?mod=series&view=gallery<?php echo ($q !== '' ? '&q=' . urlencode($q) : '') . ((int)$genreFilter !== 0 ? '&genre=' . (int)$genreFilter : '') . ($filterComplete ? '&filter_complete=1' : '') . ($filterPartial ? '&filter_partial=1' : '') . ($filterNew ? '&filter_new=1' : ''); ?>" class="btn btn-sm <?php echo $viewMode === 'gallery' ? 'btn-primary' : 'btn-outline-primary'; ?>" title="Galerieansicht">
                    <i class="bi bi-grid"></i> Galerie
                </a>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="mb-3">
            <form method="get" class="d-flex gap-2 justify-content-between align-items-center">
                <input type="hidden" name="mod" value="series">
                <input type="hidden" name="view" value="<?php echo h($viewMode); ?>">
                <!-- Hidden inputs to preserve filter state when using search/genre -->
                <input type="hidden" name="filter_complete" value="<?php echo $filterComplete; ?>" id="hidden_filter_complete">
                <input type="hidden" name="filter_partial" value="<?php echo $filterPartial; ?>" id="hidden_filter_partial">
                <input type="hidden" name="filter_new" value="<?php echo $filterNew; ?>" id="hidden_filter_new">
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm <?php echo $filterComplete ? 'btn-primary' : 'btn-outline-primary'; ?>" 
                            onclick="document.getElementById('hidden_filter_complete').value = <?php echo $filterComplete ? '0' : '1'; ?>; 
                                     document.getElementById('hidden_filter_partial').value = '<?php echo $filterPartial; ?>';
                                     document.getElementById('hidden_filter_new').value = '<?php echo $filterNew; ?>';">
                        Vollständig
                    </button>
                    <button type="submit" class="btn btn-sm <?php echo $filterPartial ? 'btn-primary' : 'btn-outline-primary'; ?>" 
                            onclick="document.getElementById('hidden_filter_complete').value = '<?php echo $filterComplete; ?>';
                                     document.getElementById('hidden_filter_partial').value = <?php echo $filterPartial ? '0' : '1'; ?>;
                                     document.getElementById('hidden_filter_new').value = '<?php echo $filterNew; ?>';">
                        Aktuell
                    </button>
                    <button type="submit" class="btn btn-sm <?php echo $filterNew ? 'btn-primary' : 'btn-outline-primary'; ?>" 
                            onclick="document.getElementById('hidden_filter_complete').value = '<?php echo $filterComplete; ?>';
                                     document.getElementById('hidden_filter_partial').value = '<?php echo $filterPartial; ?>';
                                     document.getElementById('hidden_filter_new').value = <?php echo $filterNew ? '0' : '1'; ?>;">
                        Neu
                    </button>
                    <?php if ($filterComplete || $filterPartial || $filterNew || $genreFilter !== ''): ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" name="filter_reset" value="1">Reset</button>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" name="genre" style="width: auto;">
                        <option value="">Alle Genres</option>
                        <?php foreach ($allGenres as $genreId => $label): ?>
                            <option value="<?php echo (int)$genreId; ?>" <?php echo (int)$genreFilter === (int)$genreId ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Titel" value="<?php echo h($q); ?>" style="width: auto;">
                    <button type="submit" class="btn btn-sm btn-primary">Suchen</button>
                </div>
            </form>
        </div>

        <!-- Info Card -->
        <div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h6 class="card-title mb-1">Serien</h6>
                        <p class="card-text" style="font-size: 1.2em; font-weight: bold;"><?php echo $total; ?></p>
                    </div>
                    <div class="col-md-2">
                        <h6 class="card-title mb-1">Alle Episoden</h6>
                        <p class="card-text" style="font-size: 1.2em; font-weight: bold;"><?php echo h(number_format($totalEpisodeCount, 0, ',', '.')); ?> (<?php echo h(formatMinutesToHours($totalEpisodeRuntime)); ?> h)</p>
                    </div>
                    <div class="col-md-2">
                        <h6 class="card-title mb-1">Importierte</h6>
                        <p class="card-text" style="font-size: 1.2em; font-weight: bold;"><?php echo h(number_format($totalMovieCount, 0, ',', '.')); ?> (<?php echo h(formatMinutesToHours($totalMovieRuntime)); ?> h)</p>
                    </div>
                    <div class="col-md-2">
                        <h6 class="card-title mb-1">Offene</h6>
                        <p class="card-text" style="font-size: 1.2em; font-weight: bold;"><?php echo h(number_format($totalEpisodeCount-$totalMovieCount, 0, ',', '.')); ?> (<?php echo h(formatMinutesToHours($openRuntime)); ?> h)</p>
                    </div>
                    <div class="col-md-2">
                        <h6 class="card-title mb-1">Fortschritt</h6>
                        <p class="card-text" style="font-size: 1.2em; font-weight: bold;">
                            <?php 
                            if ($totalEpisodeCount > 0) {
                                $percentage = round(($totalMovieCount / $totalEpisodeCount) * 100);
                                echo h($percentage) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($viewMode === 'table'): ?>
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
        <?php else: ?>
            <!-- Galerieansicht -->
            <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3">
                <?php if (empty($series)): ?>
                    <div class="col-12 text-center text-muted mt-5">
                        <p>Keine Serien gefunden.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($series as $s): ?>
                        <?php 
                            $coverFile = __DIR__ . '/../cover/' . $s['const'] . '.jpg';
                            $hasCover = (!empty($s['poster_url']) || file_exists($coverFile));
                            $episodeCount = isset($s['episode_count']) ? (int)$s['episode_count'] : 0;
                            $episodeMoviesCount = isset($s['episode_movies_count']) ? (int)$s['episode_movies_count'] : 0;
                        ?>
                        <div class="col">
                            <div class="card h-100 position-relative" style="overflow: hidden;">
                                <?php if ($hasCover): ?>
                                    <img src="<?php echo file_exists($coverFile) ? './cover/' . h($s['const']) . '.jpg' : h($s['poster_url']); ?>" class="card-img-top" alt="<?php echo h($s['title']); ?>" style="height: 300px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="card-img-top d-flex align-items-center justify-content-center" style="height: 300px; background: var(--card-bg); border-bottom: 1px solid var(--table-border);">
                                        <span class="text-muted text-center" style="font-size: 0.9em; padding: 1rem;">Kein Cover</span>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body d-flex flex-column">
                                    <h6 class="card-title" style="font-size: 0.9em; line-height: 1.2; margin-bottom: 0.5rem;">
                                        <a href="?mod=serie&amp;const=<?php echo urlencode($s['const']); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo h(strlen($s['title']) > 50 ? substr($s['title'], 0, 47) . '...' : $s['title']); ?>
                                        </a>
                                    </h6>
                                    <div class="small mb-2" style="color: var(--text-color);">
                                        <span><?php echo h($s['year']); ?></span>
                                        <?php if (!empty($s['genres'])): ?>
                                            <br><small><?php echo h($s['genres']); ?></small>
                                        <?php endif; ?>
                                        <?php 
                                            $hasRating = array_key_exists('imdb_rating', $s) && $s['imdb_rating'] !== null;
                                            $hasVotes = array_key_exists('num_votes', $s) && $s['num_votes'] !== null && $s['num_votes'] !== '';
                                        ?>
                                        <?php if ($hasRating): ?>
                                            <br><i class="bi bi-star-fill" style="color: gold;"></i> <?php echo h($s['imdb_rating']); ?>
                                            <?php if ($hasVotes): ?>
                                                <small>(<?php echo number_format((int)$s['num_votes'], 0, ',', '.'); ?>)</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($s['your_rating'] !== null): ?>
                                            <br><i class="bi bi-star" style="color: orange;"></i> <?php echo h($s['your_rating']); ?>
                                        <?php endif; ?>
                                        <?php if ($episodeCount > 0): ?>
                                            <br><small>Episoden: <?php echo h($episodeMoviesCount . '/' . $episodeCount); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-auto">
                                        <a class="btn btn-sm btn-outline-secondary w-100 mb-2" href="?mod=serie&amp;const=<?php echo urlencode($s['const']); ?>">Episoden</a>
                                        <?php if (!empty($s['url'])): ?>
                                            <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo h($s['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Pagination mit Ellipsis -->
        <nav aria-label="Seiten">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '?mod=series&view=' . $viewMode;
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                if ((int)$genreFilter !== 0) $baseUrl .= '&genre=' . (int)$genreFilter;
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
