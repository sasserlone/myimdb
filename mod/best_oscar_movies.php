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

$typeFilter = isset($_GET['type']) ? $_GET['type'] : 'nominations';
if (!in_array($typeFilter, ['nominations', 'wins'])) {
    $typeFilter = 'nominations';
}

$minCount = isset($_GET['min_count']) ? (int)$_GET['min_count'] : 1;
if ($minCount < 1) $minCount = 1;

$pdo = getConnection();

// Get all distinct years
$yearStmt = $pdo->query('SELECT DISTINCT year FROM oscar_nominations ORDER BY year DESC');
$allYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Build WHERE clause
$whereParts = [];
$params = [];

if ($yearFilter !== '') {
    $whereParts[] = 'on1.year = ?';
    $params[] = $yearFilter;
}

// Query basierend auf Filter-Typ
if ($typeFilter === 'wins') {
    // Nur Gewinner
    $whereParts[] = 'on1.winner = 1';
    $havingClause = "HAVING COUNT(*) >= $minCount";
    $orderBy = 'win_count DESC, nomination_count DESC, m.year DESC';
} else {
    // Alle Nominierungen
    $havingClause = "HAVING COUNT(*) >= $minCount";
    $orderBy = 'nomination_count DESC, win_count DESC, m.year DESC';
}

// imdb_const muss vorhanden sein
$whereParts[] = 'on1.imdb_const IS NOT NULL';

$whereClause = 'WHERE ' . implode(' AND ', $whereParts);

// Gesamtanzahl ermitteln (für Pagination)
$countSql = "
    SELECT COUNT(*) 
    FROM (
        SELECT on1.imdb_const, COUNT(*) as cnt
        FROM oscar_nominations on1
        $whereClause
        GROUP BY on1.imdb_const
        $havingClause
    ) as subquery
";

if (!empty($params)) {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
} else {
    $stmtCount = $pdo->query($countSql);
}
$total = (int)$stmtCount->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Hauptabfrage
$sql = "
    SELECT 
        m.const,
        m.title,
        m.original_title,
        m.year,
        m.poster_url,
        COUNT(*) as nomination_count,
        SUM(on1.winner) as win_count
    FROM oscar_nominations on1
    INNER JOIN movies m ON m.const COLLATE utf8mb4_unicode_ci = on1.imdb_const COLLATE utf8mb4_unicode_ci
    $whereClause
    GROUP BY on1.imdb_const, m.const, m.title, m.original_title, m.year, m.poster_url
    $havingClause
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";

if (!empty($params)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query($sql);
}
$movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiken
$statsSql = "
    SELECT 
        COUNT(DISTINCT on1.imdb_const) as unique_movies,
        COUNT(*) as total_nominations,
        SUM(on1.winner) as total_wins
    FROM oscar_nominations on1
    $whereClause
";

if (!empty($params)) {
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($params);
} else {
    $statsStmt = $pdo->query($statsSql);
}
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

?>

