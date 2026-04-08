<?php
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
/**
 * BirdNET-Pi REST API v2
 * 
 * API completa per l'app Flutter BirdNET-Pi.
 * Fornisce endpoints JSON per tutte le funzionalità del frontend PHP.
 * 
 * Routing: /api/v2/{resource}[/{id}][/{action}]
 */

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__DIR__));
}
require_once(__ROOT__ . '/scripts/common.php');

//  CORS & Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

//  JSON helpers
function json_success($data, $code = 200)
{
    http_response_code($code);
    if (ob_get_length())
        ob_clean();
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $code = 400)
{
    http_response_code($code);
    if (ob_get_length())
        ob_clean();
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_auth()
{
    if (!is_authenticated()) {
        json_error('Autenticazione richiesta', 401);
    }
}

function get_json_body()
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function get_db_rw()
{
    $db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(2000);
    return $db;
}

//  Router
$config = get_config();
set_timezone();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Strip /api/v2 prefix
$path = preg_replace('#^/api/v2/?#', '', $requestUri);
$segments = array_values(array_filter(explode('/', $path)));

$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$action = $segments[2] ?? null;

// Optimization: release the session file lock for all non-writing endpoints.
// This significantly speeds up concurrent requests from the frontend.
if (!in_array($resource, ['auth', 'config'])) {
    session_write_close();
}

try {
    switch ($resource) {
        case 'overview':
            handle_overview();
            break;
        case 'detections':
            handle_detections($method, $id);
            break;
        case 'species':
            handle_species($method, $id, $action);
            break;
        case 'speciesbyperiod':
            handle_species_by_period($method);
            break;
        case 'recordings':
            handle_recordings($method, $id, $action);
            break;
        case 'charts':
            handle_charts($id);
            break;
        case 'report':
            handle_report($id);
            break;
        case 'chart':
            $chartPath = substr($path, strlen('chart/'));
            handle_serve_chart($chartPath);
            break;
        case 'backup-file':
            $filename = substr($path, strlen('backup-file/'));
            handle_backup_file($method, $filename);
            break;
        case 'media':
            $mediaPath = substr($path, strlen('media/'));
            handle_serve_media($mediaPath);
            break;
        case 'config':
            handle_config($method, $id);
            break;
        case 'recordinglength':
            handle_recording_length($method);
            break;
        case 'database-lang':
            handle_database_lang();
            break;
        case 'services':
            handle_services($method, $id);
            break;
        case 'system':
            handle_system($method, $id, $action);
            break;
        case 'species-lists':
            handle_species_lists($method, $id);
            break;
        case 'image':
            handle_image($id);
            break;
        case 'stream':
            if ($id === 'detections') {
                handle_stream_detections();
            } else {
                handle_stream_info();
            }
            break;
        case 'ebird':
            handle_ebird($method, $id);
            break;
        case 'export':
            handle_export($method, $id);
            break;
        case 'auth':
            handle_auth($method, $id);
            break;
        case 'logs':
            handle_logs();
            break;
        case 'ping':

            json_success(['pong' => true, 'version' => '2.0', 'timestamp' => date('c')]);
            break;
        default:
            json_error('Endpoint non trovato: /api/v2/' . $resource, 404);
    }
} catch (Exception $e) {
    json_error('Errore interno: ' . $e->getMessage(), 500);
}

//  AUTH
function handle_auth($method, $action)
{
    if ($action === 'login' && $method === 'POST') {
        $body = get_json_body();
        $username = trim($body['username'] ?? '');
        $password = trim($body['password'] ?? '');

        if (empty($username)) {
            json_error('Username richiesto', 400);
        }

        $config = get_config();
        $validUser = 'birdnet';
        $validPass = $config['CADDY_PWD'] ?? '';

        // Se CADDY_PWD non e' impostata (prima installazione), basta lo username corretto
        if ($username === $validUser && ($validPass === '' || $password === $validPass)) {
            $_SESSION['my_authenticated'] = true;
            json_success(['authenticated' => true]);
        } else {
            json_error('Credenziali non valide', 401);
        }
    }

    json_error('Endpoint auth non valido', 404);
}

//  ENDPOINT HANDLERS

//  OVERVIEW
function handle_overview()
{
    $summary = get_summary();
    $db = get_db();

    // Most recent detection
    $stmt = $db->prepare("SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name 
                          FROM detections ORDER BY Date DESC, Time DESC LIMIT 1");
    ensure_db_ok($stmt);
    $result = $stmt->execute();
    $latest = $result->fetchArray(SQLITE3_ASSOC);

    // Top species today
    $stmt2 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count 
                           FROM detections 
                           WHERE Date = DATE('now', 'localtime')
                           GROUP BY Sci_Name 
                           ORDER BY count DESC LIMIT 10");
    ensure_db_ok($stmt2);
    $result2 = $stmt2->execute();
    $topSpecies = [];
    while ($row = $result2->fetchArray(SQLITE3_ASSOC)) {
        $topSpecies[] = $row;
    }

    json_success([
        'total_detections' => (int) $summary['totalcount'],
        'today_detections' => (int) $summary['todaycount'],
        'hour_detections' => (int) $summary['hourcount'],
        'today_species' => (int) $summary['speciestally'],
        'total_species' => (int) $summary['totalspeciestally'],
        'most_recent' => $latest ?: null,
        'top_species_today' => $topSpecies,
    ]);
}

//  DETECTIONS
function handle_detections($method, $id)
{
    if ($method !== 'GET')
        json_error('Metodo non supportato', 405);

    $db = get_db();
    $home = get_home();
    $date = $_GET['date'] ?? date('Y-m-d');
    $species = $_GET['species'] ?? null;
    $min_confidence = floatval($_GET['min_confidence'] ?? 0);
    $limit = intval($_GET['limit'] ?? 500);
    $offset = intval($_GET['offset'] ?? 0);

    // Unified logic for standard and 'recent' detections
    $where = [];
    $params = [];

    if ($id !== 'recent') {
        $where[] = "Date = :date";
        $params[':date'] = $date;
    }

    if ($species) {
        $where[] = "Sci_Name = :species";
        $params[':species'] = $species;
    }
    if ($min_confidence > 0) {
        $where[] = "Confidence >= :minconf";
        $params[':minconf'] = $min_confidence;
    }

    $whereStr = count($where) > 0 ? implode(' AND ', $where) : '1=1';
    $sql = "SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name, Lat, Lon
            FROM detections WHERE $whereStr 
            ORDER BY Date DESC, Time DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    ensure_db_ok($stmt);

    $excludeFile = rtrim($home, '/') . "/BirdNET-Pi/scripts/disk_check_exclude.txt";
    $disk_check_exclude_arr = [];
    if (file_exists($excludeFile)) {
        $fp = @fopen($excludeFile, 'r');
        if ($fp) {
            $disk_check_exclude_arr = explode("\n", fread($fp, filesize($excludeFile)));
            fclose($fp);
        }
    }

    $result = $stmt->execute();
    $detections = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['Confidence'] = floatval($row['Confidence']);
        $comName = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
        $fileRelPath = "{$row['Date']}/{$comName}/{$row['File_Name']}";
        $row['is_locked'] = in_array($fileRelPath, $disk_check_exclude_arr);
        $detections[] = $row;
    }

    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM detections WHERE $whereStr");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countResult = $countStmt->execute();
    $total = $countResult->fetchArray(SQLITE3_ASSOC)['total'];

    json_success([
        'date' => $date,
        'detections' => $detections,
        'total' => (int) $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

//  SPECIES
function handle_species($method, $id, $action)
{
    if ($method !== 'GET')
        json_error('Metodo non supportato', 405);

    $db = get_db();

    // Detail for specific species
    if ($id) {
        if ($action === 'trends') {
            $start = $_GET['start_date'] ?? null;
            $end = $_GET['end_date'] ?? null;
            handle_trends($method, urldecode($id), $start, $end);
            return;
        }
        $sci_name = urldecode($id);

        // Aggregated info
        $stmt = $db->prepare("SELECT Com_Name, Sci_Name, 
                              COUNT(*) as detection_count,
                              MAX(Confidence) as max_confidence,
                              MIN(Date) as first_seen,
                              MAX(Date) as last_seen,
                              AVG(Confidence) as avg_confidence
                              FROM detections WHERE Sci_Name = :name");
        $stmt->bindValue(':name', $sci_name);
        ensure_db_ok($stmt);
        $info = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$info || !$info['Com_Name']) {
            json_error('Specie non trovata', 404);
        }

        $info['detection_count'] = (int) $info['detection_count'];
        $info['max_confidence'] = floatval($info['max_confidence']);
        $info['avg_confidence'] = floatval($info['avg_confidence']);

        // Best detection
        $stmt2 = $db->prepare("SELECT Date, Time, File_Name, Confidence, Com_Name, Sci_Name 
                               FROM detections WHERE Sci_Name = :name 
                               ORDER BY Confidence DESC LIMIT 1");
        $stmt2->bindValue(':name', $sci_name);
        $best = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
        if ($best)
            $best['Confidence'] = floatval($best['Confidence']);
        $info['best_detection'] = $best;

        // Daily trend (last 30 days)
        $stmt3 = $db->prepare("SELECT Date, COUNT(*) as count 
                               FROM detections 
                               WHERE Sci_Name = :name AND Date >= DATE('now', '-30 days', 'localtime')
                               GROUP BY Date ORDER BY Date ASC");
        $stmt3->bindValue(':name', $sci_name);
        $trend = [];
        $r3 = $stmt3->execute();
        while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
            $trend[] = ['date' => $row['Date'], 'count' => (int) $row['count']];
        }
        $info['daily_trend'] = $trend;

        // Image
        $config = get_config();
        try {
            if ($config["IMAGE_PROVIDER"] === 'NONE' || empty($config["IMAGE_PROVIDER"])) {
                $info['image'] = null;
            } else {
                if ($config["IMAGE_PROVIDER"] === 'FLICKR') {
                    $provider = new Flickr();
                } else {
                    $provider = new Wikipedia();
                }
                $image = $provider->get_image($sci_name);
                $info['image'] = $image ?: null;
            }
        } catch (Exception $e) {
            $info['image'] = null;
        }

        // External Link Info
        $infoUrlData = get_info_url($sci_name);
        $info['info_url'] = $infoUrlData['URL'];
        $info['info_title'] = $infoUrlData['TITLE'];

        json_success($info);
    }

    // List all species
    $sort = $_GET['sort'] ?? 'occurrences';
    $date = $_GET['date'] ?? null;
    $result = fetch_species_array($sort, $date);

    $species = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $species[] = [
            'Com_Name' => $row['Com_Name'],
            'Sci_Name' => $row['Sci_Name'],
            'Count' => (int) $row['Count'],
            'MaxConfidence' => floatval($row['MaxConfidence']),
            'Date' => $row['Date'],
            'Time' => $row['Time'],
            'File_Name' => $row['File_Name'],
        ];
    }

    json_success([
        'species' => $species,
        'total' => count($species),
        'sort' => $sort,
        'date' => $date,
    ]);
}

//  SPECIES BY PERIOD
function handle_species_by_period($method)
{
    if ($method !== 'GET') {
        json_error('Metodo non supportato', 405);
    }

    $db = get_db();

    $from_date = $_GET['from_date'] ?? null;
    $to_date = $_GET['to_date'] ?? null;
    $from_time = $_GET['from_time'] ?? null;
    $to_time = $_GET['to_time'] ?? null;
    $sort = $_GET['sort'] ?? 'occurrences';

    $where = [];
    $params = [];

    if ($from_date) {
        $where[] = "Date >= :from_date";
        $params[':from_date'] = $from_date;
    }
    if ($to_date) {
        $where[] = "Date <= :to_date";
        $params[':to_date'] = $to_date;
    }
    if ($from_time && $to_time) {
        // Cross-midnight check: if from_time > to_time the range spans midnight
        if ($from_time > $to_time) {
            $where[] = "(Time >= :from_time OR Time <= :to_time)";
        } else {
            $where[] = "(Time >= :from_time AND Time <= :to_time)";
        }
        $params[':from_time'] = $from_time;
        $params[':to_time'] = $to_time;
    } elseif ($from_time) {
        $where[] = "Time >= :from_time";
        $params[':from_time'] = $from_time;
    } elseif ($to_time) {
        $where[] = "Time <= :to_time";
        $params[':to_time'] = $to_time;
    }

    $whereStr = count($where) > 0 ? "WHERE " . implode(' AND ', $where) : "";

    $sortMap = [
        'occurrences' => 'Count DESC',
        'confidence' => 'MaxConfidence DESC',
        'date' => 'MAX(Date) DESC, MAX(Time) DESC',
        'name' => 'Com_Name ASC',
    ];
    $orderBy = $sortMap[$sort] ?? 'Count DESC';

    $sql = "SELECT Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence, 
                   MAX(Date) as Date, MAX(Time) as Time, File_Name
            FROM detections 
            $whereStr 
            GROUP BY Sci_Name 
            ORDER BY $orderBy";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    ensure_db_ok($stmt);
    $result = $stmt->execute();

    $species = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $species[] = [
            'Com_Name' => $row['Com_Name'],
            'Sci_Name' => $row['Sci_Name'],
            'Count' => (int) $row['Count'],
            'MaxConfidence' => floatval($row['MaxConfidence']),
            'Date' => $row['Date'],
            'Time' => $row['Time'],
            'File_Name' => $row['File_Name'],
        ];
    }

    json_success([
        'species' => $species,
        'total' => count($species),
        'sort' => $sort,
        'from_date' => $from_date,
        'to_date' => $to_date,
        'from_time' => $from_time,
        'to_time' => $to_time,
    ]);
}

//  RECORDINGS
function handle_recordings($method, $id, $action)
{
    $db = get_db();
    $user = get_user();
    $home = get_home();

    switch ($method) {
        case 'GET':
            $date = $_GET['date'] ?? null;
            $from_date = $_GET['from_date'] ?? null;
            $to_date = $_GET['to_date'] ?? null;
            $from_time = $_GET['from_time'] ?? null;
            $to_time = $_GET['to_time'] ?? null;
            $species = $_GET['species'] ?? null;
            $sort = $_GET['sort'] ?? 'date';
            $limit = intval($_GET['limit'] ?? 200);

            $where = [];
            $params = [];

            if ($from_date && $to_date) {
                $where[] = "Date BETWEEN :from_date AND :to_date";
                $params[':from_date'] = $from_date;
                $params[':to_date'] = $to_date;
            } elseif ($date) {
                $where[] = "Date = :date";
                $params[':date'] = $date;
            } elseif (!$species) {
                // If neither range nor single date nor species is provided, default to today
                $where[] = "Date = :date";
                $params[':date'] = date('Y-m-d');
            }

            if ($from_time && $to_time) {
                // Cross-midnight check: if from_time > to_time the range spans midnight
                if ($from_time > $to_time) {
                    $where[] = "(Time >= :from_time OR Time <= :to_time)";
                } else {
                    $where[] = "(Time BETWEEN :from_time AND :to_time)";
                }
                $params[':from_time'] = $from_time;
                $params[':to_time'] = $to_time;
            }

            if ($species) {
                $where[] = "Sci_Name = :species";
                $params[':species'] = $species;
            }

            $whereStr = count($where) > 0 ? implode(' AND ', $where) : '1=1';
            $sortMap = [
                'confidence' => 'Confidence DESC',
                'name' => 'Com_Name ASC, Time DESC',
            ];
            $orderBy = isset($sortMap[$sort]) ? $sortMap[$sort] : 'Time DESC';

            $stmt = $db->prepare("SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name 
                                  FROM detections WHERE $whereStr 
                                  ORDER BY $orderBy LIMIT :limit");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            ensure_db_ok($stmt);

            $excludeFile = rtrim($home, '/') . "/BirdNET-Pi/scripts/disk_check_exclude.txt";
            $disk_check_exclude_arr = [];
            if (file_exists($excludeFile)) {
                $fp = @fopen($excludeFile, 'r');
                if ($fp) {
                    $disk_check_exclude_arr = explode("\n", fread($fp, filesize($excludeFile)));
                    fclose($fp);
                }
            }

            $recordings = [];
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $comName = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
                $fileRelPath = "{$row['Date']}/{$comName}/{$row['File_Name']}";
                $filePath = rtrim($home, '/') . "/BirdSongs/Extracted/By_Date/" . $fileRelPath;
                $row['Confidence'] = floatval($row['Confidence']);
                $row['file_exists'] = file_exists($filePath);
                $row['is_locked'] = in_array($fileRelPath, $disk_check_exclude_arr);
                $recordings[] = $row;
            }

            json_success(['recordings' => $recordings, 'total' => count($recordings)]);
            break;

        case 'DELETE':
            require_auth();
            if (!$id)
                json_error('Nome file richiesto', 400);
            $fileName = urldecode($id);
            $sciName = $_GET['sci_name'] ?? null;
            $date = $_GET['date'] ?? null;
            $time = $_GET['time'] ?? null;

            if (!$sciName || !$date || !$time) {
                json_error('Parametri sci_name, date e time richiesti per la cancellazione sicura', 400);
            }

            // Find the specific detection in the database
            $stmt = $db->prepare("SELECT Date, Com_Name, File_Name FROM detections WHERE File_Name = :fn AND Sci_Name = :sn AND Date = :d AND Time = :t LIMIT 1");
            $stmt->bindValue(':fn', $fileName);
            $stmt->bindValue(':sn', $sciName);
            $stmt->bindValue(':d', $date);
            $stmt->bindValue(':t', $time);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$row)
                json_error('Detection non trovata nel database', 404);

            $comName = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
            $fileRelativePath = "{$row['Date']}/{$comName}/{$fileName}";
            $filePath = rtrim($home, '/') . "/BirdSongs/Extracted/By_Date/" . $fileRelativePath;

            // Delete the database record first
            $dbRw = get_db_rw();
            $delStmt = $dbRw->prepare("DELETE FROM detections WHERE File_Name = :fn AND Sci_Name = :sn AND Date = :d AND Time = :t");
            $delStmt->bindValue(':fn', $fileName);
            $delStmt->bindValue(':sn', $sciName);
            $delStmt->bindValue(':d', $date);
            $delStmt->bindValue(':t', $time);
            $result = $delStmt->execute();

            if ($result === false || $dbRw->changes() === 0) {
                json_error('Error - database line deletion failed : ' . $dbRw->lastErrorMsg(), 500);
            }

            // Check if other detections still reference the same file
            $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM detections WHERE File_Name = :fn");
            $checkStmt->bindValue(':fn', $fileName);
            $checkCount = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];

            $fileDeleted = false;
            if ($checkCount == 0) {
                // No more references, safe to delete the physical file
                $output = [];
                $cmd = "sudo rm " . escapeshellarg($filePath) . " 2>&1 && sudo rm " . escapeshellarg($filePath . ".png") . " 2>&1";
                if (exec($cmd, $output)) {
                    // exec returns the last line of output if successful in some contexts, 
                    // but usually we check return value. In PHP exec return is last line.
                }
                $fileDeleted = true;
            }

            json_success([
                'deleted' => true,
                'db_rows_affected' => $dbRw->changes(),
                'file_removed_from_disk' => $fileDeleted
            ]);
            break;

        case 'PUT':
            require_auth();
            if (!$id)
                json_error('Nome file richiesto', 400);

            $body = get_json_body();
            $fileName = urldecode($id);

            if ($action === 'id' || isset($body['new_name'])) {
                // Change identification
                $newName = $body['new_name'] ?? '';
                if (empty($newName))
                    json_error('new_name richiesto', 400);

                $sciName = $_GET['sci_name'] ?? '';
                $date = $_GET['date'] ?? '';
                $time = $_GET['time'] ?? '';

                $output = [];
                // Execute backend script just like play.php
                $cmd = "sudo -u " . escapeshellarg($user) . " " . escapeshellarg($home . "/BirdNET-Pi/scripts/birdnet_changeidentification.sh") . " " . escapeshellarg($fileName) . " " . escapeshellarg($newName) . " log_errors " . escapeshellarg($sciName) . " " . escapeshellarg($date) . " " . escapeshellarg($time) . " 2>&1";
                if (!exec($cmd, $output)) {
                    json_success(['updated' => true, 'new_name' => $newName]);
                } else {
                    json_error('Error : ' . implode(", ", $output), 500);
                }
            } elseif ($action === 'lock' || isset($body['locked'])) {
                // Toggle lock
                $lock = $body['locked'] ?? true;
                $stmt = $db->prepare("SELECT Date, Com_Name FROM detections WHERE File_Name = :fn LIMIT 1");
                $stmt->bindValue(':fn', $fileName);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$row)
                    json_error('File non trovato', 404);

                $comName = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
                $fileRelPath = "{$row['Date']}/{$comName}/{$fileName}";
                $excludeFile = rtrim($home, '/') . "/BirdNET-Pi/scripts/disk_check_exclude.txt";

                if (!file_exists($excludeFile)) {
                    file_put_contents($excludeFile, "##start\n##end\n");
                }

                if ($lock) {
                    $myfile = fopen($excludeFile, "a");
                    if ($myfile) {
                        fwrite($myfile, $fileRelPath . "\n");
                        fwrite($myfile, $fileRelPath . ".png\n");
                        fclose($myfile);
                    } else {
                        json_error('Unable to open exclude file', 500);
                    }
                } else {
                    $lines = file($excludeFile);
                    $resultStr = '';
                    foreach ($lines as $line) {
                        if (stripos($line, $fileRelPath) === false && stripos($line, $fileRelPath . ".png") === false) {
                            $resultStr .= $line;
                        }
                    }
                    file_put_contents($excludeFile, $resultStr);
                }

                json_success(['locked' => $lock]);
            } else {
                json_error('Azione non specificata (id o lock)', 400);
            }
            break;

        default:
            json_error('Metodo non supportato', 405);
    }
}

