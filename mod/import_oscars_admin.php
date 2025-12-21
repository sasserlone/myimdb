<?php
/**
 * Oscar Import Admin Interface
 * Management und Monitoring von Oscar-Daten
 */

require_once __DIR__ . '/../inc/database.inc.php';

// HTML-Escape Helper
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = getConnection();
$action = $_GET['action'] ?? '';

// Statistiken laden
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN oscar_winner = 1 THEN 1 ELSE 0 END) as winners,
        SUM(CASE WHEN oscar_nominations > 0 THEN 1 ELSE 0 END) as nominated,
        AVG(CASE WHEN oscar_nominations > 0 THEN oscar_nominations ELSE NULL END) as avg_noms
    FROM movies
    WHERE oscar_winner = 1 OR oscar_nominations > 0
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Oscar-Gewinner nach Jahr
$byYearStmt = $pdo->query("
    SELECT oscar_year, COUNT(*) as count
    FROM movies
    WHERE oscar_winner = 1
    GROUP BY oscar_year
    ORDER BY oscar_year DESC
    LIMIT 10
");
$byYear = $byYearStmt->fetchAll(PDO::FETCH_ASSOC);

// Top-Oscar-Kategorien
$categoriesStmt = $pdo->query("
    SELECT oscar_category, COUNT(*) as count
    FROM movies
    WHERE oscar_category IS NOT NULL
    GROUP BY oscar_category
    ORDER BY count DESC
    LIMIT 10
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Oscar-Import Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #333333;
            --card-bg: #f8f9fa;
            --border-color: #dee2e6;
        }
        
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-color: #1e1e1e;
                --text-color: #e0e0e0;
                --card-bg: #2d2d2d;
                --border-color: #404040;
            }
        }
        
        body {
            background: var(--bg-color);
            color: var(--text-color);
        }
        
        .card {
            background: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ffc107;
        }
        
        .stat-label {
            color: var(--text-color);
            margin-top: 5px;
        }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <h1 class="mb-4">🏆 Oscar-Import Admin</h1>
    
    <!-- Status Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Filme mit Oscar-Daten</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['winners'] ?? 0; ?></div>
                <div class="stat-label">Oscar-Gewinner</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['nominated'] ?? 0; ?></div>
                <div class="stat-label">Nominierte Filme</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?php echo round($stats['avg_noms'] ?? 0, 1); ?></div>
                <div class="stat-label">Ø Nominierungen</div>
            </div>
        </div>
    </div>
    
    <!-- Import Controls -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Import-Kontrolle</h5>
                </div>
                <div class="card-body">
                    <div class="btn-group" role="group">
                        <a href="import_oscars.php?mode=initial&limit=1000&verbose=1" 
                           class="btn btn-primary" target="_blank">
                            ▶ Neue Daten importieren
                        </a>
                        <a href="import_oscars.php?mode=refresh&limit=1000&verbose=1" 
                           class="btn btn-warning" target="_blank">
                            🔄 Aktualisieren
                        </a>
                        <a href="import_oscars.php?stats=1" 
                           class="btn btn-info" target="_blank">
                            📊 Statistiken anzeigen
                        </a>
                    </div>
                    <p class="text-muted mt-3 mb-0">
                        <small>Import findet in neuem Tab statt. Seite mit F5 aktualisieren um neue Daten zu sehen.</small>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Awards by Year -->
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Oscar-Gewinner pro Jahr</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Jahr</th>
                                    <th class="text-end">Anzahl</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byYear as $row): ?>
                                <tr>
                                    <td><?php echo $row['oscar_year'] ?? '-'; ?></td>
                                    <td class="text-end"><strong><?php echo $row['count']; ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($byYear)): ?>
                                <tr><td colspan="2" class="text-center text-muted">Keine Daten</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Categories -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Top Oscar-Kategorien</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Kategorie</th>
                                    <th class="text-end">Einträge</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $row): ?>
                                <tr>
                                    <td><?php echo h($row['oscar_category']); ?></td>
                                    <td class="text-end"><strong><?php echo $row['count']; ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($categories)): ?>
                                <tr><td colspan="2" class="text-center text-muted">Keine Daten</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Winners -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Neueste Oscar-Gewinner</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Titel</th>
                                    <th>Const</th>
                                    <th>Jahr</th>
                                    <th>Kategorie</th>
                                    <th class="text-center">Nominierungen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $recentStmt = $pdo->query("
                                    SELECT title, const, oscar_year, oscar_category, oscar_nominations
                                    FROM movies
                                    WHERE oscar_winner = 1
                                    ORDER BY oscar_year DESC
                                    LIMIT 15
                                ");
                                $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($recent as $movie):
                                ?>
                                <tr>
                                    <td><?php echo h($movie['title']); ?></td>
                                    <td><code><?php echo h($movie['const']); ?></code></td>
                                    <td><?php echo $movie['oscar_year']; ?></td>
                                    <td><?php echo h($movie['oscar_category']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?php echo $movie['oscar_nominations']; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
