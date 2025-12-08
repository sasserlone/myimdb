<?php

require_once __DIR__ . '/global.inc.php';

// ****************************************************************************

function getConnection() {
    global $host;
    global $dbname;
    global $username;
    global $password;
    static $pdo;
    
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        }
        catch (PDOException $e) {
            die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// ****************************************************************************

?>