//  CHARTS
function handle_charts($type)
{
    $date = $_GET['date'] ?? date('Y-m-d');

    if ($type === 'daily') {
        $db = get_db();

        // Detections per hour
        $stmt = $db->prepare("SELECT SUBSTR(Time, 1, 2) as hour, COUNT(*) as count 
                              FROM detections WHERE Date = :date 
                              GROUP BY hour ORDER BY hour");
        $stmt->bindValue(':date', $date);
        ensure_db_ok($stmt);
        $byHour = [];
        $r = $stmt->execute();
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $byHour[] = ['hour' => $row['hour'], 'count' => (int) $row['count']];
        }

        // Species per hour
        $stmt2 = $db->prepare("SELECT SUBSTR(Time, 1, 2) as hour, COUNT(DISTINCT Sci_Name) as species_count 
                               FROM detections WHERE Date = :date 
                               GROUP BY hour ORDER BY hour");
        $stmt2->bindValue(':date', $date);
        $speciesByHour = [];
        $r2 = $stmt2->execute();
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
            $speciesByHour[] = ['hour' => $row['hour'], 'species_count' => (int) $row['species_count']];
        }

        // Top species for the day
        $stmt3 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count, MAX(Confidence) as max_conf
                               FROM detections WHERE Date = :date 
                               GROUP BY Sci_Name ORDER BY count DESC LIMIT 20");
        $stmt3->bindValue(':date', $date);
        $topSpecies = [];
        $r3 = $stmt3->execute();
        while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
            $row['count'] = (int) $row['count'];
            $row['max_conf'] = floatval($row['max_conf']);
            $topSpecies[] = $row;
        }

        // Total
        $stmt4 = $db->prepare("SELECT COUNT(*) as total FROM detections WHERE Date = :date");
        $stmt4->bindValue(':date', $date);
        $total = $stmt4->execute()->fetchArray(SQLITE3_ASSOC)['total'];

        // Heatmap Matrix: Counts per species per hour (only for the top species)
        $speciesHourlyCounts = [];
        if (count($topSpecies) > 0) {
            $topNames = array_map(function ($sp) {
                return "'" . SQLite3::escapeString($sp['Sci_Name']) . "'";
            }, $topSpecies);
            $inClause = implode(',', $topNames);
            $stmt5 = $db->prepare("SELECT Sci_Name, Com_Name, SUBSTR(Time, 1, 2) as hour, COUNT(*) as count 
                                   FROM detections 
                                   WHERE Date = :date AND Sci_Name IN ($inClause)
                                   GROUP BY Sci_Name, hour 
                                   ORDER BY Sci_Name, hour");
            $stmt5->bindValue(':date', $date);
            $r5 = $stmt5->execute();

            // Initialize empty map for each top species
            foreach ($topSpecies as $sp) {
                // Initialize array of 24 hours with 0 counts
                $speciesHourlyCounts[$sp['Sci_Name']] = [
                    'Com_Name' => $sp['Com_Name'],
                    'Sci_Name' => $sp['Sci_Name'],
                    'hours' => array_fill(0, 24, 0),
                    'total' => $sp['count']
                ];
            }

            // Fill with real data
            while ($row = $r5->fetchArray(SQLITE3_ASSOC)) {
                $h = (int) $row['hour'];
                $sciName = $row['Sci_Name'];
                if (isset($speciesHourlyCounts[$sciName])) {
                    $speciesHourlyCounts[$sciName]['hours'][$h] = (int) $row['count'];
                }
            }
        }

        // --- HOURLY WEATHER ADDITION ---
        $hourlyWeather = array_fill(0, 24, null);
        $stmt_w = $db->prepare("SELECT Hour, Temp, WindSpeed, WindDirection, ConditionCode, IsDay FROM weather WHERE Date = :date");
        $stmt_w->bindValue(':date', $date);
        $res_w = $stmt_w->execute();
        while ($wrow = $res_w->fetchArray(SQLITE3_ASSOC)) {
            $h = (int) $wrow['Hour'];
            $tempC = isset($wrow['Temp']) && $wrow['Temp'] !== '' ? round(($wrow['Temp'] - 32) * 5 / 9, 1) : null;

            $code = (int) ($wrow['ConditionCode'] ?? 0);
            $condDesc = 'Cloudy';
            if ($code === 0)
                $condDesc = 'Clear';
            else if ($code >= 1 && $code <= 3)
                $condDesc = 'Cloudy';
            else if ($code == 45 || $code == 48)
                $condDesc = 'Fog';
            else if (($code >= 51 && $code <= 64) || ($code >= 80 && $code <= 82))
                $condDesc = 'Rain';
            else if (($code >= 71 && $code <= 77) || $code == 85 || $code == 86)
                $condDesc = 'Snow';
            else if (($code >= 95 && $code <= 99) || ($code >= 65 && $code <= 67))
                $condDesc = 'Thunderstorm';

            $hourlyWeather[$h] = [
                'temp' => $tempC,
                'wind' => $wrow['WindSpeed'] ?? null,
                'wind_deg' => $wrow['WindDirection'] ?? null,
                'condition' => $condDesc,
                'isday' => isset($wrow['IsDay']) ? (int) $wrow['IsDay'] : (isset($wrow['isday']) ? (int) $wrow['isday'] : 1)
            ];
        }

        // --------------------------------

        // Chart images (if they exist)
        $home = get_home();
        $chart1 = "$home/BirdSongs/Extracted/Charts/Combo-$date.png";
        $chart2 = "$home/BirdSongs/Extracted/Charts/Combo2-$date.png";

        // LDFCS charts (if they exist)
        $config = get_config();
        $extractedDir = (isset($config['EXTRACTED']) && !empty($config['EXTRACTED']))
            ? rtrim($config['EXTRACTED'], '/')
            : "$home/BirdSongs/Extracted";

        // Ensure absolute path
        if (!empty($extractedDir) && $extractedDir[0] !== '/' && strpos($extractedDir, ':') === false) {
            $extractedDir = __ROOT__ . '/' . $extractedDir;
        }

        $ldfcs_std = "$extractedDir/LongSpectrograms/daily_standard_$date.png";
        $ldfcs_ind = "$extractedDir/LongSpectrograms/daily_indices_$date.png";

        json_success([
            'date' => $date,
            'total_detections' => (int) $total,
            'detections_by_hour' => $byHour,
            'species_by_hour' => $speciesByHour,
            'top_species' => $topSpecies,
            'species_hourly_counts' => array_values($speciesHourlyCounts),
            'hourly_weather' => $hourlyWeather,
            'chart1_available' => file_exists($chart1),
            'chart2_available' => file_exists($chart2),
            'ldfcs_standard_available' => (($config['GENERATE_LDFCS_STANDARD'] ?? '0') === '1' && file_exists($ldfcs_std)),
            'ldfcs_indices_available' => (($config['GENERATE_LDFCS_INDICES'] ?? '0') === '1' && file_exists($ldfcs_ind)),
            'ldfcs_standard_file' => (isset($config['GENERATE_LDFCS_STANDARD']) && $config['GENERATE_LDFCS_STANDARD'] === '1' && file_exists($ldfcs_std)) ? "LongSpectrograms/" . basename($ldfcs_std) : null,
            'ldfcs_indices_file' => (isset($config['GENERATE_LDFCS_INDICES']) && $config['GENERATE_LDFCS_INDICES'] === '1' && file_exists($ldfcs_ind)) ? "LongSpectrograms/" . basename($ldfcs_ind) : null,
        ]);
    }


    // Available dates
    if ($type === 'dates') {
        $db = get_db();
        $stmt = $db->prepare("SELECT DISTINCT Date, COUNT(*) as count 
                              FROM detections GROUP BY Date ORDER BY Date DESC LIMIT 365");
        ensure_db_ok($stmt);
        $dates = [];
        $r = $stmt->execute();
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $dates[] = ['date' => $row['Date'], 'count' => (int) $row['count']];
        }
        json_success(['dates' => $dates]);
    }

    json_error('Tipo grafico non valido. Usa: daily, dates', 400);
}

