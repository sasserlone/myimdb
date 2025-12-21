<?php
require_once __DIR__ . '/../inc/database.inc.php';

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

// Filter nach title_type
$titleTypeFilter = '';
if (!empty($_GET['title_type'])) {
    $titleTypeFilter = trim($_GET['title_type']);
}

// Genre-Filter
$genreFilter = '';
if (!empty($_GET['genre'])) {
    $genreFilter = (int)$_GET['genre'];
}

// Actor-Filter
$actorFilter = '';
if (!empty($_GET['actor'])) {
    $actorFilter = trim($_GET['actor']);
}

// Rating-Filter (all, rated, unrated)
$ratingFilter = '';
if (!empty($_GET['rating'])) {
    $ratingFilter = trim($_GET['rating']);
}
if (!in_array($ratingFilter, ['rated', 'unrated'])) {
    $ratingFilter = '';
}

// View-Modus (table oder gallery)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'table';
if (!in_array($viewMode, ['table', 'gallery'])) {
    $viewMode = 'table';
}

$pdo = getConnection();

// Get all distinct genres from genres table
$genreStmt = $pdo->query('
    SELECT DISTINCT g.id, g.name 
    FROM genres g
    INNER JOIN movies_genres mg ON mg.genre_id = g.id
    ORDER BY g.name
');
$allGenres = [];
while ($row = $genreStmt->fetch(PDO::FETCH_ASSOC)) {
    $allGenres[$row['id']] = $row['name'];
}

// Build WHERE clause
$whereParts = [];
$params = [];

if ($q !== '') {
    $whereParts[] = 'title LIKE ?';
    $params[] = "%$q%";
}

if ($titleTypeFilter !== '') {
    if ($titleTypeFilter === 'nur_filme') {
        // Nur Filme: alles außer Fernsehepisode, Miniserie, Fernsehserie
        $whereParts[] = 'title_type NOT IN ("Fernsehepisode", "Miniserie", "Fernsehserie")';
    } elseif ($titleTypeFilter === 'nur_serien') {
        // Nur Serien: Fernsehserie und Miniserie
        $whereParts[] = 'title_type IN ("Fernsehserie", "Miniserie")';
    } else {
        // Spezifischer title_type
        $whereParts[] = 'title_type = ?';
        $params[] = $titleTypeFilter;
    }
}

if ($genreFilter !== '') {
    $whereParts[] = 'EXISTS (SELECT 1 FROM movies_genres mg WHERE mg.movie_id = movies.id AND mg.genre_id = ?)';
    $params[] = (int)$genreFilter;
}

if ($actorFilter !== '') {
    // Suche nach Schauspieler-Name oder nconst
    $whereParts[] = 'EXISTS (SELECT 1 FROM movie_principals mp 
                              INNER JOIN actors a ON a.nconst = mp.nconst 
                              WHERE mp.movie_id = movies.id 
                              AND (a.primary_name LIKE ? OR a.nconst LIKE ?))';
    $params[] = "%$actorFilter%";
    $params[] = "%$actorFilter%";
}

if ($ratingFilter === 'rated') {
    $whereParts[] = 'your_rating IS NOT NULL AND your_rating > 0';
} elseif ($ratingFilter === 'unrated') {
    $whereParts[] = '(your_rating IS NULL OR your_rating = 0)';
}

$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// Gesamtanzahl ermitteln
$countSql = 'SELECT COUNT(*) FROM movies ' . $whereClause;
if (!empty($params)) {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
} else {
    $stmtCount = $pdo->query($countSql);
}
$total = (int)$stmtCount->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

// Summen über alle gefilterten Filme (unabhängig von der aktuellen Seite)
$sumSql = 'SELECT 
    COUNT(*) AS total_movies,
    SUM(CASE WHEN your_rating IS NOT NULL THEN 1 ELSE 0 END) AS rated_movies,
    COALESCE(SUM(CASE WHEN runtime_mins IS NOT NULL THEN runtime_mins ELSE 0 END), 0) AS total_runtime,
    COALESCE(SUM(CASE WHEN your_rating IS NOT NULL AND runtime_mins IS NOT NULL THEN runtime_mins ELSE 0 END), 0) AS rated_runtime
    FROM movies ' . $whereClause;
