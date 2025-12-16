<?php
// Fuehrt die drei IMDb-Importe sequentiell aus und schreibt eine Logdatei
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../inc/database.inc.php';

// Argumente parsen
$episodes = null; $principals = null; $names = null; $log = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--episodes=') === 0) $episodes = substr($arg, 11);
    if (strpos($arg, '--principals=') === 0) $principals = substr($arg, 13);
    if (strpos($arg, '--names=') === 0) $names = substr($arg, 8);
    if (strpos($arg, '--log=') === 0) $log = substr($arg, 6);
}

if (!$episodes || !$principals || !$names || !$log) {
    fwrite(STDERR, "Fehlende Argumente. Erwartet: --episodes= --principals= --names= --log=\n");
    exit(2);
}

$phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

function run_step($label, $cmd, $log) {
    $header = "\n==============================\n[" . date('Y-m-d H:i:s') . "] START: $label\n==============================\n";
    file_put_contents($log, $header, FILE_APPEND);
    $exit = 0; $out = [];
    exec($cmd . ' 2>&1', $out, $exit);
    file_put_contents($log, implode("\n", $out) . "\n", FILE_APPEND);
    $footer = "[" . date('Y-m-d H:i:s') . "] ENDE: $label (Exitcode $exit)\n";
    file_put_contents($log, $footer, FILE_APPEND);
    return $exit;
}

$scriptsDir = __DIR__;
$cmdEpisodes   = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptsDir . '/import_episodes.php')   . ' ' . escapeshellarg($episodes);
$cmdPrincipals = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptsDir . '/import_principals.php') . ' ' . escapeshellarg($principals);
$cmdNames      = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptsDir . '/import_names.php')      . ' ' . escapeshellarg($names);

$startMsg = '[' . date('Y-m-d H:i:s') . "] Pipeline gestartet\n";
file_put_contents($log, $startMsg, FILE_APPEND);

if (run_step('Episoden', $cmdEpisodes, $log) !== 0) exit(10);
if (run_step('Principals', $cmdPrincipals, $log) !== 0) exit(11);
if (run_step('Namen', $cmdNames, $log) !== 0) exit(12);

$endMsg = '[' . date('Y-m-d H:i:s') . "] Pipeline abgeschlossen\n";
file_put_contents($log, $endMsg, FILE_APPEND);
exit(0);
