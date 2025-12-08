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
    <title>Meine Webanwendung</title>
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
            <a class="navbar-brand" href="#">Training Tracker</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'home') ? 'active' : '';?>" href="?mod=home">Home</a>
                    </li>
                    
                    <!-- Dropdown Menü für Aktivitäten -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'records' || $mod == 'volumes') ? 'active' : '';?>" href="#" id="rekordeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Aktivitäten
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="rekordeDropdown">
                        	<li><a class="dropdown-item" href="?mod=trainings">Trainings</a></li>
                            <li><a class="dropdown-item" href="?mod=recordsactivity">Rekorde</a></li>
                            <li><a class="dropdown-item" href="?mod=recordsvolume">Zeiträume</a></li>
                            <li><a class="dropdown-item" href="?mod=fastestintervals">schnellste Distanzen</a></li>
                            <li><a class="dropdown-item" href="?mod=raceresults">Wettkämpfe</a></li>
                        </ul>
                    </li>
                    
                    <!-- Dropdown Menü für Fitness -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'fitness' || $mod == 'runprognose') ? 'active' : '';?>" href="#" id="fitnessDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Fitness
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="streaksDropdown">
                            <li><a class="dropdown-item" href="?mod=fitness">Daten</a></li>
                            <li><a class="dropdown-item" href="?mod=runprognose">Laufprognose</a></li>
                            <li><a class="dropdown-item" href="?mod=segments">Segmente</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'timechart') ? 'active' : '';?>" href="?mod=timechart">Trainingsumfänge</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'goals') ? 'active' : '';?>" href="?mod=goals">Trainingsziele</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'import') ? 'active' : '';?>" href="?mod=import">Import</a>
                    </li>
            
                    <!-- Dropdown Menü für Streaks -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'streaksrun' || $mod == 'streakssteps') ? 'active' : '';?>" href="#" id="streaksDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Streaks
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="streaksDropdown">
                            <li><a class="dropdown-item" href="?mod=streaksrun">Laufen</a></li>
                            <li><a class="dropdown-item" href="?mod=streakssteps">Schritte</a></li>
                        </ul>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'dataimport' || $mod == 'datamaintanance') ? 'active' : '';?>" href="#" id="dataDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Daten
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="dataDropdown">
                            <li><a class="dropdown-item" href="?mod=datafitness">Fitness</a></li>
                            <li><a class="dropdown-item" href="?mod=datamaintance">Datenpflege</a></li>
                            <li><a class="dropdown-item" href="?mod=dataimport">Import</a></li>
                            <li><a class="dropdown-item" href="?mod=datasegments">Segmente</a></li>
                            <li><a class="dropdown-item" href="?mod=datafastestintervals">schnellste Intervalle</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'summary') ? 'active' : '';?>" href="?mod=summary">Übersicht</a>
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