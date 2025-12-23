<?php

function loadModule($mod)
{
    switch ($mod) {
        case 'home':
            require_once 'mod/home.php';
            break;
        case 'import_imdb':
            require_once 'mod/import_imdb.php';
            break;
        case 'import_movies':
            require_once 'mod/import_movies.php';
            break;
        case 'import_oscars':
            require_once 'mod/import_oscars.php';
            break;
        case 'import_golden_globes':
            require_once 'mod/import_golden_globes.php';
            break;
        case 'data_golden_globes':
            require_once 'mod/data_golden_globes.php';
            break;
        case 'data_backup':
            require_once 'mod/data_backup.php';
            break;
        case 'oscars':
            require_once 'mod/oscars.php';
            break;
        case 'oscars':
            require_once 'mod/oscars.php';
            break;
        case 'golden_globes':
            require_once 'mod/golden_globes.php';
            break;
        case 'movies':
            require_once 'mod/movies.php';
            break;
        case 'series':
            require_once 'mod/series.php';
            break;
        case 'serie':
            require_once 'mod/serie.php';
            break;
        case 'movie':
            require_once 'mod/movie.php';
            break;
        case 'movies_actor':
            require_once 'mod/movies_actor.php';
            break;
        case 'import_omdb':
            require_once 'mod/import_omdb.php';
            break;
      default:
            require_once 'mod/home.php';
    }
}

?>