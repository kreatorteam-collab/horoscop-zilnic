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
    // Pool-ul de chei: urmatoarea cheie neepuizata, semnat cu secretul de deploy al site-ului.
    const KEY_NEXT_URL = 'https://app.targetseo.ro/api/magnific-key-next.php';

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
        // Starea se leagă de CHEIE, nu doar de zi: dacă userul pune altă cheie API
        // (alt cont, altă cotă), blocarea de azi nu mai are ce căuta.
        update_option('hzb_exhausted_day', array(
            'date' => gmdate('Y-m-d'),
            'k'    => self::key_fp(),
        ), 'no');
        delete_transient('hzb_central_ex');
        // Contul e epuizat pt TOATE site-urile cu aceeași cheie: anunță și central.
        self::central_post(array('a' => 'exhaust'));
    }

    /** Amprentă scurtă a cheii curente, ca să legăm contoarele de cheia care le-a produs. */
    private static function key_fp() {
        $h = self::key_hash();
        return $h === '' ? '' : substr($h, 0, 16);
    }

    /**
     * Șterge starea zilei (contor + blocaj) — se apelează la schimbarea cheii API:
     * cheia nouă are cota ei, nu moștenește blocajul celei vechi.
     */
    public static function reset_daily_state() {
        delete_option('hzb_exhausted_day');
        delete_option('hzb_dl_day');
        delete_transient('hzb_central_dl');
        delete_transient('hzb_central_ex');
    }

    /** True dacă azi (zi UTC) contul a refuzat deja descărcările — local sau raportat central. */
    public static function is_exhausted() {
        $ex = get_option('hzb_exhausted_day', '');
        // Format vechi (string cu data) sau nou (array cu data + amprenta cheii).
        if (is_array($ex)) {
            if (isset($ex['date'], $ex['k']) && $ex['date'] === gmdate('Y-m-d') && $ex['k'] === self::key_fp()) {
                return true;
            }
        } elseif ((string) $ex === gmdate('Y-m-d')) {
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
        // Contorul aparține cheii care l-a produs; la cheie nouă pornim de la zero.
        if (isset($c['k']) && $c['k'] !== self::key_fp()) {
            return 0;
        }
        return (int) $c['count'];
    }

    public static function daily_limit() {
        $l = (int) get_option('hzb_daily_limit', 100);
        return max(1, min(100, $l ?: 100));
    }

    public static function limit_reached() {
        // Refuzul contului e adevărul de teren; contoarele sunt doar o estimare.
        return self::is_exhausted() || self::account_downloads_today() >= self::daily_limit();
    }

    private static function log_download() {
        $today = gmdate('Y-m-d');
        $fp    = self::key_fp();
        $c = get_option('hzb_dl_day', array());
        if (!is_array($c) || !isset($c['date']) || $c['date'] !== $today
            || (isset($c['k']) && $c['k'] !== $fp)) {
            $c = array('date' => $today, 'count' => 0);
        }
        $c['k'] = $fp;
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

    /* ---- Rotirea cheilor (pool central) ----
     * Cota Magnific e ~100 descarcari/zi PE CONT, deci o cheie noua din ACELASI cont
     * n-ar aduce cota in plus. Panoul tine un pool de chei (conturi diferite) si, cand
     * cea curenta e refuzata, ne da urmatoarea care mai are cota azi. Cheile nu se
     * imprastie prin cele 77 de baze de date: le cerem la nevoie, semnat. */

    /** Cheile incercate AZI pe site-ul asta, ca sa nu ne intoarcem la una epuizata. */
    private static function tried_today() {
        $t = get_option('hzb_keys_tried', array());
        if (!is_array($t) || !isset($t['date']) || $t['date'] !== gmdate('Y-m-d') || !isset($t['h']) || !is_array($t['h'])) {
            return array();
        }
        return array_values(array_filter(array_map('strval', $t['h'])));
    }

    private static function mark_tried($hash) {
        if ($hash === '') { return; }
        $h = self::tried_today();
        if (in_array($hash, $h, true)) { return; }
        $h[] = $hash;
        update_option('hzb_keys_tried', array('date' => gmdate('Y-m-d'), 'h' => $h), 'no');
    }

    /** Ultima rotire automata (pentru hz/v1/info si pagina de setari). */
    public static function last_rotation() {
        $r = get_option('hzb_key_rotated', array());
        return is_array($r) ? $r : array();
    }

    /**
     * Cere panoului urmatoarea cheie neepuizata si o instaleaza pe site.
     * Returneaza true DOAR daca a intrat o cheie noua (alt hash).
     *
     * Semnatura e cu `magpress_deploy_secret` - acelasi secret cu care panoul comanda
     * site-ul; fara tema Magpress (secret absent) rotirea pur si simplu nu exista si
     * ramane comportamentul vechi: asteptam resetul de la 00:00 UTC.
     */
    public static function rotate_key() {
        $secret = (string) get_option('magpress_deploy_secret', '');
        if (strlen($secret) < 32) { return false; }
        // Un singur apel la cateva minute: la 77 de site-uri care lovesc cota in
        // aceeasi ora, un tick ratacit n-are voie sa ceara chei in rafala.
        if (get_transient('hzb_rotate_lock')) { return false; }
        set_transient('hzb_rotate_lock', 1, 3 * MINUTE_IN_SECONDS);

        $cur = self::key_hash();
        if ($cur !== '') { self::mark_tried($cur); }

        $body = wp_json_encode(array(
            'site'  => home_url(),
            'ts'    => time(),
            'cur'   => $cur,
            'tried' => self::tried_today(),
        ));
        $resp = wp_remote_post(self::KEY_NEXT_URL, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'         => 'application/json',
                'X-Magpress-Signature' => hash_hmac('sha256', $body, $secret),
            ),
            'body'    => $body,
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) { return false; }
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($j) || empty($j['ok']) || empty($j['key'])) { return false; }

        $new = trim((string) $j['key']);
        if ($new === '' || hash('sha256', 'mgq1|' . $new) === $cur) { return false; }

        // update_option trece prin filtrul `pre_update_option_hzb_magnific_key`, deci
        // cheia ajunge in DB criptata, la fel ca atunci cand o pui din setari.
        update_option('hzb_magnific_key', $new);
        self::reset_daily_state();          // cheie noua = contor si blocaj de la zero
        self::mark_tried(hash('sha256', 'mgq1|' . $new));
        update_option('hzb_key_rotated', array(
            'at'    => time(),
            'label' => isset($j['label']) ? (string) $j['label'] : '',
            'used'  => isset($j['used']) ? (int) $j['used'] : 0,
        ), 'no');
        return true;
    }

    /**
     * True daca avem o cheie cu cota azi. Daca cea curenta e epuizata, incearca
     * automat urmatoarea din pool. False = chiar s-au terminat toate.
     */
    public static function ensure_usable_key() {
        if (!self::has_key()) { return false; }
        if (!self::limit_reached()) { return true; }
        if (!self::rotate_key()) { return false; }
        return !self::limit_reached();
    }

    /* ---- Căutare ---- */

    /**
     * Etnia impusă persoanelor din fotografii (gol = fără filtru). Valorile sunt cele
     * acceptate de API-ul Magnific; pe flotă rulăm 'white' = persoane europene.
     */
    const ETHNICITIES = array('white', 'black', 'hispanic', 'indian', 'east-asian', 'south-asian', 'southeast-asian', 'middle-eastern', 'multiracial');

    public static function ethnicity() {
        $e = trim((string) get_option('hzb_people_ethnicity', 'white'));
        return in_array($e, self::ETHNICITIES, true) ? $e : '';
    }

    /**
     * Caută fotografii de stock. Returnează array de resurse (id, title, preview)
     * sau WP_Error. Interogarea trebuie să fie în engleză pentru rezultate bune.
     *
     * Cu filtrul de etnie pornit căutarea merge pe TREPTE, ca articolul să nu rămână
     * fără poză: (1) fotografii cu persoane de etnia cerută; (2) dacă nu există,
     * fotografii FĂRĂ persoane (peisaje, obiecte, mâncare — majoritatea subiectelor,
     * unde filtrul de persoane ar da zero rezultate); (3) abia la final căutarea
     * nefiltrată. Deci ori persoanele din poză sunt cele cerute, ori poza n-are
     * persoane deloc.
     */
    public static function search($term, $limit = 10) {
        if (!self::has_key()) {
            return new WP_Error('hzb_no_key', 'Cheia API Magnific nu este configurată.');
        }

        $base = array(
            'term'  => $term,
            'limit' => max(1, min(50, (int) $limit)),
            'order' => 'relevance',
            'filters[content_type][photo]'     => 1,
            'filters[orientation][landscape]'  => 1,
        );
        if ((int) get_option('hzb_ai_excluded', 1)) {
            $base['filters[ai-generated][excluded]'] = 1;
        }

        $steps = array();
        $eth   = self::ethnicity();
        if ($eth !== '') {
            $steps[] = array('filters[people][ethnicity]' => $eth);
            $steps[] = array('filters[people][exclude]'   => 1);
        }
        $steps[] = array(); // fără filtru de persoane

        foreach ($steps as $extra) {
            $results = self::do_search(array_merge($base, $extra));
            if (is_wp_error($results)) {
                // Dacă API-ul refuză filtrul de persoane (dacă își schimbă numele sau
                // valorile), nu pierdem poza: trecem la treapta următoare. Eroarea de
                // pe treapta nefiltrată e reală și se raportează mai departe.
                if (!empty($extra)) { continue; }
                return $results;
            }
            if (!empty($results)) { return $results; }
        }

        // Zero rezultate pe toate treptele: mai încercăm o dată fără filtrul de
        // orientare, pe aceleași trepte (păstrăm preferința pentru persoane).
        unset($base['filters[orientation][landscape]']);
        foreach ($steps as $extra) {
            $results = self::do_search(array_merge($base, $extra));
            if (!is_wp_error($results) && !empty($results)) { return $results; }
        }
        return array();
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
    public static function sideload($resource_id, $post_id, $title, $rotated = false) {
        if (!self::has_key()) {
            return new WP_Error('hzb_no_key', 'Cheia API Magnific nu este configurată.');
        }
        // Nu ne mai oprim la prima cheie epuizata: ensure_usable_key() roteste singur
        // pe urmatoarea din pool. Eroarea apare doar cand s-au terminat toate.
        if (!self::ensure_usable_key()) {
            return new WP_Error('hzb_limit', 'Limita zilnică de descărcări Magnific a fost atinsă pe toate cheile din pool.');
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
                // Pool-ul are chei de pe CONTURI diferite: rotim pe urmatoarea si
                // reincercam O SINGURA data exact aceeasi descarcare.
                if (!$rotated && self::rotate_key()) {
                    return self::sideload($resource_id, $post_id, $title, true);
                }
                // Limita de ~100/zi e pe CONT, nu pe cheie: chei noi generate în
                // același cont trag din aceeași cotă și vor primi același refuz.
                return new WP_Error('hzb_credits', 'Cota de descărcări de stock a contului Magnific e epuizată azi (limita e pe CONT, nu pe cheie - o cheie nouă din același cont nu adaugă cotă).');
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

        // $title ajunge doar in titlul atasamentului (ca sa se recunoasca in biblioteca
        // media), NU in alt: pozele de stock sunt decorative, nu descriu continutul.
        $att_id = media_handle_sideload(array('name' => $filename, 'tmp_name' => $tmp), $post_id, $title);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return $att_id;
        }
        // Alt GOL, explicit: fara el, tema/WP ar putea cadea inapoi pe titlu.
        update_post_meta($att_id, '_wp_attachment_image_alt', '');
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
