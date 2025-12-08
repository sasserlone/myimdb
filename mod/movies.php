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
                        <th>IMDb</th>
                        <th>Deine Bewertung</th>
                        <th>Laufzeit</th>
                        <th>Genres</th>
                        <th>Regie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movies)): ?>
                        <tr><td colspan="9" class="text-center">Keine Filme gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($movies as $i => $m): ?>
                            <tr>
                                <td><?php echo h($offset + $i + 1); ?></td>
                                <td><?php echo h($m['const']); ?></td>
                                <td><?php echo h($m['title']); ?></td>
                                <td><?php echo h($m['year']); ?></td>
                                <td><?php echo $m['imdb_rating'] !== null ? h($m['imdb_rating']) : ''; ?></td>
                                <td><?php echo $m['your_rating'] !== null ? h($m['your_rating']) : ''; ?></td>
                                <td><?php echo $m['runtime_mins'] !== null ? h($m['runtime_mins']) . ' min' : ''; ?></td>
                                <td style="max-width:220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['genres']); ?></td>
                                <td style="max-width:180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo h($m['directors']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <nav aria-label="Seiten">
            <ul class="pagination">
                <?php
                $baseUrl = '?mod=movies';
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                for ($p = 1; $p <= $pages; $p++):
                    $active = $p === $page ? ' active' : '';
                ?>
                    <li class="page-item<?php echo $active; ?>">
                        <a class="page-link" href="<?php echo $baseUrl . '&page=' . $p . '&per_page=' . $perPage; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>

    </div>
</div>