//  DAILY / WEEKLY / MONTHLY REPORT
function handle_report($type)
{
    if ($type !== 'daily' && $type !== 'weekly' && $type !== 'monthly')
        json_error('Tipo report non valido', 400);

    $db = get_db();
    $targetDate = $_GET['date'] ?? date('Y-m-d');

    if ($type === 'daily') {
        $thisPeriodStart = $targetDate;
        $thisPeriodEnd = $targetDate;
        $lastPeriodStart = date('Y-m-d', strtotime("-1 day", strtotime($targetDate)));
    } elseif ($type === 'weekly') {
        // date('N') restituisce 1 per Lunedi' e 7 per Domenica.
        // Sottraendo (date('N') - 1) giorni, troviamo esattamente il Lunedi' della settimana in corso.
        $daysToSubtract = date('N', strtotime($targetDate)) - 1;
        $thisPeriodStart = date('Y-m-d', strtotime("-{$daysToSubtract} days", strtotime($targetDate)));
        $thisPeriodEnd = date('Y-m-d', strtotime("+6 days", strtotime($thisPeriodStart)));

        // La settimana precedente inizia 7 giorni prima di $thisPeriodStart
        $lastPeriodStart = date('Y-m-d', strtotime("-7 days", strtotime($thisPeriodStart)));
    } else {
        // month logic
        $thisPeriodStart = date('Y-m-01', strtotime($targetDate));
        $thisPeriodEnd = date('Y-m-t', strtotime($targetDate));

        // previous month
        $lastPeriodStart = date('Y-m-01', strtotime("-1 month", strtotime($thisPeriodStart)));
    }

    // This period
    $stmt = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count, MAX(Confidence) as max_conf
                          FROM detections 
                          WHERE Date >= :start AND Date <= :end
                          GROUP BY Sci_Name ORDER BY count DESC");
    $stmt->bindValue(':start', $thisPeriodStart);
    $stmt->bindValue(':end', $thisPeriodEnd);
    ensure_db_ok($stmt);
    $thisWeek = [];
    $r = $stmt->execute();
    $totalThisWeek = 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['count'] = (int) $row['count'];
        $row['max_conf'] = floatval($row['max_conf']);
        $totalThisWeek += $row['count'];
        $thisWeek[] = $row;
    }

    // Last week / month
    $stmt2 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count
                           FROM detections 
                           WHERE Date >= :start AND Date < :end
                           GROUP BY Sci_Name ORDER BY count DESC");
    $stmt2->bindValue(':start', $lastPeriodStart);
    $stmt2->bindValue(':end', $thisPeriodStart);
    ensure_db_ok($stmt2);
    $lastWeek = [];
    $r2 = $stmt2->execute();
    $totalLastWeek = 0;
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
        $lastWeek[$row['Sci_Name']] = (int) $row['count'];
        $totalLastWeek += (int) $row['count'];
    }

    // Compute changes and new species
    $speciesWithChange = [];
    $newSpecies = [];
    foreach ($thisWeek as $sp) {
        $prevCount = $lastWeek[$sp['Sci_Name']] ?? 0;
        $pctChange = $prevCount > 0 ? round((($sp['count'] - $prevCount) / $prevCount) * 100, 1) : null;
        $sp['previous_count'] = $prevCount;
        $sp['percent_change'] = $pctChange;
        $sp['is_new'] = $prevCount === 0;
        if ($prevCount === 0)
            $newSpecies[] = $sp['Com_Name'];
        $speciesWithChange[] = $sp;
    }

    $totalPctChange = $totalLastWeek > 0
        ? round((($totalThisWeek - $totalLastWeek) / $totalLastWeek) * 100, 1)
        : null;

    $heatmapLimit = 999999;

    // Heatmap Matrix: Counts per species per hour (only for the top species in this period)
    $speciesHourlyCounts = [];
    if (count($speciesWithChange) > 0) {
        $topNames = [];
        $i = 0;
        foreach ($speciesWithChange as $sp) {
            if ($i >= $heatmapLimit)
                break; // Limit heatmap
            $topNames[] = "'" . SQLite3::escapeString($sp['Sci_Name']) . "'";
            $i++;
        }
        $inClause = implode(',', $topNames);

        $stmt3 = $db->prepare("SELECT Sci_Name, Com_Name, SUBSTR(Time, 1, 2) as hour, COUNT(*) as count 
                               FROM detections 
                               WHERE Date >= :start AND Date <= :end AND Sci_Name IN ($inClause)
                               GROUP BY Sci_Name, hour 
                               ORDER BY Sci_Name, hour");
        $stmt3->bindValue(':start', $thisPeriodStart);
        $stmt3->bindValue(':end', $thisPeriodEnd);
        $r3 = $stmt3->execute();

        // Initialize empty map for each top species
        foreach ($speciesWithChange as $index => $sp) {
            if ($index >= $heatmapLimit)
                break;
            $speciesHourlyCounts[$sp['Sci_Name']] = [
                'Com_Name' => $sp['Com_Name'],
                'Sci_Name' => $sp['Sci_Name'],
                'hours' => array_fill(0, 24, 0),
                'total' => $sp['count'],
                'is_new' => $sp['is_new'],
                'percent_change' => $sp['percent_change']
            ];
        }

        // Fill with real data
        while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
            $h = (int) $row['hour'];
            $sciName = $row['Sci_Name'];
            if (isset($speciesHourlyCounts[$sciName])) {
                $speciesHourlyCounts[$sciName]['hours'][$h] = (int) $row['count'];
            }
        }
    }

    $daily_trend = null;
    $daily_hourly = null;
    $sun_info = null;

    if ($type === 'weekly' || $type === 'monthly') {
        // 1. Daily detections & unique species
        $stmt_daily = $db->prepare("SELECT Date, COUNT(*) as count, COUNT(DISTINCT Sci_Name) as unique_species FROM detections WHERE Date >= :start AND Date <= :end GROUP BY Date ORDER BY Date ASC");
        $stmt_daily->bindValue(':start', $thisPeriodStart);
        $stmt_daily->bindValue(':end', $thisPeriodEnd);
        ensure_db_ok($stmt_daily);
        $res_daily = $stmt_daily->execute();
        $daily_raw_map = [];
        while ($row = $res_daily->fetchArray(SQLITE3_ASSOC)) {
            $daily_raw_map[$row['Date']] = [
                'count' => (int) $row['count'],
                'unique_species' => (int) $row['unique_species']
            ];
        }

        // 2. Daily weather stats continuous
        $stmt_weather = $db->prepare("SELECT Date, ROUND(AVG((Temp - 32) * 5.0 / 9.0), 1) as avg_temp, ROUND(AVG(WindSpeed), 1) as avg_wind FROM weather WHERE Date BETWEEN :start AND :end GROUP BY Date");
        $stmt_weather->bindValue(':start', $thisPeriodStart);
        $stmt_weather->bindValue(':end', $thisPeriodEnd);
        ensure_db_ok($stmt_weather);
        $res_weather = $stmt_weather->execute();
        $weather_map = [];
        while ($w = $res_weather->fetchArray(SQLITE3_ASSOC)) {
            $weather_map[$w['Date']] = $w;
        }

        // 3. Reconstruct continuous daily trend
        $home = get_home();
        $config = get_config();
        $extractedDir = (isset($config['EXTRACTED']) && !empty($config['EXTRACTED']))
            ? rtrim($config['EXTRACTED'], '/')
            : "$home/BirdSongs/Extracted";

        if (!empty($extractedDir) && $extractedDir[0] !== '/' && strpos($extractedDir, ':') === false) {
            $extractedDir = __ROOT__ . '/' . $extractedDir;
        }

        $daily_trend = [];
        $start_ts = strtotime($thisPeriodStart);
        $end_ts = strtotime($thisPeriodEnd);

        for ($t = $start_ts; $t <= $end_ts; $t = strtotime('+1 day', $t)) {
            $date_str = date('Y-m-d', $t);
            $raw = $daily_raw_map[$date_str] ?? ['count' => 0, 'unique_species' => 0];
            $w = $weather_map[$date_str] ?? ['avg_temp' => null, 'avg_wind' => null];

            $ldfcs_std_file = "LongSpectrograms/daily_standard_$date_str.png";
            $ldfcs_ind_file = "LongSpectrograms/daily_indices_$date_str.png";
            $ldfcs_std_path = "$extractedDir/$ldfcs_std_file";
            $ldfcs_ind_path = "$extractedDir/$ldfcs_ind_file";

            $daily_trend[] = [
                'date' => $date_str,
                'count' => $raw['count'],
                'unique_species' => $raw['unique_species'],
                'avg_temp' => $w['avg_temp'] !== null ? (float) $w['avg_temp'] : null,
                'avg_wind' => $w['avg_wind'] !== null ? (float) $w['avg_wind'] : null,
                'ldfcs_standard_available' => (($config['GENERATE_LDFCS_STANDARD'] ?? '0') === '1' && file_exists($ldfcs_std_path)),
                'ldfcs_indices_available' => (($config['GENERATE_LDFCS_INDICES'] ?? '0') === '1' && file_exists($ldfcs_ind_path)),
                'ldfcs_standard_file' => (isset($config['GENERATE_LDFCS_STANDARD']) && $config['GENERATE_LDFCS_STANDARD'] === '1' && file_exists($ldfcs_std_path)) ? $ldfcs_std_file : null,
                'ldfcs_indices_file' => (isset($config['GENERATE_LDFCS_INDICES']) && $config['GENERATE_LDFCS_INDICES'] === '1' && file_exists($ldfcs_ind_path)) ? $ldfcs_ind_file : null,
            ];
        }

        // 4. Daily-Hourly distribution for Heatmap
        $stmt_daily_hourly = $db->prepare("SELECT Date, SUBSTR(Time, 1, 2) as hour, COUNT(*) as count FROM detections WHERE Date >= :start AND Date <= :end GROUP BY Date, hour ORDER BY Date ASC, hour ASC");
        $stmt_daily_hourly->bindValue(':start', $thisPeriodStart);
        $stmt_daily_hourly->bindValue(':end', $thisPeriodEnd);
        ensure_db_ok($stmt_daily_hourly);
        $res_daily_hourly = $stmt_daily_hourly->execute();
        $daily_hourly = [];
        while ($row = $res_daily_hourly->fetchArray(SQLITE3_ASSOC)) {
            $daily_hourly[] = [
                'date' => $row['Date'],
                'hour' => (int) $row['hour'],
                'count' => (int) $row['count']
            ];
        }

        // 5. Sunrise/Sunset times
        $config = get_config();
        $lat = $config['LATITUDE'] ?? $config['latitude'] ?? '';
        $lon = $config['LONGITUDE'] ?? $config['longitude'] ?? '';

        if (!empty($lat) && !empty($lon)) {
            $lat = (float) $lat;
            $lon = (float) $lon;
            for ($t = $start_ts; $t <= $end_ts; $t = strtotime('+1 day', $t)) {
                $date_str = date('Y-m-d', $t);
                $sun = date_sun_info($t, $lat, $lon);
                if ($sun) {
                    $sunrise_hour = (float) date('H', $sun['sunrise']) + (float) date('i', $sun['sunrise']) / 60.0;
                    $sunset_hour = (float) date('H', $sun['sunset']) + (float) date('i', $sun['sunset']) / 60.0;
                    $sun_info[] = [
                        'date' => $date_str,
                        'sunrise' => round($sunrise_hour, 2),
                        'sunset' => round($sunset_hour, 2),
                    ];
                }
            }
        }
    }

    // --- HOURLY WEATHER ADDITION ---
    $hourlyWeather = null;
    if ($type === 'daily') {
        $hourlyWeather = array_fill(0, 24, null);

        $stmt_w = $db->prepare("SELECT Hour, Temp, WindSpeed, WindDirection, ConditionCode, IsDay FROM weather WHERE Date = :date");
        $stmt_w->bindValue(':date', $targetDate);
        $res_w = $stmt_w->execute();
        while ($wrow = $res_w->fetchArray(SQLITE3_ASSOC)) {
            $h = (int) $wrow['Hour'];
            $tempC = isset($wrow['Temp']) && $wrow['Temp'] !== '' ? round(($wrow['Temp'] - 32) * 5 / 9, 1) : null;

            $code = (int) ($wrow['ConditionCode'] ?? 0);
            $condDesc = 'Cloudy';
            if ($code === 0)
                $condDesc = 'Clear';
            else if ($code >= 1 && $code <= 3)
                $condDesc = 'Cloudy';
            else if ($code == 45 || $code == 48)
                $condDesc = 'Fog';
            else if (($code >= 51 && $code <= 64) || ($code >= 80 && $code <= 82))
                $condDesc = 'Rain';
            else if (($code >= 71 && $code <= 77) || $code == 85 || $code == 86)
                $condDesc = 'Snow';
            else if (($code >= 95 && $code <= 99) || ($code >= 65 && $code <= 67))
                $condDesc = 'Thunderstorm';

            $hourlyWeather[$h] = [
                'temp' => $tempC,
                'wind' => $wrow['WindSpeed'] ?? null,
                'wind_deg' => $wrow['WindDirection'] ?? null,
                'condition' => $condDesc,
                'isday' => isset($wrow['IsDay']) ? (int) $wrow['IsDay'] : (isset($wrow['isday']) ? (int) $wrow['isday'] : 1)
            ];
        }


    }
    // --------------------------------

    $response = [
        'period_start' => $thisPeriodStart,
        'period_end' => $thisPeriodEnd,
        'total_detections' => $totalThisWeek,
        'total_previous' => $totalLastWeek,
        'total_percent_change' => $totalPctChange,
        'unique_species' => count($thisWeek),
        'unique_species_previous' => count($lastWeek),
        'new_species' => $newSpecies,
        'species' => $speciesWithChange,
        'species_hourly_counts' => array_values($speciesHourlyCounts),
        'hourly_weather' => $hourlyWeather,
        'daily_trend' => $daily_trend,
        'daily_hourly' => $daily_hourly,
        'sun_info' => $sun_info
    ];

    // Added for Daily LDFCS
    if ($type === 'daily') {
        $home = get_home();
        $config = get_config();
        $extractedDir = (isset($config['EXTRACTED']) && !empty($config['EXTRACTED']))
            ? rtrim($config['EXTRACTED'], '/')
            : "$home/BirdSongs/Extracted";

        if (!empty($extractedDir) && $extractedDir[0] !== '/' && strpos($extractedDir, ':') === false) {
            $extractedDir = __ROOT__ . '/' . $extractedDir;
        }

        $ldfcs_std = "$extractedDir/LongSpectrograms/daily_standard_$thisPeriodStart.png";
        $ldfcs_ind = "$extractedDir/LongSpectrograms/daily_indices_$thisPeriodStart.png";

        $response['ldfcs_standard_available'] = (($config['GENERATE_LDFCS_STANDARD'] ?? '0') === '1' && file_exists($ldfcs_std));
        $response['ldfcs_indices_available'] = (($config['GENERATE_LDFCS_INDICES'] ?? '0') === '1' && file_exists($ldfcs_ind));
        $response['ldfcs_standard_file'] = (isset($config['GENERATE_LDFCS_STANDARD']) && $config['GENERATE_LDFCS_STANDARD'] === '1' && file_exists($ldfcs_std)) ? "LongSpectrograms/" . basename($ldfcs_std) : null;
        $response['ldfcs_indices_file'] = (isset($config['GENERATE_LDFCS_INDICES']) && $config['GENERATE_LDFCS_INDICES'] === '1' && file_exists($ldfcs_ind)) ? "LongSpectrograms/" . basename($ldfcs_ind) : null;
    }

    json_success($response);
}

