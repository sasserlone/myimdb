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

function getStreakData($date1, $date2) {
    getConnection()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = getConnection()->prepare("select tr.*, ty.name as type from runalyze_training tr, runalyze_type ty
    where tr.typeid=ty.id and tr.sportid = 1 and date(from_unixtime(time)) between :date1 and :date2
    order by time asc");
    
    $stmt->execute([':date1' => $date1, ':date2' => $date2]);
    return  $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ****************************************************************************

function getFirstTrainingYear ($sportid=0) {
    $sql = "select year(from_unixtime(min(time))) as year from runalyze_training";
    if ($sportid>0) {
        $sql .= " where id=$sportid";
    }
    $stmt = getConnection()->query($sql);
    
    return $stmt->fetch(PDO::FETCH_ASSOC)['year'];
}

// ****************************************************************************

function getSports($maxid=100) {
    $pdo = getConnection();
    $stmt = $pdo->query("select id, name, distances from runalyze_sport where id < $maxid order by order_by");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ****************************************************************************

?>