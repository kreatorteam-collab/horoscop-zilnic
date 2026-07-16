<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Criptare AES-256-GCM pentru secrete (cheia API).
 * Cheia de criptare e derivată din salt-urile WordPress din wp-config.php,
 * deci NU se află în baza de date: un dump de DB nu expune cheia API.
 */
class CW_Crypto {

    const PREFIX = 'cwenc::';
    const CIPHER = 'aes-256-gcm';

    public static function available() {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }

    private static function key() {
        // 32 bytes derivați din salt-ul de autentificare (din wp-config.php).
        return hash('sha256', wp_salt('auth') . '|claude-writer', true);
    }

    public static function is_encrypted($value) {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    public static function encrypt($plain) {
        $plain = (string) $plain;
        if ($plain === '' || !self::available()) {
            return $plain; // gol sau fără openssl -> degradare grațioasă la text simplu
        }
        $iv  = openssl_random_pseudo_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            return $plain;
        }
        $encoded = self::PREFIX . base64_encode($iv . $tag . $ct);

        // Auto-verificare: dacă runda criptare->decriptare nu reproduce exact
        // textul original (bug openssl/GCM pe anumite versiuni de PHP), stocăm
        // în clar ca să nu corupem niciodată cheia. Pe servere sănătoase rămâne criptat.
        if (self::decrypt($encoded) !== $plain) {
            return $plain;
        }
        return $encoded;
    }

    public static function decrypt($stored) {
        if (!self::is_encrypted($stored)) {
            return (string) $stored; // text simplu sau valoare veche -> returnează ca atare
        }
        if (!self::available()) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }
}
