<?php
/**
 * BirdNET-Pi eBird Export Backend
 * Script PHP per estrarre dati dal database e generare CSV eBird
 */

// Configurazione
define('DB_PATH', '../scripts/birds.db'); // Percorso al database SQLite di BirdNET-Pi

// Headers per JSON response
header('Content-Type: application/json');

/**
 * Funzione principale di routing
 */
function handleRequest() {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {	  
        case 'get_location':
            getLocationConfig();
            break;	  
        case 'get_detections_by_date':
            getDetectionsByDate();
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
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
