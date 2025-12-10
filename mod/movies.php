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

$pdo = getConnection();

// Gesamtanzahl ermitteln
if ($q !== '') {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM movies WHERE title LIKE ?');
    $stmtCount->execute(["%$q%"]);
} else {
    $stmtCount = $pdo->query('SELECT COUNT(*) FROM movies');
}
$total = (int)$stmtCount->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

// Daten laden
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM movies ORDER BY title ASC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt = $pdo->prepare('SELECT * FROM movies ORDER BY title ASC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Filme</h2>
            <form class="d-flex" method="get" style="gap:.5rem">
                <input type="hidden" name="mod" value="movies">
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Titel" value="<?php echo h($q); ?>">
                <button class="btn btn-sm btn-primary ms-2">Suchen</button>
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
                        <th>Type</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movies)): ?>
                        <tr><td colspan="10" class="text-center">Keine Filme gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($movies as $i => $m): ?>
                            <tr>
                                <td><?php echo h($offset + $i + 1); ?></td>
                                <td><?php echo h($m['const']); ?></td>
                                <td><?php echo h($m['title']); ?></td>
                                <td><?php echo h($m['year']); ?></td>
                                <td class="text-end numeric"><?php echo $m['imdb_rating'] !== null ? h($m['imdb_rating']) : ''; ?></td>
                                <td class="text-end numeric"><?php echo ($m['num_votes'] !== null && $m['num_votes'] !== '') ? h(number_format((int)$m['num_votes'], 0, ',', '.')) : ''; ?></td>
                                <td class="text-end numeric"><?php echo $m['your_rating'] !== null ? h($m['your_rating']) : ''; ?></td>
                                <td class="text-end numeric"><?php echo $m['runtime_mins'] !== null ? h($m['runtime_mins']) . ' min' : ''; ?></td>
                                <td style="max-width:220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['genres']); ?></td>
                                <td style="max-width:180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['title_type']); ?></td>
                                <td>
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

        <!-- Pagination mit Ellipsis -->
        <nav aria-label="Seiten">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '?mod=movies';
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                
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
