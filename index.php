<?php
ini_set('display_errors', true);
error_reporting(E_ALL);
require_once __DIR__ . '/inc/database.inc.php';
require_once __DIR__ . '/mod/module.php';

$mod = isset($_GET['mod']) ? $_GET['mod'] : 'home';

header('Content-Type: text/html; charset=utf-8');

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyIMDb</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://kit.fontawesome.com/a076d05399.js"></script> <!-- Font Awesome für Icons -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="./styles.css?v=1.1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">MyIMDb</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'home') ? 'active' : '';?>" href="?mod=home">Home</a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'import') ? 'active' : '';?>" href="?mod=import">Import</a>
                    </li>
            
                    <!-- Dropdown Menü für Imports -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'import_ratings' || $mod == 'import_movies') ? 'active' : '';?>" href="#" id="importDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Import
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="importDropdown">
                            <li><a class="dropdown-item" href="?mod=import_ratings">Ratings</a></li>
                            <li><a class="dropdown-item" href="?mod=import_movies">Filme</a></li>
                        </ul>
                    </li>
                    
                </ul>
                <div class="d-flex">
                    <button id="toggle-mode" class="btn btn-outline-secondary btn-sm ms-2" title="Dark/Light Mode">🌙</button>
                </div>
            </div>

        </div>
    </nav>

    <!-- Hauptinhalt -->
    <div class="container mt-5">
    	<?php
            loadModule($mod);
        ?>
    </div>
 </body>
</html>