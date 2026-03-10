<?php

define('__ROOT__', dirname(dirname(__FILE__)));

if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();

function ensure_db_ok($sql_stmt)
{
  if ($sql_stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}

function set_timezone()
{
  if (!isset($_SESSION['my_timezone'])) {
    $tz = @trim(shell_exec('timedatectl show --value --property=Timezone 2>nul'));
    if (empty($tz))
      $tz = @date_default_timezone_get() ?: 'UTC';
    $_SESSION['my_timezone'] = $tz;
  }
  if (!empty($_SESSION['my_timezone'])) {
    date_default_timezone_set($_SESSION['my_timezone']);
  }
}

function get_config($force_reload = false)
{
  $mtime = stat('/etc/birdnet/birdnet.conf')["mtime"];
  if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
    $force_reload = true;
  }
  if (!isset($_SESSION['my_config']) || $force_reload) {
    $source = preg_replace("~^#+.*$~m", "", file_get_contents('/etc/birdnet/birdnet.conf'));
    $my_config = parse_ini_string($source);
    if ($my_config) {
      $_SESSION['my_config'] = $my_config;
    }
    else {
      syslog(LOG_ERR, "Cannot parse config");
    }
    $_SESSION['my_config_version'] = $mtime;
  }
  return $_SESSION['my_config'];
}

function get_user()
{
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

function get_home()
{
  $home = '/home/' . get_user();
  return $home;
}

function get_sitename()
{
  $config = get_config();

  if ($config["SITE_NAME"] == "") {
    $site_name = "BirdNET-Pi";
  }
  else {
    $site_name = $config['SITE_NAME'];
  }
  return $site_name;
}

function get_service_mount_name()
{
  $home = get_home();
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . $home . "/BirdSongs/StreamData"));
  return $service_mount;
}

function is_authenticated()
{
  $ret = false;
  if (isset($_SERVER['PHP_AUTH_USER'])) {
    $config = get_config();
    $ret = ($_SERVER['PHP_AUTH_PW'] == $config['CADDY_PWD'] && $_SERVER['PHP_AUTH_USER'] == 'birdnet');
  }
  return $ret;
}

function ensure_authenticated($error_message = 'You cannot edit the settings for this installation')
{
  if (!is_authenticated()) {
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<table><tr><td>' . $error_message . '</td></tr></table>';
    exit;
  }
}

