<?php
/**
 * BirdNET-Pi wrapper for Adminer 6.3.1 (SQLite3).
 * Keeps upstream adminer-core.php untouched for clean future upgrades.
 * Access to /scripts* is protected by Caddy Basic Auth (CADDY_PWD).
 */

if (empty($_POST) && !isset($_GET['file']) && !isset($_GET['sqlite'])) {
    $_GET['sqlite'] = '';
    if (!isset($_GET['db'])) {
        $_GET['db'] = 'birds.db';
    }
}

function adminer_object() {
    return new class extends \Adminer\Adminer {
        public function name() {
            $href = defined('Adminer\ME') ? \Adminer\ME : '?';
            return '<a href="' . \Adminer\h($href) . '" id="h1">BirdNET-Pi DB</a>';
        }

        public function login($login, $password) {
            // SQLite has no credentials; HTTP access is gated by Caddy Basic Auth
            return true;
        }

        public function loginFormField($name, $heading, $value) {
            if ($name === 'driver' || $name === 'server' || $name === 'username' || $name === 'password') {
                return '';
            }
            if ($name === 'db') {
                $db = (isset($_GET['db']) && $_GET['db'] !== '') ? $_GET['db'] : 'birds.db';
                return $heading
                    . '<input type="hidden" name="auth[driver]" value="sqlite">'
                    . '<input type="hidden" name="auth[server]" value="">'
                    . '<input type="hidden" name="auth[username]" value="">'
                    . '<input type="hidden" name="auth[password]" value="">'
                    . '<input name="auth[db]" value="' . \Adminer\h($db) . '" autocapitalize="off">' . "\n";
            }
            return parent::loginFormField($name, $heading, $value);
        }
    };
}

require __DIR__ . '/adminer-core.php';
