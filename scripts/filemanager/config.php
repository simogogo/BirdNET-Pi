<?php
/**
 * BirdNET-Pi configuration overrides for Tiny File Manager 2.6.
 * Loaded automatically by filemanager.php without modifying upstream code.
 */

// Read COLOR_SCHEME directly from /etc/birdnet/birdnet.conf without starting a conflicting PHP session
$color_scheme = 'light';
if (is_readable('/etc/birdnet/birdnet.conf')) {
    $raw_conf = preg_replace('~^#+.*$~m', '', file_get_contents('/etc/birdnet/birdnet.conf'));
    $parsed_conf = @parse_ini_string($raw_conf);
    if (is_array($parsed_conf) && !empty($parsed_conf['COLOR_SCHEME'])) {
        $color_scheme = ($parsed_conf['COLOR_SCHEME'] === 'dark') ? 'dark' : 'light';
    }
}

$CONFIG = json_encode([
    'lang' => 'en',
    'error_reporting' => false,
    'show_hidden' => true,
    'hide_Cols' => false,
    'theme' => $color_scheme,
]);

// Access to /scripts* is protected by Caddy Basic Auth (CADDY_PWD)
$use_auth = false;

// Root directory for browsing BirdNET-Pi files
$root_path = '/home';
$root_url = '';

// Highlight.js theme matched to BirdNET-Pi color scheme
$highlightjs_style = ($color_scheme === 'dark') ? 'ir-black' : 'vs';

// 100 MB upload limit (aligned with php.ini upload_max_filesize in install_services.sh)
$max_upload_size_bytes = 104857600;