function debug_log($message)
{
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

function get_com_en_name($sci_name)
{
  if (!isset($_labels_flickr)) {
    $_labels_flickr = json_decode(file_get_contents(get_home() . "/BirdNET-Pi/model/l18n/labels_en.json"), true);
  }
  $engname = $_labels_flickr[$sci_name];
  return $engname;
}

function get_label($record, $sort_by, $date = null)
{
  $name = $record["Com_Name"];
  if ($sort_by == "confidence") {
    $ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
  }
  elseif ($sort_by == "occurrences") {
    $valuescount = $record['Count'];
    if ($valuescount >= 1000) {
      $ret = $name . ' (' . round($valuescount / 1000, 1) . 'k)';
    }
    else {
      $ret = $name . ' (' . $valuescount . ')';
    }
  }
  elseif (($sort_by == "date") && !isset($date)) {
    $ret = $name . ' (' . $record['Date'] . ')';
  }
  elseif (($sort_by == "date") && isset($date)) {
    $ret = $name . ' (' . $record['Time'] . ')';
  }
  else {
    $ret = $name;
  }
  return $ret;
}

function get_db()
{
  if (!isset($_db)) {
    $_db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
    $_db->busyTimeout(1000);
  }
  return $_db;
}

function fetch_species_array($sort_by, $date = null)
{
  $db = get_db();
  $where = (isset($date)) ? "WHERE Date == \"$date\"" : "";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY COUNT(*) DESC");
  }
  elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MAX(Confidence) DESC");
  }
  elseif ($sort_by === "date") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MIN(Date) DESC, Time DESC");
  }
  else {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY Com_Name ASC");
  }
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function fetch_best_detection($com_name)
{
  $db = get_db();
  $statement = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*), MAX(Confidence), File_Name, Date, Time from detections WHERE Com_Name = \"$com_name\"");
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function fetch_all_detections($sci_name, $sort_by, $date = null)
{
  $db = get_db();
  $filter = (isset($date)) ? "AND Date == \"$date\"" : "";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == \"$sci_name\" $filter ORDER BY COUNT(*) DESC");
  }
  elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == \"$sci_name\" $filter ORDER BY Confidence DESC");
  }
  else {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
    $statement = $db->prepare("SELECT * FROM detections where Sci_Name == \"$sci_name\" $filter ORDER BY $order");
  }
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function get_summary()
{
  $db = get_db();
  $statement = $db->prepare('SELECT COUNT(*) FROM detections');
  ensure_db_ok($statement);
  $result = $statement->execute();
  $totalcount = $result->fetchArray(SQLITE3_ASSOC);

  $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
  ensure_db_ok($statement2);
  $result2 = $statement2->execute();
  $todaycount = $result2->fetchArray(SQLITE3_ASSOC);

  $statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')');
  ensure_db_ok($statement3);
  $result3 = $statement3->execute();
  $hourcount = $result3->fetchArray(SQLITE3_ASSOC);

  $statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')');
  ensure_db_ok($statement5);
  $result5 = $statement5->execute();
  $todayspeciestally = $result5->fetchArray(SQLITE3_ASSOC);

  $statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections');
  ensure_db_ok($statement6);
  $result6 = $statement6->execute();
  $totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

  $ret = [
    'totalcount' => $totalcount['COUNT(*)'],
    'todaycount' => $todaycount['COUNT(*)'],
    'hourcount' => $hourcount['COUNT(*)'],
    'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))']
  ];
  return $ret;
}

class ImageProvider
{

  protected $db = null;
  protected $db_path = null;
  protected $db_reset = false;
  protected $context = null;

  public function __construct()
  {
    $this->set_db();
    // User-Agent conforme alle policy Wikimedia per evitare blocchi 429
    $ua = "BirdNET-Pi/1.1 (https://github.com/mcguirepr89/BirdNET-Pi; birds@birdnetpi.com) Mozilla/5.0";
    $opts = [
      'http' => [
        'header' => "User-Agent: $ua",
        'timeout' => 15,
        'follow_location' => 1,
        'ignore_errors' => false // Vogliamo che file_get_contents ritorni false su errori HTTP
      ]
    ];
    $this->context = stream_context_create($opts);
  }

  public function get_image($sci_name)
  {
    $image = $this->get_image_from_db($sci_name);
    if ($image !== false) {
      $now = new DateTime();
      $datetime = DateTime::createFromFormat("Y-m-d", $image['date_created']);
      $interval = $now->diff($datetime);
      $expire_days = rand(15, 25);
      if ($interval->days > $expire_days) {
        $image = false;
      }

      // Se l'immagine è in cache ma non ha i dati Base64 (migrazione legacy), 
      // scaricali e aggiorna il DB ora.
      if ($image !== false && empty($image['base64_data'])) {
        $base64 = $this->get_base64_image($image['image_url']);
        if ($base64) {
          $this->set_image_in_db(
            $image['sci_name'],
            $image['com_en_name'],
            $image['image_url'],
            $image['title'],
            $image['id'],
            $image['author_url'],
            $image['license_url'],
            $base64
          );
          $image['base64_data'] = $base64;
        }
      }
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    return $image;
  }

  public function get_cached_image($sci_name)
  {
    return $this->get_image_from_db($sci_name);
  }

  public function is_reset()
  {
    return $this->db_reset;
  }

  protected function get_json($url)
  {
    return json_decode(file_get_contents($url, false, $this->context), true);
  }

  protected function set_db()
  {
    try {
      if ($this->db === null) {
        $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
        $this->db = $db;

        // Verifica se la colonna base64_data esiste, altrimenti aggiungila (migrazione)
        $result = $this->db->query("PRAGMA table_info(images)");
        $hasBase64 = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
          if ($row['name'] === 'base64_data') {
            $hasBase64 = true;
            break;
          }
        }
        if (!$hasBase64) {
          $this->db->exec("ALTER TABLE images ADD COLUMN base64_data TEXT");
        }
      }
    }
    catch (Exception $ex) {
      $this->create_tables();
    }
    $this->db->busyTimeout(1000);
  }