//  SERVE CHART (IMAGE)
function handle_serve_chart($filename)
{
    if (!$filename)
        json_error('Nome file richiesto', 400);

    // Security check to avoid path traversal
    if (strpos($filename, '..') !== false) {
        json_error('Nome file non valido', 400);
    }

    $home = get_home();
    // Default fallback path for XAMPP
    if (empty($home))
        $home = __ROOT__;

    // Charts path logic, handling both Raspberry Pi and local XAMPP structure
    $config = get_config();
    $extractedDir = (isset($config['EXTRACTED']) && !empty($config['EXTRACTED']))
        ? rtrim($config['EXTRACTED'], '/')
        : "$home/BirdSongs/Extracted";

    // Ensure absolute path
    if (!empty($extractedDir) && $extractedDir[0] !== '/' && strpos($extractedDir, ':') === false) {
        $extractedDir = __ROOT__ . '/' . $extractedDir;
    }

    // Try several locations
    $searchPaths = [
        "$extractedDir/$filename",                      // New flexible path (e.g. LongSpectrograms/...)
        "$home/BirdSongs/Extracted/Charts/$filename",   // Legacy path
        __ROOT__ . "/Charts/$filename"                  // XAMPP legacy path
    ];

    $path = null;
    foreach ($searchPaths as $p) {
        if (file_exists($p)) {
            $path = $p;
            break;
        }
    }

    if (!$path) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Grafico non trovato'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Serve image
    $mime = 'image/png'; // Default for charts
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg')
        $mime = 'image/jpeg';
    else if ($ext === 'gif')
        $mime = 'image/gif';

    // CORS and Headers
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: $mime");
    header("Content-Length: " . filesize($path));
    header("Cache-Control: public, max-age=86400");

    // Clear any output buffer to avoid memory issues with large files
    while (ob_get_level()) {
        ob_end_clean();
    }
    flush();

    readfile($path);
    exit;
}

//  SERVE MEDIA (AUDIO/SPECTROGRAM)
function handle_serve_media($filepath)
{
    if (!$filepath)
        json_error('Percorso file richiesto', 400);

    $filepath = urldecode($filepath);

    // Security check to avoid path traversal
    if (strpos($filepath, '..') !== false) {
        json_error('Percorso file non valido', 400);
    }

    $config = get_config();
    if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
        $extractedDir = rtrim($config['EXTRACTED'], '/');
    } else {
        $home = get_home();
        if (empty($home))
            $home = __ROOT__;
        $extractedDir = "$home/BirdSongs/Extracted";
    }

    $mediaPath = "$extractedDir/By_Date/$filepath";

    if (!file_exists($mediaPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media non trovato'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mime = mime_content_type($mediaPath);
    if (!$mime || $mime === 'text/plain') { // Fallback stringente
        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        if ($ext === 'wav')
            $mime = 'audio/wav';
        elseif ($ext === 'flac')
            $mime = 'audio/flac';
        elseif ($ext === 'mp3')
            $mime = 'audio/mpeg';
        elseif ($ext === 'png')
            $mime = 'image/png';
        else
            $mime = 'application/octet-stream';
    }

    header("Content-Type: $mime");
    header("Content-Length: " . filesize($mediaPath));
    header("Cache-Control: public, max-age=86400");

    // Clear any output buffer to avoid memory issues with large files
    while (ob_get_level()) {
        ob_end_clean();
    }
    flush();

    readfile($mediaPath);
    exit;
}

//  RECORDING LENGTH (No Auth) 
function handle_recording_length($method)
{
    if ($method === 'GET') {
        $config = get_config();
        json_success(['RECORDING_LENGTH' => $config['RECORDING_LENGTH'] ?? '15']);
    }
    json_error('Metodo non supportato', 405);
}

//  DATABASE LANG (No Auth) 
function handle_database_lang()
{
    $config = get_config();
    json_success(['DATABASE_LANG' => $config['DATABASE_LANG'] ?? 'en']);
}

//  CONFIG 
function handle_config($method, $id = null)
{
    if ($method === 'GET') {
        if ($id === 'species-tester') {
            require_auth();
            $threshold = $_GET['threshold'] ?? null;
            if ($threshold === null || !is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
                json_error('Invalid threshold value', 400);
            }

            $user = get_user();
            $home = get_home();
            $command = "sudo -u $user " . $home . "/BirdNET-Pi/birdnet/bin/python3 " . $home . "/BirdNET-Pi/scripts/species.py --threshold $threshold 2>&1";
            $output = shell_exec($command);

            json_success(['output' => $output]);
        }

        require_auth();
        $config = get_config();

        $keys = [
            'SITE_NAME' => 'BirdNET-Pi',
            'LATITUDE' => '',
            'LONGITUDE' => '',
            'BIRDNET_USER' => '',
            'MODEL' => '',
            'DATABASE_LANG' => 'en',
            'BIRDWEATHER_ID' => '',
            'CONFIDENCE' => '0.7',
            'SENSITIVITY' => '1.0',
            'OVERLAP' => '0.0',
            'AUDIOFMT' => 'mp3',
            'RECORDING_LENGTH' => '15',
            'EXTRACTION_LENGTH' => '6',
            'IMAGE_PROVIDER' => 'WIKIPEDIA',
            'INFO_SITE' => 'ALLABOUTBIRDS',
            'APPRISE_NOTIFICATION_TITLE' => '',
            'APPRISE_NOTIFICATION_BODY' => '',
            'APPRISE_NOTIFY_NEW_SPECIES' => '0',
            'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => '0',
            'APPRISE_WEEKLY_REPORT' => '0',
            'COLOR_SCHEME' => 'light',
            'APPRISE' => '',

            // Basic Additions
            'APPRISE_NOTIFY_EACH_DETECTION' => '0',
            'FLICKR_API_KEY' => '',
            'FLICKR_FILTER_EMAIL' => '',
            'APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES' => '0',
            'SF_THRESH' => '0.03',
            'DATA_MODEL_VERSION' => '1',
            'APPRISE_ONLY_NOTIFY_SPECIES_NAMES' => '',
            'APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2' => '',

            // Advanced Additions
            'CADDY_PWD' => '',
            'ICE_PWD' => '',
            'BIRDNETPI_URL' => '',
            'RTSP_STREAM' => '',
            'RTSP_STREAM_TO_LIVESTREAM' => '',
            'ACTIVATE_FREQSHIFT_IN_LIVESTREAM' => '',
            'FREQSHIFT_HI' => '',
            'FREQSHIFT_LO' => '',
            'FREQSHIFT_PITCH' => '',
            'FREQSHIFT_TOOL' => 'sox',
            'FREQSHIFT_RECONNECT_DELAY' => '1000',
            'FULL_DISK' => 'keep',
            'PURGE_THRESHOLD' => '90',
            'MAX_FILES_SPECIES' => '0',
            'PRIVACY_THRESHOLD' => '0',
            'REC_CARD' => 'default',
            'CHANNELS' => '1',
            'SILENCE_UPDATE_INDICATOR' => '0',
            'AUTOMATIC_UPDATE' => '0',
            'RAW_SPECTROGRAM' => '0',
            'GENERATE_LDFCS_STANDARD' => '0',
            'GENERATE_LDFCS_INDICES' => '0',
            'RARE_SPECIES_THRESHOLD' => '30',
            'CUSTOM_IMAGE' => '',
            'CUSTOM_IMAGE_TITLE' => '',
            'LogLevel_BirdnetRecordingService' => 'error',
            'LogLevel_SpectrogramViewerService' => 'error',
            'LogLevel_LiveAudioStreamService' => 'error'
        ];

        $response = [];
        foreach ($keys as $key => $default) {
            $val = $config[$key] ?? $default;
            if (is_numeric($default) && strpos((string) $default, '.') !== false) {
                $response[$key] = floatval($val);
            } else if (is_numeric($default)) {
                // Return as string or int depending on usage, but sticking to config as strings is safest for most,
                // except some are floats like overlap.
                $response[$key] = $val;
            } else {
                $response[$key] = $val;
            }
        }
        json_success($response);
    }

    if ($method === 'PUT') {
        require_auth();
        $body = get_json_body();
        if (empty($body))
            json_error('Body vuoto', 400);

        $allowed = [
            'SITE_NAME',
            'LATITUDE',
            'LONGITUDE',
            'BIRDNET_USER',
            'MODEL',
            'DATABASE_LANG',
            'BIRDWEATHER_ID',
            'CONFIDENCE',
            'SENSITIVITY',
            'OVERLAP',
            'AUDIOFMT',
            'RECORDING_LENGTH',
            'EXTRACTION_LENGTH',
            'IMAGE_PROVIDER',
            'INFO_SITE',
            'APPRISE_NOTIFICATION_TITLE',
            'APPRISE_NOTIFICATION_BODY',
            'APPRISE_NOTIFY_NEW_SPECIES',
            'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY',
            'APPRISE_WEEKLY_REPORT',
            'COLOR_SCHEME',
            'APPRISE',

            'APPRISE_NOTIFY_EACH_DETECTION',
            'FLICKR_API_KEY',
            'FLICKR_FILTER_EMAIL',
            'APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES',
            'SF_THRESH',
            'DATA_MODEL_VERSION',
            'APPRISE_ONLY_NOTIFY_SPECIES_NAMES',
            'APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2',

            'CADDY_PWD',
            'ICE_PWD',
            'BIRDNETPI_URL',
            'RTSP_STREAM',
            'RTSP_STREAM_TO_LIVESTREAM',
            'ACTIVATE_FREQSHIFT_IN_LIVESTREAM',
            'FREQSHIFT_HI',
            'FREQSHIFT_LO',
            'FREQSHIFT_PITCH',
            'FREQSHIFT_TOOL',
            'FREQSHIFT_RECONNECT_DELAY',
            'FULL_DISK',
            'PURGE_THRESHOLD',
            'MAX_FILES_SPECIES',
            'PRIVACY_THRESHOLD',
            'REC_CARD',
            'CHANNELS',
            'SILENCE_UPDATE_INDICATOR',
            'AUTOMATIC_UPDATE',
            'RAW_SPECTROGRAM',
            'GENERATE_LDFCS_STANDARD',
            'GENERATE_LDFCS_INDICES',
            'RARE_SPECIES_THRESHOLD',
            'CUSTOM_IMAGE',
            'CUSTOM_IMAGE_TITLE',
            'LogLevel_BirdnetRecordingService',
            'LogLevel_SpectrogramViewerService',
            'LogLevel_LiveAudioStreamService'
        ];

        $configPath = '/etc/birdnet/birdnet.conf';
        if (!file_exists($configPath) || !is_writable($configPath)) {
            json_error('File di configurazione non accessibile o non scrivibile', 500);
        }

        $content = file_get_contents($configPath);
        $updated = [];

        $update_caddyfile = false;
        $restart_livestream = false;
        $restart_chart_viewer = false;
        $update_language = false;

        $old_config = get_config();

        foreach ($body as $key => $value) {
            if (!in_array($key, $allowed))
                continue;

            // Normalizzazione separatore decimale per campi numerici e prevenzione notazione scientifica
            if (in_array($key, ['SF_THRESH', 'LATITUDE', 'LONGITUDE', 'RARE_SPECIES_THRESHOLD', 'PURGE_THRESHOLD'])) {
                $value = str_replace(',', '.', (string) $value);
                if (is_numeric($value)) {
                    // Forziamo un formato decimale standard per evitare scientific notation in birdnet.conf
                    $value = sprintf("%.6f", (float) $value);
                    // Rimuoviamo gli zeri superflui ma manteniamo almeno un decimale se necessario o il punto
                    $value = rtrim(rtrim($value, '0'), '.');
                    if ($value === "" || $value === "0")
                        $value = "0.0";
                }
                if ($key === 'SF_THRESH' && (trim($value) === "" || !is_numeric($value))) {
                    $value = "0.03";
                }
            }

            $oldValue = $old_config[$key] ?? null;
            // Se il valore è numericamente uguale ma il formato è diverso (es. quotes), procediamo comunque
            // Forza l'aggiornamento se il valore nel file è racchiuso tra virgolette ma non dovrebbe
            $is_currently_quoted = preg_match("/^\s*#?\s*" . preg_quote($key) . "\s*=\s*\"/mi", $content);

            if ((string) $oldValue === (string) $value && !$is_currently_quoted)
                continue;

            if ($key === 'BIRDNETPI_URL') {
                $value = rtrim($value, '/');
            }

            if (in_array($key, ['CADDY_PWD', 'BIRDNETPI_URL']))
                $update_caddyfile = true;
            if (in_array($key, ['ICE_PWD', 'RTSP_STREAM', 'RTSP_STREAM_TO_LIVESTREAM', 'ACTIVATE_FREQSHIFT_IN_LIVESTREAM', 'LogLevel_LiveAudioStreamService']))
                $restart_livestream = true;
            if (in_array($key, ['SITE_NAME', 'COLOR_SCHEME']))
                $restart_chart_viewer = true;
            if (in_array($key, ['MODEL', 'DATABASE_LANG']))
                $update_language = true;

            // Determiniamo se il valore deve essere racchiuso tra virgolette.
            $should_quote = in_array($key, [
                'SITE_NAME',
                'APPRISE_NOTIFICATION_TITLE',
                'APPRISE_NOTIFICATION_BODY',
                'APPRISE_ONLY_NOTIFY_SPECIES_NAMES',
                'APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2',
                'CUSTOM_IMAGE_TITLE',
                'BIRDNETPI_URL',
                'APPRISE',
                'RTSP_STREAM'
            ]) || (strpos((string) $value, ' ') !== false);

            // Salvataggio nel file di configurazione con regex robusta (case-insensitive per il key)
            $pattern = "/^\s*#?\s*" . preg_quote($key) . "\s*=\s*.*$/mi";
            $safeValue = addcslashes($value, '$\\');
            $replacement = $should_quote ? "$key=\"$safeValue\"" : "$key=$safeValue";

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $replacement, $content);
            } else {
                $content .= "\n$replacement";
            }
            $updated[] = $key;
        }

        if (!empty($updated)) {
            if (file_put_contents($configPath, $content) === false) {
                json_error('Errore durante la scrittura di birdnet.conf', 500);
            }

            if (function_exists('get_config')) {
                get_config(true); // Force config reload
            }

            // Execute service restarts
            $user = get_user();
            $home = get_home();

            if ($update_language) {
                shell_exec("sudo -u $user $home/BirdNET-Pi/scripts/install_language_label.sh > /dev/null 2>&1 &");
            }
            if ($restart_chart_viewer) {
                shell_exec("sudo systemctl restart chart_viewer.service > /dev/null 2>&1 &");
            }
            if ($update_caddyfile) {
                shell_exec("sudo /usr/local/bin/update_caddyfile.sh > /dev/null 2>&1 &");
            }
            shell_exec("sudo restart_services.sh > /dev/null 2>&1 &");
            if ($restart_livestream) {
                shell_exec("sudo systemctl restart livestream.service > /dev/null 2>&1 &");
            }
        }

        json_success(['updated_keys' => $updated]);
    }

    json_error('Metodo non supportato', 405);
}

