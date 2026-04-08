<?php
/**
 * backup_async.php
 * Background worker to generate a complete system backup archive.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

$root = dirname(__FILE__) . '/..';
require_once "{$root}/scripts/common.php";

$config = get_config();
$user = get_user();
$home = get_home();

// Determine backup directory
$recsDir = $config['RECS_DIR'] ?? "{$home}/BirdSongs";
$backupDir = rtrim($recsDir, '/') . "/Backups";

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$timestamp = date("Ymd_His");
$filename = "backup_{$timestamp}.tar";
$finalPath = "{$backupDir}/{$filename}";
$statusFile = "{$backupDir}/{$filename}.status";

// Mark as processing
file_put_contents($statusFile, json_encode([
    'status' => 'processing',
    'filename' => $filename,
    'timestamp' => time(),
    'pid' => getmypid()
]));
@chmod($statusFile, 0644);

// Execute the existing backup script
// Since backup_data.sh restarts services, we use nohup to ensure we don't kill ourselves if caddy restarts
$cmd = "sudo -u $user $home/BirdNET-Pi/scripts/backup_data.sh -a backup -f " . escapeshellarg($finalPath) . " 2>&1";
$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

if ($return_var === 0 && file_exists($finalPath)) {
    @chmod($finalPath, 0644);
    file_put_contents($statusFile, json_encode([
        'status' => 'completed',
        'filename' => $filename,
        'size' => filesize($finalPath),
        'timestamp' => time()
    ]));
    @chmod($statusFile, 0644);
} else {
    // If it failed, we still have the status file to report the error
    file_put_contents($statusFile, json_encode([
        'status' => 'error',
        'filename' => $filename,
        'error' => implode("\n", $output),
        'timestamp' => time()
    ]));
    @chmod($statusFile, 0644);
}
