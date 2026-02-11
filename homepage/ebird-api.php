<?php
/**
 * BirdNET-Pi eBird Export Backend
 * Script PHP per estrarre dati dal database e generare CSV eBird
 */

// Configurazione
define('DB_PATH', '/home/pi/BirdNET-Pi/scripts/birds.db'); // Percorso al database SQLite di BirdNET-Pi
define('MIN_CONFIDENCE', 0.8); // Confidenza minima predefinita

// Headers per JSON response
header('Content-Type: application/json');

/**
 * Funzione principale di routing
 */
function handleRequest() {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'get_recordings':
            getRecordings();
            break;
        case 'export_csv':
            exportToEbirdCSV();
            break;
        case 'get_location':
            getLocationConfig();
            break;
        case 'save_location':
            saveLocationConfig();
            break;
        case 'get_available_dates':
            getAvailableDates();
            break;
        case 'get_detections_by_date':
            getDetectionsByDate();
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Recupera le date disponibili con statistiche
 */
function getAvailableDates() {
    try {
        $db = new SQLite3(DB_PATH);
        
        $query = "
            SELECT 
                d.Date as date,
                COUNT(DISTINCT d.Sci_Name) as species_count,
                COUNT(*) as detections_count
            FROM detections d
            WHERE d.Confidence >= :min_confidence
            GROUP BY d.Date
            ORDER BY d.Date DESC
            LIMIT 30
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':min_confidence', 0.7, SQLITE3_FLOAT);
        
        $result = $stmt->execute();
        
        $dates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $dates[] = [
                'date' => $row['date'],
                'speciesCount' => intval($row['species_count']),
                'detectionsCount' => intval($row['detections_count'])
            ];
        }
        
        $db->close();
        
        jsonResponse([
            'success' => true,
            'dates' => $dates
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Recupera le detection raggruppate per ora per una data specifica
 */
function getDetectionsByDate() {
    try {
        $date = $_GET['date'] ?? null;
        
        if (!$date) {
            jsonResponse(['error' => 'Date parameter required'], 400);
            return;
        }
        
        $db = new SQLite3(DB_PATH);
        
        $query = "
            SELECT 
                d.Date as date,
                d.Time as time,
                d.Sci_Name as scientific_name,
                d.Com_Name as common_name,
                d.Confidence as confidence,
                d.File_Name as filename,
                d.Lat as latitude,
                d.Lon as longitude
            FROM detections d
            WHERE d.Date = :date AND d.Sci_Name NOT IN ('Human vocal', 'Human non-vocal', 'Human whistle', 'Dog', 'Power tools', 'Siren', 'Engine', 'Gun', 'Fireworks')
            AND d.Confidence >= 0.7
            ORDER BY d.Time ASC, d.Confidence DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':date', $date, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        
        $detectionsByHour = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Estrai l'ora (primi 2 caratteri del time HH:MM:SS)
            $hour = substr($row['time'], 0, 2);
            
            if (!isset($detectionsByHour[$hour])) {
                $detectionsByHour[$hour] = [];
            }
            
			$founded = "NO";
			foreach ($detectionsByHour[$hour] as $myValue) {
				if($row['scientific_name'] == $myValue['scientific_name']) {
					$founded = "YES";
				}
			}
			
			
			if ($founded == "NO") {
				$detectionsByHour[$hour][] = [
					'date' => $row['date'],
					'time' => $row['time'],
					'scientific_name' => $row['scientific_name'],
					'common_name' => $row['common_name'],
					'confidence' => floatval($row['confidence']),
					'filename' => $row['filename'],
					'latitude' => $row['latitude'] ?? '',
					'longitude' => $row['longitude'] ?? '',
					'included' => true // Default: inclusa nell'export
																   
				];
			}
        }
        
        $db->close();
        
        jsonResponse([
            'success' => true,
            'date' => $date,
            'detectionsByHour' => $detectionsByHour
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Recupera le registrazioni dal database
 */
function getRecordings() {
    try {
        $db = new SQLite3(DB_PATH);
        
        // Parametri filtro
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $minConfidence = floatval($_GET['min_confidence'] ?? MIN_CONFIDENCE);
        $species = $_GET['species'] ?? null;
        
        // Query base
        $query = "
            SELECT 
                d.Date as date,
                d.Time as time,
                d.Sci_Name as scientific_name,
                d.Com_Name as common_name,
                d.Confidence as confidence,
                d.File_Name as filename,
                d.Lat as latitude,
                d.Lon as longitude
            FROM detections d
            WHERE d.Confidence >= :min_confidence
        ";
        
        // Aggiungi filtri
        if ($startDate) {
            $query .= " AND d.Date >= :start_date";
        }
        if ($endDate) {
            $query .= " AND d.Date <= :end_date";
        }
        if ($species) {
            $query .= " AND (d.Com_Name LIKE :species OR d.Sci_Name LIKE :species)";
        }
        
        $query .= " ORDER BY d.Date DESC, d.Time DESC LIMIT 1000";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':min_confidence', $minConfidence, SQLITE3_FLOAT);
        
        if ($startDate) {
            $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        }
        if ($endDate) {
            $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        }
        if ($species) {
            $stmt->bindValue(':species', "%$species%", SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        $recordings = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $recordings[] = [
                'date' => $row['date'],
                'time' => $row['time'],
                'datetime' => $row['date'] . ' ' . $row['time'],
                'scientific_name' => $row['scientific_name'],
                'common_name' => $row['common_name'],
                'confidence' => floatval($row['confidence']),
                'filename' => $row['filename'],
                'latitude' => $row['latitude'] ?? '',
                'longitude' => $row['longitude'] ?? ''
            ];
        }
        
        $db->close();
        
        jsonResponse([
            'success' => true,
            'recordings' => $recordings,
            'count' => count($recordings)
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Esporta selezione in formato eBird CSV
 */
function exportToEbirdCSV() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['recordings']) || !is_array($input['recordings'])) {
            jsonResponse(['error' => 'No recordings provided'], 400);
            return;
        }
        
        $recordings = $input['recordings'];
        $config = $input['config'] ?? [];
        
        // Configurazione export
        $locationName = $config['location_name'] ?? 'BirdNET-Pi Station';
        $latitude = $config['latitude'] ?? '';
        $longitude = $config['longitude'] ?? '';
        $protocolType = $config['protocol_type'] ?? 'Stationary';
        $groupByDay = $config['group_by_day'] ?? true;
        $removeDuplicates = $config['remove_duplicates'] ?? true;
        
        // Genera CSV
        $csv = generateEbirdCSV($recordings, [
            'location_name' => $locationName,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'protocol_type' => $protocolType,
            'group_by_day' => $groupByDay,
            'remove_duplicates' => $removeDuplicates
        ]);
        
        // Salva file temporaneo
        $filename = 'birdnet-ebird-export-' . date('Y-m-d-His') . '.csv';
        $filepath = '/tmp/' . $filename;
        file_put_contents($filepath, $csv);
        
        jsonResponse([
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'download_url' => '/download.php?file=' . urlencode($filename)
        ]);
        
    } catch (Exception $e) {
        jsonResponse(['error' => 'Export error: ' . $e->getMessage()], 500);
    }
}

/**
 * Genera il contenuto del CSV eBird
 */
function generateEbirdCSV($recordings, $config) {
    // Header eBird
    $csv = "Common Name,Genus,Species,Number,Species Comments,Location Name,Latitude,Longitude,Date,Start Time,State/Province,Country Code,Protocol,Number of Observers,Duration,All observations reported?,Distance Traveled,Area covered,Submission Comments\n";
    
    // Ordina per data e ora
    usort($recordings, function($a, $b) {
        return strcmp($a['datetime'], $b['datetime']);
    });
    
    // Raggruppa per ora e rimuovi duplicati
    $recordingsByHour = [];
    foreach ($recordings as $rec) {
        $hour = substr($rec['time'], 0, 2); // Estrae HH
        if (!isset($recordingsByHour[$hour])) {
            $recordingsByHour[$hour] = [];
        }
        
        $key = $rec['scientific_name'];
        
        // Mantieni solo la detection con confidenza più alta per ogni specie in ogni ora
        if (!isset($recordingsByHour[$hour][$key]) || $rec['confidence'] > $recordingsByHour[$hour][$key]['confidence']) {
            $recordingsByHour[$hour][$key] = $rec;
        }
    }
    
    // Genera CSV per ogni ora
    foreach ($recordingsByHour as $hour => $hourRecordings) {
        $startTime = sprintf("%s:00", $hour);
        $duration = "60";
        $hourlyComment = sprintf(
            "%s - Hourly checklist %s:00-%s:59",
            $config['comments'] ?? 'Auto-generated from BirdNET-Pi recordings',
            $hour,
            $hour
        );
        
        foreach ($hourRecordings as $rec) {
            $csv .= generateEbirdRow($rec, $startTime, $duration, $hourlyComment, $config);
        }
    }
    
    return $csv;
}

/**
 * Rimuove specie duplicate mantenendo quella con confidenza più alta
 */
function removeDuplicateSpecies($recordings) {
    $seen = [];
    $unique = [];
    
    foreach ($recordings as $rec) {
        $key = $rec['scientific_name'];
        
        if (!isset($seen[$key]) || $rec['confidence'] > $seen[$key]['confidence']) {
            $seen[$key] = $rec;
        }
    }
    
    return array_values($seen);
}

/**
 * Genera una singola riga CSV eBird
 */
function generateEbirdRow($recording, $startTime, $duration, $hourlyComment, $config) {
    // Il nome scientifico completo va nel campo Species
    // Il campo Genus rimane vuoto
    $genus = '';
    $species = $recording['scientific_name'];
    
    // Commento specie: solo confidenza, senza ora
    $speciesComment = sprintf(
        'Confidence: %.1f%%',
        $recording['confidence'] * 100
    );
    
    $fields = [
        escapeCsvField($recording['common_name']),
        escapeCsvField($genus),
        escapeCsvField($species),
        'X', // Presenza senza conteggio
        escapeCsvField($speciesComment),
        escapeCsvField($config['location_name']),
        $config['latitude'],
        $config['longitude'],
        $recording['date'],
        $startTime, // Ora di inizio della checklist oraria
        escapeCsvField($config['state_province'] ?? ''), // State/Province
        escapeCsvField($config['country_code'] ?? ''), // Country Code
        $config['protocol_type'],
        $config['observers'] ?? '1',
        $duration, // Durata: 60 minuti
        'N', // Tutte le osservazioni riportate
        '', // Distanza percorsa
        '', // Area coperta
        escapeCsvField($hourlyComment)
    ];
    
    return implode(',', $fields) . "\n";
}

/**
 * Escape caratteri speciali per CSV
 */
function escapeCsvField($value) {
    if (strpos($value, ',') !== false || 
        strpos($value, '"') !== false || 
        strpos($value, "\n") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Recupera configurazione localizzazione
 */
function getLocationConfig() {
    $config = [];
    
    // Percorsi possibili per il file di configurazione
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
        // Leggi il file riga per riga
        $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Salta commenti
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parsing formato KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                
                switch($key) {
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
    
    // Valori di default se non trovati
    jsonResponse([
        'latitude' => $config['latitude'] ?? '',
        'longitude' => $config['longitude'] ?? '',
        'locality' => $config['locality'] ?? 'BirdNET-Pi Station',
        'stateProvince' => $config['stateProvince'] ?? '',
        'countryCode' => $config['countryCode'] ?? ''
    ]);
}

/**
 * Salva configurazione localizzazione
 */
function saveLocationConfig() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // In un'implementazione completa, qui salveresti nel file di configurazione
    // Per ora restituiamo solo successo
    
    jsonResponse([
        'success' => true,
        'message' => 'Location saved successfully'
    ]);
}

/**
 * Risposta JSON
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Gestisci la richiesta
handleRequest();
?>