  protected function create_tables()
  {
    $tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE, base64_data TEXT)";
    $db = new SQLite3($this->db_path);
    $db->exec($tbl_def);
    $db->exec('CREATE TABLE source (ID INTEGER PRIMARY KEY, email VARCHAR(63), uid VARCHAR(63), date_created DATE)');
    $this->db_reset = true;
    $this->db = $db;
  }

  protected function delete_image_from_db($sci_name)
  {
    $statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->execute();
  }

  protected function get_image_from_db($sci_name)
  {
    $statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created, base64_data FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url, $base64_data = null)
  {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, DATE(\"now\"), :base64_data)");
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->bindValue(':com_en_name', $com_en_name);
    $statement0->bindValue(':image_url', $image_url);
    $statement0->bindValue(':title', $title);
    $statement0->bindValue(':id', $id);
    $statement0->bindValue(':author_url', $author_url);
    $statement0->bindValue(':license_url', $license_url);
    $statement0->bindValue(':base64_data', $base64_data);
    $statement0->execute();
  }

  protected function get_base64_image($url)
  {
    if (empty($url))
      return null;
    try {
      $content = @file_get_contents($url, false, $this->context);

      // Se il download fallisce o il contenuto è vuoto, ritorna null
      if ($content === false || empty($content)) {
        return null;
      }

      $mime = "image/jpeg"; // Default fallback
      if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $content);
        finfo_close($finfo);
      }
      elseif (function_exists('getimagesizefromstring')) {
        $size = getimagesizefromstring($content);
        if ($size && isset($size['mime'])) {
          $mime = $size['mime'];
        }
      }

      // Validazione CRITICA: Se il MIME è html o xml, abbiamo catturato una pagina di errore (es: 429)
      if (strpos($mime, 'text/html') !== false || strpos($mime, 'application/xml') !== false) {
        return null;
      }

      // Assicuriamoci che sia effettivamente un'immagine
      if (strpos($mime, 'image/') === 0) {
        return 'data:' . $mime . ';base64,' . base64_encode($content);
      }
    }
    catch (\Throwable $e) {
    // Ignora errori di download o encoding, ritorni null per fallback su URL
    }
    return null;
  }
}

class Flickr extends ImageProvider
{

  protected $db_path = __ROOT__ . '/scripts/flickr.db';

  private $flickr_api_key = null;
  private $args = "&license=2%2C3%2C4%2C5%2C6%2C9&orientation=square,portrait";
  private $blacklisted_ids = [];
  private $licenses_urls = [];
  private $flickr_email = null;
  private $comnameprefix = "%20bird";

  public function __construct()
  {
    parent::__construct();

    $blacklisted = get_home() . "/BirdNET-Pi/scripts/blacklisted_images.txt";
    if (file_exists($blacklisted)) {
      $blacklisted_file = file($blacklisted);
      if ($blacklisted_file) {
        $this->blacklisted_ids = array_map('trim', $blacklisted_file);
      }
    }
    $this->flickr_api_key = get_config()["FLICKR_API_KEY"];
    $this->flickr_email = get_config()["FLICKR_FILTER_EMAIL"];
    $source = $this->get_uid_from_db();
    if ($source['email'] !== $this->flickr_email) {
      // reset the DB
      $this->db->exec("DROP TABLE images;");
      $this->create_tables();
      if (!empty($this->flickr_email)) {
        $source = $this->get_uid_from_db();
        if ($source['email'] !== $this->flickr_email) {
          $this->get_uid_from_flickr();
          $source = $this->get_uid_from_db();
        }
      }
      else {
        $this->set_uid_in_db("");
      }
    }
    if (!empty($this->flickr_email)) {
      $this->args = "&user_id=" . $source['uid'];
      $this->comnameprefix = "";
    }
  }

