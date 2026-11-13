<?php
/**
 * HZ_Discover (v3.56.0) - pozele articolelor aduse la cerințele Google Discover
 * ÎNAINTE să intre în biblioteca media:
 *   - minimum 1200 px lățime și peste 300.000 de pixeli în total;
 *   - format 16:9 (decupaj centrat, fără deformare); maximum 1920 px lățime.
 *
 * Se aplică pozelor de stock (Magnific), celor generate cu AI, celor încărcate din
 * aplicație (hz/v1/media) și celor din modulul Știri. NU și pozelor de produs din
 * recenzii (un produs decupat la 16:9 își pierde marginile).
 *
 * Opțiunea `hz_discover_images` ('1' implicit, '0' = oprit) - setabilă și din
 * aplicație prin hz/v1/settings. Orice eroare = poza originală rămâne neatinsă:
 * mai bine o poză care nu e 16:9 decât un articol fără poză.
 */

if (!defined('ABSPATH')) { exit; }

class HZ_Discover {

    const MIN_W     = 1200;
    const MIN_PX    = 300000;
    const MAX_W     = 1920;
    const TOLERANCE = 0.02;

    public static function enabled() {
        return get_option('hz_discover_images', '1') !== '0';
    }

    /** Dimensiunile respectă deja cerințele (lățime, pixeli, 16:9)? */
    public static function is_ok($w, $h) {
        $w = (int) $w; $h = (int) $h;
        if ($w < self::MIN_W || $h <= 0 || $w * $h < self::MIN_PX) { return false; }
        return abs($w / $h - 16 / 9) <= self::TOLERANCE * (16 / 9);
    }

    /** Decupajul 16:9 centrat + dimensiunea finală (multiplu de 16 => 16:9 exact). */
    public static function plan($w, $h) {
        $w = max(1, (int) $w); $h = max(1, (int) $h);
        if ($w / $h > 16 / 9) { $ch = $h; $cw = (int) round($h * 16 / 9); }
        else                  { $cw = $w; $ch = (int) round($w * 9 / 16); }
        $cw = min($cw, $w); $ch = min($ch, $h);
        $ow = max(self::MIN_W, min(self::MAX_W, $cw));
        $ow -= $ow % 16;
        return array(
            'x' => (int) floor(($w - $cw) / 2), 'y' => (int) floor(($h - $ch) / 2),
            'cw' => $cw, 'ch' => $ch, 'ow' => $ow, 'oh' => (int) ($ow / 16 * 9),
        );
    }

    /**
     * Aduce fișierul $path la 16:9 / 1200-1920 px. Returnează calea fișierului de folosit
     * (alta decât $path dacă poza a fost refăcută; atunci $path e șters) sau $path neschimbat.
     */
    public static function fit($path) {
        if (!self::enabled() || !is_string($path) || $path === '' || !is_file($path)) { return $path; }
        $size = @getimagesize($path);
        if (!$size || empty($size[0]) || empty($size[1])) { return $path; }
        if (self::is_ok($size[0], $size[1])) { return $path; }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) { return $path; }
        if (method_exists($editor, 'maybe_exif_rotate')) { $editor->maybe_exif_rotate(); } // poze de telefon
        $dim = $editor->get_size();
        if (empty($dim['width']) || empty($dim['height'])) { return $path; }

        $p = self::plan($dim['width'], $dim['height']);
        $r = $editor->crop($p['x'], $p['y'], $p['cw'], $p['ch'], $p['ow'], $p['oh']);
        if (is_wp_error($r)) { return $path; }

        $mime = function_exists('wp_get_image_mime') ? (string) wp_get_image_mime($path) : (string) $size['mime'];
        $exts = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif');
        $ext  = isset($exts[$mime]) ? $exts[$mime] : 'jpg';
        if ($ext === 'jpg') { $editor->set_quality(88); }
        $dest  = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $path) . '-discover.' . $ext;
        $saved = $editor->save($dest, $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext);
        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) { return $path; }

        @unlink($path);
        return (string) $saved['path'];
    }

    /**
     * Pentru media_handle_sideload: același $file, cu tmp_name refăcut și extensia din
     * `name` potrivită formatului final.
     */
    public static function fit_sideload(array $file) {
        if (empty($file['tmp_name'])) { return $file; }
        $new = self::fit($file['tmp_name']);
        if ($new !== $file['tmp_name']) {
            $file['tmp_name'] = $new;
            $ext = strtolower(pathinfo($new, PATHINFO_EXTENSION));
            if ($ext !== '' && !empty($file['name'])) {
                $file['name'] = preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string) $file['name']) . '.' . $ext;
            }
        }
        return $file;
    }
}
