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
session_write_close();
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
            handle_serve_chart($id);
            break;
        case 'media':
            $mediaPath = substr($path, strlen('media/'));
            handle_serve_media($mediaPath);
            break;
        case 'config':
            handle_config($method);
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
            handle_system($method, $id);
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
            }
            else {
                handle_stream_info();
            }
            break;
        case 'ebird':
            handle_ebird($method, $id);
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
}
catch (Exception $e) {
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
            json_success(['authenticated' => true]);
        }
        else {
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
        'total_detections' => (int)$summary['totalcount'],
        'today_detections' => (int)$summary['todaycount'],
        'hour_detections' => (int)$summary['hourcount'],
        'today_species' => (int)$summary['speciestally'],
        'total_species' => (int)$summary['totalspeciestally'],
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
        'total' => (int)$total,
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

        $info['detection_count'] = (int)$info['detection_count'];
        $info['max_confidence'] = floatval($info['max_confidence']);
        $info['avg_confidence'] = floatval($info['avg_confidence']);

        // Best detection
        $stmt2 = $db->prepare("SELECT Date, Time, File_Name, Confidence 
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
            $trend[] = ['date' => $row['Date'], 'count' => (int)$row['count']];
        }
        $info['daily_trend'] = $trend;

        // Image
        $config = get_config();
        try {
            if ($config["IMAGE_PROVIDER"] === 'FLICKR') {
                $provider = new Flickr();
            }
            else {
                $provider = new Wikipedia();
            }
            $image = $provider->get_image($sci_name);
            $info['image'] = $image ?: null;
        }
        catch (Exception $e) {
            $info['image'] = null;
        }

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
            'Count' => (int)$row['Count'],
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

//  RECORDINGS
function handle_recordings($method, $id, $action)
{
    $db = get_db();
    $user = get_user();
    $home = get_home();

    switch ($method) {
        case 'GET':
            $date = $_GET['date'] ?? null;
            $species = $_GET['species'] ?? null;
            $sort = $_GET['sort'] ?? 'date';
            $limit = intval($_GET['limit'] ?? 200);

            $where = [];
            $params = [];

            if ($date) {
                $where[] = "Date = :date";
                $params[':date'] = $date;
            }
            elseif (!$species) {
                // If neither date nor species is provided, default to today
                $where[] = "Date = :date";
                $params[':date'] = date('Y-m-d');
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

            // Find the file in the database
            $stmt = $db->prepare("SELECT Date, Com_Name, File_Name FROM detections WHERE File_Name = :fn LIMIT 1");
            $stmt->bindValue(':fn', $fileName);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$row)
                json_error('File non trovato nel database', 404);

            $comName = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
            $fileRelativePath = "{$row['Date']}/{$comName}/{$fileName}";
            $filePath = rtrim($home, '/') . "/BirdSongs/Extracted/By_Date/" . $fileRelativePath;

            $output = [];
            $cmd = "sudo rm " . escapeshellarg($filePath) . " 2>&1 && sudo rm " . escapeshellarg($filePath . ".png") . " 2>&1";
            if (!exec($cmd, $output)) {
                $dbRw = get_db_rw();
                $delStmt = $dbRw->prepare("DELETE FROM detections WHERE File_Name = :fn LIMIT 1");
                $delStmt->bindValue(':fn', $fileName);
                $result1 = $delStmt->execute();

                if ($result1 === false || $dbRw->changes() === 0) {
                    json_error('Error - database line deletion failed : ' . $dbRw->lastErrorMsg(), 500);
                }

                json_success(['deleted' => true, 'db_rows_affected' => $dbRw->changes()]);
            }
            else {
                json_error('Error - file deletion failed : ' . implode(", ", $output), 500);
            }
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

                $output = [];
                // Execute backend script just like play.php
                $cmd = "sudo -u " . escapeshellarg($user) . " " . escapeshellarg($home . "/BirdNET-Pi/scripts/birdnet_changeidentification.sh") . " " . escapeshellarg($fileName) . " " . escapeshellarg($newName) . " log_errors 2>&1";
                if (!exec($cmd, $output)) {
                    json_success(['updated' => true, 'new_name' => $newName]);
                }
                else {
                    json_error('Error : ' . implode(", ", $output), 500);
                }
            }
            elseif ($action === 'lock' || isset($body['locked'])) {
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
                    }
                    else {
                        json_error('Unable to open exclude file', 500);
                    }
                }
                else {
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
            }
            else {
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
            $byHour[] = ['hour' => $row['hour'], 'count' => (int)$row['count']];
        }

        // Species per hour
        $stmt2 = $db->prepare("SELECT SUBSTR(Time, 1, 2) as hour, COUNT(DISTINCT Sci_Name) as species_count 
                               FROM detections WHERE Date = :date 
                               GROUP BY hour ORDER BY hour");
        $stmt2->bindValue(':date', $date);
        $speciesByHour = [];
        $r2 = $stmt2->execute();
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
            $speciesByHour[] = ['hour' => $row['hour'], 'species_count' => (int)$row['species_count']];
        }

        // Top species for the day
        $stmt3 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count, MAX(Confidence) as max_conf
                               FROM detections WHERE Date = :date 
                               GROUP BY Sci_Name ORDER BY count DESC LIMIT 20");
        $stmt3->bindValue(':date', $date);
        $topSpecies = [];
        $r3 = $stmt3->execute();
        while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
            $row['count'] = (int)$row['count'];
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
                $h = (int)$row['hour'];
                $sciName = $row['Sci_Name'];
                if (isset($speciesHourlyCounts[$sciName])) {
                    $speciesHourlyCounts[$sciName]['hours'][$h] = (int)$row['count'];
                }
            }
        }

        // Chart images (if they exist)
        $home = get_home();
        $chart1 = "$home/BirdSongs/Extracted/Charts/Combo-$date.png";
        $chart2 = "$home/BirdSongs/Extracted/Charts/Combo2-$date.png";

        json_success([
            'date' => $date,
            'total_detections' => (int)$total,
            'detections_by_hour' => $byHour,
            'species_by_hour' => $speciesByHour,
            'top_species' => $topSpecies,
            'species_hourly_counts' => array_values($speciesHourlyCounts),
            'chart1_available' => file_exists($chart1),
            'chart2_available' => file_exists($chart2),
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
            $dates[] = ['date' => $row['Date'], 'count' => (int)$row['count']];
        }
        json_success(['dates' => $dates]);
    }

    json_error('Tipo grafico non valido. Usa: daily, dates', 400);
}

