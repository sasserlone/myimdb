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
                        <a class="nav-link <?=($mod == 'movies') ? 'active' : '';?>" href="?mod=movies">Filme</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?=($mod == 'series') ? 'active' : '';?>" href="?mod=series">Serien</a>
                    </li>
            
                    <!-- Dropdown Menü für Imports -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?=($mod == 'import_ratings' || $mod == 'import_movies') ? 'active' : '';?>" href="#" id="importDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Import
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="importDropdown">
                            <li><a class="dropdown-item" href="?mod=import_ratings">Ratings</a></li>
                            <li><a class="dropdown-item" href="?mod=import">Import</a></li>
                            <li><a class="dropdown-item" href="?mod=import_movies">Filme</a></li>
                            <li><a class="dropdown-item" href="?mod=import_episodes">Episoden</a></li>
                        </ul>
                    </li>
                    
                </ul>
                <div class="d-flex">
                    <button id="toggle-mode" class="btn btn-outline-secondary btn-sm ms-2" title="Dark/Light Mode" aria-label="Theme wechseln"><span class="toggle-icon"></span></button>
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
        <!-- Floating theme toggle (optional) -->
        <button id="themeToggle" class="theme-toggle" aria-label="Theme wechseln"><span class="toggle-icon"></span></button>

        <script>
        (function(){
            const key = 'movies-theme';
            const navBtn = document.getElementById('toggle-mode');
            const floatBtn = document.getElementById('themeToggle');
            const iconTpl = {
                sun: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>',
                moon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path></svg>'
            };

            let theme = localStorage.getItem(key);
            if(!theme) {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            function setIconFor(el, t){
                if(!el) return;
                el.innerHTML = (t === 'dark') ? iconTpl.sun : iconTpl.moon;
            }

            function applyTheme(t){
                if(t === 'dark'){
                    document.body.classList.add('theme-dark');
                    document.body.classList.remove('theme-light');
                } else {
                    document.body.classList.remove('theme-dark');
                    document.body.classList.add('theme-light');
                }
                setIconFor(navBtn ? navBtn.querySelector('.toggle-icon') : null, t);
                setIconFor(floatBtn ? floatBtn.querySelector('.toggle-icon') : null, t);
            }

            applyTheme(theme);

            function toggleAndStore(){
                theme = (theme === 'dark') ? 'light' : 'dark';
                localStorage.setItem(key, theme);
                applyTheme(theme);
            }

            if(navBtn){ navBtn.addEventListener('click', toggleAndStore); }
            if(floatBtn){ floatBtn.addEventListener('click', toggleAndStore); }
        })();
        </script>
</body>
</html>
</body>
</html>