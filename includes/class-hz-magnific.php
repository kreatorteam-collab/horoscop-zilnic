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

    /**
     * Cuvinte care, în titlul sau tagurile resursei, arată o poză nepotrivită ca
     * imagine de articol: grafică în loc de fotografie, poze „de catalog" pe fundal
     * alb, machete, colaje. Nu le excludem, doar le trimitem la coada clasamentului.
     */
    const BAD_HINTS = array('isolated', 'white background', 'grey background', 'gray background',
        'plain background', 'studio shot', 'posing', 'poses', 'thumbs up', 'looking at camera',
        'copy space', 'mockup', 'mock up', 'collage', 'illustration', 'drawing', 'cartoon',
        'clipart', 'render', 'banner', 'template', 'watermark', 'icon set');

    /** Cuvinte prea comune ca să conteze la potrivirea cu titlul/tagurile resursei. */
    const STOP_WORDS = array('the', 'and', 'for', 'with', 'from', 'photo', 'image',
        'picture', 'stock', 'that', 'this', 'your', 'you', 'are', 'its');

    public static function ethnicity() {
        $e = trim((string) get_option('hzb_people_ethnicity', 'white'));
        return in_array($e, self::ETHNICITIES, true) ? $e : '';
    }

    /**
     * Caută fotografii de stock. Returnează resursele (id, title, preview, keywords,
     * downloads) ORDONATE după cât de bine se potrivesc cu interogarea, sau WP_Error.
     * Interogarea trebuie să fie în engleză — stocul e indexat în engleză.
     *
     * $alt = interogări de rezervă, mai largi (le dă HZ_Bulk din aceeași traducere a
     * titlului). Se folosesc doar dacă prima nu întoarce nimic, deci o poză de nișă
     * bate mereu una generică.
     *
     * Cu filtrul de etnie pornit cerem, pentru fiecare interogare, DOUĂ liste pe care
     * le judecăm împreună: (1) fotografii cu persoane de etnia cerută și (2) fotografii
     * FĂRĂ persoane. Ori persoanele din poză sunt cele cerute, ori poza n-are persoane
     * deloc — nu mai există treaptă nefiltrată care să strecoare altă etnie.
     */
    public static function search($term, $limit = 10, $alt = array()) {
        if (!self::has_key()) {
            return new WP_Error('hzb_no_key', 'Cheia API Magnific nu este configurată.');
        }

        $limit = max(1, min(50, (int) $limit));
        $base  = array(
            'limit' => $limit,
            'order' => 'relevance',
            'filters[content_type][photo]'     => 1,
            'filters[orientation][landscape]'  => 1,
        );
        if ((int) get_option('hzb_ai_excluded', 1)) {
            $base['filters[ai-generated][excluded]'] = 1;
        }

        $eth = self::ethnicity();
        if ($eth !== '') {
            // `include` explicit: singur, filtrul de etnie nu spune API-ului că vrem
            // neapărat oameni în cadru, iar prima listă s-ar amesteca cu a doua.
            $people = array(
                array('filters[people][include]' => 1, 'filters[people][ethnicity]' => $eth),
                array('filters[people][exclude]' => 1),
            );
        } else {
            $people = array(array());
        }

        $steps = self::term_steps($term, $alt);
        // Interogarea precisă ȘI cea largă se caută ÎMPREUNĂ, într-un singur coș.
        // Înainte, prima care întorcea ceva câștiga — iar pentru un subiect medical
        // („tiroida hipoactivă") stocul are doar câteva cadre pozate de studio, deci
        // articolul primea o poză urâtă fără să se uite nimeni la „diete", unde sunt
        // mii de fotografii bune. Acum se judecă toate la un loc.
        $rounds = array(
            array(array_slice($steps, 0, 2), true),   // precisă + largă
            array(array_slice($steps, 2), true),      // abia apoi scurtarea
            array($steps, false),                     // ultima șansă: fără orientare
        );

        $err = null;
        foreach ($rounds as $round) {
            list($terms, $keep_orientation) = $round;
            if (empty($terms)) { continue; }
            $b = $base;
            if (!$keep_orientation) { unset($b['filters[orientation][landscape]']); }

            $pool = array();
            foreach ($terms as $t) {
                foreach ($people as $extra) {
                    $res = self::do_search(array_merge($b, array('term' => $t), $extra));
                    if (is_wp_error($res)) {
                        // Dacă API-ul refuză filtrul de persoane (își schimbă numele sau
                        // valorile), nu pierdem poza: mergem mai departe. Eroarea se
                        // raportează doar dacă nu găsim nimic pe nicio treaptă.
                        $err = $res;
                        continue;
                    }
                    foreach ($res as $r) {
                        if (!isset($pool[$r['id']])) { $pool[$r['id']] = $r; }
                    }
                }
            }
            if (!empty($pool)) {
                return array_slice(self::rank($pool, $steps), 0, $limit);
            }
        }
        return $err !== null ? $err : array();
    }

    /**
     * Treptele de interogare: cea exactă, apoi varianta mai largă primită de la
     * traducere, apoi o scurtare automată la primele două cuvinte.
     */
    private static function term_steps($term, $alt) {
        $out = array();
        $add = function ($t) use (&$out) {
            $t = trim(preg_replace('/\s+/', ' ', (string) $t));
            if ($t !== '' && !in_array($t, $out, true)) { $out[] = $t; }
        };
        $add($term);
        foreach ((array) $alt as $a) { $add($a); }
        $w = preg_split('/\s+/', trim((string) $term));
        if (count($w) > 2) { $add(implode(' ', array_slice($w, 0, 2))); }
        return $out;
    }

    /** Cuvintele „grele" ale interogării, pentru potrivirea cu titlul/tagurile resursei. */
    private static function query_words($term) {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::lower($term));
        $out   = array();
        foreach ((array) $parts as $x) {
            if (strlen($x) < 3 || in_array($x, self::STOP_WORDS, true)) { continue; }
            $out[] = $x;
        }
        return array_values(array_unique($out));
    }

    /**
     * Litere mici, fără să depindem de mbstring: WordPress pune polyfill doar
     * pentru mb_substr/mb_strlen, nu și pentru mb_strtolower.
     */
    private static function lower($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s) : strtolower((string) $s);
    }

    /**
     * Ordonează candidații. Relevanța se măsoară față de TOATE interogările, dar
     * cea precisă cântărește întreg, iar cele largi 60% — așa, o poză bună de la
     * „diete" poate întrece un cadru urât care se potrivește pe „tiroidă", fără ca
     * o potrivire exactă bună să fie vreodată dată la o parte.
     *
     * Peste relevanță se adaugă semnalele de calitate pe care API-ul le dă și pe
     * care nu le foloseam: licența premium, anul apariției, rezoluția, popularitatea
     * — și penalizarea cadrului pozat de studio, sursa pozelor care „arată rău".
     * Resursele deja folosite pe site cad la coadă.
     */
    private static function rank($pool, $terms) {
        $sets   = array();
        $weight = 1.0;
        foreach ((array) $terms as $t) {
            $words = self::query_words($t);
            if (!empty($words)) { $sets[] = array($words, $weight); }
            $weight = 0.6;
        }
        $used = array_flip(self::all_used_ids());
        $list = array_values($pool);

        foreach ($list as $i => $r) {
            $hay = self::lower($r['title'] . ' ' . implode(' ', $r['keywords']));

            $s = 0.0;
            foreach ($sets as $set) {
                $hit = 0;
                foreach ($set[0] as $w) {
                    if (strpos($hay, $w) !== false) { $hit++; }
                }
                $v = ($hit / count($set[0])) * 100.0 * $set[1];
                if ($v > $s) { $s = $v; }
            }

            // Popularitatea n-are voie să bată relevanța: cel mult 10 puncte.
            $s += min(10.0, log10(max(1, (int) $r['downloads'])) * 2.0);
            // Premium = fotografie plătită; în medie, altă ligă față de gratuite.
            if (!empty($r['premium'])) { $s += 6.0; }
            // Stocul vechi se vede că e vechi (lumină, machiaj, haine, procesare).
            $age = $r['year'] > 0 ? ((int) gmdate('Y') - (int) $r['year']) : 99;
            if ($age <= 2)      { $s += 8.0; }
            elseif ($age <= 5)  { $s += 4.0; }
            elseif ($age >= 10) { $s -= 6.0; }
            // Rezoluția mare = ședință foto adevărată, nu cadru recuperat.
            if ((int) $r['width'] >= 5000)     { $s += 4.0; }
            elseif ((int) $r['width'] >= 3000) { $s += 2.0; }
            // Cadrul pozat de studio (fundal gri, „thumbs up", privit în obiectiv)
            // e principala sursă de poze care arată prost într-un articol. Penalizarea
            // trebuie să bată o potrivire perfectă de cuvinte (100): în modă, stocul e
            // plin de decupaje pe alb care se potrivesc pe toate cuvintele din titlu.
            foreach (self::BAD_HINTS as $bad) {
                if (strpos($hay, $bad) !== false) { $s -= 40.0; break; }
            }
            // Ordinea dată de API e ea însăși un semnal; o păstrăm ca departajare.
            $s -= (float) $r['pos'] * 0.1;
            if (isset($used[$r['id']])) { $s -= 1000.0; }
            $list[$i]['score'] = $s;
        }
        usort($list, function ($a, $b) {
            if ($a['score'] == $b['score']) { return $a['pos'] - $b['pos']; }
            return ($a['score'] < $b['score']) ? 1 : -1;
        });
        return self::spread($list);
    }

    /** Cât de mult sub primul clasat mai considerăm un candidat „la fel de bun". */
    const SPREAD_BAND = 12.0;

    /**
     * Împrăștie alegerea ÎNTRE site-uri. Toată flota primește aceeași listă de la
     * Magnific pentru un subiect, deci dacă fiecare site ia primul rezultat, aceeași
     * poză ajunge pe zeci de site-uri (verificat: un fișier era pe 20 din 77).
     * Candidații practic la fel de relevanți (în banda SPREAD_BAND sub primul) se
     * reordonează după o cheie derivată din domeniu: alt site = altă poză, dar pe
     * ACELAȘI site ordinea e stabilă (nu se schimbă de la o rulare la alta) și
     * relevanța nu are de suferit — toți din bandă se potrivesc aproape la fel.
     */
    private static function spread($list) {
        $n = count($list);
        if ($n < 2) { return $list; }
        $top  = $list[0]['score'];
        $band = 0;
        while ($band < $n && $list[$band]['score'] >= $top - self::SPREAD_BAND) { $band++; }
        if ($band < 2) { return $list; }

        $seed = md5(home_url('/'));
        $head = array_slice($list, 0, $band);
        usort($head, function ($a, $b) use ($seed) {
            $ka = crc32($seed . ':' . $a['id']);
            $kb = crc32($seed . ':' . $b['id']);
            if ($ka === $kb) { return $a['pos'] - $b['pos']; }
            return ($ka < $kb) ? -1 : 1;
        });
        return array_merge($head, array_slice($list, $band));
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
            $pos = 0;
            foreach ($body['data'] as $r) {
                if (!isset($r['id'])) { continue; }
                $out[] = array(
                    'id'        => (int) $r['id'],
                    'title'     => isset($r['title']) ? (string) $r['title'] : '',
                    'preview'   => isset($r['image']['source']['url']) ? (string) $r['image']['source']['url'] : '',
                    'keywords'  => self::keywords_of($r),
                    'downloads' => isset($r['stats']['downloads']) ? (int) $r['stats']['downloads'] : 0,
                    // Semnale de calitate pe care API-ul le dă și pe care nu le foloseam:
                    // licența (premium = fotografie plătită, alt nivel decât gratuitele),
                    // anul apariției (stocul vechi arată vizibil vechi) și rezoluția.
                    'premium'   => self::is_premium($r),
                    'year'      => isset($r['meta']['published_at'])
                        ? (int) substr((string) $r['meta']['published_at'], 0, 4) : 0,
                    'width'     => self::width_of($r),
                    'pos'       => $pos++,
                );
            }
        }
        return $out;
    }

    /** True dacă resursa e premium (plătită) — în medie, fotografii mai bune. */
    private static function is_premium($r) {
        if (!isset($r['licenses']) || !is_array($r['licenses'])) { return false; }
        foreach ($r['licenses'] as $l) {
            $t = is_array($l) ? (isset($l['type']) ? (string) $l['type'] : '') : (string) $l;
            if (strtolower($t) === 'premium') { return true; }
        }
        return false;
    }

    /** Lățimea originalului din `image.source.size` (format „WxH"). */
    private static function width_of($r) {
        $size = isset($r['image']['source']['size']) ? (string) $r['image']['source']['size'] : '';
        if ($size === '' || strpos($size, 'x') === false) { return 0; }
        $parts = explode('x', strtolower($size));
        return (int) trim($parts[0]);
    }

    /** Tagurile resursei din `related.keywords` (listă de {name, slug} sau de șiruri). */
    private static function keywords_of($r) {
        $k = isset($r['related']['keywords']) ? $r['related']['keywords'] : array();
        if (!is_array($k)) { return array(); }
        $out = array();
        foreach ($k as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) { $out[] = (string) $item['name']; }
            } elseif (is_string($item)) {
                $out[] = $item;
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

    /* ---- Evidența resurselor deja folosite (anti-duplicat între articole) ----
     * Două liste care se completează:
     *   - `hzb_used_ids` = ultimele 500, în opțiuni (rapid, fără interogare în DB);
     *   - biblioteca media = TOT ce am descărcat vreodată, citit din meta
     *     `_hzb_magnific_id` pe atașamente.
     * Doar prima nu ajungea: după câteva luni ID-ul iese din fereastra de 500 și
     * aceeași poză se descarcă din nou (văzut pe inspirate.ro de 3 ori în 19 luni). */

    public static function used_ids() {
        $ids = get_option('hzb_used_ids', array());
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /**
     * Toate resursele Magnific existente în biblioteca media a site-ului. Ținut în
     * transient o oră: la scrierea în lot ar fi o interogare per articol degeaba.
     */
    public static function library_ids() {
        $c = get_transient('hzb_lib_ids');
        if (is_array($c)) { return $c; }
        global $wpdb;
        $ids = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_hzb_magnific_id'");
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        set_transient('hzb_lib_ids', $ids, HOUR_IN_SECONDS);
        return $ids;
    }

    /** Evidența completă: fereastra rapidă din opțiuni + biblioteca media. */
    public static function all_used_ids() {
        return array_values(array_unique(array_merge(self::used_ids(), self::library_ids())));
    }

    public static function mark_used($resource_id) {
        $ids   = self::used_ids();
        $ids[] = (int) $resource_id;
        // Fereastra rapidă rămâne la 500; memoria de durată e biblioteca media.
        if (count($ids) > 500) {
            $ids = array_slice($ids, -500);
        }
        update_option('hzb_used_ids', array_values(array_unique($ids)), 'no');

        // Ținem cache-ul bibliotecii la zi în loc să-l invalidăm: altfel fiecare
        // articol din lot ar reface interogarea în postmeta.
        $lib = get_transient('hzb_lib_ids');
        if (is_array($lib)) {
            $lib[] = (int) $resource_id;
            set_transient('hzb_lib_ids', array_values(array_unique($lib)), HOUR_IN_SECONDS);
        }
    }
}