  public function get_image($sci_name)
  {
    $image = parent::get_image_from_db($sci_name);
    if ($image !== false && in_array($image['id'], $this->blacklisted_ids)) {
      $image = false;
      $this->delete_image_from_db($sci_name);
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    if ($image === false)
      return false;
    // external link to photo
    $photos_url = str_replace('/people/', '/photos/', $image['author_url'] . '/' . $image['id']);
    $image['photos_url'] = $photos_url;
    return $image;
  }

  private function get_from_source($sci_name)
  {
    $engname = get_com_en_name($sci_name);

    $flickr_url = "https://www.flickr.com/services/rest/?method=flickr.photos.search&api_key=" . $this->flickr_api_key . "&text=" . str_replace(" ", "%20", $engname) . $this->comnameprefix . "&sort=relevance" . $this->args . "&per_page=5&media=photos&format=json&nojsoncallback=1";
    $flickr_data = $this->get_json($flickr_url);
    $flickrjson = isset($flickr_data["photos"]["photo"]) ? $flickr_data["photos"]["photo"] : [];
    // could be null!!
    // Find the first photo that is not blacklisted or is not the specific blacklisted id
    $photo = null;
    foreach ($flickrjson as $flickrphoto) {
      if ($flickrphoto["id"] !== "4892923285" && !in_array($flickrphoto["id"], $this->blacklisted_ids)) {
        $photo = $flickrphoto;
        break;
      }
    }

    if ($photo === null)
      return;

    $license_response = $this->get_json("https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=" . $this->flickr_api_key . "&photo_id=" . $photo["id"] . "&format=json&nojsoncallback=1");
    $license_id = $license_response["photo"]["license"];
    $license_url = $this->get_license_url($license_id);

    $authorlink = "https://flickr.com/people/" . $photo["owner"];
    $imageurl = 'https://farm' . $photo["farm"] . '.static.flickr.com/' . $photo["server"] . '/' . $photo["id"] . '_' . $photo["secret"] . '.jpg';

    // Scarica e codifica l'immagine in Base64 per la cache locale
    $base64_data = $this->get_base64_image($imageurl);

    $this->set_image_in_db($sci_name, $engname, $imageurl, $photo["title"], $photo["id"], $authorlink, $license_url, $base64_data);
  }

  private function get_license_url($id)
  {
    if (empty($this->licenses_urls)) {
      $licenses_url = "https://api.flickr.com/services/rest/?method=flickr.photos.licenses.getInfo&api_key=" . $this->flickr_api_key . "&format=json&nojsoncallback=1";
      $licenses_response = $this->get_json($licenses_url);
      $licenses_data = $licenses_response["licenses"]["license"];
      foreach ($licenses_data as $license) {
        $license_id = $license["id"];
        $license_url = $license["url"];
        $this->licenses_urls[$license_id] = $license_url;
      }
    }
    return $this->licenses_urls[$id];
  }

  public function get_uid_from_db()
  {
    $statement0 = $this->db->prepare('SELECT email, uid, date_created FROM source');
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function set_uid_in_db($uid)
  {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO source VALUES (1, :email, :uid, DATE(\"now\"))");
    $statement0->bindValue(':email', $this->flickr_email);
    $statement0->bindValue(':uid', $uid);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function get_uid_from_flickr()
  {
    $uid = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.people.findByEmail&api_key=" . $this->flickr_api_key . "&find_email=" . $this->flickr_email . "&format=json&nojsoncallback=1"), true)["user"]["nsid"];
    $this->set_uid_in_db($uid);
  }
}

class Wikipedia extends ImageProvider
{

  protected $db_path = __ROOT__ . '/scripts/wikipedia.db';

  protected function get_from_source($sci_name)
  {
    $page_title = str_replace(' ', '_', $sci_name);
    $data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title");
    if ($data == false or !isset($data['originalimage']))
      return;

    $image_name = substr($data['originalimage']['source'], strrpos($data['originalimage']['source'], '/') + 1);
    $metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|size&format=json");
    if ($metadata == false or !isset($metadata['query']['pages']))
      return;

    $image_url = $data['originalimage']['source'];
    $title = $data['title'];

    foreach ($metadata['query']['pages'] as $page) {
      $details = $page['imageinfo']['0']['extmetadata'];
      $author = isset($details['Artist']) ? $details['Artist']['value'] : 'Wikipedia User';
      $matches = [];
      if (preg_match('/href="(http\S*)"/', $author, $matches)) {
        $author_url = $matches[1];
      }
      else {
        $author_url = $this->get_external_link($image_url);
      }
      $license_url = isset($details['LicenseUrl']) ? $details['LicenseUrl']['value'] : $this->get_external_link($image_url);

      // Costruzione URL thumbnail più robusta se l'immagine è troppo grande
      if (isset($page["imageinfo"][0]["width"]) && $page["imageinfo"][0]["width"] > 1024) {
        if (strpos($image_url, '/commons/') !== false) {
          $image_url = str_replace('/commons/', '/commons/thumb/', $image_url) . '/1024px-' . $image_name;
        }
        elseif (preg_match('#/wikipedia/(\w+)/#', $image_url, $m)) {
          $image_url = str_replace("/wikipedia/{$m[1]}/", "/wikipedia/{$m[1]}/thumb/", $image_url) . '/1024px-' . $image_name;
        }
      }
    }

    $engname = get_com_en_name($sci_name);

    // Usa la thumbnail della REST API come fallback primario per il download (spesso 320px)
    // Se abbiamo costruito una 1024px sopra, proviamo quella, altrimenti summary thumb, altrimenti original.
    $fetch_url = $image_url;
    if (isset($data['thumbnail']) && strpos($image_url, '1024px-') === false) {
      $fetch_url = $data['thumbnail']['source'];
    }

    // Scarica e codifica l'immagine in Base64 per la cache locale
    $base64_data = $this->get_base64_image($fetch_url);

    // Se il download del thumb costruito/assegnato fallisce, prova l'originale come ultima spiaggia
    if (empty($base64_data) && $fetch_url !== $data['originalimage']['source']) {
      $base64_data = $this->get_base64_image($data['originalimage']['source']);
    }

    //                     $sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url, $base64_data
    $this->set_image_in_db($sci_name, $engname, $image_url, $title, $sci_name, $author_url, $license_url, $base64_data);
  }

  public function get_image($sci_name)
  {
    $image = parent::get_image($sci_name);
    if ($image === false)
      return false;

    $image['photos_url'] = $this->get_external_link($image['image_url']);
    return $image;
  }

  private function get_external_link($image_url)
  {
    if (strpos($image_url, '/commons/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    }
    else {
      $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    }
    $photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
    return $photo_url;
  }
}

function get_info_url($sciname)
{
  $engname = get_com_en_name($sciname);
  $config = get_config();
  if ($config['INFO_SITE'] === 'EBIRD') {
    require_once __DIR__ . '/ebird.php';
    $ebird = isset($ebirds[$sciname]) ? $ebirds[$sciname] : '';
    $language = $config['DATABASE_LANG'];
    $url = "https://ebird.org/species/$ebird?siteLanguage=$language";
    $url_title = "eBird";
  }
  else {
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
    $url = "https://allaboutbirds.org/guide/$engname_url";
    $url_title = "All About Birds";
  }
  $ret = array(
    'URL' => $url,
    'TITLE' => $url_title
  );
  return $ret;
}

function get_color_scheme()
{
  $config = get_config();
  if (strtolower($config['COLOR_SCHEME']) === 'dark') {
    return 'static/dark-style.css';
  }
  else {
    return 'style.css';
  }
}
