<?php

function loadModule($mod)
{
    switch ($mod) {
        case 'home':
            require_once 'mod/home.php';
            break;
        case 'import':
            require_once 'mod/import.php';
            break;
      default:
            require_once 'mod/home.php';
    }
}

?>