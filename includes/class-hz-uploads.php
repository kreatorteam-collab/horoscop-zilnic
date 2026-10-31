<?php
/**
 * v3.55.0: poze ÎNCĂRCATE din panoul central (AI Writer flotă, sursa „Încărcate de
 * mine"). Panoul trimite fiecare poză O SINGURĂ DATĂ, pe ruta hz/v1/media, înainte de
 * enqueue; aici stă într-un folder temporar (uploads/hz-pending) până se publică
 * articolul. La publicare fișierul e MUTAT în biblioteca media (media_handle_sideload
 * face rename, nu copie) și devine coverul articolului — nu rămâne nicio copie în plus.
 *
 * Curățenie, ca să nu se adune fișiere pe disc:
 *  - lot anulat / coadă golită -> pozele articolelor scoase din coadă se șterg imediat;
 *  - plasa de siguranță: orice fișier din hz-pending mai vechi de 14 zile se șterge
 *    (verificat cel mult o dată pe zi, la procesarea cozii).
 */
if (!defined('ABSPATH')) { exit; }

class HZ_Uploads {

    const DIR       = 'hz-pending';
    const MAX_BYTES = 15728640; // 15 MB / poză (panoul le micșorează oricum înainte)
    const MAX_AGE   = 14;       // zile

    /** Folderul temporar (creat la nevoie, cu index gol + fără listare). */
    public static function dir() {
        $up  = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . self::DIR;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.html', '');
            @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(jpe?g|png|webp)$\">\n  Require all denied\n</FilesMatch>\n");
        }
        return $dir;
    }

    /** Token valid = 32 hex + extensia imaginii. */
    public static function valid_token($token) {
        return is_string($token) && (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $token);
    }

    public static function path($token) {
        return self::valid_token($token) ? self::dir() . '/' . $token : '';
    }

    public static function exists($token) {
        $p = self::path($token);
        return $p !== '' && is_file($p);
    }

    /**
     * Salvează o poză primită ca base64. Verifică să fie chiar imagine (jpg/png/webp).
     * @return string|WP_Error token-ul fișierului
     */
    public static function store($b64) {
        $bin = base64_decode((string) $b64, true);
        if ($bin === false || $bin === '') {
            return new WP_Error('bad_data', 'Poza n-a ajuns întreagă (base64 invalid).');
        }
        if (strlen($bin) > self::MAX_BYTES) {
            return new WP_Error('too_big', 'Poza e prea mare (maximum 15 MB).');
        }
        $info = @getimagesizefromstring($bin);
        $map  = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (!$info || empty($info['mime']) || !isset($map[$info['mime']])) {
            return new WP_Error('bad_type', 'Fișierul nu e o poză JPG, PNG sau WEBP.');
        }
        $token = bin2hex(random_bytes(16)) . '.' . $map[$info['mime']];
        if (@file_put_contents(self::dir() . '/' . $token, $bin) === false) {
            return new WP_Error('write_failed', 'Nu am putut scrie poza pe disc (spațiu sau permisiuni).');
        }
        return $token;
    }

    public static function delete($token) {
        $p = self::path($token);
        if ($p !== '' && is_file($p)) { @unlink($p); }
    }

    /** Șterge pozele itemilor scoși din coadă (anulare lot / golire coadă). */
    public static function delete_for_items($items) {
        foreach ((array) $items as $it) {
            if (is_array($it) && !empty($it['imgf'])) { self::delete($it['imgf']); }
        }
    }

    /**
     * Mută poza în biblioteca media, ca atașament al articolului.
     * @return int|WP_Error ID-ul atașamentului
     */
    public static function attach($token, $post_id, $title) {
        $p = self::path($token);
        if ($p === '' || !is_file($p)) {
            return new WP_Error('missing', 'Poza încărcată nu mai e pe site.');
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext  = pathinfo($token, PATHINFO_EXTENSION);
        $name = sanitize_title(mb_substr((string) $title, 0, 80));
        if ($name === '') { $name = 'poza'; }
        // media_handle_sideload MUTĂ fișierul (rename) în folderul lunii -> hz-pending
        // rămâne gol, fără copie.
        $att = media_handle_sideload(array('name' => $name . '.' . $ext, 'tmp_name' => $p), (int) $post_id, $title);
        if (is_wp_error($att)) {
            return $att; // fișierul rămâne, se reîncearcă la rularea următoare
        }
        if (is_file($p)) { @unlink($p); }
        return (int) $att;
    }

    /** Plasa de siguranță: fișiere uitate (mai vechi de 14 zile). Cel mult o dată pe zi. */
    public static function cleanup_old() {
        if (get_transient('hz_uploads_cleanup')) { return; }
        set_transient('hz_uploads_cleanup', 1, DAY_IN_SECONDS);
        $up  = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . self::DIR;
        if (!is_dir($dir)) { return; }
        $limit = time() - self::MAX_AGE * DAY_IN_SECONDS;
        foreach ((array) glob($dir . '/*.{jpg,png,webp}', GLOB_BRACE) as $f) {
            if (is_file($f) && filemtime($f) < $limit) { @unlink($f); }
        }
    }
}
