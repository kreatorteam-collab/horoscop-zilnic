<?php
if (!defined('ABSPATH')) { exit; }

/**
 * HZ_ImageGen — imagini AI fotorealiste prin OpenAI Images API (gpt-image-2), pe cheia
 * OpenAI a site-ului. Alternativa la stocul Magnific: fiecare articol primeste o imagine
 * UNICA, generata din titlu. Costul se contorizeaza pe contextul „imagini" (CW_Usage).
 */
class HZ_ImageGen {

    const ENDPOINT = 'https://api.openai.com/v1/images/generations';

    public static function has_key() {
        return class_exists('CW_API') && CW_API::openai_key() !== '';
    }

    /** Modelul de imagini (setabil remote prin hz/v1/settings), implicit gpt-image-2. */
    public static function model() {
        $m = trim((string) get_option('hz_imagegen_model', 'gpt-image-2'));
        return $m !== '' ? $m : 'gpt-image-2';
    }

    /** Marimea (peisaj by default, potrivit pentru cover de articol). */
    public static function size() {
        $allowed = array('1536x1024', '1024x1024', '1024x1536');
        $s = (string) get_option('hz_imagegen_size', '1536x1024');
        return in_array($s, $allowed, true) ? $s : '1536x1024';
    }

    public static function quality() {
        $allowed = array('low', 'medium', 'high');
        $q = (string) get_option('hz_imagegen_quality', 'medium');
        return in_array($q, $allowed, true) ? $q : 'medium';
    }

    /** Stilul de imagine al site-ului (gol = fara stil impus). Setabil remote. */
    public static function style() {
        return trim((string) get_option('hz_imagegen_style', ''));
    }

    /**
     * Construieste promptul. $keywords = subiectul (cuvinte cheie EN din titlu),
     * $style = instructiuni de stil comune lotului (ex. „fotografii de mancare, vedere de sus").
     */
    public static function build_prompt($keywords, $style = '') {
        $kw = trim((string) $keywords);
        if ($kw === '') { $kw = 'a relevant scene'; }
        $style = trim((string) $style);
        if ($style === '') { $style = self::style(); }

        $p = 'A realistic editorial photograph about ' . $kw . '.';
        if ($style !== '') { $p .= ' Style: ' . $style . '.'; }
        return $p . ' Natural lighting, candid documentary style, shot on 50mm, shallow depth '
                  . 'of field, high detail, no text, no watermark, no logos, no collage.';
    }

    /**
     * Genereaza o imagine si o incarca in biblioteca media. Returneaza att_id sau WP_Error.
     * $keywords = cuvinte-cheie in engleza (deja traduse din titlu de HZ_Bulk).
     */
    public static function sideload($keywords, $post_id, $title, $style = '') {
        if (!self::has_key()) {
            return new WP_Error('hzig_key', 'Cheia OpenAI nu e configurată pentru generarea de imagini.');
        }
        $key    = CW_API::openai_key();
        $prompt = self::build_prompt($keywords, $style);

        $payload = array(
            'model'   => self::model(),
            'prompt'  => mb_substr($prompt, 0, 4000),
            'size'    => self::size(),
            'quality' => self::quality(),
            'n'       => 1,
        );

        $resp = wp_remote_post(self::ENDPOINT, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 180,
        ));
        if (is_wp_error($resp)) {
            return new WP_Error('hzig_http', 'Generare imagine: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) && isset($body['error']['message']) ? $body['error']['message'] : ('HTTP ' . $code);
            // „insufficient_quota"/credit = anuntam la fel ca la scriere.
            if (class_exists('CW_API')) { CW_API::maybe_credit_alert('openai', (string) $msg); }
            return new WP_Error('hzig_api', 'Generare imagine eșuată: ' . $msg);
        }

        $item = isset($body['data'][0]) ? $body['data'][0] : array();
        $bytes = '';
        if (!empty($item['b64_json'])) {
            $bytes = base64_decode((string) $item['b64_json'], true);
        } elseif (!empty($item['url'])) {
            $dl = wp_remote_get((string) $item['url'], array('timeout' => 60));
            if (!is_wp_error($dl)) { $bytes = (string) wp_remote_retrieve_body($dl); }
        }
        if ($bytes === '' || $bytes === false || strlen($bytes) < 100) {
            return new WP_Error('hzig_empty', 'Generare imagine: răspuns fără imagine validă.');
        }

        // Cost: OpenAI intoarce usage pentru gpt-image (tokeni imagine). Il logam pe „imagini".
        if (class_exists('CW_Usage') && isset($body['usage'])) {
            // gpt-image raporteaza usage in tokeni; folosim log_extra cu un cost aproximativ
            // per imagine dupa marime (evitam sub/supra-raportarea din tokeni de imagine).
            $approx = self::approx_cost(self::model(), self::size(), self::quality());
            if ($approx > 0) { CW_Usage::log_extra(self::model(), $approx); }
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam('hz-aiimg.png');
        if (!$tmp || @file_put_contents($tmp, $bytes) === false) {
            return new WP_Error('hzig_tmp', 'Generare imagine: nu am putut scrie fișierul temporar.');
        }
        $filename = sanitize_file_name('ai-' . substr(md5($title . microtime()), 0, 10) . '.png');
        $att_id = media_handle_sideload(array('name' => $filename, 'tmp_name' => $tmp), $post_id, $title);
        if (is_wp_error($att_id)) { @unlink($tmp); return $att_id; }

        // Alt GOL, ca la pozele de stock (imagine decorativa) — coerent cu regula existenta.
        update_post_meta($att_id, '_wp_attachment_image_alt', '');
        update_post_meta($att_id, '_hz_imagegen', 1);
        return (int) $att_id;
    }

    /** Cost aproximativ per imagine (USD), dupa model/marime (pt contorul lunar). */
    private static function approx_cost($model, $size, $quality) {
        // Preturi orientative gpt-image (aug 2026): 1K ~$0.03, 2K ~$0.05. Mini mult mai ieftin.
        $is2k  = ($size === '1536x1024' || $size === '1024x1536');
        $base  = $is2k ? 0.05 : 0.03;
        if (strpos($model, 'mini') !== false) { $base = $is2k ? 0.012 : 0.008; }
        if ($quality === 'low')  { $base *= 0.6; }
        if ($quality === 'high') { $base *= 1.5; }
        return round($base, 4);
    }
}
