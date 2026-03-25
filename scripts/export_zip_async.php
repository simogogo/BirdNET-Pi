<?php
// export_zip_async.php
// Script eseguito in background per comprimere tutte le registrazioni di un giorno

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

if ($argc < 2) {
    die("Usage: php export_zip_async.php YYYY-MM-DD\n");
}

$date = preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1]);
$root = dirname(__FILE__) . '/..';

require_once "{$root}/scripts/common.php";
$config = get_config();

if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
    $extractedDir = rtrim($config['EXTRACTED'], '/');
} else {
    $home = get_home() ?: $root;
    $extractedDir = "{$home}/BirdSongs/Extracted";
}
$zipDir = "{$extractedDir}/eBirdZips";

if (!is_dir($zipDir)) {
    @mkdir($zipDir, 0777, true);
}

$audioDir = "{$extractedDir}/By_Date/{$date}";

$batchId = $argv[2] ?? null;

$statusFile = $batchId ? "{$zipDir}/eBird_Export_{$batchId}.status" : "{$zipDir}/export_{$date}.status";
$zipFileName = $batchId ? "eBird_Export_{$batchId}.zip" : "Daily_Export_{$date}.zip";
$batchFile = $batchId ? "{$zipDir}/batch_{$batchId}.json" : null;

$finalZipPath = "{$zipDir}/{$zipFileName}";

if (!is_dir($audioDir) && (!$batchId || !file_exists($batchFile))) {
    file_put_contents($statusFile, json_encode(['status' => 'error', 'error' => 'Audio directory not found or batch file missing', 'date' => $date, 'timestamp' => time()]));
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    file_put_contents($statusFile, json_encode(['status' => 'error', 'error' => 'Cannot create zip file', 'date' => $date, 'timestamp' => time()]));
    exit(1);
}

$addedFiles = 0;

if ($batchId && file_exists($batchFile)) {
    $filesToZip = json_decode(file_get_contents($batchFile), true);
    if (is_array($filesToZip)) {
        foreach ($filesToZip as $item) {
            $filename = $item['filename'] ?? '';
            $species = $item['species'] ?? 'Unknown_Species';
            $safeSpecies = preg_replace('/[^a-zA-Z0-9_ -]/', '_', $species);

            $sourcePath = "{$audioDir}/{$safeSpecies}/{$filename}";
            if (file_exists($sourcePath)) {
                $zip->addFile($sourcePath, "{$safeSpecies}/{$filename}");
                $addedFiles++;
            } else {
                $flatSourcePath = "{$audioDir}/{$filename}";
                if (file_exists($flatSourcePath)) {
                    $zip->addFile($flatSourcePath, "{$safeSpecies}/{$filename}");
                    $addedFiles++;
                }
            }
        }
    }
} else {
    // Standard full day export logic
    if (is_dir($audioDir)) {
        $speciesDirs = scandir($audioDir);
        foreach ($speciesDirs as $species) {
            if ($species === '.' || $species === '..') continue;
            $speciesPath = "{$audioDir}/{$species}";
            if (is_dir($speciesPath)) {
                $files = scandir($speciesPath);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    if (str_ends_with($file, '.wav') || str_ends_with($file, '.flac') || str_ends_with($file, '.mp3')) {
                        $zip->addFile("{$speciesPath}/{$file}", "{$species}/{$file}");
                        $addedFiles++;
                    }
                }
            }
        }
    }
}

$zip->close();

if ($addedFiles === 0) {
    @unlink($finalZipPath);
    file_put_contents($statusFile, json_encode(['status' => 'error', 'error' => 'No audio files found', 'date' => $date, 'timestamp' => time()]));
} else {
    file_put_contents($statusFile, json_encode(['status' => 'completed', 'filename' => $zipFileName, 'date' => $date, 'timestamp' => time()]));
}

if ($batchFile && file_exists($batchFile)) {
    @unlink($batchFile);
}
