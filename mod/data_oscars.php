<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';
$pdo = getConnection();

// ****************************************************************************
// Löschen
// ****************************************************************************
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    try {
        $id = (int)$_POST['delete_id'];
        $stmt = $pdo->prepare('DELETE FROM oscar_nominations WHERE id = ?');
        $stmt->execute([$id]);
        $message = '✓ Eintrag wurde gelöscht.';
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// ****************************************************************************
// Speichern (Neu oder Update)
// ****************************************************************************
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    try {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $year = (int)$_POST['year'];
        $categoryId = (int)$_POST['category_id'];
        $nominated = trim($_POST['nominated']);
        $film = !empty($_POST['film']) ? trim($_POST['film']) : null;
        $imdbConst = !empty($_POST['imdb_const']) ? trim($_POST['imdb_const']) : null;
        $tmdbId = !empty($_POST['tmdb_id']) ? (int)$_POST['tmdb_id'] : null;
        $winner = isset($_POST['winner']) ? 1 : 0;
        
        if (empty($nominated)) {
            throw new Exception('Nominierte/r darf nicht leer sein.');
        }
        
        if ($id) {
            // Update
            $stmt = $pdo->prepare('
                UPDATE oscar_nominations 
                SET year = ?, category_id = ?, nominated = ?, film = ?, imdb_const = ?, tmdb_id = ?, winner = ?
                WHERE id = ?
            ');
            $stmt->execute([$year, $categoryId, $nominated, $film, $imdbConst, $tmdbId, $winner, $id]);
            $message = '✓ Eintrag wurde aktualisiert.';
        } else {
            // Neu anlegen
            $stmt = $pdo->prepare('
                INSERT INTO oscar_nominations 
                (year, category_id, nominated, film, imdb_const, tmdb_id, winner)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$year, $categoryId, $nominated, $film, $imdbConst, $tmdbId, $winner]);
            $message = '✓ Neuer Eintrag wurde angelegt. Formular bleibt für weiteren Eintrag geöffnet.';
            
            // Formular bleibt offen mit den eingegebenen Daten (außer nominated wird geleert)
            $_GET['new'] = '1';
            $_POST = [];  // POST-Daten löschen um Doppel-Submit zu verhindern
        }
        
        // Bei neuem Eintrag: Daten für nächsten Eintrag vorbereiten
        if (!$id) {
            $editEntry = [
                'id' => null,
                'year' => $year,
                'category_id' => $categoryId,
                'nominated' => '',  // Leeren für nächsten Eintrag
                'film' => '',  // Leeren für nächsten Eintrag
                'imdb_const' => '',
                'tmdb_id' => '',
                'winner' => $winner
            ];
            $editMode = true;
        }
    } catch (Exception $e) {
        $error = 'Fehler beim Speichern: ' . $e->getMessage();
    }
}

// ****************************************************************************
// Bearbeiten/Neu - Formular anzeigen
// ****************************************************************************
if (!isset($editMode)) {
    $editMode = false;
}
if (!isset($editEntry)) {
    $editEntry = null;
}

if (isset($_GET['edit']) && !isset($_POST['save'])) {
    $editMode = true;
    $editId = (int)$_GET['edit'];
    
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM oscar_nominations WHERE id = ?');
        $stmt->execute([$editId]);
        $editEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$editEntry) {
            $error = 'Eintrag nicht gefunden.';
            $editMode = false;
        }
    }
}

if (isset($_GET['new']) && !isset($_POST['save']) && !$editMode) {
    $editMode = true;
    $editEntry = [
        'id' => null,
        'year' => date('Y'),
        'category_id' => '',
        'nominated' => '',
        'film' => '',
        'imdb_const' => '',
        'tmdb_id' => '',
        'winner' => 0
    ];
}

// Kategorien laden
$categories = [];
$stmtCat = $pdo->query('SELECT id, name, german FROM oscar_category ORDER BY COALESCE(german, name)');
while ($row = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = !empty($row['german']) ? $row['german'] : $row['name'];
}

// ****************************************************************************
// Liste anzeigen
// ****************************************************************************
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Filter
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
$yearFilter = isset($_GET['year']) ? trim($_GET['year']) : '';
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$whereParts = [];
$params = [];

