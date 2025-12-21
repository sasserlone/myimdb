<?php
require_once __DIR__ . '/../inc/database.inc.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$nconst = isset($_GET['nconst']) ? trim($_GET['nconst']) : '';

$pdo = getConnection();

$actor = null;
$filmsMovies = [];
$filmsSeriesEpisodes = [];
$filmsOther = [];

if ($nconst !== '') {
    // Actor info
    $stmtActor = $pdo->prepare('SELECT * FROM actors WHERE nconst = ? LIMIT 1');
    $stmtActor->execute([$nconst]);
    $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);

    if ($actor) {
        // Get all movies for this actor with their categories
        $stmtMovies = $pdo->prepare('
            SELECT m.*, 
                   GROUP_CONCAT(DISTINCT mp.category ORDER BY mp.ordering SEPARATOR ", ") as all_categories,
                   GROUP_CONCAT(DISTINCT mp.characters ORDER BY mp.ordering SEPARATOR ", ") as all_characters,
                   GROUP_CONCAT(DISTINCT CASE WHEN mp.category IN ("actor", "actress", "self") THEN mp.category END ORDER BY mp.ordering SEPARATOR ", ") as acting_categories,
                   GROUP_CONCAT(DISTINCT CASE WHEN mp.category NOT IN ("actor", "actress", "self") THEN mp.category END ORDER BY mp.ordering SEPARATOR ", ") as crew_categories
            FROM movies m
            INNER JOIN movie_principals mp ON mp.movie_id = m.id
            WHERE mp.nconst = ?
            GROUP BY m.id
            ORDER BY m.year DESC, m.title ASC
        ');
        $stmtMovies->execute([$nconst]);
        $allMovies = $stmtMovies->fetchAll(PDO::FETCH_ASSOC);

        // Kategorisiere Filme nach title_type und vorhandenen Rollen
        foreach ($allMovies as $m) {
            $titleType = strtolower((string)($m['title_type'] ?? ''));
            $hasActing = !empty($m['acting_categories']);
            $hasCrew = !empty($m['crew_categories']);
            
            // Filme: Wenn Acting-Rolle vorhanden, mit allen Kategorien
            if (!in_array($titleType, ['fernsehserie', 'miniserie', 'fernsehepisode']) && $hasActing) {
                $filmsMovies[] = $m;
            }
            // Serien/Episoden: Wenn Acting-Rolle vorhanden, mit allen Kategorien
            elseif (in_array($titleType, ['fernsehserie', 'miniserie', 'fernsehepisode']) && $hasActing) {
                $filmsSeriesEpisodes[] = $m;
            }
            
            // Sonstige Rollen: Nur wenn Crew-Rollen vorhanden (auch wenn Acting dabei ist)
            if ($hasCrew) {
                $filmsOther[] = $m;
            }
        }
    }
}

// Hilfsfunktion: Bekannt für-Zeichenkette mit Links formatieren
function formatKnownForTitles($knownForTitles, $pdo) {
    if (!$knownForTitles || $knownForTitles === '') {
        return '';
    }
    
    // Bekannt für ist eine komma-getrennte Liste von tconst
    $tconstList = array_filter(array_map('trim', explode(',', $knownForTitles)));
    
    if (empty($tconstList)) {
        return h($knownForTitles);
    }
    
    // Filme aus der DB laden
    $placeholders = implode(',', array_fill(0, count($tconstList), '?'));
    $stmt = $pdo->prepare("SELECT id, const, title FROM movies WHERE const IN ($placeholders) ORDER BY FIELD(const, " . implode(',', array_fill(0, count($tconstList), '?')) . ")");
    
    $params = array_merge($tconstList, $tconstList);
    $stmt->execute($params);
    $movieMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $movieMap[$row['const']] = $row;
    }
    
    // Links für bekannte Filme generieren
    $links = [];
    foreach ($tconstList as $tconst) {
        if (isset($movieMap[$tconst])) {
            $links[] = '<a href="?mod=movie&amp;const=' . urlencode($tconst) . '">' . h($movieMap[$tconst]['title']) . '</a>';
        } else {
            // Film nicht in DB: Link zu IMDb
            $links[] = '<a href="https://www.imdb.com/title/' . h($tconst) . '/" target="_blank" rel="noopener noreferrer">' . h($tconst) . '</a>';
        }
    }
    
    return implode(', ', $links);
}