<div id="best-oscar-module">
    <div class="row">
        <div class="col-md-12">
            <h2>🏆 Beste Oscar-Filme</h2>
            <p class="text-muted">Erfolgreichste Filme nach Oscar-Nominierungen und -Gewinnen</p>
            
            <!-- Statistiken -->
            <div class="info-box mb-4">
                <strong>📊 Statistik:</strong><br>
                • Filme mit Nominierungen: <?php echo number_format($stats['unique_movies'] ?? 0, 0, ',', '.'); ?><br>
                • Gesamte Nominierungen: <?php echo number_format($stats['total_nominations'] ?? 0, 0, ',', '.'); ?><br>
                • Gesamte Gewinne: <?php echo number_format($stats['total_wins'] ?? 0, 0, ',', '.'); ?>
            </div>
            
            <!-- Filter -->
            <form method="GET" class="filter-form mb-4">
                <input type="hidden" name="mod" value="best_oscar_movies">
                
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="type" class="form-label">Anzeigen</label>
                        <select name="type" id="type" class="form-select">
                            <option value="nominations" <?php echo $typeFilter === 'nominations' ? 'selected' : ''; ?>>Nominierungen</option>
                            <option value="wins" <?php echo $typeFilter === 'wins' ? 'selected' : ''; ?>>Nur Gewinne</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="year" class="form-label">Jahr</label>
                        <select name="year" id="year" class="form-select">
                            <option value="">Alle Jahre</option>
                            <?php foreach ($allYears as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" 
                                        <?php echo $yearFilter == $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="min_count" class="form-label">Minimum</label>
                        <input type="number" name="min_count" id="min_count" 
                               class="form-control" value="<?php echo $minCount; ?>" min="1">
                    </div>
                    
                    <div class="col-md-2">
                        <label for="per_page" class="form-label">Pro Seite</label>
                        <select name="per_page" id="per_page" class="form-select">
                            <option value="20" <?php echo $perPage == 20 ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo $perPage == 200 ? 'selected' : ''; ?>>200</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">Filtern</button>
                            <a href="?mod=best_oscar_movies" class="btn btn-secondary">Zurücksetzen</a>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Ergebnisse -->
            <?php if (!empty($movies)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 80px;">Cover</th>
                                <th>Titel</th>
                                <th style="width: 80px;">Jahr</th>
                                <th style="width: 120px;">Nominierungen</th>
                                <th style="width: 100px;">Gewinne</th>
                                <th style="width: 100px;">Quote</th>
                                <th style="width: 150px;">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = $offset + 1;
                            foreach ($movies as $movie): 
                                $posterPath = '';
                                if (!empty($movie['poster_url'])) {
                                    $posterPath = $movie['poster_url'];
                                } elseif (file_exists(__DIR__ . '/../cover/' . $movie['const'] . '.jpg')) {
                                    $posterPath = 'cover/' . $movie['const'] . '.jpg';
                                }
                                
                                $winRate = $movie['nomination_count'] > 0 
                                    ? round(($movie['win_count'] / $movie['nomination_count']) * 100, 1) 
                                    : 0;
                            ?>
                            <tr>
                                <td><?php echo $rank++; ?></td>
                                <td>
                                    <?php if ($posterPath): ?>
                                        <img src="<?php echo htmlspecialchars($posterPath); ?>" 
                                             alt="<?php echo htmlspecialchars($movie['title']); ?>"
                                             style="width: 60px; height: auto; border-radius: 4px;">
                                    <?php else: ?>
                                        <div style="width: 60px; height: 90px; background: #333; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #666;">
                                            <i class="bi bi-film"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($movie['title']); ?></strong>
                                    <?php if ($movie['original_title'] && $movie['original_title'] !== $movie['title']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($movie['original_title']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($movie['year']); ?></td>
                                <td>
                                    <span class="badge bg-primary" style="font-size: 1.1em;">
                                        <?php echo $movie['nomination_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark" style="font-size: 1.1em;">
                                        🏆 <?php echo $movie['win_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $winRate >= 50 ? 'success' : 'secondary'; ?>">
                                        <?php echo $winRate; ?>%
                                    </span>
                                </td>
                                <td>
                                    <a href="?mod=movie&const=<?php echo urlencode($movie['const']); ?>" 
                                       class="btn btn-sm btn-primary" title="Details">
                                        Details
                                    </a>
                                    <a href="https://www.imdb.com/title/<?php echo urlencode($movie['const']); ?>/" 
                                       target="_blank" class="btn btn-sm btn-secondary" title="IMDb">
                                        IMDb
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                    <nav aria-label="Seitennavigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?mod=best_oscar_movies&page=<?php echo $page - 1; ?>&type=<?php echo urlencode($typeFilter); ?>&year=<?php echo urlencode($yearFilter); ?>&min_count=<?php echo $minCount; ?>&per_page=<?php echo $perPage; ?>">
                                        Vorherige
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <li class="page-item disabled">
                                <span class="page-link">Seite <?php echo $page; ?> von <?php echo $pages; ?></span>
                            </li>
                            
                            <?php if ($page < $pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?mod=best_oscar_movies&page=<?php echo $page + 1; ?>&type=<?php echo urlencode($typeFilter); ?>&year=<?php echo urlencode($yearFilter); ?>&min_count=<?php echo $minCount; ?>&per_page=<?php echo $perPage; ?>">
                                        Nächste
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="alert alert-info">
                    Keine Filme gefunden, die den Filterkriterien entsprechen.
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>
