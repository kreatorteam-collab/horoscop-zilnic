<?php
if (!defined('ABSPATH')) { exit; }

/**
 * HZ_ImageGen — imagini AI fotorealiste prin OpenAI Images API (gpt-image-2), pe cheia
 * OpenAI a site-ului. Alternativa la stocul Magnific: fiecare articol primeste o imagine
 * UNICA, generata din titlu. Costul se contorizeaza pe contextul „imagini" (CW_Usage).
 */
class HZ_ImageGen {

    const ENDPOINT = 'https://api.openai.com/v1/images/generations';
    /** Sabloanele de stil se editeaza in platforma, nu pe fiecare site. */
    const PRESETS_URL = 'https://app.targetseo.ro/api/imagegen-presets.php';

    /**
     * Sabloanele de stil din platforma (eticheta => prompt de stil), tinute in
     * transient 6 ore. Semnatura e cu `magpress_deploy_secret`, acelasi secret cu
     * care panoul comanda scrierea. Fara secret sau fara raspuns bun intoarce array gol
     * — panoul din editor ramane utilizabil, doar fara dropdown de sabloane.
     */
    public static function presets($force = false) {
        $cached = get_transient('hz_imagegen_presets');
        if (!$force && is_array($cached)) { return $cached; }

        $secret = (string) get_option('magpress_deploy_secret', '');
        if ($secret === '') { return array(); }

        $body = wp_json_encode(array('site' => home_url('/'), 'ts' => time()));
        $resp = wp_remote_post(self::PRESETS_URL, array(
            'headers' => array('Content-Type' => 'application/json',
                               'X-Magpress-Signature' => hash_hmac('sha256', $body, $secret)),
            'body'    => $body,
            'timeout' => 15,
        ));
        // Esecul se tine minte 15 minute: altfel, cu platforma picata, FIECARE
        // deschidere de articol ar astepta 15 secunde dupa o lista care nu vine.
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) { return self::presets_fail(); }
        $j = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($j) || empty($j['presets']) || !is_array($j['presets'])) { return self::presets_fail(); }

        $out = array();
        foreach ($j['presets'] as $label => $style) {
            $label = trim((string) $label); $style = trim((string) $style);
            if ($label === '' || $style === '') { continue; }
            $out[$label] = $style;
            if (count($out) >= 50) { break; }
        }
        set_transient('hz_imagegen_presets', $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /** Lista goala, retinuta scurt, ca sa nu batem endpointul la fiecare pagina. */
    private static function presets_fail() {
        set_transient('hz_imagegen_presets', array(), 15 * MINUTE_IN_SECONDS);
        return array();
    }

    /** Cheia modulului „imagini" (cheia dedicata, daca e setata; altfel cea implicita). */
    public static function has_key() {
        return class_exists('CW_API') && CW_API::openai_key_for('imagini') !== '';
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

    /**
     * Formatul in care SALVAM imaginea (OpenAI o trimite PNG). Implicit jpeg:
     * un PNG de 1536x1024 are 2-3 MB, iar WP genereaza si toate marimile derivate
     * tot in PNG — cateva MB per articol, degeaba. Setabil remote (hz/v1/settings
     * `imagegen_format`), 'png' = pastreaza originalul.
     */
    public static function format() {
        $allowed = array('jpeg', 'webp', 'png');
        $f = strtolower(trim((string) get_option('hz_imagegen_format', 'jpeg')));
        return in_array($f, $allowed, true) ? $f : 'jpeg';
    }

    /** Calitatea de compresie la conversie (0-100). */
    public static function compression() {
        $q = (int) get_option('hz_imagegen_compression', 82);
        return ($q >= 40 && $q <= 100) ? $q : 82;
    }

    /**
     * Miniaturile in WebP (originalul ramane JPEG). Originalul e ce citeste Facebook
     * din og:image, deci il lasam in pace; greutatea paginii vine oricum din marimea
     * afisata in articol, care e o miniatura. Masurat pe o poza de 1536x1024:
     * hero 1280 JPEG q82 = 163 KB, WebP q75 = 82 KB.
     */
    public static function subsizes_webp() {
        return get_option('hz_img_subsizes_webp', '') === '1';
    }

    public static function webp_quality() {
        $q = (int) get_option('hz_img_webp_quality', 75);
        return ($q >= 40 && $q <= 100) ? $q : 75;
    }

    /** Suntem in mijlocul generarii miniaturilor? Doar atunci schimbam formatul. */
    private static $in_subsizes = false;

    /** Se cheama la plugins_loaded; fara optiune activa nu inregistreaza nimic. */
    public static function boot() {
        if (!self::subsizes_webp()) { return; }
        add_filter('image_editor_output_format', array(__CLASS__, 'filter_output_format'));
        add_filter('wp_editor_set_quality', array(__CLASS__, 'filter_quality'), 10, 2);
        // Fereastra in care se fac miniaturile. Fara ea, filtrul prinde si salvarea
        // ORIGINALULUI, iar og:image (ce citeste Facebook) ajunge WebP.
        add_filter('intermediate_image_sizes_advanced', array(__CLASS__, 'subsizes_start'), 5);
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'subsizes_end'), 999);
    }

    public static function subsizes_start($sizes) { self::$in_subsizes = true;  return $sizes; }
    public static function subsizes_end($meta)    { self::$in_subsizes = false; return $meta; }

    public static function filter_output_format($formats) {
        if (!self::$in_subsizes) { return $formats; }
        if (!is_array($formats)) { $formats = array(); }
        $formats['image/jpeg'] = 'image/webp';
        $formats['image/png']  = 'image/webp';
        return $formats;
    }

    /**
     * WP reseteaza calitatea cand salveaza in alt format decat sursa (cheama
     * set_quality() fara argument), deci valoarea pentru WebP se impune de aici.
     */
    public static function filter_quality($quality, $mime = '') {
        return ($mime === 'image/webp') ? self::webp_quality() : $quality;
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
        $people = self::people_rule();
        if ($people !== '') { $p .= ' ' . $people; }
        return $p . ' Natural lighting, candid documentary style, shot on 50mm, shallow depth '
                  . 'of field, high detail, no text, no watermark, no logos, no collage.';
    }

    /**
     * Regula pentru persoanele din imagine, din aceeasi optiune ca filtrul de stock
     * (hzb_people_ethnicity) — o singura setare guverneaza si stocul, si generarea AI.
     * Gol = fara constrangere. Formulata conditionat: subiectele fara oameni raman
     * fara oameni, nu fortam aparitia unei persoane in poza.
     */
    private static function people_rule() {
        $eth = class_exists('HZ_Magnific') ? HZ_Magnific::ethnicity() : '';
        $map = array(
            'white'           => 'white European',
            'black'           => 'Black',
            'hispanic'        => 'Hispanic',
            'indian'          => 'Indian',
            'east-asian'      => 'East Asian',
            'south-asian'     => 'South Asian',
            'southeast-asian' => 'Southeast Asian',
            'middle-eastern'  => 'Middle Eastern',
            'multiracial'     => 'multiracial',
        );
        if ($eth === '' || !isset($map[$eth])) { return ''; }
        return 'If any people are visible, they must ALL look ' . $map[$eth]
             . ' - no person of any other ethnicity anywhere in the frame.';
    }

    /**
     * Genereaza o imagine si o incarca in biblioteca media. Returneaza att_id sau WP_Error.
     * $keywords = cuvinte-cheie in engleza (deja traduse din titlu de HZ_Bulk).
     */
    public static function sideload($keywords, $post_id, $title, $style = '', $full_prompt = '') {
        if (!self::has_key()) {
            return new WP_Error('hzig_key', 'Cheia OpenAI nu e configurată pentru generarea de imagini.');
        }
        $key    = CW_API::openai_key_for('imagini');
        // $full_prompt (v3.50.4, modulul Știri): promptul complet, fără șablonul foto de mai jos.
        $prompt = $full_prompt !== '' ? $full_prompt : self::build_prompt($keywords, $style);

        $payload = array(
            'model'   => self::model(),
            'prompt'  => mb_substr($prompt, 0, 4000),
            'size'    => self::size(),
            'quality' => self::quality(),
            'n'       => 1,
        );

        // v3.51.4: cu cheie dedicată pentru imagini, mergem la OpenAI direct (serverele
        // compatibile, ex. api.ns.fm, pot să nu aibă generare de imagini); altfel prin
        // serverul API configurat.
        $direct = trim((string) get_option('cw_openai_key_images', '')) !== '';
        $resp = wp_remote_post(($direct || !class_exists('CW_API')) ? self::ENDPOINT : CW_API::openai_url('images/generations'), array(
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
        $file   = self::convert($tmp, $title);
        $att_id = media_handle_sideload(array('name' => $file['name'], 'tmp_name' => $file['path']), $post_id, $title);
        if (is_wp_error($att_id)) { @unlink($file['path']); return $att_id; }

        // Alt GOL, ca la pozele de stock (imagine decorativa) — coerent cu regula existenta.
        update_post_meta($att_id, '_wp_attachment_image_alt', '');
        update_post_meta($att_id, '_hz_imagegen', 1);
        return (int) $att_id;
    }

    /**
     * Converteste PNG-ul primit de la OpenAI in formatul de salvare, INAINTE de
     * sideload — asa ies usoare si miniaturile, nu doar originalul. Daca editorul
     * de imagini lipseste sau da eroare, ramanem pe PNG: poza conteaza mai mult
     * decat kilobaitii. Returneaza array('path' => fisier temporar, 'name' => nume final).
     */
    private static function convert($tmp, $title) {
        $base = sanitize_file_name('ai-' . substr(md5($title . microtime()), 0, 10));
        $png  = array('path' => $tmp, 'name' => $base . '.png');
        $fmt  = self::format();
        if ($fmt === 'png') { return $png; }

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) { return $png; }
        $editor->set_quality(self::compression());

        $ext   = ($fmt === 'webp') ? 'webp' : 'jpg';
        $mime  = ($fmt === 'webp') ? 'image/webp' : 'image/jpeg';
        $saved = $editor->save($tmp . '.' . $ext, $mime);
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) { return $png; }

        @unlink($tmp);
        return array('path' => (string) $saved['path'], 'name' => $base . '.' . $ext);
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
