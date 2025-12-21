<?php
require_once __DIR__ . '/../inc/database.inc.php';

// Pagination-Parameter
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
if ($perPage <= 0) $perPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page <= 0) $page = 1;
$offset = ($page - 1) * $perPage;

// Filter-Parameter
$yearFilter = '';
if (!empty($_GET['year'])) {
    $yearFilter = trim($_GET['year']);
}

$categoryFilter = '';
if (!empty($_GET['category'])) {
    $categoryFilter = (int)$_GET['category'];
}

$winnerFilter = '';
if (!empty($_GET['winner'])) {
    $winnerFilter = trim($_GET['winner']);
}
if (!in_array($winnerFilter, ['winner', 'nominated'])) {
    $winnerFilter = '';
}

$q = '';
// Optional: suche per Titel/Nominee
if (!empty($_GET['q'])) {
    $q = trim($_GET['q']);
}

$pdo = getConnection();

// Get all distinct years
$yearStmt = $pdo->query('SELECT DISTINCT year FROM oscar_awards ORDER BY year DESC');
$allYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Get all distinct categories
$categoryStmt = $pdo->query('SELECT id, name, german FROM oscar_category ORDER BY german');
$allCategories = [];
while ($row = $categoryStmt->fetch(PDO::FETCH_ASSOC)) {
    $allCategories[$row['id']] = !empty($row['german']) ? $row['german'] : $row['name'];
}

// Build WHERE clause
$whereParts = [];
$params = [];

if ($yearFilter !== '') {
    $whereParts[] = 'oa.year = ?';
    $params[] = $yearFilter;
}

if ($categoryFilter !== '') {
    $whereParts[] = 'on1.category_id = ?';
    $params[] = (int)$categoryFilter;
}

if ($winnerFilter === 'winner') {
    $whereParts[] = 'on1.winner = 1';
} elseif ($winnerFilter === 'nominated') {
    $whereParts[] = 'on1.winner = 0';
}

if ($q !== '') {
    $whereParts[] = 'on1.nominated LIKE ?';
    $params[] = "%$q%";
}

$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// Gesamtanzahl ermitteln - OHNE movies JOIN für Performance
$countSql = 'SELECT COUNT(*) FROM oscar_nominations on1
             INNER JOIN oscar_awards oa ON oa.id = on1.award_id
             ' . $whereClause;
if (!empty($params)) {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
} else {
    $stmtCount = $pdo->query($countSql);
}
$total = (int)$stmtCount->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

// Statistiken ermitteln (nur wenn keine Filter aktiv sind, sonst verwende $total)
if (empty($whereParts)) {
    // Schnelle Gesamtstatistik ohne JOINs
    $statsRow = $pdo->query('
        SELECT 
            COUNT(*) AS total_nominations,
            SUM(winner) AS total_winners
        FROM oscar_nominations
    ')->fetch(PDO::FETCH_ASSOC);
    $totalNominations = (int)($statsRow['total_nominations'] ?? 0);
    $totalWinners = (int)($statsRow['total_winners'] ?? 0);
    
    $totalYears = $pdo->query('SELECT COUNT(DISTINCT year) FROM oscar_awards')->fetchColumn();
    $totalCategories = $pdo->query('SELECT COUNT(*) FROM oscar_category')->fetchColumn();
} else {
    // Bei aktiven Filtern: verwende die gezählten Ergebnisse
    $totalNominations = $total;
    $totalWinners = 0; // Wird nicht separat gezählt bei Filtern
    $totalYears = 0;
    $totalCategories = 0;
}

// Daten laden
$selectSql = 'SELECT on1.id, on1.imdb_const, on1.tmdb_id, on1.nominated, on1.winner,
              oa.year, oc.name AS category_name, oc.german AS category_german,
              m.title, m.year AS movie_year, m.url
              FROM oscar_nominations on1
              INNER JOIN oscar_awards oa ON oa.id = on1.award_id
              INNER JOIN oscar_category oc ON oc.id = on1.category_id
              LEFT JOIN movies m ON m.const COLLATE utf8mb4_general_ci = on1.imdb_const
              ' . $whereClause . ' 
              ORDER BY oa.year DESC, oc.name ASC, on1.winner DESC, on1.nominated ASC
              LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($selectSql);
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
}
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();