//  WEEKLY REPORT
function handle_report($type)
{
    if ($type !== 'weekly')
        json_error('Tipo report non valido', 400);

    $db = get_db();
    $targetDate = $_GET['date'] ?? date('Y-m-d');

    // date('N') restituisce 1 per Lunedi' e 7 per Domenica.
    // Sottraendo (date('N') - 1) giorni, troviamo esattamente il Lunedi' della settimana in corso.
    $daysToSubtract = date('N', strtotime($targetDate)) - 1;
    $thisWeekStart = date('Y-m-d', strtotime("-{$daysToSubtract} days", strtotime($targetDate)));
    $thisWeekEnd = date('Y-m-d', strtotime("+6 days", strtotime($thisWeekStart)));

    // La settimana precedente inizia 7 giorni prima di $thisWeekStart
    $lastWeekStart = date('Y-m-d', strtotime("-7 days", strtotime($thisWeekStart)));

    // This week
    $stmt = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count, MAX(Confidence) as max_conf
                          FROM detections 
                          WHERE Date >= :start AND Date <= :end
                          GROUP BY Sci_Name ORDER BY count DESC");
    $stmt->bindValue(':start', $thisWeekStart);
    $stmt->bindValue(':end', $thisWeekEnd);
    ensure_db_ok($stmt);
    $thisWeek = [];
    $r = $stmt->execute();
    $totalThisWeek = 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['count'] = (int)$row['count'];
        $row['max_conf'] = floatval($row['max_conf']);
        $totalThisWeek += $row['count'];
        $thisWeek[] = $row;
    }

    // Last week
    $stmt2 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as count
                           FROM detections 
                           WHERE Date >= :start AND Date < :end
                           GROUP BY Sci_Name ORDER BY count DESC");
    $stmt2->bindValue(':start', $lastWeekStart);
    $stmt2->bindValue(':end', $thisWeekStart);
    ensure_db_ok($stmt2);
    $lastWeek = [];
    $r2 = $stmt2->execute();
    $totalLastWeek = 0;
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
        $lastWeek[$row['Sci_Name']] = (int)$row['count'];
        $totalLastWeek += (int)$row['count'];
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

    json_success([
        'period_start' => $thisWeekStart,
        'period_end' => $thisWeekEnd,
        'total_detections' => $totalThisWeek,
        'total_previous' => $totalLastWeek,
        'total_percent_change' => $totalPctChange,
        'unique_species' => count($thisWeek),
        'unique_species_previous' => count($lastWeek),
        'new_species' => $newSpecies,
        'species' => $speciesWithChange,
    ]);
}