if ($searchTerm !== '') {
    $whereParts[] = '(on1.nominated LIKE ? OR on1.film LIKE ?)';
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if ($yearFilter !== '') {
    $whereParts[] = 'on1.year = ?';
    $params[] = $yearFilter;
}

if ($categoryFilter > 0) {
    $whereParts[] = 'on1.category_id = ?';
    $params[] = $categoryFilter;
}

$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// Gesamtanzahl
$countSql = "SELECT COUNT(*) FROM oscar_nominations on1 $whereClause";
if (!empty($params)) {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
} else {
    $stmtCount = $pdo->query($countSql);
}
$total = (int)$stmtCount->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Daten laden
$sql = "SELECT on1.*, COALESCE(oc.german, oc.name) AS category_name
        FROM oscar_nominations on1
        INNER JOIN oscar_category oc ON oc.id = on1.category_id
        $whereClause
        ORDER BY on1.year ASC, COALESCE(oc.german, oc.name) ASC, on1.winner DESC
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
}
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Jahre für Filter
$years = $pdo->query('SELECT DISTINCT year FROM oscar_nominations ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>🏆 Oscar Daten bearbeiten</h2>
            <a href="?mod=data_oscars&new" class="btn btn-sm btn-success">+ Neuer Eintrag</a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($editMode): ?>
            <!-- Bearbeiten/Neu Formular -->
            <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
                <div class="card-body">
                    <h4><?php echo $editEntry['id'] ? 'Eintrag bearbeiten' : 'Neuer Eintrag'; ?></h4>
                    
                    <form method="POST">
                        <input type="hidden" name="id" value="<?php echo h($editEntry['id'] ?? ''); ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Jahr</label>
                                <input type="number" class="form-control" name="year" value="<?php echo h($editEntry['year']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Kategorie</label>
                                <select class="form-select" name="category_id" required>
                                    <option value="">Bitte wählen</option>
                                    <?php foreach ($categories as $catId => $catName): ?>
                                        <option value="<?php echo $catId; ?>" <?php echo $editEntry['category_id'] == $catId ? 'selected' : ''; ?>>
                                            <?php echo h($catName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Nominierte/r *</label>
                                <input type="text" class="form-control" name="nominated" value="<?php echo h($editEntry['nominated']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Film</label>
                                <input type="text" class="form-control" name="film" value="<?php echo h($editEntry['film']); ?>" placeholder="Filmtitel">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">IMDb Const (tconst)</label>
                                <input type="text" class="form-control" name="imdb_const" value="<?php echo h($editEntry['imdb_const']); ?>" placeholder="tt1234567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TMDb ID</label>
                                <input type="number" class="form-control" name="tmdb_id" value="<?php echo h($editEntry['tmdb_id']); ?>" placeholder="12345">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="winner" id="winner" <?php echo $editEntry['winner'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="winner">🏆 Gewinner</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="save" class="btn btn-primary">💾 Speichern</button>
                            <a href="?mod=data_oscars" class="btn btn-secondary">Abbrechen</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter -->
        <form class="d-flex mb-3" method="get" style="gap:.5rem">
            <input type="hidden" name="mod" value="data_oscars">
            <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche Nominierte/r oder Film" value="<?php echo h($searchTerm); ?>" style="width: 300px;">
            <select class="form-select form-select-sm" name="year" style="width: auto;">
                <option value="">Alle Jahre</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?php echo h($year); ?>" <?php echo $yearFilter == $year ? 'selected' : ''; ?>><?php echo h($year); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="category" style="width: auto;">
                <option value="">Alle Kategorien</option>
                <?php foreach ($categories as $catId => $catName): ?>
                    <option value="<?php echo $catId; ?>" <?php echo $categoryFilter == $catId ? 'selected' : ''; ?>><?php echo h($catName); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary">Suchen</button>
            <?php if ($searchTerm || $yearFilter || $categoryFilter): ?>
                <a href="?mod=data_oscars" class="btn btn-sm btn-secondary">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <!-- Liste -->
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Jahr</th>
                        <th>Kategorie</th>
                        <th>Nominierte/r</th>
                        <th>Film</th>
                        <th>IMDb</th>
                        <th>TMDb</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="9" class="text-center">Keine Einträge gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?php echo h($entry['id']); ?></td>
                                <td><?php echo h($entry['year']); ?></td>
                                <td style="font-size: 0.9em;"><?php echo h($entry['category_name']); ?></td>
                                <td><?php echo h($entry['nominated']); ?></td>
                                <td style="font-size: 0.9em;"><?php echo !empty($entry['film']) ? h($entry['film']) : '<span style="opacity:0.5;">—</span>'; ?></td>
                                <td>
                                    <?php if (!empty($entry['imdb_const'])): ?>
                                        <a href="?mod=movie&const=<?php echo urlencode($entry['imdb_const']); ?>" title="<?php echo h($entry['imdb_const']); ?>">
                                            🔗
                                        </a>
                                    <?php else: ?>
                                        <span style="opacity:0.5;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($entry['tmdb_id'])): ?>
                                        <a href="https://www.themoviedb.org/movie/<?php echo h($entry['tmdb_id']); ?>" target="_blank" rel="noopener noreferrer" title="TMDb ID: <?php echo h($entry['tmdb_id']); ?>">
                                            🔗
                                        </a>
                                    <?php else: ?>
                                        <span style="opacity:0.5;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($entry['winner']): ?>
                                        <span title="Gewinner">🏆</span>
                                    <?php else: ?>
                                        <span title="Nominiert">📋</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?mod=data_oscars&edit=<?php echo $entry['id']; ?>" class="btn btn-sm btn-outline-primary">✏️ Bearbeiten</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Wirklich löschen?');">
                                        <input type="hidden" name="delete_id" value="<?php echo $entry['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav aria-label="Seiten" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                    $baseUrl = '?mod=data_oscars';
                    if ($searchTerm) $baseUrl .= '&q=' . urlencode($searchTerm);
                    if ($yearFilter) $baseUrl .= '&year=' . urlencode($yearFilter);
                    if ($categoryFilter) $baseUrl .= '&category=' . $categoryFilter;
                    
                    for ($p = 1; $p <= $pages; $p++):
                        $active = $p === $page ? ' active' : '';
                        ?>
                        <li class="page-item<?php echo $active; ?>">
                            <a class="page-link" href="<?php echo $baseUrl . '&page=' . $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>