if (!empty($params)) {
    $stmtSum = $pdo->prepare($sumSql);
    $stmtSum->execute($params);
} else {
    $stmtSum = $pdo->query($sumSql);
}
$sumRow = $stmtSum->fetch(PDO::FETCH_ASSOC);
$totalMovies = (int)($sumRow['total_movies'] ?? 0);
$ratedMovies = (int)($sumRow['rated_movies'] ?? 0);
$totalRuntime = (int)($sumRow['total_runtime'] ?? 0);
$ratedRuntime = (int)($sumRow['rated_runtime'] ?? 0);

// Daten laden (inkl. parent_tconst für Episoden-Cover)
$selectSql = 'SELECT movies.*, episodes.parent_tconst 
              FROM movies 
              LEFT JOIN episodes ON episodes.tconst = movies.const 
              ' . $whereClause . ' ORDER BY title ASC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($selectSql);
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
}
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();

$movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Genres für jeden Film laden
foreach ($movies as &$movie) {
    $genreStmt = $pdo->prepare('
        SELECT g.name 
        FROM genres g
        INNER JOIN movies_genres mg ON mg.genre_id = g.id
        WHERE mg.movie_id = ?
        ORDER BY g.name
    ');
    $genreStmt->execute([$movie['id']]);
    $genres = $genreStmt->fetchAll(PDO::FETCH_COLUMN);
    $movie['genres_list'] = !empty($genres) ? implode(', ', $genres) : '';
}
unset($movie);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Filme</h2>
            <div class="btn-group" role="group">
                <a href="?mod=movies&view=table<?php echo ($q !== '' ? '&q=' . urlencode($q) : '') . ($titleTypeFilter !== '' ? '&title_type=' . urlencode($titleTypeFilter) : '') . ($genreFilter !== '' ? '&genre=' . (int)$genreFilter : '') . ($actorFilter !== '' ? '&actor=' . urlencode($actorFilter) : '') . ($ratingFilter !== '' ? '&rating=' . urlencode($ratingFilter) : ''); ?>" class="btn btn-sm <?php echo $viewMode === 'table' ? 'btn-primary' : 'btn-outline-primary'; ?>" title="Tabellenansicht">
                    <i class="bi bi-list"></i> Tabelle
                </a>
                <a href="?mod=movies&view=gallery<?php echo ($q !== '' ? '&q=' . urlencode($q) : '') . ($titleTypeFilter !== '' ? '&title_type=' . urlencode($titleTypeFilter) : '') . ($genreFilter !== '' ? '&genre=' . (int)$genreFilter : '') . ($actorFilter !== '' ? '&actor=' . urlencode($actorFilter) : '') . ($ratingFilter !== '' ? '&rating=' . urlencode($ratingFilter) : ''); ?>" class="btn btn-sm <?php echo $viewMode === 'gallery' ? 'btn-primary' : 'btn-outline-primary'; ?>" title="Galerieansicht">
                    <i class="bi bi-grid"></i> Galerie
                </a>
            </div>
        </div>

        <form class="d-flex mb-3" method="get" style="gap:.5rem">
            <input type="hidden" name="mod" value="movies">
            <input type="hidden" name="view" value="<?php echo h($viewMode); ?>">
            <select class="form-select form-select-sm" name="genre" style="width: auto;">
                <option value="">Alle Genres</option>
                <?php foreach ($allGenres as $genreId => $label): ?>
                    <option value="<?php echo (int)$genreId; ?>" <?php echo (int)$genreFilter === (int)$genreId ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="title_type" style="width: auto;">
                <option value="">Alle Typen</option>
                    <option value="nur_filme" <?php echo $titleTypeFilter === 'nur_filme' ? 'selected' : ''; ?>>Nur Filme</option>
                    <option value="nur_serien" <?php echo $titleTypeFilter === 'nur_serien' ? 'selected' : ''; ?>>Nur Serien</option>
                    <option value="" disabled>──────────</option>
                    <option value="Film" <?php echo $titleTypeFilter === 'Film' ? 'selected' : ''; ?>>Film</option>
                    <option value="Kurzfilm" <?php echo $titleTypeFilter === 'Kurzfilm' ? 'selected' : ''; ?>>Kurzfilm</option>
                    <option value="Video" <?php echo $titleTypeFilter === 'Video' ? 'selected' : ''; ?>>Video</option>
                    <option value="Videospiel" <?php echo $titleTypeFilter === 'Videospiel' ? 'selected' : ''; ?>>Videospiel</option>
                    <option value="Fernsehepisode" <?php echo $titleTypeFilter === 'Fernsehepisode' ? 'selected' : ''; ?>>Fernsehepisode</option>
                    <option value="Fernsehfilm" <?php echo $titleTypeFilter === 'Fernsehfilm' ? 'selected' : ''; ?>>Fernsehfilm</option>
                    <option value="Fernsehkurzfilm" <?php echo $titleTypeFilter === 'Fernsehkurzfilm' ? 'selected' : ''; ?>>Fernsehkurzfilm</option>
                    <option value="Fernsehserie" <?php echo $titleTypeFilter === 'Fernsehserie' ? 'selected' : ''; ?>>Fernsehserie</option>
                    <option value="Fernsehspecial" <?php echo $titleTypeFilter === 'Fernsehspecial' ? 'selected' : ''; ?>>Fernsehspecial</option>
                    <option value="Miniserie" <?php echo $titleTypeFilter === 'Miniserie' ? 'selected' : ''; ?>>Miniserie</option>
                </select>
                <select class="form-select form-select-sm" name="rating" style="width: auto;">
                    <option value="">Alle Ratings</option>
                    <option value="rated" <?php echo $ratingFilter === 'rated' ? 'selected' : ''; ?>>Bewertet</option>
                    <option value="unrated" <?php echo $ratingFilter === 'unrated' ? 'selected' : ''; ?>>Nicht bewertet</option>
                </select>
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Titel" value="<?php echo h($q); ?>">
                <input class="form-control form-control-sm" type="search" name="actor" placeholder="Suche Schauspieler" value="<?php echo h($actorFilter); ?>">
                <button class="btn btn-sm btn-primary ms-2">Suchen</button>
            </form>
        </div>

        <!-- Info Card -->
        <div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Alle Filme</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $totalMovies; ?></p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Bewertete Filme</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $ratedMovies; ?></p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Laufzeit gesamt</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo h(number_format($totalRuntime, 0, ',', '.')); ?> min</p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Laufzeit bewertet</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo h(number_format($ratedRuntime, 0, ',', '.')); ?> min</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($viewMode === 'table'): ?>
            <!-- Tabellenansicht -->
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
                            <th class="text-end">Meta</th>
                            <th class="text-end">Rotten</th>
                            <th class="text-end">MyRate</th>
                            <th class="text-end">Laufzeit</th>
                            <th>Genres</th>
                            <th>Type</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movies)): ?>
                            <tr><td colspan="13" class="text-center">Keine Filme gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($movies as $i => $m): ?>
                                <tr>
                                    <td><?php echo h($offset + $i + 1); ?></td>
                                    <td><?php echo h($m['const']); ?></td>
                                    <td><?php echo h($m['title']); ?></td>
                                    <td><?php echo h($m['year']); ?></td>
                                    <td class="text-end numeric"><?php echo $m['imdb_rating'] !== null ? h($m['imdb_rating']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo ($m['num_votes'] !== null && $m['num_votes'] !== '') ? h(number_format((int)$m['num_votes'], 0, ',', '.')) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo !empty($m['metascore']) ? h($m['metascore']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo !empty($m['rotten_tomatoes']) ? h($m['rotten_tomatoes']) . '%' : ''; ?></td>
                                    <td class="text-end numeric"><?php echo $m['your_rating'] !== null ? h($m['your_rating']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo $m['runtime_mins'] !== null ? h($m['runtime_mins']) . ' min' : ''; ?></td>
                                    <td style="max-width:220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['genres']); ?></td>
                                    <td style="max-width:180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['title_type']); ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary me-1" href="?mod=movie&amp;const=<?php echo urlencode($m['const']); ?>">Details</a>
                                        <?php if (!empty($m['url'])): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo h($m['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
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
                <?php if (empty($movies)): ?>
                    <div class="col-12 text-center text-muted mt-5">
                        <p>Keine Filme gefunden.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($movies as $m): ?>
                        <div class="col">
                            <div class="card h-100 position-relative" style="overflow: hidden;">
                                <?php 
                                // Bei Episoden das Cover der Serie anzeigen
                                $coverConst = !empty($m['parent_tconst']) ? $m['parent_tconst'] : $m['const'];
                                $coverFile = __DIR__ . '/../cover/' . $coverConst . '.jpg';
                                $hasCover = !empty($m['poster_url']) || file_exists($coverFile);
                                ?>
                                <?php if ($hasCover): ?>
                                    <img src="<?php 
                                        echo file_exists($coverFile) ? './cover/' . h($coverConst) . '.jpg' : h($m['poster_url']);
                                    ?>" class="card-img-top" alt="<?php echo h($m['title']); ?>" style="height: 300px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="card-img-top d-flex align-items-center justify-content-center" style="height: 300px; background: var(--card-bg); border-bottom: 1px solid var(--table-border);">
                                        <span class="text-muted text-center" style="font-size: 0.9em; padding: 1rem;">Kein Cover</span>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body d-flex flex-column">
                                    <h6 class="card-title" style="font-size: 0.9em; line-height: 1.2; margin-bottom: 0.5rem;">
                                        <a href="?mod=movie&amp;const=<?php echo urlencode($m['const']); ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo h(strlen($m['title']) > 50 ? substr($m['title'], 0, 47) . '...' : $m['title']); ?>
                                        </a>
                                    </h6>
                                    <div class="small mb-2" style="color: var(--text-color);">
                                        <span><?php echo h($m['year']); ?></span>
                                        <?php if (!empty($m['genres_list'])): ?>
                                            <br><small><?php echo h($m['genres_list']); ?></small>
                                        <?php endif; ?>
                                        <?php 
                                            $hasRating = array_key_exists('imdb_rating', $m) && $m['imdb_rating'] !== null;
                                            $hasVotes = array_key_exists('num_votes', $m) && $m['num_votes'] !== null && $m['num_votes'] !== '';
                                        ?>
                                        <?php if ($hasRating): ?>
                                            <br><i class="bi bi-star-fill" style="color: gold;"></i> <?php echo h($m['imdb_rating']); ?>
                                            <?php if ($hasVotes): ?>
                                                <small>(<?php echo number_format((int)$m['num_votes'], 0, ',', '.'); ?>)</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($m['your_rating'] !== null): ?>
                                            <br><i class="bi bi-star" style="color: orange;"></i> <?php echo h($m['your_rating']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-auto">
                                        <a class="btn btn-sm btn-outline-secondary w-100 mb-2" href="?mod=movie&amp;const=<?php echo urlencode($m['const']); ?>">Details</a>
                                        <?php if (!empty($m['url'])): ?>
                                            <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo h($m['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
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
        <nav aria-label="Seiten" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '?mod=movies&view=' . $viewMode;
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                if ($titleTypeFilter !== '') $baseUrl .= '&title_type=' . urlencode($titleTypeFilter);
                if ($genreFilter !== '') $baseUrl .= '&genre=' . (int)$genreFilter;
                if ($actorFilter !== '') $baseUrl .= '&actor=' . urlencode($actorFilter);
                if ($ratingFilter !== '') $baseUrl .= '&rating=' . urlencode($ratingFilter);
                
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
