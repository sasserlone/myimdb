<?php

function loadModule($mod)
{
    switch ($mod) {
        case 'home':
            require_once 'mod/home.php';
            break;
        case 'import_ratings':
            require_once 'mod/import_ratings.php';
            break;
        case 'import_movies':
            require_once 'mod/import_movies.php';
            break;
      default:
            require_once 'mod/home.php';
    }
}

?>