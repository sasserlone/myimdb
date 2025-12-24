<?php
require_once __DIR__ . '/../inc/database.inc.php';

$message = '';
$error = '';
$pdo = getConnection();

// ****************************************************************************
// Backup erstellen
// ****************************************************************************
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    try {
        $backupFile = __DIR__ . '/../db/backup.sql';
        
        // Datenbank-Informationen aus der Verbindung extrahieren
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        
        // Alle Tabellen abrufen
        $tables = [];
        $result = $pdo->query('SHOW TABLES');
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        if (empty($tables)) {
            throw new Exception('Keine Tabellen gefunden.');
        }
        
        // SQL-Datei erstellen
        $sqlContent = "-- MySQL Database Backup\n";
        $sqlContent .= "-- Database: $dbName\n";
        $sqlContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sqlContent .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sqlContent .= "SET AUTOCOMMIT = 0;\n";
        $sqlContent .= "START TRANSACTION;\n";
        $sqlContent .= "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
            // Tabellenerstellung
            $sqlContent .= "-- --------------------------------------------------------\n\n";
            $sqlContent .= "-- Tabellenstruktur für Tabelle `$table`\n\n";
            
            $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $sqlContent .= "DROP TABLE IF EXISTS `$table`;\n";
            $sqlContent .= $createTable['Create Table'] . ";\n\n";
            
            // Daten exportieren
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $sqlContent .= "-- Daten für Tabelle `$table`\n\n";
                
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $values = array_map(function($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return $pdo->quote($value);
                    }, array_values($row));
                    
                    $sqlContent .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (";
                    $sqlContent .= implode(', ', $values);
                    $sqlContent .= ");\n";
                }
                
                $sqlContent .= "\n";
            }
        }
        
        $sqlContent .= "COMMIT;\n";
        
        // Datei schreiben
        $written = file_put_contents($backupFile, $sqlContent);
        
        if ($written === false) {
            throw new Exception('Backup-Datei konnte nicht geschrieben werden.');
        }
        
        $fileSize = filesize($backupFile);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        
        // SQL-Datei komprimieren
        $backupFileGz = $backupFile . '.gz';
        $gz = gzopen($backupFileGz, 'w9'); // 9 = maximale Kompression
        
        if ($gz === false) {
            throw new Exception('Komprimierte Backup-Datei konnte nicht erstellt werden.');
        }
        
        gzwrite($gz, $sqlContent);
        gzclose($gz);
        
        $compressedSize = filesize($backupFileGz);
        $compressedSizeMB = round($compressedSize / 1024 / 1024, 2);
        $compressionRatio = round((1 - ($compressedSize / $fileSize)) * 100, 1);
        
        // Unkomprimierte SQL-Datei löschen (optional - auskommentieren wenn beide behalten werden sollen)
        unlink($backupFile);
        
        $message = "✓ Backup erfolgreich erstellt und komprimiert!<br>";
        $message .= "• Datei: db/backup.sql.gz<br>";
        $message .= "• Originalgröße: $fileSizeMB MB<br>";
        $message .= "• Komprimiert: $compressedSizeMB MB<br>";
        $message .= "• Kompression: $compressionRatio%<br>";
        $message .= "• Tabellen: " . count($tables) . "<br>";
        $message .= "• Zeit: " . date('d.m.Y H:i:s');
        
    } catch (Exception $e) {
        $error = 'Backup fehlgeschlagen: ' . $e->getMessage();
    }
}

// ****************************************************************************
// Backup-Info laden (falls vorhanden)
// ****************************************************************************
$backupFile = __DIR__ . '/../db/backup.sql.gz';
$backupExists = file_exists($backupFile);
$backupInfo = null;

if ($backupExists) {
    $backupInfo = [
        'size' => filesize($backupFile),
        'sizeMB' => round(filesize($backupFile) / 1024 / 1024, 2),
        'date' => date('d.m.Y H:i:s', filemtime($backupFile))
    ];
}

// Datenbank-Statistiken
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tableCount = $pdo->query('SHOW TABLES')->rowCount();
    
    // Datenbank-Größe ermitteln
    $sizeResult = $pdo->query("
        SELECT SUM(data_length + index_length) as size 
        FROM information_schema.TABLES 
        WHERE table_schema = '$dbName'
    ")->fetch(PDO::FETCH_ASSOC);
    
    $dbSize = $sizeResult['size'] ?? 0;
    $dbSizeMB = round($dbSize / 1024 / 1024, 2);
    
} catch (Exception $e) {
    $dbName = 'Unbekannt';
    $tableCount = 0;
    $dbSizeMB = 0;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>

<div class="row">
    <div class="col-md-8">
        <h2>💾 Datenbank-Backup</h2>
        <p class="text-muted">Erstelle ein vollständiges Backup der Datenbank</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Datenbank-Informationen -->
        <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <h5 class="card-title">📊 Datenbank-Informationen</h5>
                <div class="mt-3">
                    <div class="mb-2"><strong>Datenbank:</strong> <?php echo h($dbName); ?></div>
                    <div class="mb-2"><strong>Tabellen:</strong> <?php echo $tableCount; ?></div>
                    <div class="mb-2"><strong>Größe:</strong> <?php echo $dbSizeMB; ?> MB</div>
                </div>
            </div>
        </div>

        <!-- Aktuelles Backup -->
        <?php if ($backupExists): ?>
            <div class="card mb-4" style="background: var(--card-bg); border-color: var(--table-border);">
                <div class="card-body">
                    <h5 class="card-title">📁 Aktuelles Backup</h5>
                    <div class="mt-3">
                        <div class="mb-2"><strong>Datei:</strong> db/backup.sql.gz</div>
                        <div class="mb-2"><strong>Größe:</strong> <?php echo $backupInfo['sizeMB']; ?> MB (komprimiert)</div>
                        <div class="mb-2"><strong>Erstellt:</strong> <?php echo $backupInfo['date']; ?></div>
                        <div class="mt-3">
                            <a href="./db/backup.sql.gz" class="btn btn-sm btn-outline-primary" download>⬇️ Backup herunterladen</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <strong>ℹ️ Hinweis:</strong> Es existiert noch kein Backup. Erstelle jetzt dein erstes Backup.
            </div>
        <?php endif; ?>

        <!-- Backup erstellen -->
        <div class="card" style="background: var(--card-bg); border-color: var(--table-border);">
            <div class="card-body">
                <h5 class="card-title">🔧 Backup erstellen</h5>
                
                <div class="alert alert-warning mt-3">
                    <strong>⚠️ Wichtig:</strong><br>
                    • Das Backup überschreibt die bestehende backup.sql.gz Datei<br>
                    • Die Datei wird automatisch mit gzip komprimiert<br>
                    • Große Datenbanken können einige Zeit benötigen<br>
                    • Die Datei wird im Verzeichnis <code>db/</code> gespeichert
                </div>

                <form method="POST" onsubmit="return confirm('Backup jetzt erstellen? Die bestehende backup.sql wird überschrieben.');">
                    <button type="submit" name="create_backup" class="btn btn-primary">
                        💾 Backup jetzt erstellen
                    </button>
                    <a href="?mod=import" class="btn btn-secondary ms-2">
                        📦 Zu Import/Export
                    </a>
                </form>
            </div>
        </div>

    </div>
</div>