//  SERVE CHART (IMAGE)
function handle_serve_chart($filename)
{
    if (!$filename)
        json_error('Nome file richiesto', 400);

    // Security check to avoid path traversal
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
        json_error('Nome file non valido', 400);
    }

    $home = get_home();
    // Default fallback path for XAMPP
    if (empty($home))
        $home = __ROOT__;

    // Charts path logic, handling both Raspberry Pi and local XAMPP structure
    $chartPath1 = "$home/BirdSongs/Extracted/Charts/$filename";
    $chartPath2 = __ROOT__ . "/Charts/$filename";

    $path = file_exists($chartPath1) ? $chartPath1 : (file_exists($chartPath2) ? $chartPath2 : null);

    if (!$path) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Grafico non trovato'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Serve image
    $mime = mime_content_type($path);
    if (strpos($mime, 'image/') !== 0) {
        $mime = 'image/png';
    }

    header("Content-Type: $mime");
    header("Content-Length: " . filesize($path));
    // Cache for 1 day
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
    }
    else {
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
function handle_config($method)
{
    if ($method === 'GET') {
        require_auth();
        $config = get_config();

        $keys = [
            'SITE_NAME' => 'BirdNET-Pi', 'LATITUDE' => '', 'LONGITUDE' => '', 'BIRDNET_USER' => '',
            'MODEL' => '', 'DATABASE_LANG' => 'en', 'BIRDWEATHER_ID' => '', 'CONFIDENCE' => '0.7',
            'SENSITIVITY' => '1.0', 'OVERLAP' => '0.0', 'AUDIOFMT' => 'mp3', 'RECORDING_LENGTH' => '15',
            'EXTRACTION_LENGTH' => '6', 'IMAGE_PROVIDER' => 'WIKIPEDIA', 'INFO_SITE' => 'ALLABOUTBIRDS',
            'APPRISE_NOTIFICATION_TITLE' => '', 'APPRISE_NOTIFICATION_BODY' => '', 'APPRISE_NOTIFY_NEW_SPECIES' => '0',
            'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => '0', 'APPRISE_WEEKLY_REPORT' => '0', 'COLOR_SCHEME' => 'light',

            // Basic Additions
            'APPRISE_NOTIFY_EACH_DETECTION' => '0', 'FLICKR_API_KEY' => '', 'FLICKR_FILTER_EMAIL' => '',
            'APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES' => '0', 'SF_THRESH' => '0.03',
            'DATA_MODEL_VERSION' => '1', 'APPRISE_ONLY_NOTIFY_SPECIES_NAMES' => '', 'APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2' => '',

            // Advanced Additions
            'CADDY_PWD' => '', 'ICE_PWD' => '', 'BIRDNETPI_URL' => '', 'RTSP_STREAM' => '',
            'RTSP_STREAM_TO_LIVESTREAM' => '', 'ACTIVATE_FREQSHIFT_IN_LIVESTREAM' => '',
            'FREQSHIFT_HI' => '', 'FREQSHIFT_LO' => '', 'FREQSHIFT_PITCH' => '', 'FREQSHIFT_TOOL' => 'sox',
            'FREQSHIFT_RECONNECT_DELAY' => '1000', 'FULL_DISK' => 'keep', 'PURGE_THRESHOLD' => '90',
            'MAX_FILES_SPECIES' => '0', 'PRIVACY_THRESHOLD' => '0', 'REC_CARD' => 'default', 'CHANNELS' => '1',
            'SILENCE_UPDATE_INDICATOR' => '0', 'AUTOMATIC_UPDATE' => '0', 'RAW_SPECTROGRAM' => '0',
            'RARE_SPECIES_THRESHOLD' => '30', 'CUSTOM_IMAGE' => '', 'CUSTOM_IMAGE_TITLE' => '',
            'LogLevel_BirdnetRecordingService' => 'error', 'LogLevel_SpectrogramViewerService' => 'error',
            'LogLevel_LiveAudioStreamService' => 'error'
        ];

        $response = [];
        foreach ($keys as $key => $default) {
            $val = $config[$key] ?? $default;
            if (is_numeric($default) && strpos((string)$default, '.') !== false) {
                $response[$key] = floatval($val);
            }
            else if (is_numeric($default)) {
                // Return as string or int depending on usage, but sticking to config as strings is safest for most,
                // except some are floats like overlap.
                $response[$key] = $val;
            }
            else {
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
            'SITE_NAME', 'LATITUDE', 'LONGITUDE', 'BIRDNET_USER',
            'MODEL', 'DATABASE_LANG', 'BIRDWEATHER_ID', 'CONFIDENCE',
            'SENSITIVITY', 'OVERLAP', 'AUDIOFMT', 'RECORDING_LENGTH',
            'EXTRACTION_LENGTH', 'IMAGE_PROVIDER', 'INFO_SITE',
            'APPRISE_NOTIFICATION_TITLE', 'APPRISE_NOTIFICATION_BODY', 'APPRISE_NOTIFY_NEW_SPECIES',
            'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY', 'APPRISE_WEEKLY_REPORT', 'COLOR_SCHEME',

            'APPRISE_NOTIFY_EACH_DETECTION', 'FLICKR_API_KEY', 'FLICKR_FILTER_EMAIL',
            'APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES', 'SF_THRESH',
            'DATA_MODEL_VERSION', 'APPRISE_ONLY_NOTIFY_SPECIES_NAMES', 'APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2',

            'CADDY_PWD', 'ICE_PWD', 'BIRDNETPI_URL', 'RTSP_STREAM',
            'RTSP_STREAM_TO_LIVESTREAM', 'ACTIVATE_FREQSHIFT_IN_LIVESTREAM',
            'FREQSHIFT_HI', 'FREQSHIFT_LO', 'FREQSHIFT_PITCH', 'FREQSHIFT_TOOL',
            'FREQSHIFT_RECONNECT_DELAY', 'FULL_DISK', 'PURGE_THRESHOLD',
            'MAX_FILES_SPECIES', 'PRIVACY_THRESHOLD', 'REC_CARD', 'CHANNELS',
            'SILENCE_UPDATE_INDICATOR', 'AUTOMATIC_UPDATE', 'RAW_SPECTROGRAM',
            'RARE_SPECIES_THRESHOLD', 'CUSTOM_IMAGE', 'CUSTOM_IMAGE_TITLE',
            'LogLevel_BirdnetRecordingService', 'LogLevel_SpectrogramViewerService',
            'LogLevel_LiveAudioStreamService'
        ];

        $configPath = '/etc/birdnet/birdnet.conf';
        if (!file_exists($configPath) || !is_writable($configPath)) {
            json_error('File di configurazione non accessibile', 500);
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

            $oldValue = $old_config[$key] ?? null;
            if ($oldValue === $value)
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

            // Save to config file
            $pattern = "/^" . preg_quote($key) . "=.*$/m";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "$key=\"$value\"", $content);
            }
            else {
                $content .= "\n$key=\"$value\"";
            }
            $updated[] = $key;
        }

        if (!empty($updated)) {
            file_put_contents($configPath, $content);

            if (function_exists('get_config')) {
                get_config(true); // Force config reload
            }

            // Execute service restarts asynchronously to avoid blocking API
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
        }
        elseif ($action === 'enable') {
            if ($serviceName === 'livestream') {
                shell_exec("sudo systemctl enable icecast2.service 2>&1");
                shell_exec("sudo systemctl start icecast2.service 2>&1");
                $output = shell_exec("sudo systemctl enable --now  livestream.service 2>&1");
                $output .= "\n" . shell_exec("sudo systemctl start livestream.service 2>&1");
            }
            else {
                $output = shell_exec("sudo systemctl enable --now  $svcName 2>&1");
            }
        }
        else {
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

//  SYSTEM 
function handle_system($method, $action)
{
    // GET is allowed only for 'info' (read-only); all other actions require POST
    if ($action === 'info' && $method !== 'GET' && $method !== 'POST')
        json_error('Usa GET o POST', 405);
    if ($action !== 'info' && $method !== 'POST')
        json_error('Usa POST', 405);
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
                ? (int)trim(shell_exec("sudo -u $user $gitBin -C $gitRepo rev-list HEAD..origin/$gitBranch --count 2>/dev/null") ?? '0')
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

        case 'backup':
            $backupPath = "/tmp/birdnet_backup_" . date('Y-m-d_His') . ".tar.gz";
            $dbPath = __ROOT__ . '/scripts/birds.db';
            shell_exec("tar -czf $backupPath $dbPath /etc/birdnet/birdnet.conf 2>/dev/null");

            if (file_exists($backupPath)) {
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
                readfile($backupPath);
                unlink($backupPath);
                exit;
            }
            json_error('Errore nella creazione del backup', 500);
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
            json_error("Azione non valida: $action. Valori ammessi: reboot, shutdown, update, clear-data, info, backup, stop-services, restart-services", 400);
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
        }
        elseif ($action === 'remove') {
            $existing = array_values(array_filter($existing, function ($s) use ($name) {
                return $s !== $name;
            }));
        }
        else {
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

    try {
        if ($config["IMAGE_PROVIDER"] === 'FLICKR') {
            $provider = new Flickr();
        }
        else {
            $provider = new Wikipedia();
        }
        $result = $provider->get_image($sciName);

        if ($result === false) {
            json_error('Immagine non trovata', 404);
        }

        json_success($result);
    }
    catch (Exception $e) {
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
    }
    else {
        $look_in_directory = $STREAM_DATA_DIR;

        if (file_exists($look_in_directory) && is_dir($look_in_directory)) {
            $files = scandir($look_in_directory, SCANDIR_SORT_ASCENDING);

            if (!empty($config['RTSP_STREAM_TO_LIVESTREAM']) && is_numeric($config['RTSP_STREAM_TO_LIVESTREAM'])) {
                $RTSP_STREAM_LISTENED_TO = ((int)$config['RTSP_STREAM_TO_LIVESTREAM'] + 1);
            }
            else {
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
            ORDER BY Time ASC, Confidence DESC
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
        $extractedDir = isset($config['EXTRACTED']) ? rtrim($config['EXTRACTED'], '/') : (__ROOT__ . "/Extracted");

        $zipFileName = "eBird_Export_{$date}.zip";
        $realZipDir = $extractedDir . "/eBirdZips";
        $webZipDir = "/eBirdZips";

        if (!file_exists($realZipDir))
            @mkdir($realZipDir, 0777, true);
        $finalZipPath = "{$realZipDir}/{$zipFileName}";

        $zip = new ZipArchive();
        if ($zip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            json_error('Cannot create zip file', 500);
        }

        $home = get_home() ?: __ROOT__;
        $audioDir = "{$home}/BirdSongs/Extracted/By_Date/{$date}";

        $addedFiles = 0;
        foreach ($files as $item) {
            $filename = $item['filename'] ?? '';
            $species = $item['species'] ?? 'Unknown_Species';
            $safeSpecies = preg_replace('/[^a-zA-Z0-9_ -]/', '_', $species);

            $sourcePath = "{$audioDir}/{$safeSpecies}/{$filename}";
            if (file_exists($sourcePath)) {
                $zip->addFile($sourcePath, "{$safeSpecies}/{$filename}");
                $addedFiles++;
            }
            else {
                $flatSourcePath = "{$audioDir}/{$filename}";
                if (file_exists($flatSourcePath)) {
                    $zip->addFile($flatSourcePath, "{$safeSpecies}/{$filename}");
                    $addedFiles++;
                }
            }
        }
        $zip->close();

        if ($addedFiles === 0) {
            if (file_exists($finalZipPath))
                @unlink($finalZipPath);
            json_error('Nessun file audio originale trovato da inserire nello zip', 404);
        }

        $downloadUrl = "{$webZipDir}/{$zipFileName}";

        json_success([
            'download_url' => $downloadUrl,
            'files_zipped' => $addedFiles
        ]);
    }

    json_error('Endpoint ebird non trovato o metodo non supportato', 404);
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
    }
    else {
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
        }
        else {
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