// Hilfsfunktion für Tabellendarstellung
function renderMoviesTable($movies, $title, $showCrewOnly = false) {
    if (empty($movies)) {
        return;
    }
    ?>
    <h4><?php echo $title; ?></h4>
    <div class="table-responsive mb-4">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Titel</th>
                    <th>Jahr</th>
                    <th class="text-end">IMDb</th>
                    <th class="text-end">Votes</th>
                    <th class="text-end">MyRate</th>
                    <th class="text-end">Meta</th>
                    <th class="text-end">Rotten</th>
                    <th>Kategorie</th>
                    <th>Charakter</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movies as $i => $m): ?>
                    <tr>
                        <td><?php echo h($i + 1); ?></td>
                        <td style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo h($m['title']); ?>
                        </td>
                        <td><?php echo $m['year'] !== null ? h($m['year']) : ''; ?></td>
                        <td class="text-end numeric"><?php echo $m['imdb_rating'] !== null ? h($m['imdb_rating']) : ''; ?></td>
                        <td class="text-end numeric"><?php echo ($m['num_votes'] !== null && $m['num_votes'] !== '') ? h(number_format((int)$m['num_votes'], 0, ',', '.')) : ''; ?></td>
                        <td class="text-end numeric"><?php echo $m['your_rating'] !== null ? h($m['your_rating']) : ''; ?></td>
                        <td class="text-end numeric"><?php echo !empty($m['metascore']) ? h($m['metascore']) : ''; ?></td>
                        <td class="text-end numeric"><?php echo !empty($m['rotten_tomatoes']) ? h($m['rotten_tomatoes']) . '%' : ''; ?></td>
                        <td><?php echo h($showCrewOnly ? $m['crew_categories'] : $m['all_categories']); ?></td>
                        <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo h($showCrewOnly ? '' : $m['all_characters']); ?>
                        </td>
                        <td>
                            <a class="btn btn-sm btn-outline-secondary me-1" href="?mod=movie&amp;const=<?php echo urlencode($m['const']); ?>">Film</a>
                            <a class="btn btn-sm btn-outline-primary" href="https://www.imdb.com/title/<?php echo h($m['const']); ?>/" target="_blank" rel="noopener noreferrer">IMDb</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
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
                            <div><strong>nconst:</strong> <a href="https://www.imdb.com/name/<?php echo h($actor['nconst']); ?>/" target="_blank" rel="noopener noreferrer"><?php echo h($actor['nconst']); ?></a></div>
                            <div><strong>Geburtsyahr:</strong> <?php echo $actor['birth_year'] !== null ? h($actor['birth_year']) : ''; ?></div>
                            <div><strong>Todesjahr:</strong> <?php echo $actor['death_year'] !== null ? h($actor['death_year']) : ''; ?></div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>Berufe:</strong> <?php echo h($actor['primary_profession']); ?></div>
                            <div><strong>Bekannt für:</strong> <?php echo formatKnownForTitles($actor['known_for_titles'], $pdo); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filme -->
            <?php renderMoviesTable($filmsMovies, 'Filme (' . count($filmsMovies) . ')'); ?>

            <!-- Serien / Episoden -->
            <?php renderMoviesTable($filmsSeriesEpisodes, 'Serien / Episoden (' . count($filmsSeriesEpisodes) . ')'); ?>

            <!-- Sonstige Rollen -->
            <?php renderMoviesTable($filmsOther, 'Sonstige Rollen (' . count($filmsOther) . ')', true); ?>

        <?php endif; ?>
    </div>
</div>