$oscars = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Oscar-Verleihungen</h2>
        </div>

        <form class="d-flex mb-3" method="get" style="gap:.5rem">
            <input type="hidden" name="mod" value="oscars">
            <select class="form-select form-select-sm" name="year" style="width: auto;">
                <option value="">Alle Jahre</option>
                <?php foreach ($allYears as $year): ?>
                    <option value="<?php echo h($year); ?>" <?php echo $yearFilter === $year ? 'selected' : ''; ?>><?php echo h($year); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="category" style="width: auto;">
                <option value="">Alle Kategorien</option>
                <?php foreach ($allCategories as $catId => $catName): ?>
                    <option value="<?php echo (int)$catId; ?>" <?php echo (int)$categoryFilter === (int)$catId ? 'selected' : ''; ?>><?php echo h($catName); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="winner" style="width: auto;">
                <option value="">Alle</option>
                <option value="winner" <?php echo $winnerFilter === 'winner' ? 'selected' : ''; ?>>Nur Gewinner</option>
                <option value="nominated" <?php echo $winnerFilter === 'nominated' ? 'selected' : ''; ?>>Nur Nominierte</option>
            </select>
            <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Film/Nominee" value="<?php echo h($q); ?>">
            <button class="btn btn-sm btn-primary ms-2">Suchen</button>
        </form>

        <!-- Info Card -->
        <div class="card mb-3" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Nominierungen</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $totalNominations; ?></p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Gewinner</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $totalWinners; ?></p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Jahre</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $totalYears; ?></p>
                    </div>
                    <div class="col-md-3">
                        <h5 class="card-title mb-1">Kategorien</h5>
                        <p class="card-text" style="font-size: 1.5em; font-weight: bold;"><?php echo $totalCategories; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabellenansicht -->
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Jahr</th>
                        <th>Kategorie</th>
                        <th>German</th>
                        <th>Nominierte</th>
                        <th>Film</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($oscars)): ?>
                        <tr><td colspan="8" class="text-center">Keine Einträge gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($oscars as $i => $o): ?>
                            <tr <?php echo $o['winner'] ? 'style="background-color: rgba(255, 215, 0, 0.1);"' : ''; ?>>
                                <td><?php echo h($offset + $i + 1); ?></td>
                                <td><?php echo h($o['year']); ?></td>
                                <td><?php echo h($o['category_name']); ?></td>
                                <td><?php echo !empty($o['category_german']) ? h($o['category_german']) : '<span class="text-muted">—</span>'; ?></td>
                                <td><?php echo h($o['nominated']); ?></td>
                                <td>
                                    <?php if (!empty($o['title'])): ?>
                                        <?php echo h($o['title']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($o['winner']): ?>
                                        <span title="Gewinner" style="font-size: 1.3em;">🏆</span>
                                    <?php else: ?>
                                        <span title="Nominiert" style="font-size: 1.1em;">📋</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($o['imdb_const'])): ?>
                                        <a class="btn btn-sm btn-outline-secondary me-1" href="?mod=movie&amp;const=<?php echo urlencode($o['imdb_const']); ?>">Details</a>
                                    <?php endif; ?>
                                    <?php if (!empty($o['url'])): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h($o['url']); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
                                    <?php elseif (!empty($o['imdb_const'])): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="https://www.imdb.com/title/<?php echo h($o['imdb_const']); ?>/" target="_blank" rel="noopener noreferrer">IMDb</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination mit Ellipsis -->
        <nav aria-label="Seiten" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '?mod=oscars';
                if ($q !== '') $baseUrl .= '&q=' . urlencode($q);
                if ($yearFilter !== '') $baseUrl .= '&year=' . urlencode($yearFilter);
                if ($categoryFilter !== '') $baseUrl .= '&category=' . (int)$categoryFilter;
                if ($winnerFilter !== '') $baseUrl .= '&winner=' . urlencode($winnerFilter);
                
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
