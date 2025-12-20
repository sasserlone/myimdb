<?php
require_once __DIR__ . '/../inc/database.inc.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function formatCharacters($raw) {
    if ($raw === null || $raw === '') {
        return '';
    }
    // Characters sind bereits bereinigt vom Import
    return $raw;
}

$const = isset($_GET['const']) ? trim($_GET['const']) : '';
$pdo = getConnection();

$movie = null;
$principals = [];

if ($const !== '') {
    $stmt = $pdo->prepare('SELECT * FROM movies WHERE `const` = ? LIMIT 1');
    $stmt->execute([$const]);
    $movie = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($movie) {
        $stmtP = $pdo->prepare('SELECT mp.*, a.primary_name, a.birth_year, a.death_year, a.primary_profession, a.known_for_titles
            FROM movie_principals mp
            LEFT JOIN actors a ON a.nconst = mp.nconst
            WHERE mp.movie_id = ?
            ORDER BY (mp.ordering IS NULL), mp.ordering, mp.category, a.primary_name');
        $stmtP->execute([(int)$movie['id']]);
        $principals = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    }
}

$castCategories = ['actor', 'actress', 'self'];
$cast = [];
$crew = [];
foreach ($principals as $p) {
    $category = strtolower((string)($p['category'] ?? ''));
    if (in_array($category, $castCategories, true)) {
        $cast[] = $p;
    } else {
        $crew[] = $p;
    }
}

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Film: <?php echo $movie ? h($movie['title']) : h($const); ?></h2>
            <div>
                <a class="btn btn-sm btn-outline-primary" href="?mod=movies">← Zurück zu Filme</a>
            </div>
        </div>

        <?php if ($const === ''): ?>
            <div class="alert alert-warning">Kein Film ausgewählt.</div>
        <?php elseif (!$movie): ?>
            <div class="alert alert-danger">Film mit Kennung <?php echo h($const); ?> wurde nicht gefunden.</div>
        <?php else: ?>
            <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <div><strong>Titel:</strong> <?php echo h($movie['title']); ?></div>
                            <div><strong>tconst:</strong> <?php echo h($movie['const']); ?></div>
                            <div><strong>Jahr:</strong> <?php echo $movie['year'] !== null ? h($movie['year']) : ''; ?></div>
                            <div><strong>Typ:</strong> <?php echo h($movie['title_type']); ?></div>
                            <div><strong>Genres:</strong> <?php echo h($movie['genres']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>Laufzeit:</strong> <?php echo $movie['runtime_mins'] !== null ? h($movie['runtime_mins']) . ' min' : ''; ?></div>
                            <div><strong>IMDb:</strong> <?php
                                if ($movie['imdb_rating'] !== null) {
                                    echo '<span class="numeric">' . h($movie['imdb_rating']) . '</span>';
                                    if (!empty($movie['num_votes'])) {
                                        echo ' (' . '<span class="numeric">' . h(number_format((int)$movie['num_votes'], 0, ',', '.')) . '</span>' . ')';
                                    }
                                }
                            ?></div>
                            <div><strong>Meine Bewertung:</strong> <?php echo $movie['your_rating'] !== null ? h($movie['your_rating']) : ''; ?></div>
                            <?php if (!empty($movie['metascore']) || !empty($movie['metacritic_score']) || !empty($movie['rotten_tomatoes'])): ?>
                                <div><strong>Critics:</strong>
                                    <?php if (!empty($movie['metascore'])): ?>
                                        Metascore: <?php echo h($movie['metascore']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($movie['metacritic_score'])): ?>
                                        <?php if (!empty($movie['metascore'])): ?> · <?php endif; ?>
                                        Metacritic: <?php echo h($movie['metacritic_score']); ?>/100
                                    <?php endif; ?>
                                    <?php if (!empty($movie['rotten_tomatoes'])): ?>
                                        <?php if (!empty($movie['metascore']) || !empty($movie['metacritic_score'])): ?> · <?php endif; ?>
                                        Rotten: <?php echo h($movie['rotten_tomatoes']); ?>%
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div><strong>Link:</strong> <?php if (!empty($movie['url'])): ?>
                                <a href="<?php echo h($movie['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb öffnen</a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?></div>
                        </div>
                    </div>
                    <?php if (!empty($movie['plot']) || !empty($movie['language']) || !empty($movie['country'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <?php if (!empty($movie['plot'])): ?>
                                    <div class="mb-2"><strong>Plot:</strong><br><?php echo nl2br(h($movie['plot'])); ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size: 0.95em;">
                                    <?php if (!empty($movie['language'])): ?>
                                        <span><strong>Sprache:</strong> <?php echo h($movie['language']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($movie['country'])): ?>
                                        <?php if (!empty($movie['language'])): ?> · <?php endif; ?>
                                        <span><strong>Land:</strong> <?php echo h($movie['country']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h4>Besetzung</h4>
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Kategorie</th>
                            <th>Charaktere</th>
                            <th>Job</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cast)): ?>
                            <tr><td colspan="6" class="text-center">Keine Schauspieler gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cast as $i => $p): ?>
                                <tr>
                                    <td><?php echo h($i + 1); ?></td>
                                    <td>
                                        <?php echo h($p['primary_name'] ?? $p['nconst']); ?>
                                        <div class="text-muted" style="font-size: 0.9em;">
                                            <?php //echo h($p['nconst']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo h($p['category']); ?></td>
                                    <td style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($p['characters']); ?>
                                    </td>
                                    <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($p['job']); ?>
                                    </td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-info" href="?mod=movies_actor&amp;nconst=<?php echo urlencode($p['nconst']); ?>">Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h4>Weitere Mitwirkende</h4>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Kategorie</th>
                            <th>Charaktere / Funktion</th>
                            <th>Job</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($crew)): ?>
                            <tr><td colspan="6" class="text-center">Keine weiteren Einträge.</td></tr>
                        <?php else: ?>
                            <?php foreach ($crew as $i => $p): ?>
                                <tr>
                                    <td><?php echo h($i + 1); ?></td>
                                    <td>
                                        <?php echo h($p['primary_name'] ?? $p['nconst']); ?>
                                        <div class="text-muted" style="font-size: 0.9em;">
                                            <?php //echo h($p['nconst']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo h($p['category']); ?></td>
                                    <td style="max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($p['characters'] ?: $p['primary_profession']); ?>
                                    </td>
                                    <td style="max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo h($p['job']); ?>
                                    </td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-info" href="?mod=movies_actor&amp;nconst=<?php echo urlencode($p['nconst']); ?>">Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
