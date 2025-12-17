<?php
require_once __DIR__ . '/../inc/database.inc.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$nconst = isset($_GET['nconst']) ? trim($_GET['nconst']) : '';
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage <= 0) $perPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page <= 0) $page = 1;
$offset = ($page - 1) * $perPage;

$pdo = getConnection();

$actor = null;
$movies = [];
$total = 0;
$pages = 0;

if ($nconst !== '') {
    // Actor info
    $stmtActor = $pdo->prepare('SELECT * FROM actors WHERE nconst = ? LIMIT 1');
    $stmtActor->execute([$nconst]);
    $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);

    if ($actor) {
        // Count total movies for this actor
        $stmtCount = $pdo->prepare('
            SELECT COUNT(DISTINCT mp.movie_id) as cnt
            FROM movie_principals mp
            WHERE mp.nconst = ?
        ');
        $stmtCount->execute([$nconst]);
        $total = (int)$stmtCount->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));

        // Get movies for this actor
        $stmtMovies = $pdo->prepare('
            SELECT DISTINCT m.*, mp.ordering, mp.category, mp.characters
            FROM movies m
            INNER JOIN movie_principals mp ON mp.movie_id = m.id
            WHERE mp.nconst = ?
            ORDER BY m.year DESC, m.title ASC
            LIMIT ? OFFSET ?
        ');
        $stmtMovies->bindValue(1, $nconst);
        $stmtMovies->bindValue(2, $perPage, PDO::PARAM_INT);
        $stmtMovies->bindValue(3, $offset, PDO::PARAM_INT);
        $stmtMovies->execute();
        $movies = $stmtMovies->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><?php echo $actor ? h($actor['primary_name']) : h($nconst); ?></h2>
            <div>
                <a class="btn btn-sm btn-outline-primary" href="?mod=movies">← Zurück zu Filme</a>
            </div>
        </div>

        <?php if ($nconst === ''): ?>
            <div class="alert alert-warning">Keine Person ausgewählt.</div>
        <?php elseif (!$actor): ?>
            <div class="alert alert-danger">Person mit nconst <?php echo h($nconst); ?> wurde nicht gefunden.</div>
        <?php else: ?>
            <!-- Actor Info Card -->
            <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <div><strong>Name:</strong> <?php echo h($actor['primary_name']); ?></div>
                            <div><strong>nconst:</strong> <?php echo h($actor['nconst']); ?></div>
                            <div><strong>Geburtsyahr:</strong> <?php echo $actor['birth_year'] !== null ? h($actor['birth_year']) : ''; ?></div>
                            <div><strong>Todesjahr:</strong> <?php echo $actor['death_year'] !== null ? h($actor['death_year']) : ''; ?></div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>Berufe:</strong> <?php echo h($actor['primary_profession']); ?></div>
                            <div><strong>Bekannt für:</strong> <?php echo h($actor['known_for_titles']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <h4>Filme (<?php echo $total; ?>)</h4>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Titel</th>
                            <th>Jahr</th>
                            <th class="text-end">IMDb</th>
                            <th class="text-end">Votes</th>
                            <th>Kategorie</th>
                            <th>Charakter</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movies)): ?>
                            <tr><td colspan="8" class="text-center">Keine Filme gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($movies as $i => $m): ?>
                                <tr>
                                    <td><?php echo h($offset + $i + 1); ?></td>
                                    <td style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($m['title']); ?>
                                    </td>
                                    <td><?php echo $m['year'] !== null ? h($m['year']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo $m['imdb_rating'] !== null ? h($m['imdb_rating']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo ($m['num_votes'] !== null && $m['num_votes'] !== '') ? h(number_format((int)$m['num_votes'], 0, ',', '.')) : ''; ?></td>
                                    <td><?php echo h($m['category']); ?></td>
                                    <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($m['characters']); ?>
                                    </td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary" href="?mod=movie&amp;const=<?php echo urlencode($m['const']); ?>">Film</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <nav aria-label="Seiten">
                <ul class="pagination justify-content-center">
                    <?php
                    $baseUrl = '?mod=movies_actor&nconst=' . urlencode($nconst);

                    // Previous
                    if ($page > 1):
                        ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=1&per_page=' . $perPage; ?>">&laquo;</a></li><?php
                    endif;

                    $delta = 2;
                    $start = max(1, $page - $delta);
                    $end = min($pages, $page + $delta);

                    if ($start > 1):
                        ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=1&per_page=' . $perPage; ?>">1</a></li><?php
                        if ($start > 2):
                            ?><li class="page-item disabled"><span class="page-link">...</span></li><?php
                        endif;
                    endif;

                    for ($p = $start; $p <= $end; $p++):
                        $active = $p === $page ? ' active' : '';
                        ?>
                        <li class="page-item<?php echo $active; ?>">
                            <a class="page-link" href="<?php echo $baseUrl . '&page=' . $p . '&per_page=' . $perPage; ?>"><?php echo $p; ?></a>
                        </li>
                        <?php
                    endfor;

                    if ($end < $pages):
                        if ($end < $pages - 1):
                            ?><li class="page-item disabled"><span class="page-link">...</span></li><?php
                        endif;
                        ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=' . $pages . '&per_page=' . $perPage; ?>"><?php echo $pages; ?></a></li><?php
                    endif;

                    if ($page < $pages):
                        ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&page=' . ($page + 1) . '&per_page=' . $perPage; ?>">&raquo;</a></li><?php
                    endif;
                    ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