//  SERVICES 
function handle_services($method, $serviceName)
{
    $services = [
        'livestream' => ['name' => 'livestream.service', 'label' => 'Live Audio Stream'],
        'birdnet_analysis' => ['name' => 'birdnet_analysis.service', 'label' => 'BirdNET Analisi'],
        'birdnet_recording' => ['name' => 'birdnet_recording.service', 'label' => 'BirdNET Registrazione'],
        'birdnet_stats' => ['name' => 'birdnet_stats.service', 'label' => 'Statistiche'],
        'birdnet_log' => ['name' => 'birdnet_log.service', 'label' => 'BirdNET Log'],
        'chart_viewer' => ['name' => 'chart_viewer.service', 'label' => 'Chart Viewer'],
        'spectrogram_viewer' => ['name' => 'spectrogram_viewer.service', 'label' => 'Spettrogramma'],
        'extraction' => ['name' => 'extraction.service', 'label' => 'Estrazione Audio'],
    ];

    if ($method === 'GET') {
        require_auth();
        $result = [];
        foreach ($services as $key => $svc) {
            $status = trim(shell_exec("systemctl is-active {$svc['name']} 2>/dev/null") ?? 'unknown');
            $enabled = trim(shell_exec("systemctl is-enabled {$svc['name']} 2>/dev/null") ?? 'unknown');
            $result[] = [
                'id' => $key,
                'service_name' => $svc['name'],
                'label' => $svc['label'],
                'status' => $status,
                'enabled' => in_array($enabled, ['enabled', 'linked', 'static']),
            ];
        }
        json_success(['services' => $result]);
    }

    if ($method === 'POST') {
        require_auth();
        if (!$serviceName || !isset($services[$serviceName])) {
            json_error('Servizio non valido: ' . ($serviceName ?? 'null'), 400);
        }

        $body = get_json_body();
        $action = $body['action'] ?? $_GET['action'] ?? '';
        $svcName = $services[$serviceName]['name'];

        $validActions = ['start', 'stop', 'restart', 'enable', 'disable'];
        if (!in_array($action, $validActions)) {
            json_error("Azione non valida. Valori ammessi: " . implode(', ', $validActions), 400);
        }

        if ($action === 'disable') {
            $output = shell_exec("sudo systemctl disable --now $svcName 2>&1");
            if ($serviceName === 'livestream') {
                shell_exec("sudo systemctl disable icecast2.service 2>&1");
                shell_exec("sudo systemctl stop icecast2.service 2>&1");
            }
        } elseif ($action === 'enable') {
            if ($serviceName === 'livestream') {
                shell_exec("sudo systemctl enable icecast2.service 2>&1");
                shell_exec("sudo systemctl start icecast2.service 2>&1");
                $output = shell_exec("sudo systemctl enable --now  livestream.service 2>&1");
                $output .= "\n" . shell_exec("sudo systemctl start livestream.service 2>&1");
            } else {
                $output = shell_exec("sudo systemctl enable --now  $svcName 2>&1");
            }
        } else {
            $output = shell_exec("sudo systemctl $action $svcName 2>&1");
            if ($serviceName === 'livestream') {
                shell_exec("sudo systemctl $action icecast2.service 2>&1");
            }
        }

        $newStatus = trim(shell_exec("systemctl is-active $svcName 2>/dev/null") ?? 'unknown');
        $newEnabled = trim(shell_exec("systemctl is-enabled $svcName 2>/dev/null") ?? 'unknown');

        json_success([
            'service' => $serviceName,
            'action' => $action,
            'new_status' => $newStatus,
            'new_enabled' => in_array($newEnabled, ['enabled', 'linked', 'static']),
            'output' => trim($output ?? ''),
        ]);
    }

    json_error('Metodo non supportato', 405);
}

function validate_restore_archive($path)
{
    if (!file_exists($path))
        return null;

    $required = [
        "birdnet.conf",
        "birds.db",
        "BirdDB.txt",
        "Charts",
        "By_Date"
    ];

    $optional = [
        "apprise.txt",
        "body.txt",
        "blacklisted_images.txt",
        "disk_check_exclude.txt",
        "exclude_species_list.txt",
        "confirmed_species_list.txt",
        "include_species_list.txt"
    ];

    // Get top-level entries in the tar
    $output = [];
    $cmd = "tar --list --exclude=\"*/*\" -f " . escapeshellarg($path) . " | sed 's/\/\$//'";
    exec($cmd, $output);

    $found_required = [];
    $missing_required = [];
    foreach ($required as $r) {
        $found = false;
        foreach ($output as $line) {
            if (trim($line) === $r) {
                $found = true;
                break;
            }
        }
        if ($found)
            $found_required[] = $r;
        else
            $missing_required[] = $r;
    }

    $found_optional = [];
    $missing_optional = [];
    foreach ($optional as $o) {
        $found = false;
        foreach ($output as $line) {
            if (trim($line) === $o) {
                $found = true;
                break;
            }
        }
        if ($found)
            $found_optional[] = $o;
        else
            $missing_optional[] = $o;
    }

    return [
        'filename' => basename($path),
        'size' => filesize($path),
        'mtime' => filemtime($path),
        'validation' => [
            'mandatory' => count($missing_required) === 0,
            'optional' => count($found_optional) > 0,
            'required_found' => $found_required,
            'required_missing' => $missing_required,
            'optional_found' => $found_optional,
            'optional_missing' => $missing_optional
        ]
    ];
}

