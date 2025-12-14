<?php
require_once __DIR__ . '/../inc/database.inc.php';

$const = isset($_GET['const']) ? trim($_GET['const']) : '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = getConnection();

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_episode_id'])) {
    $episodeId = (int)$_POST['delete_episode_id'];
    try {
        $stmt = $pdo->prepare('UPDATE episodes SET visible = 0 WHERE id = ?');
        $stmt->execute([$episodeId]);
        // Redirect to avoid form resubmission
        header('Location: ?mod=serie&const=' . urlencode($const) . '&page=' . (isset($_GET['page']) ? (int)$_GET['page'] : 1));
        exit;
    } catch (Exception $e) {
        $deleteError = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

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

    // Gesamtanzahl Episoden (nur sichtbare)
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE parent_tconst = ? AND visible = 1');
    $stmtCount->execute([$const]);
    $total = (int)$stmtCount->fetchColumn();

    // Episoden laden (mit Episode-Details aus `movies`, nur sichtbare)
    $sql = '
        SELECT ep.*, mv.title AS episode_title, mv.year AS episode_year, mv.imdb_rating AS episode_imdb_rating,
               mv.num_votes AS episode_num_votes, mv.your_rating AS episode_your_rating, mv.runtime_mins AS episode_runtime_mins,
               mv.genres AS episode_genres, mv.url AS episode_url
        FROM episodes ep
        LEFT JOIN movies mv ON mv.`const` = ep.tconst
        WHERE ep.parent_tconst = ? AND ep.visible = 1
        ORDER BY COALESCE(ep.season_number, 0) ASC, COALESCE(ep.episode_number, 0) ASC
        LIMIT ? OFFSET ?
    ';
    $stmtEp = $pdo->prepare($sql);
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

            <!-- Serien-Übersicht: gleiche Reihenfolge wie in series.php -->
            <?php if ($seriesInfo): ?>
                <div class="card mb-3">
                    <div class="card-body p-2">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Titel:</strong> <?php echo h($seriesInfo['title']); ?><br>
                                <strong>Jahr:</strong> <?php echo $seriesInfo['year'] !== null ? h($seriesInfo['year']) : ''; ?><br>
                                <strong>IMDb:</strong>
                                <?php if ($seriesInfo['imdb_rating'] !== null) {
                                    echo '<span class="numeric">' . h($seriesInfo['imdb_rating']) . '</span>';
                                    if (!empty($seriesInfo['num_votes'])) echo ' (' . '<span class="numeric">' . h(number_format((int)$seriesInfo['num_votes'], 0, ',', '.')) . '</span>' . ')';
                                } ?><br>
                            </div>
                            <div class="col-md-6">
                                <strong>meine Bewertung:</strong> <?php echo $seriesInfo['your_rating'] !== null ? h($seriesInfo['your_rating']) : ''; ?><br>
                                <strong>Laufzeit:</strong> <?php echo $seriesInfo['runtime_mins'] !== null ? h($seriesInfo['runtime_mins']) . ' min' : ''; ?><br>
                                <strong>Genres:</strong> <?php echo h($seriesInfo['genres']); ?><br>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>tconst</th>
                            <th>Titel</th>
                            <th>Jahr</th>
                            <th class="text-end">IMDb</th>
                            <th class="text-end">Votes</th>
                            <th class="text-end">MyRate</th>
                            <th class="text-end">Laufzeit</th>
                            <th>Genres</th>
                            <th class="text-end">Season</th>
                            <th class="text-end">Episode</th>
                            <th>Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($episodes)): ?>
                            <tr><td colspan="11" class="text-center">Keine Episoden gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($episodes as $i => $ep): ?>
                                <tr>
                                    <td><?php echo h($offset + $i + 1); ?></td>
                                    <td><?php echo h($ep['tconst']); ?></td>
                                    <td style="max-width:360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($ep['episode_title'] ?? $ep['tconst']); ?>
                                    </td>
                                    <td><?php echo isset($ep['episode_year']) ? h($ep['episode_year']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo isset($ep['episode_imdb_rating']) && $ep['episode_imdb_rating'] !== null ? h($ep['episode_imdb_rating']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo (isset($ep['episode_num_votes']) && $ep['episode_num_votes'] !== null && $ep['episode_num_votes'] !== '') ? h(number_format((int)$ep['episode_num_votes'], 0, ',', '.')) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo isset($ep['episode_your_rating']) && $ep['episode_your_rating'] !== null ? h($ep['episode_your_rating']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo isset($ep['episode_runtime_mins']) && $ep['episode_runtime_mins'] !== null ? h($ep['episode_runtime_mins']) . ' min' : ''; ?></td>
                                    <td style="max-width:220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo isset($ep['episode_genres']) ? h($ep['episode_genres']) : ''; ?></td>
                                    <td class="text-end numeric"><?php echo $ep['season_number'] !== null ? h($ep['season_number']) : '-'; ?></td>
                                    <td class="text-end numeric"><?php echo $ep['episode_number'] !== null ? h($ep['episode_number']) : '-'; ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-primary" href="https://www.imdb.com/title/<?php echo h($ep['tconst']); ?>/" target="_blank" rel="noopener noreferrer">IMDb</a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="delete_episode_id" value="<?php echo $ep['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Wirklich löschen?');">Löschen</button>
                                        </form>
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
