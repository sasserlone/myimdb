<?php
require_once __DIR__ . '/../inc/database.inc.php';

$const = isset($_GET['const']) ? trim($_GET['const']) : '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Pagination
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
if ($perPage <= 0) $perPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page <= 0) $page = 1;
$offset = ($page - 1) * $perPage;

$pdo = getConnection();

$seriesInfo = null;
$total = 0;
$episodes = [];

if ($const !== '') {
    // Serien-Info (falls vorhanden)
    $stmt = $pdo->prepare('SELECT * FROM movies WHERE `const` = ? LIMIT 1');
    $stmt->execute([$const]);
    $seriesInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // Gesamtanzahl Episoden
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE parent_tconst = ?');
    $stmtCount->execute([$const]);
    $total = (int)$stmtCount->fetchColumn();

    // Episoden laden
    $stmtEp = $pdo->prepare('SELECT * FROM episodes WHERE parent_tconst = ? ORDER BY COALESCE(season_number, 0) ASC, COALESCE(episode_number, 0) ASC LIMIT ? OFFSET ?');
    $stmtEp->bindValue(1, $const);
    $stmtEp->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmtEp->bindValue(3, $offset, PDO::PARAM_INT);
    $stmtEp->execute();
    $episodes = $stmtEp->fetchAll(PDO::FETCH_ASSOC);
}

$pages = max(1, (int)ceil(max(1, $total) / $perPage));

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Serie: <?php echo $seriesInfo ? h($seriesInfo['title']) : h($const); ?></h2>
            <div>
                <a class="btn btn-sm btn-secondary" href="?mod=series">← Zurück zu Serien</a>
            </div>
        </div>

        <?php if ($const === ''): ?>
            <div class="message error">Keine Serie ausgewählt.</div>
        <?php else: ?>
            <p class="text-muted">Episoden für: <?php echo $seriesInfo ? h($seriesInfo['title']) : h($const); ?> (<?php echo number_format($total, 0, ',', '.'); ?>)</p>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>tconst</th>
                            <th>Season</th>
                            <th>Episode</th>
                            <th>Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($episodes)): ?>
                            <tr><td colspan="5" class="text-center">Keine Episoden gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($episodes as $i => $ep): ?>
                                <tr>
                                    <td><?php echo h($offset + $i + 1); ?></td>
                                    <td><?php echo h($ep['tconst']); ?></td>
                                    <td><?php echo $ep['season_number'] !== null ? h($ep['season_number']) : '-'; ?></td>
                                    <td><?php echo $ep['episode_number'] !== null ? h($ep['episode_number']) : '-'; ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-primary" href="https://www.imdb.com/title/<?php echo h($ep['tconst']); ?>/" target="_blank" rel="noopener noreferrer">IMDb</a>
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
                    $baseUrl = '?mod=serie&const=' . urlencode($const);
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
