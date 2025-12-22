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
$oscars = [];

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
        
        // Oscar-Nominierungen laden
        $stmtOscar = $pdo->prepare('
            SELECT on1.*, oc.german AS category_german, oc.name AS category_name
            FROM oscar_nominations on1
            INNER JOIN oscar_category oc ON oc.id = on1.category_id
            WHERE on1.imdb_const = ?
            ORDER BY on1.year DESC, on1.winner DESC
        ');
        $stmtOscar->execute([$const]);
        $oscars = $stmtOscar->fetchAll(PDO::FETCH_ASSOC);
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
                    <div class="row">
                        <!-- Cover -->
                        <div class="col-md-3">
                            <?php 
                            $coverFile = __DIR__ . '/../cover/' . $movie['const'] . '.jpg';
                            $hasCover = !empty($movie['poster_url']) || file_exists($coverFile);
                            ?>
                            <?php if ($hasCover): ?>
                                <img src="<?php 
                                    echo file_exists($coverFile) ? './cover/' . h($movie['const']) . '.jpg' : h($movie['poster_url']);
                                ?>" class="img-fluid rounded" alt="<?php echo h($movie['title']); ?>" style="width: 100%; max-width: 300px;">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center rounded" style="width: 100%; height: 400px; background: var(--card-bg); border: 1px solid var(--table-border);">
                                    <span style="opacity: 0.5;">Kein Cover</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Info -->
                        <div class="col-md-9">
                            <?php if (!empty($movie['plot'])): ?>
                                <div class="mb-3">
                                    <div><strong>Plot:</strong><br><?php echo nl2br(h($movie['plot'])); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <div class="mb-2"><strong>Titel:</strong> <?php echo h($movie['title']); ?></div>
                                    <div class="mb-2"><strong>tconst:</strong> <a href="https://www.imdb.com/title/<?php echo h($movie['const']); ?>/" target="_blank" rel="noopener noreferrer"><?php echo h($movie['const']); ?></a></div>
                                    <div class="mb-2"><strong>Jahr:</strong> <?php echo $movie['year'] !== null ? h($movie['year']) : ''; ?></div>
                                    <div class="mb-2"><strong>Typ:</strong> <?php echo h($movie['title_type']); ?></div>
                                    <div class="mb-2"><strong>Genres:</strong> <?php echo h($movie['genres']); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2"><strong>Laufzeit:</strong> <?php echo $movie['runtime_mins'] !== null ? h($movie['runtime_mins']) . ' min' : ''; ?></div>
                                    <div class="mb-2"><strong>IMDb:</strong> <?php
                                        if ($movie['imdb_rating'] !== null) {
                                            echo '<span class="numeric">' . h($movie['imdb_rating']) . '</span>';
                                            if (!empty($movie['num_votes'])) {
                                                echo ' (' . '<span class="numeric">' . h(number_format((int)$movie['num_votes'], 0, ',', '.')) . '</span>' . ')';
                                            }
                                        }
                                    ?></div>
                                    <div class="mb-2"><strong>Meine Bewertung:</strong> <?php echo $movie['your_rating'] !== null ? h($movie['your_rating']) : ''; ?></div>
                                    <?php if (!empty($movie['metascore']) || !empty($movie['metacritic_score']) || !empty($movie['rotten_tomatoes'])): ?>
                                        <div class="mb-2"><strong>Critics:</strong>
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
                                </div>
                            </div>
                            <?php if (!empty($movie['language']) || !empty($movie['country'])): ?>
                                <div class="mt-3">
                                    <div style="font-size: 0.95em; opacity: 0.8;">
                                        <?php if (!empty($movie['language'])): ?>
                                            <span><strong>Sprache:</strong> <?php echo h($movie['language']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($movie['country'])): ?>
                                            <?php if (!empty($movie['language'])): ?> · <?php endif; ?>
                                            <span><strong>Land:</strong> <?php echo h($movie['country']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($oscars)): ?>
                                <div class="mt-3">
                                    <div><strong>Oscar-Auszeichnungen:</strong></div>
                                    <div class="mt-2">
                                        <?php 
                                        $oscarWins = array_filter($oscars, function($o) { return $o['winner'] == 1; });
                                        $oscarNoms = array_filter($oscars, function($o) { return $o['winner'] == 0; });
                                        ?>
                                        <?php if (!empty($oscarWins)): ?>
                                            <div class="mb-2">
                                                <strong style="color: gold;">🏆 Gewonnen (<?php echo count($oscarWins); ?>):</strong>
                                                <ul class="mb-0 mt-1" style="font-size: 0.95em;">
                                                    <?php foreach ($oscarWins as $oscar): ?>
                                                        <li>
                                                            <strong><?php echo h($oscar['year']); ?>:</strong> 
                                                            <?php echo !empty($oscar['category_german']) ? h($oscar['category_german']) : h($oscar['category_name']); ?>
                                                            <?php if (!empty($oscar['nominated'])): ?>
                                                                <span style="opacity: 0.8;"> – <?php echo h($oscar['nominated']); ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($oscarNoms)): ?>
                                            <div>
                                                <strong>📋 Nominiert (<?php echo count($oscarNoms); ?>):</strong>
                                                <ul class="mb-0 mt-1" style="font-size: 0.95em;">
                                                    <?php foreach ($oscarNoms as $oscar): ?>
                                                        <li>
                                                            <strong><?php echo h($oscar['year']); ?>:</strong> 
                                                            <?php echo !empty($oscar['category_german']) ? h($oscar['category_german']) : h($oscar['category_name']); ?>
                                                            <?php if (!empty($oscar['nominated'])): ?>
                                                                <span style="opacity: 0.8;"> – <?php echo h($oscar['nominated']); ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
