<?php
/**
 * backup_async.php
 * Background worker to generate a complete system backup archive.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Become process group leader to allow killing entire process tree
if (function_exists('posix_setpgid')) {
    posix_setpgid(0, 0);
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
    shell_exec("sudo -u $user mkdir -p " . escapeshellarg($backupDir));
    shell_exec("sudo -u $user chmod 777 " . escapeshellarg($backupDir));
}

/**
 * Funzione helper per scrivere lo stato usando sudo per evitare problemi di permessi
 */
function update_status($path, $data, $user) {
    $json = json_encode($data);
    $cmd = "echo " . escapeshellarg($json) . " | sudo -u " . escapeshellarg($user) . " tee " . escapeshellarg($path) . " > /dev/null";
    shell_exec($cmd);
}

$timestamp = date("Ymd_His");
$filename = "backup_{$timestamp}.tar";
$finalPath = "{$backupDir}/{$filename}";
$statusFile = "{$backupDir}/{$filename}.status";

// Mark as processing
update_status($statusFile, [
    'status' => 'processing',
    'filename' => $filename,
    'timestamp' => time(),
    'pid' => getmypid()
], $user);

// Execute the existing backup script
// Since backup_data.sh restarts services, we use nohup to ensure we don't kill ourselves if caddy restarts
$cmd = "sudo -u $user $home/BirdNET-Pi/scripts/backup_data.sh -a backup -f " . escapeshellarg($finalPath) . " 2>&1";
$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

if ($return_var === 0 && file_exists($finalPath)) {
    @chmod($finalPath, 0644);
    update_status($statusFile, [
        'status' => 'completed',
        'filename' => $filename,
        'size' => filesize($finalPath),
        'timestamp' => time()
    ], $user);
} else {
    // If it failed, we still have the status file to report the error
    update_status($statusFile, [
        'status' => 'error',
        'filename' => $filename,
        'error' => implode("\n", $output),
        'timestamp' => time()
    ], $user);
}
