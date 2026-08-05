<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Client pentru API-ul de stock Magnific (fost Freepik): căutare + descărcare
 * imagini licențiate direct în Media Library. Descărcările de stock nu consumă
 * credite AI, dar au o limită zilnică per cont (implicit 100/zi) — o urmărim
 * local ca să nu lovim limita cu capul, mai ales dacă aceeași cheie e folosită
 * pe mai multe site-uri.
 */
class HZ_Magnific {

    const API_BASE = 'https://api.magnific.com';
    // Contor CENTRAL agregat pe cheie (mai multe site-uri = aceeași cheie = un cont ~100/zi).
    const QUOTA_URL = 'https://app.targetseo.ro/api/magnific-usage.php';

    /** Cheia API (stocată criptat, la fel ca și cheile AI). */
    public static function api_key() {
        return trim((string) CW_Crypto::decrypt(get_option('hzb_magnific_key', '')));
    }

    public static function has_key() {
        return self::api_key() !== '';
    }

    private static function headers() {
        return array(
            'x-magnific-api-key' => self::api_key(),
            'Accept-Language'    => 'en-US',
        );
    }

    /**
     * Toate apelurile către Magnific trec pe aici: pauză de 2s înainte de fiecare
     * cerere (limita lor e 50/min per cheie, dar rafalele dau 429) + un retry cu
     * așteptare de 15s la „Too many requests".
     */
    private static function request($url, $timeout) {
        sleep(2);
        for ($try = 0; $try < 2; $try++) {
            $response = wp_remote_get($url, array('headers' => self::headers(), 'timeout' => $timeout));
            if (is_wp_error($response)) { return $response; }
            $code = wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code === 429 || stripos($body, 'Too many requests') !== false) {
                sleep(15);
                continue;
            }
            return $response;
        }
        return $response;
    }

    /**
     * Magnific a refuzat descărcarea („Insufficient credits") = cota REALĂ a contului
     * pe ziua UTC curentă s-a terminat. Contoarele noastre (local/central) văd doar
     * descărcările făcute prin plugin, deci pot arăta mult mai puțin decât consumul
     * real (descărcări de pe magnific.com, alte unelte, rulări dinainte de contor).
     * De aceea refuzul se memorează ca STARE persistentă pe ziua UTC și bate contoarele.
     */
    public static function mark_exhausted() {
        update_option('hzb_exhausted_day', gmdate('Y-m-d'), 'no');
        delete_transient('hzb_central_ex');
        // Contul e epuizat pt TOATE site-urile cu aceeași cheie: anunță și central.
        self::central_post(array('a' => 'exhaust'));
    }

    /** True dacă azi (zi UTC) contul a refuzat deja descărcările — local sau raportat central. */
    public static function is_exhausted() {
        if ((string) get_option('hzb_exhausted_day', '') === gmdate('Y-m-d')) {
            return true;
        }
        $cached = get_transient('hzb_central_ex');
        if ($cached !== false) { return (int) $cached === 1; }
        $j = self::central_post(array('a' => 'get'));
        if ($j !== null && isset($j['exhausted'])) {
            $ex = (int) $j['exhausted'] === 1 ? 1 : 0;
            set_transient('hzb_central_ex', $ex, 2 * MINUTE_IN_SECONDS);
            if (isset($j['count'])) { set_transient('hzb_central_dl', (int) $j['count'], 2 * MINUTE_IN_SECONDS); }
            return $ex === 1;
        }
        return false;
    }

    /* ---- Limita zilnică de descărcări ----
     * Cota Magnific se resetează la 00:00 UTC (03:00 ora României vara), deci
     * „ziua" contorului e ziua UTC, nu ziua site-ului — altfel reluarea coadei
     * ar porni cu ore înainte de resetul real și ar lovi iar refuzul. */

    public static function downloads_today() {
        $c = get_option('hzb_dl_day', array());
        if (!is_array($c) || !isset($c['date']) || $c['date'] !== gmdate('Y-m-d')) {
            return 0;
        }
        return (int) $c['count'];
    }

    public static function daily_limit() {
        $l = (int) get_option('hzb_daily_limit', 90);
        return max(1, min(100, $l ?: 90));
    }

    public static function limit_reached() {
        // Refuzul contului e adevărul de teren; contoarele sunt doar o estimare.
        return self::is_exhausted() || self::account_downloads_today() >= self::daily_limit();
    }

    private static function log_download() {
        $today = gmdate('Y-m-d');
        $c = get_option('hzb_dl_day', array());
        if (!is_array($c) || !isset($c['date']) || $c['date'] !== $today) {
            $c = array('date' => $today, 'count' => 0);
        }
        $c['count']++;
        update_option('hzb_dl_day', $c, 'no');
        // Raportează și în contorul central (agregat pe cheie, între site-uri).
        self::central_report(1);
    }

    /* ---- Contor CENTRAL (agregat pe cheie, între toate site-urile) ----
     * Trimitem DOAR un hash al cheii (capability), niciodată cheia reală. */

    /** Amprenta cheii Magnific (SHA-256 cu sare), pentru agregare centrală. */
    public static function key_hash() {
        $key = self::api_key();
        return $key === '' ? '' : hash('sha256', 'mgq1|' . $key);
    }

    /** Apel către contorul central. Întoarce array-ul răspuns sau null la orice eșec. */
    private static function central_post($payload) {
        $hash = self::key_hash();
        if ($hash === '') { return null; }
        $payload['k'] = $hash;
        $resp = wp_remote_post(self::QUOTA_URL, array(
            'timeout' => 6,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) { return null; }
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        return (is_array($j) && !empty($j['ok'])) ? $j : null;
    }

    /** Raportează n descărcări în contorul central (best-effort) și împrospătează cache-ul. */
    public static function central_report($n = 1) {
        $j = self::central_post(array('a' => 'hit', 'n' => (int) $n));
        if ($j !== null && isset($j['count'])) {
            set_transient('hzb_central_dl', (int) $j['count'], 2 * MINUTE_IN_SECONDS);
        }
    }

    /** Total descărcări azi pe TOT contul (toate site-urile cu aceeași cheie). null = indisponibil. */
    public static function central_today() {
        $cached = get_transient('hzb_central_dl');
        if ($cached !== false) { return (int) $cached; }
        $j = self::central_post(array('a' => 'get'));
        if ($j !== null && isset($j['count'])) {
            $c = (int) $j['count'];
            set_transient('hzb_central_dl', $c, 2 * MINUTE_IN_SECONDS);
            return $c;
        }
        return null;
    }

    /** Consumul de azi relevant pentru limită: central dacă e disponibil, altfel local. */
    public static function account_downloads_today() {
        $c = self::central_today();
        return $c === null ? self::downloads_today() : $c;
    }

    /** Timestamp-ul următorului reset de cotă: 00:10 UTC (marja de 10 min). */
    public static function next_reset_ts() {
        return (new DateTimeImmutable('tomorrow 00:10', new DateTimeZone('UTC')))->getTimestamp();
    }

    /* ---- Căutare ---- */

    /**
     * Caută fotografii de stock. Returnează array de resurse (id, title, preview)
     * sau WP_Error. Interogarea trebuie să fie în engleză pentru rezultate bune.
     */
    public static function search($term, $limit = 10) {
        if (!self::has_key()) {
            return new WP_Error('hzb_no_key', 'Cheia API Magnific nu este configurată.');
        }

        $args = array(
            'term'  => $term,
            'limit' => max(1, min(50, (int) $limit)),
            'order' => 'relevance',
            'filters[content_type][photo]'     => 1,
            'filters[orientation][landscape]'  => 1,
        );
        if ((int) get_option('hzb_ai_excluded', 1)) {
            $args['filters[ai-generated][excluded]'] = 1;
        }

        $results = self::do_search($args);
        if (is_wp_error($results)) { return $results; }

        // Zero rezultate cu filtrul de orientare: mai încercăm o dată fără el.
        if (empty($results)) {
            unset($args['filters[orientation][landscape]']);
            $results = self::do_search($args);
        }
        return $results;
    }

    private static function do_search($args) {
        $url = self::API_BASE . '/v1/resources?' . http_build_query($args);
        $response = self::request($url, 30);
        if (is_wp_error($response)) {
            return new WP_Error('hzb_http', 'Magnific: eroare de conexiune: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) && isset($body['message']) ? $body['message'] : ('HTTP ' . $code);
            return new WP_Error('hzb_api', 'Magnific: căutarea a eșuat (' . $msg . ').');
        }
        $out = array();
        if (isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $r) {
                if (!isset($r['id'])) { continue; }
                $out[] = array(
                    'id'      => (int) $r['id'],
                    'title'   => isset($r['title']) ? (string) $r['title'] : '',
                    'preview' => isset($r['image']['source']['url']) ? (string) $r['image']['source']['url'] : '',
                );
            }
        }
        return $out;
    }

    /* ---- Descărcare + import în Media ---- */

    /**
     * Descarcă resursa în Media Library. Returnează attachment ID sau WP_Error.
     * Numără descărcarea în limita zilnică DOAR dacă apelul de download a reușit.
     */
    public static function sideload($resource_id, $post_id, $alt) {
        if (!self::has_key()) {
            return new WP_Error('hzb_no_key', 'Cheia API Magnific nu este configurată.');
        }
        if (self::limit_reached()) {
            return new WP_Error('hzb_limit', 'Limita zilnică de descărcări Magnific a fost atinsă.');
        }

        $size = get_option('hzb_image_size', 'large');
        if (!in_array($size, array('small', 'medium', 'large', 'original'), true)) { $size = 'large'; }

        $url = self::API_BASE . '/v1/resources/' . (int) $resource_id . '/download?image_size=' . $size;
        $response = self::request($url, 60);
        if (is_wp_error($response)) {
            return new WP_Error('hzb_http', 'Magnific: descărcarea a eșuat: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !isset($body['data']['url'])) {
            $msg = is_array($body) && isset($body['message']) ? $body['message'] : ('HTTP ' . $code);
            // „Insufficient credits" la stock = cota de descărcări a CONTULUI e goală
            // azi (contorul local nu vede alte site-uri care folosesc aceeași cheie).
            if (stripos($msg, 'insufficient credits') !== false) {
                self::mark_exhausted();
                return new WP_Error('hzb_credits', 'Cota de descărcări de stock a contului Magnific e epuizată azi.');
            }
            return new WP_Error('hzb_api', 'Magnific: descărcarea a eșuat (' . $msg . ').');
        }
        self::log_download();

        $file_url = (string) $body['data']['url'];
        $filename = isset($body['data']['filename']) && $body['data']['filename'] !== ''
            ? sanitize_file_name((string) $body['data']['filename'])
            : ('magnific-' . (int) $resource_id . '.jpg');
        if (!preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $filename)) {
            $filename .= '.jpg';
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($file_url, 120);
        if (is_wp_error($tmp)) {
            return new WP_Error('hzb_dl', 'Magnific: fișierul nu a putut fi descărcat: ' . $tmp->get_error_message());
        }

        $att_id = media_handle_sideload(array('name' => $filename, 'tmp_name' => $tmp), $post_id, $alt);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return $att_id;
        }
        update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        update_post_meta($att_id, '_hzb_magnific_id', (int) $resource_id);
        return (int) $att_id;
    }

    /* ---- Evidența resurselor deja folosite (anti-duplicat între articole) ---- */

    public static function used_ids() {
        $ids = get_option('hzb_used_ids', array());
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    public static function mark_used($resource_id) {
        $ids   = self::used_ids();
        $ids[] = (int) $resource_id;
        // Păstrăm doar ultimele 500 — suficient anti-duplicat, fără să crească la infinit.
        if (count($ids) > 500) {
            $ids = array_slice($ids, -500);
        }
        update_option('hzb_used_ids', array_values(array_unique($ids)), 'no');
    }
}