//  SYSTEM 
function handle_system($method, $action, $subAction = null)
{
    // GET and DELETE are allowed for 'backups' (CRUD-like action); 
    // all other actions (except 'info') require POST.
    $allowed_methods = ['POST'];
    if ($action === 'info' || $action === 'backups' || $action === 'restore') {
        $allowed_methods[] = 'GET';
    }
    if ($action === 'backups' || $action === 'restore') {
        $allowed_methods[] = 'DELETE';
    }

    if (!in_array($method, $allowed_methods)) {
        json_error("Metodo {$method} non supportato per l'azione {$action}. Usa: " . implode(', ', $allowed_methods), 405);
    }
    require_auth();

    $home = get_home();
    $user = get_user();

    switch ($action) {
        case 'reboot':
            shell_exec('nohup sudo reboot > /dev/null 2>&1 &');
            json_success(['message' => 'Riavvio in corso...']);
            break;

        case 'shutdown':
            shell_exec('nohup sudo shutdown now > /dev/null 2>&1 &');
            json_success(['message' => 'Spegnimento in corso...']);
            break;

        case 'update':
            shell_exec("nohup sudo -u $user $home/BirdNET-Pi/scripts/update_birdnet.sh > /dev/null 2>&1 &");
            json_success(['message' => 'Aggiornamento avviato']);
            break;

        case 'clear-data':
            shell_exec("nohup sudo -u $user $home/BirdNET-Pi/scripts/clear_all_data.sh > /dev/null 2>&1 &");
            json_success(['message' => 'Cancellazione dati avviata']);
            break;

        case 'info':
            // __ROOT__ is always the BirdNET-Pi project root -- more reliable than
            // "cd $home/BirdNET-Pi" which depends on the web-server user's HOME.
            $gitRepo = __ROOT__;
            // www-data has a minimal PATH; resolve git's location explicitly.
            chdir($home);
            $gitBin = trim(shell_exec('which git 2>/dev/null') ?? '') ?: '/usr/bin/git';
            $gitHash = trim(shell_exec("sudo -u $user $gitBin -C $gitRepo rev-parse --short HEAD 2>/dev/null") ?? '');
            $gitBranch = trim(shell_exec("sudo -u $user $gitBin -C $gitRepo rev-parse --abbrev-ref HEAD ") ?? '');
            $uptime = trim(shell_exec('uptime -p 2>/dev/null') ?? '');
            $diskUsage = trim(shell_exec("df -h / | tail -1 | awk '{print $3\"/\"$2\" (\"$5\" used)\"}'") ?? '');
            $memUsage = trim(shell_exec("free -h | grep Mem | awk '{print $3\"/\"$2}'") ?? '');
            $cpuTemp = trim(shell_exec("vcgencmd measure_temp 2>/dev/null | cut -d= -f2") ?? '');
            trim(shell_exec("sudo -u $user $gitBin -C $gitRepo fetch 2>/dev/null"));

            // Count commits behind the remote (uses cached fetch; non-blocking)
            $commitsBehind = $gitBranch !== ''
                ? (int) trim(shell_exec("sudo -u $user $gitBin -C $gitRepo rev-list HEAD..origin/$gitBranch --count 2>/dev/null") ?? '0')
                : 0;

            $infoResponse = [
                'git_hash' => $gitHash,
                'git_branch' => $gitBranch,
                'uptime' => $uptime,
                'disk_usage' => $diskUsage,
                'memory_usage' => $memUsage,
                'cpu_temperature' => $cpuTemp,
            ];
            if ($commitsBehind !== 0) {
                $infoResponse['commits_behind'] = $commitsBehind;
            }
            json_success($infoResponse);
            break;

        case 'backups':
            require_auth();
            $config = get_config();
            $home = get_home();
            $recsDir = $config['RECS_DIR'] ?? "{$home}/BirdSongs";
            $backupDir = rtrim($recsDir, '/') . "/Backups";
            if (!is_dir($backupDir))
                @mkdir($backupDir, 0777, true);

            if ($method === 'GET') {
                $results = [];
                if (is_dir($backupDir)) {
                    $files = scandir($backupDir);
                    $backups = [];
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..')
                            continue;
                        $base = null;
                        if (substr($f, -4) === '.tar') {
                            $base = $f;
                        } elseif (substr($f, -7) === '.status') {
                            $base = substr($f, 0, -7); // remove .status
                        }
                        if ($base && !isset($backups[$base])) {
                            $backups[$base] = [
                                'filename' => $base,
                                'status' => 'completed', // Default if only .tar exists
                                'size' => 0,
                                'timestamp' => 0,
                                'url' => "/backup-file/{$base}"
                            ];
                        }
                    }

                    foreach ($backups as $filename => &$data) {
                        $tarFile = "{$backupDir}/{$filename}";
                        $statusFile = "{$tarFile}.status";

                        // Default assumption: if we only have the tar, it's completed (legacy)
                        $data['status'] = 'completed';
                        $data['timestamp'] = file_exists($tarFile) ? filemtime($tarFile) : time();
                        $data['size'] = file_exists($tarFile) ? @filesize($tarFile) : 0;

                        if (file_exists($statusFile)) {
                            // If status file exists, it's the source of truth.
                            // Default to processing until proven otherwise (prevents race condition pops).
                            $data['status'] = 'processing';

                            $content = @file_get_contents($statusFile);
                            $statusData = !empty($content) ? json_decode($content, true) : null;

                            if ($statusData) {
                                $data['status'] = $statusData['status'] ?? 'processing';
                                if (isset($statusData['timestamp']))
                                    $data['timestamp'] = $statusData['timestamp'];

                                if ($data['status'] === 'completed') {
                                    $data['size'] = $statusData['size'] ?? (file_exists($tarFile) ? @filesize($tarFile) : 0);
                                } else {
                                    $data['size'] = file_exists($tarFile) ? @filesize($tarFile) : 0;
                                }
                            }
                        } elseif (!file_exists($tarFile)) {
                            // Neither status nor tar exist (shouldn't happen here due to first loop, but safety first)
                            unset($backups[$filename]);
                        }
                    }
                    $results = array_values($backups);
                }
                usort($results, function ($a, $b) {
                    return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
                });
                json_success(['backups' => $results]);
            }

            if ($method === 'POST') {
                // Check if a backup is already in progress
                if (is_dir($backupDir)) {
                    $files = scandir($backupDir);
                    foreach ($files as $f) {
                        if (substr($f, -7) === '.status') {
                            $statusData = json_decode(@file_get_contents("{$backupDir}/{$f}"), true);
                            if ($statusData && ($statusData['status'] ?? '') === 'processing') {
                                json_error('Un processo di backup è già in corso. Attendi il completamento.', 409);
                            }
                        }
                    }
                }
                // Trigger background generation
                $scriptPath = __ROOT__ . '/scripts/backup_async.php';
                shell_exec("nohup sudo -u $user php {$scriptPath} > /dev/null 2>&1 &");
                json_success(['message' => 'Generazione backup avviata']);
            }

            if ($method === 'DELETE') {
                $body = get_json_body();
                $filename = $body['filename'] ?? $_GET['filename'] ?? '';
                $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
                if (empty($filename)) {
                    json_error('Filename richiesto', 400);
                }
                $filePath = "{$backupDir}/{$filename}";
                $statusPath = "{$filePath}.status";

                // Check if it's processing and kill it
                if (file_exists($statusPath)) {
                    $statusData = json_decode(@file_get_contents($statusPath), true);
                    if ($statusData && ($statusData['status'] ?? '') === 'processing' && !empty($statusData['pid'])) {
                        $pid = (int) $statusData['pid'];
                        // Kill the entire process group (negative PID)
                        shell_exec("sudo kill -9 -- -$pid > /dev/null 2>&1");
                    }
                }

                // Use sudo to remove files created by the background user
                shell_exec("sudo -u $user rm -f " . escapeshellarg($filePath));
                shell_exec("sudo -u $user rm -f " . escapeshellarg($statusPath));

                json_success(['message' => 'Backup eliminato correttamente']);
            }
            break;

        case 'backup-size':
            require_auth();
            $user = get_user();
            $home = get_home();
            $size = trim(shell_exec("sudo -u $user $home/BirdNET-Pi/scripts/backup_data.sh -a size") ?? '0');
            json_success(['size_bytes' => (int) $size]);
            break;

        case 'restore':
            require_auth();
            $config = get_config();
            $home = get_home();
            $recsDir = $config['RECS_DIR'] ?? "{$home}/BirdSongs";
            $restoreDir = rtrim($recsDir, '/') . "/Restore";
            $tempFile = "{$restoreDir}/restore.tar";

            set_time_limit(600); // 10 minutes for slow uploads
            ini_set('memory_limit', '512M');
            if (!is_dir($restoreDir))
                @mkdir($restoreDir, 0777, true);

            if ($method === 'GET') {
                if ($subAction === 'logs') {
                    $logFile = "{$home}/BirdSongs/restore.log";
                    if (file_exists($logFile)) {
                        // Return last 100 lines
                        $output = shell_exec("tail -n 100 " . escapeshellarg($logFile));
                        json_success(['logs' => $output]);
                    } else {
                        json_success(['logs' => "Nessun log trovato.\n"]);
                    }
                }

                // Status check
                $status = ['has_file' => false];
                if (file_exists($tempFile)) {
                    $analysis = validate_restore_archive($tempFile);
                    if ($analysis) {
                        $status = array_merge(['has_file' => true], $analysis);
                    }
                }
                json_success($status);
            }

            if ($method === 'POST') {
                if ($subAction === 'upload-chunk') {
                    $chunkIndex = isset($_POST['chunkIndex']) ? (int) $_POST['chunkIndex'] : 0;
                    $totalChunks = isset($_POST['totalChunks']) ? (int) $_POST['totalChunks'] : 1;

                    if (empty($_FILES)) {
                        $max_upload = ini_get('upload_max_filesize');
                        $max_post = ini_get('post_max_size');
                        json_error("Nessun pezzetto ricevuto. Limiti PHP: upload_max=$max_upload, post_max=$max_post. Prova a ridurre ulteriormente il chunk.", 400);
                    }
                    if ($_FILES["file"]["error"]) {
                        json_error('Errore nell\'upload del pezzo: ' . $_FILES["file"]["error"], 400);
                    }

                    $chunkData = file_get_contents($_FILES["file"]["tmp_name"]);
                    if ($chunkIndex === 0) {
                        // Primo pezzo: sovrascrivi per sicurezza
                        file_put_contents($tempFile, $chunkData);
                    } else {
                        // Pezzi successivi: append
                        file_put_contents($tempFile, $chunkData, FILE_APPEND);
                    }

                    if ($chunkIndex === $totalChunks - 1) {
                        $analysis = validate_restore_archive($tempFile);
                        json_success([
                            'message' => 'Caricamento completato con successo',
                            'analysis' => $analysis,
                            'completed' => true
                        ]);
                    } else {
                        json_success([
                            'message' => "Ricevuto pezzo " . ($chunkIndex + 1) . " di $totalChunks",
                            'completed' => false
                        ]);
                    }
                } elseif ($subAction === 'upload') {
                    if (empty($_FILES))
                        json_error('Nessun file caricato', 400);
                    if ($_FILES["file"]["error"]) {
                        json_error('Errore nell\'upload del file: ' . $_FILES["file"]["error"], 400);
                    }
                    if (move_uploaded_file($_FILES["file"]["tmp_name"], $tempFile)) {
                        $analysis = validate_restore_archive($tempFile);
                        json_success([
                            'message' => 'File caricato con successo',
                            'analysis' => $analysis
                        ]);
                    } else {
                        json_error('Errore nello spostamento del file caricato', 500);
                    }
                } elseif ($subAction === 'start') {
                    if (!file_exists($tempFile))
                        json_error('File di restore non trovato. Caricalo prima.', 404);

                    $analysis = validate_restore_archive($tempFile);
                    if (!$analysis['validation']['mandatory']) {
                        json_error('Il file non contiene tutti i componenti obbligatori richiesti.', 400);
                    }

                    $logFile = "{$home}/BirdSongs/restore.log";
                    shell_exec("nohup sudo -u $user $home/BirdNET-Pi/scripts/backup_data.sh -a restore -f $tempFile > $logFile 2>&1 &");
                    json_success(['message' => 'Ripristino avviato in background. Monitora il log per lo stato.']);
                } else {
                    json_error('Sotto-azione non valida', 400);
                }
            }

            if ($method === 'DELETE') {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                    json_success(['message' => 'File di restore eliminato']);
                } else {
                    json_error('Nessun file di restore da eliminare', 404);
                }
            }
            break;

        case 'stop-services':
            $coreServices = [
                'birdnet_analysis.service',
                'birdnet_recording.service',
                'birdnet_stats.service',
                'birdnet_log.service',
                'chart_viewer.service',
                'extraction.service',
                'icecast2.service',
                'livestream.service',
                'spectrogram_viewer.service',
            ];
            foreach ($coreServices as $svc) {
                shell_exec("sudo systemctl stop $svc 2>&1");
            }
            json_success(['message' => 'Servizi core fermati']);
            break;

        case 'restart-services':
            $coreServices = [
                'birdnet_analysis.service',
                'birdnet_recording.service',
                'birdnet_stats.service',
                'birdnet_log.service',
                'chart_viewer.service',
                'extraction.service',
                'icecast2.service',
                'livestream.service',
                'spectrogram_viewer.service',
            ];
            foreach ($coreServices as $svc) {
                shell_exec("sudo systemctl restart $svc 2>&1");
            }
            json_success(['message' => 'Servizi core riavviati']);
            break;

        default:
            json_error("Azione non valida: $action. Valori ammessi: reboot, shutdown, update, clear-data, info, backup, backup-size, restore, stop-services, restart-services", 400);
    }
}

//  SPECIES LISTS 
function handle_species_lists($method, $type)
{
    $home = get_home();
    $validTypes = [
        'included' => __ROOT__ . "/include_species_list.txt",
        'excluded' => __ROOT__ . "/exclude_species_list.txt",
        'whitelist' => __ROOT__ . "/whitelist_species_list.txt",
    ];

    if (!$type || !isset($validTypes[$type])) {
        json_error("Tipo lista non valido. Valori ammessi: " . implode(', ', array_keys($validTypes)), 400);
    }

    $filePath = $validTypes[$type];

    if ($method === 'GET') {
        $species = [];
        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $species = array_values(array_filter($lines, function ($l) {
                return !empty(trim($l));
            }));
        }
        json_success(['type' => $type, 'species' => $species, 'count' => count($species)]);
    }

    if ($method === 'PUT') {
        $body = get_json_body();
        $species = $body['species'] ?? [];

        if (!is_array($species))
            json_error('Campo species deve essere un array', 400);

        $content = implode("\n", $species) . "\n";
        file_put_contents($filePath, $content);

        json_success(['type' => $type, 'count' => count($species), 'saved' => true]);
    }

    if ($method === 'POST') {
        $body = get_json_body();
        $action = $body['action'] ?? ''; // 'add' or 'remove'
        $name = $body['species_name'] ?? '';

        if (empty($name))
            json_error('species_name richiesto', 400);

        $existing = [];
        if (file_exists($filePath)) {
            $existing = array_values(array_filter(
                file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                function ($l) {
                    return !empty(trim($l));
                }
            ));
        }

        if ($action === 'add') {
            if (!in_array($name, $existing)) {
                $existing[] = $name;
            }
        } elseif ($action === 'remove') {
            $existing = array_values(array_filter($existing, function ($s) use ($name) {
                return $s !== $name;
            }));
        } else {
            json_error("Azione non valida. Usa 'add' o 'remove'", 400);
        }

        file_put_contents($filePath, implode("\n", $existing) . "\n");
        json_success(['type' => $type, 'count' => count($existing), 'action' => $action, 'species' => $name]);
    }

    json_error('Metodo non supportato', 405);
}

//  IMAGE
function handle_image($sciName)
{
    if (!$sciName)
        json_error('Nome scientifico richiesto', 400);

    $config = get_config();
    $sciName = urldecode($sciName);

    // I processi di download e encoding Base64 possono essere gravosi su Pi
    set_time_limit(180);
    ini_set('memory_limit', '512M');

    try {
        if ($config["IMAGE_PROVIDER"] === 'NONE' || empty($config["IMAGE_PROVIDER"])) {
            json_success(array('no_provider' => true));
        } else {
            if ($config["IMAGE_PROVIDER"] === 'FLICKR') {
                $provider = new Flickr();
            } else {
                $provider = new Wikipedia();
            }
            $result = $provider->get_image($sciName);

            if ($result === false) {
                json_error('Immagine non trovata', 404);
            }

            json_success($result);
        }
    } catch (Exception $e) {
        json_error('Errore nel recupero immagine: ' . $e->getMessage(), 500);
    }
}

//  STREAM INFO
function handle_stream_info()
{
    $config = get_config();
    json_success([
        'stream_url' => '/stream',
        'format' => 'audio/mpeg',
        'description' => 'BirdNET-Pi Live Audio Stream',
    ]);
}

// STREAM DETECTIONS
function handle_stream_detections()
{
    $config = get_config();

    $RECS_DIR = $config["RECS_DIR"];
    if (empty($RECS_DIR)) {
        $home = get_home() ?: __ROOT__;
        $RECS_DIR = "$home/BirdSongs/Extracted"; // Fallback to extracted if RECS_DIR is missing
    }

    // We should look in the BirdSongs directory, normally RECS_DIR is "BirdSongs"
    // Wait, let's just make sure we get the correct path. In spectrogram.php:
    // $RECS_DIR = $config["RECS_DIR"];
    // $STREAM_DATA_DIR = $RECS_DIR . "/StreamData/";
    $STREAM_DATA_DIR = rtrim($RECS_DIR, '/') . "/StreamData/";

    $newest_file = '';

    if (empty($config['RTSP_STREAM'])) {
        $look_in_directory = $STREAM_DATA_DIR;
        if (file_exists($look_in_directory) && is_dir($look_in_directory)) {
            $files = scandir($look_in_directory, SCANDIR_SORT_ASCENDING);
            if (isset($files[2])) {
                $newest_file = $files[2];
            }
        }
    } else {
        $look_in_directory = $STREAM_DATA_DIR;

        if (file_exists($look_in_directory) && is_dir($look_in_directory)) {
            $files = scandir($look_in_directory, SCANDIR_SORT_ASCENDING);

            if (!empty($config['RTSP_STREAM_TO_LIVESTREAM']) && is_numeric($config['RTSP_STREAM_TO_LIVESTREAM'])) {
                $RTSP_STREAM_LISTENED_TO = ((int) $config['RTSP_STREAM_TO_LIVESTREAM'] + 1);
            } else {
                $RTSP_STREAM_LISTENED_TO = 1;
            }

            foreach ($files as $stream_file_name) {
                if ($stream_file_name != "." && $stream_file_name != "..") {
                    if (stripos($stream_file_name, 'RTSP_' . $RTSP_STREAM_LISTENED_TO) !== false && stripos($stream_file_name, '.wav.json') !== false) {
                        $newest_file = $stream_file_name;
                    }
                }
            }
        }
    }

    $req_newest_file = $_GET['newest_file'] ?? '';

    // If the client already has this file, return empty response to save bandwidth
    if (!empty($newest_file) && $newest_file === $req_newest_file) {
        json_success(['newest_file_match' => true]);
        return;
    }

    if (!empty($newest_file) && file_exists($look_in_directory . $newest_file)) {
        $contents = file_get_contents($look_in_directory . $newest_file);
        if ($contents !== false) {
            $json = json_decode($contents, true);
            if ($json !== null) {
                $datetime = DateTime::createFromFormat(DateTime::ISO8601, $json['timestamp']);
                $now = new DateTime();
                $interval = $now->diff($datetime);
                // Total seconds elapsed
                $seconds = ($interval->days * 24 * 60 * 60) +
                    ($interval->h * 60 * 60) +
                    ($interval->i * 60) +
                    $interval->s;

                $json['delay'] = $seconds;
                $json['file_name'] = $newest_file; // explicitly inject filename for the client
                json_success($json);
                return;
            }
        }
    }

    json_success(['detections' => []]);
}


// EBIRD EXPORT 
function handle_ebird($method, $id)
{
    if ($method === 'GET' && $id === 'location') {
        $config = [];
        $possiblePaths = [
            '/etc/birdnet/birdnet.conf',
            '/home/pi/BirdNET-Pi/birdnet.conf',
            '/var/www/birdnet/config/birdnet.conf',
            '/etc/birdnet.conf'
        ];
        $configFile = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $configFile = $path;
                break;
            }
        }
        if ($configFile && is_readable($configFile)) {
            $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0)
                    continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    switch ($key) {
                        case 'LATITUDE':
                        case 'LAT':
                            $config['latitude'] = $value;
                            break;
                        case 'LONGITUDE':
                        case 'LON':
                        case 'LONG':
                            $config['longitude'] = $value;
                            break;
                        case 'LOCATION_NAME':
                        case 'LOCATION':
                            $config['locality'] = $value;
                            break;
                        case 'STATE_PROVINCE':
                        case 'STATE':
                            $config['stateProvince'] = $value;
                            break;
                        case 'COUNTRY_CODE':
                        case 'COUNTRY':
                            $config['countryCode'] = $value;
                            break;
                    }
                }
            }
        }
        json_success([
            'latitude' => $config['latitude'] ?? '',
            'longitude' => $config['longitude'] ?? '',
            'locality' => $config['locality'] ?? 'BirdNET-Pi Station',
            'stateProvince' => $config['stateProvince'] ?? '',
            'countryCode' => $config['countryCode'] ?? ''
        ]);
    }

    if ($method === 'GET' && $id === 'ebirddetections') {
        $date = $_GET['date'] ?? null;
        if (!$date)
            json_error('Date parameter required', 400);

        $db = get_db();
        $query = "
            SELECT 
                Date as date, Time as time, Sci_Name as scientific_name, Com_Name as common_name,
                Confidence as confidence, File_Name as filename, Lat as latitude, Lon as longitude
            FROM detections
            WHERE Date = :date AND Sci_Name NOT IN ('Human vocal', 'Human non-vocal', 'Human whistle', 'Dog', 'Power tools', 'Siren', 'Engine', 'Gun', 'Fireworks')
            AND Confidence >= 0.7
            ORDER BY SUBSTR(Time, 1, 2) ASC, Confidence DESC, Time ASC
        ";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':date', $date, SQLITE3_TEXT);
        $result = $stmt->execute();

        $detectionsByHour = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $hour = substr($row['time'], 0, 2);
            if (!isset($detectionsByHour[$hour]))
                $detectionsByHour[$hour] = [];

            $founded = false;
            foreach ($detectionsByHour[$hour] as $myValue) {
                if ($row['scientific_name'] == $myValue['scientific_name']) {
                    $founded = true;
                    break;
                }
            }
            if (!$founded) {
                $detectionsByHour[$hour][] = [
                    'date' => $row['date'],
                    'time' => $row['time'],
                    'scientific_name' => $row['scientific_name'],
                    'common_name' => $row['common_name'],
                    'confidence' => floatval($row['confidence']),
                    'filename' => $row['filename'],
                    'latitude' => $row['latitude'] ?? '',
                    'longitude' => $row['longitude'] ?? '',
                    'included' => true
                ];
            }
        }
        json_success([
            'date' => $date,
            'detectionsByHour' => $detectionsByHour
        ]);
    }

    if ($method === 'POST' && $id === 'export') {
        $input = get_json_body();
        if (!$input)
            json_error('Invalid input JSON', 400);
        if (!isset($input['date']))
            json_error('Missing "date" in JSON', 400);
        if (!isset($input['files']))
            json_error('Missing "files" in JSON', 400);

        $date = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['date']);
        $files = $input['files'];
        if (empty($files))
            json_error('No files to zip', 400);

        $config = get_config();
        $home = get_home();
        $user = get_user();

        if (empty($user)) {
            json_error('Impossibile determinare l\'utente di sistema', 500);
        }

        if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
            $extractedDir = rtrim($config['EXTRACTED'], '/');
        } else {
            $extractedDir = "$home/BirdSongs/Extracted";
        }
        $zipDir = $extractedDir . "/exportsZip";

        if (!is_dir($zipDir)) {
            shell_exec("sudo -u $user mkdir -p " . escapeshellarg($zipDir));
            shell_exec("sudo -u $user chmod 777 " . escapeshellarg($zipDir));
        }

        // Include date in batchId so it's easily extractable from filename
        $batchId = "{$date}_" . time() . "_" . bin2hex(random_bytes(4));
        $batchFile = "{$zipDir}/batch_{$batchId}.json";

        // Native write as the system user using PHP (robust against large checklists and permissions)
        $batchJson = json_encode($files);
        $batchPhp = 'file_put_contents("' . $batchFile . '", base64_decode("' . base64_encode($batchJson) . '"), LOCK_EX); chmod("' . $batchFile . '", 0644);';
        $batchCmd = "sudo -u $user php -r " . escapeshellarg($batchPhp);
        shell_exec($batchCmd);

        $statusFile = "{$zipDir}/eBird_Export_{$batchId}.status";
        $statusData = json_encode([
            'status' => 'processing',
            'date' => $date,
            'type' => 'ebird',
            'batch_id' => $batchId,
            'timestamp' => time()
        ]);
        $statusPhp = 'file_put_contents("' . $statusFile . '", base64_decode("' . base64_encode($statusData) . '"), LOCK_EX); chmod("' . $statusFile . '", 0644);';
        $statusCmd = "sudo -u $user php -r " . escapeshellarg($statusPhp);
        shell_exec($statusCmd);

        $scriptPath = __ROOT__ . '/scripts/export_zip_async.php';
        shell_exec("nohup sudo -u $user php {$scriptPath} " . escapeshellarg($date) . " " . escapeshellarg($batchId) . " > /dev/null 2>&1 &");

        json_success([
            'message' => 'Esportazione eBird avviata in background.',
            'batch_id' => $batchId
        ]);
    }

    json_error('Endpoint ebird non trovato o metodo non supportato', 404);
}

// EXPORT
function handle_export($method, $id)
{
    if ($method === 'POST' && $id === 'zip') {
        $body = get_json_body();
        $date = $body['date'] ?? '';
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_error('Data mancante o nel formato errato (YYYY-MM-DD)', 400);
        }
        $config = get_config();
        $home = get_home();
        $user = get_user();

        if (empty($user)) {
            json_error('Impossibile determinare l\'utente di sistema', 500);
        }

        if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
            $extractedDir = rtrim($config['EXTRACTED'], '/');
        } else {
            $extractedDir = "$home/BirdSongs/Extracted";
        }
        $zipDir = $extractedDir . "/exportsZip";

        if (!is_dir($zipDir)) {
            shell_exec("sudo -u $user mkdir -p " . escapeshellarg($zipDir));
            shell_exec("sudo -u $user chmod 777 " . escapeshellarg($zipDir));
        }

        $audioDir = "{$extractedDir}/By_Date/{$date}";
        if (!is_dir($audioDir)) {
            json_error('Nessuna registrazione trovata per la data selezionata', 404);
        }

        $statusFile = "{$zipDir}/export_{$date}.status";
        if (file_exists($statusFile)) {
            $currentStatus = json_decode(@file_get_contents($statusFile), true);
            if ($currentStatus && ($currentStatus['status'] ?? '') === 'processing') {
                json_success(['message' => 'Un\'esportazione per questa data è già in corso.']);
            }
        }

        // Native write as the system user using PHP (robust against permissions)
        $statusData = json_encode(['status' => 'processing', 'date' => $date, 'timestamp' => time()]);
        $statusPhp = 'file_put_contents("' . $statusFile . '", base64_decode("' . base64_encode($statusData) . '"), LOCK_EX); chmod("' . $statusFile . '", 0644);';
        $statusCmd = "sudo -u $user php -r " . escapeshellarg($statusPhp);
        shell_exec($statusCmd);

        $scriptPath = __ROOT__ . '/scripts/export_zip_async.php';
        shell_exec("nohup sudo -u $user php {$scriptPath} " . escapeshellarg($date) . " > /dev/null 2>&1 &");

        json_success(['message' => 'Esportazione avviata in background.']);
    }

    if ($method === 'GET' && $id === 'csv') {
        $db = get_db();
        $from_date = $_GET['from_date'] ?? null;
        $to_date = $_GET['to_date'] ?? null;
        $species = $_GET['species'] ?? null;

        $where = [];
        $params = [];

        if ($from_date && $to_date) {
            $where[] = "Date BETWEEN :from_date AND :to_date";
            $params[':from_date'] = $from_date;
            $params[':to_date'] = $to_date;
        } elseif ($from_date) {
            $where[] = "Date >= :from_date";
            $params[':from_date'] = $from_date;
        }

        if ($species) {
            $spList = explode(',', $species);
            $inClause = [];
            foreach ($spList as $i => $sp) {
                $p = ":sp_$i";
                $inClause[] = $p;
                $params[$p] = trim($sp);
            }
            if (count($inClause) > 0) {
                $where[] = "Sci_Name IN (" . implode(',', $inClause) . ")";
            }
        }

        $whereStr = count($where) > 0 ? implode(' AND ', $where) : '1=1';
        $stmt = $db->prepare("SELECT Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff FROM detections WHERE $whereStr ORDER BY Date ASC, Time ASC");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        ensure_db_ok($stmt);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="BirdNET_Export_' . date('Ymd_His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Time', 'Sci_Name', 'Com_Name', 'Confidence', 'Lat', 'Lon', 'Cutoff']);

        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, [
                $row['Date'],
                $row['Time'],
                $row['Sci_Name'],
                $row['Com_Name'],
                $row['Confidence'],
                $row['Lat'],
                $row['Lon'],
                $row['Cutoff']
            ]);
        }
        fclose($output);
        exit;
    }

    if ($method === 'GET' && $id === 'zip') {
        $config = get_config();
        $home = get_home();
        if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
            $extractedDir = rtrim($config['EXTRACTED'], '/');
        } else {
            $extractedDir = "$home/BirdSongs/Extracted";
        }
        $zipDir = $extractedDir . "/exportsZip";
        $webDir = "/exportsZip";

        $results = [];
        if (is_dir($zipDir)) {
            $files = scandir($zipDir);
            foreach ($files as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                if (substr($f, -7) === '.status') {
                    $item = json_decode(file_get_contents("{$zipDir}/{$f}"), true);
                    if ($item && $item['status'] === 'processing') {
                        $results[] = [
                            'filename' => '',
                            'date' => $item['date'] ?? '',
                            'status' => 'processing',
                            'timestamp' => (int) ($item['timestamp'] ?? 0),
                            'size' => 0,
                            'url' => ''
                        ];
                    }
                } elseif (substr($f, -4) === '.zip') {
                    if (strpos($f, 'Daily_Export_') === 0 || strpos($f, 'eBird_Export_') === 0) {
                        // Extract YYYY-MM-DD from filename
                        preg_match('/(\d{4}-\d{2}-\d{2})/', $f, $matches);
                        $dateStr = $matches[1] ?? '';

                        // If it's an eBird export without date in standard position, 
                        // we might need to be more flexible, but with our new naming it should work.

                        // Check if a corresponding .status file says it's completed (optional, usually if .zip exists it is completed)
                        $statusName = str_replace('.zip', '.status', $f);
                        if (strpos($statusName, 'export_') !== 0 && strpos($statusName, 'eBird_Export_') !== 0) {
                            // Fallback for old Daily Exports if needed, but they are usually export_YYYY-MM-DD.status
                        }

                        if ($dateStr) {
                            $results[] = [
                                'filename' => $f,
                                'date' => $dateStr,
                                'status' => 'completed',
                                'timestamp' => filemtime("{$zipDir}/{$f}"),
                                'size' => filesize("{$zipDir}/{$f}"),
                                'url' => "{$webDir}/{$f}"
                            ];
                        }
                    }
                }
            }
        }

        usort($results, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        json_success(['zips' => $results]);
    }

    if ($method === 'DELETE' && $id === 'zip') {
        $body = get_json_body();
        $filename = $body['filename'] ?? $_GET['filename'] ?? '';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
        if (empty($filename)) {
            json_error('Filename non specificato', 400);
        }

        $config = get_config();
        $home = get_home();
        $user = get_user();

        if (isset($config['EXTRACTED']) && !empty($config['EXTRACTED'])) {
            $extractedDir = rtrim($config['EXTRACTED'], '/');
        } else {
            $extractedDir = "$home/BirdSongs/Extracted";
        }
        $zipDir = $extractedDir . "/exportsZip";
        $filePath = "{$zipDir}/{$filename}";

        if (file_exists($filePath)) {
            shell_exec("sudo rm -f " . escapeshellarg($filePath));
        }

        // Delete corresponding status file
        $statusPath = str_replace('.zip', '.status', $filePath);
        if (file_exists($statusPath)) {
            $statusData = json_decode(@file_get_contents($statusPath), true);
            // Only delete if it's NOT still processing (safety)
            if (!$statusData || (isset($statusData['status']) && $statusData['status'] !== 'processing')) {
                shell_exec("sudo rm -f " . escapeshellarg($statusPath));
            }
        }

        // Fallback for old naming if needed
        preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $matches);
        if (isset($matches[1])) {
            $oldStatusPath = "{$zipDir}/export_{$matches[1]}.status";
            if (file_exists($oldStatusPath) && $oldStatusPath !== $statusPath) {
                shell_exec("sudo rm -f " . escapeshellarg($oldStatusPath));
            }
        }

        json_success(['message' => 'Zip eliminato correttamente']);
    }

    json_error('Endpoint export non trovato o metodo non supportato', 404);
}

// LOGS
function handle_logs()
{
    //require_auth();
    $cursor = $_GET['cursor'] ?? null;
    $lines = intval($_GET['lines'] ?? 100);

    $user = get_user();

    $command = "sudo -u $user journalctl --no-hostname -q -o short --show-cursor";
    if ($cursor) {
        $command .= " --after-cursor=" . escapeshellarg($cursor);
    } else {
        $command .= " -n " . $lines;
    }
    $command .= " -u birdnet_analysis -u birdnet_recording 2>&1";

    exec($command, $output);

    $newCursor = $cursor;
    $cleanLogs = [];
    $datePrefix = date("M d ");
    $home = get_home();

    foreach ($output as $line) {
        if (preg_match('/^-- cursor: (.*)$/', $line, $matches)) {
            $newCursor = $matches[1];
        } else {
            // Cleaning logic equivalent to the sed command
            // 1. Remove date (e.g. "Mar 06 ")
            $line = str_replace($datePrefix, "", $line);

            // 2. Remove $HOME path
            if ($home) {
                $line = str_replace($home . "/", "", $line);
            }

            // 3. Filter out lines containing "Line", "find", "systemd"
            if (preg_match('/Line|find|systemd/', $line))
                continue;

            // 4. Transform " hostname[pid]: " into "---"
            $line = preg_replace('/ .*\[.*\]: /', '---', $line);

            $cleanLogs[] = $line;
        }
    }

    json_success([
        'logs' => $cleanLogs,
        'cursor' => $newCursor
    ]);
}
function handle_trends($method, $species, $start_date, $end_date)
{
    if ($method !== 'GET') {
        json_error('Metodo non supportato', 405);
    }

    $db = get_db();

    $where_clause = "Sci_Name = :species";
    $where_clause_daily = "d.Sci_Name = :species";

    if ($start_date && $end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $where_clause .= " AND Date BETWEEN '$start_date' AND '$end_date'";
        $where_clause_daily .= " AND d.Date BETWEEN '$start_date' AND '$end_date'";
    } else {
        $where_clause .= " AND Date >= DATE('now', '-30 days', 'localtime')";
        $where_clause_daily .= " AND d.Date >= DATE('now', '-30 days', 'localtime')";
    }

    // 1. Static stats
    $stmt_stats = $db->prepare("SELECT Com_Name, COUNT(*) as total, MAX(Confidence) as max_conf, AVG(Confidence) as avg_conf, MIN(Date) as first_seen, MAX(Date) as last_seen FROM detections WHERE $where_clause");
    $stmt_stats->bindValue(':species', $species);
    ensure_db_ok($stmt_stats);
    $stats = $stmt_stats->execute()->fetchArray(SQLITE3_ASSOC);

    // Best detection
    $stmt_best = $db->prepare("SELECT Date, Time, File_Name, Confidence, Com_Name, Sci_Name FROM detections WHERE $where_clause ORDER BY Confidence DESC LIMIT 1");
    $stmt_best->bindValue(':species', $species);
    ensure_db_ok($stmt_best);
    $best = $stmt_best->execute()->fetchArray(SQLITE3_ASSOC);
    if ($best) {
        $best['Confidence'] = floatval($best['Confidence']);
    }

    // 2. Hourly distribution
    $stmt_hourly = $db->prepare("SELECT SUBSTR(Time, 1, 2) as hour, COUNT(*) as count FROM detections WHERE $where_clause GROUP BY hour");
    $stmt_hourly->bindValue(':species', $species);
    ensure_db_ok($stmt_hourly);
    $res_hourly = $stmt_hourly->execute();
    $hourly = array_fill(0, 24, 0);
    while ($row = $res_hourly->fetchArray(SQLITE3_ASSOC)) {
        $hourly[(int) $row['hour']] = (int) $row['count'];
    }

    // 3a. Get daily detections
    $stmt_daily = $db->prepare("SELECT Date, COUNT(*) as count FROM detections WHERE $where_clause GROUP BY Date ORDER BY Date ASC");
    $stmt_daily->bindValue(':species', $species);
    ensure_db_ok($stmt_daily);
    $res_daily = $stmt_daily->execute();
    $daily_raw_map = [];
    while ($row = $res_daily->fetchArray(SQLITE3_ASSOC)) {
        $daily_raw_map[$row['Date']] = (int) $row['count'];
    }

    // Determine continuous date range
    $start_dt = $start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : date('Y-m-d', strtotime('-30 days'));
    $end_dt = $end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ? $end_date : date('Y-m-d');

    // 3b. Get continuous daily weather stats
    $stmt_weather = $db->prepare("SELECT Date, ROUND(AVG((Temp - 32) * 5.0 / 9.0), 1) as avg_temp, ROUND(AVG(WindSpeed), 1) as avg_wind FROM weather WHERE Date BETWEEN :start AND :end GROUP BY Date");
    $stmt_weather->bindValue(':start', $start_dt);
    $stmt_weather->bindValue(':end', $end_dt);
    ensure_db_ok($stmt_weather);
    $res_weather = $stmt_weather->execute();
    $weather_map = [];
    while ($w = $res_weather->fetchArray(SQLITE3_ASSOC)) {
        $weather_map[$w['Date']] = $w;
    }

    // 3c. Reconstruct continuous daily statistics
    $daily = [];
    $start_ts = strtotime($start_dt);
    $end_ts = strtotime($end_dt);

    for ($t = $start_ts; $t <= $end_ts; $t = strtotime('+1 day', $t)) {
        $date_str = date('Y-m-d', $t);
        $count = $daily_raw_map[$date_str] ?? 0;
        $w = $weather_map[$date_str] ?? ['avg_temp' => null, 'avg_wind' => null];

        $daily[] = [
            'date' => $date_str,
            'count' => $count,
            'avg_temp' => $w['avg_temp'] !== null ? (float) $w['avg_temp'] : null,
            'avg_wind' => $w['avg_wind'] !== null ? (float) $w['avg_wind'] : null
        ];
    }

    // 4. Day vs Night Condition Correlation (Radar Chart) for Species
    $stmt_day_night = $db->prepare("SELECT 
                        w.IsDay,
                        CASE 
                            WHEN w.ConditionCode = 0 THEN 'Clear'
                            WHEN w.ConditionCode BETWEEN 1 AND 3 THEN 'Cloudy'
                            WHEN w.ConditionCode IN (45, 48) THEN 'Fog'
                            WHEN w.ConditionCode BETWEEN 51 AND 64 OR w.ConditionCode BETWEEN 80 AND 82 THEN 'Rain'
                            WHEN w.ConditionCode BETWEEN 71 AND 77 OR w.ConditionCode IN (85, 86) THEN 'Snow'
                            WHEN w.ConditionCode BETWEEN 95 AND 99 OR w.ConditionCode BETWEEN 65 AND 67 THEN 'Thunderstorm'
                            ELSE 'Cloudy'
                        END as description,
                        COUNT(*) as count
                     FROM detections d
                     JOIN weather w ON d.Date = w.Date AND CAST(SUBSTR(d.Time, 1, 2) AS INT) = w.Hour
                     WHERE $where_clause_daily
                     GROUP BY w.IsDay, description");
    $stmt_day_night->bindValue(':species', $species);
    ensure_db_ok($stmt_day_night);
    $res_day_night = $stmt_day_night->execute();
    $day_night_data = [
        'day' => ['Clear' => 0, 'Cloudy' => 0, 'Fog' => 0, 'Rain' => 0, 'Snow' => 0, 'Thunderstorm' => 0],
        'night' => ['Clear' => 0, 'Cloudy' => 0, 'Fog' => 0, 'Rain' => 0, 'Snow' => 0, 'Thunderstorm' => 0]
    ];
    while ($row = $res_day_night->fetchArray(SQLITE3_ASSOC)) {
        $type = ($row['IsDay'] == 1) ? 'day' : 'night';
        $desc = $row['description'];
        if (isset($day_night_data[$type][$desc])) {
            $day_night_data[$type][$desc] = (int) $row['count'];
        }
    }

    // 5. Daily-Hourly distribution for Heatmap
    $stmt_daily_hourly = $db->prepare("SELECT Date, SUBSTR(Time, 1, 2) as hour, COUNT(*) as count FROM detections WHERE $where_clause GROUP BY Date, hour ORDER BY Date ASC, hour ASC");
    $stmt_daily_hourly->bindValue(':species', $species);
    ensure_db_ok($stmt_daily_hourly);
    $res_daily_hourly = $stmt_daily_hourly->execute();
    $daily_hourly = [];
    while ($row = $res_daily_hourly->fetchArray(SQLITE3_ASSOC)) {
        $daily_hourly[] = [
            'date' => $row['Date'],
            'hour' => (int) $row['hour'],
            'count' => (int) $row['count']
        ];
    }

    // 6. Sunrise/Sunset times
    $config = get_config();
    $lat = $config['LATITUDE'] ?? $config['latitude'] ?? '';
    $lon = $config['LONGITUDE'] ?? $config['longitude'] ?? '';
    $sun_info = [];

    if (!empty($lat) && !empty($lon)) {
        $lat = (float) $lat;
        $lon = (float) $lon;
        if (count($daily) > 0) {
            $start = strtotime($daily[0]['date']);
            $end = strtotime($daily[count($daily) - 1]['date']);

            for ($t = $start; $t <= $end; $t = strtotime('+1 day', $t)) {
                $date_str = date('Y-m-d', $t);
                $sun = date_sun_info($t, $lat, $lon);
                if ($sun) {
                    $sunrise_hour = (float) date('H', $sun['sunrise']) + (float) date('i', $sun['sunrise']) / 60.0;
                    $sunset_hour = (float) date('H', $sun['sunset']) + (float) date('i', $sun['sunset']) / 60.0;
                    $sun_info[] = [
                        'date' => $date_str,
                        'sunrise' => round($sunrise_hour, 2),
                        'sunset' => round($sunset_hour, 2),
                    ];
                }
            }
        }
    }

    json_success([
        'species' => $species,
        'stats' => [
            'total' => (int) ($stats['total'] ?? 0),
            'max_confidence' => floatval($stats['max_conf'] ?? 0),
            'avg_confidence' => floatval($stats['avg_conf'] ?? 0),
            'first_seen' => $stats['first_seen'] ?? null,
            'last_seen' => $stats['last_seen'] ?? null,
            'Com_Name' => $stats['Com_Name'] ?? '',
            'best_detection' => $best,
        ],
        'hourly_distribution' => $hourly,
        'daily_trend' => $daily,
        'day_night_condition' => $day_night_data,
        'daily_hourly' => $daily_hourly,
        'sun_info' => $sun_info,
        'server_timezone' => date_default_timezone_get()
    ]);
}
function handle_backup_file($method, $filename)
{
    if ($method !== 'GET')
        json_error('Usa GET', 405);
    require_auth();

    // Sanitize filename to prevent directory traversal
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
    if (empty($filename)) {
        json_error('Filename non valido', 400);
    }

    $home = get_home();
    $config = get_config();
    $recsDir = $config['RECS_DIR'] ?? "{$home}/BirdSongs";
    $backupDir = rtrim($recsDir, '/') . "/Backups";
    $filePath = "{$backupDir}/{$filename}";

    if (!file_exists($filePath)) {
        json_error('File non trovato: ' . $filename, 404);
    }

    // Get file size using shell 'stat' to support files > 2GB on 32-bit systems
    $size = trim(shell_exec("stat -c%s " . escapeshellarg($filePath)));
    if (!is_numeric($size)) {
        $size = @filesize($filePath);
    }

    // Serve the file
    set_time_limit(0); // Disable timeout for large transfers
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 'Off');

    header('Content-Description: File Transfer');
    header('Content-Type: application/x-tar');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    if ($size) {
        header('Content-Length: ' . $size);
    }

    // Chunked read to minimize memory usage and prevent timeouts
    $handle = @fopen($filePath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 1048576); // 1MB chunks
            @ob_flush();
            flush();
        }
        fclose($handle);
    } else {
        readfile($filePath);
    }
    exit;
}
