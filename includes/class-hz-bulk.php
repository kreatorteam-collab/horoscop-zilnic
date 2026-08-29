<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Articole Bulk: primești o listă de titluri, alegi categoria, iar pluginul
 * generează pe rând articolele cu AI (motorul AI Writer inclus — același
 * system prompt editorial, cost tracking, limită lunară) și pune automat
 * imagini de stock relevante din Magnific (cover + opțional în corp).
 *
 * Procesarea e o coadă: un cron WP auto-înlănțuit ia câte UN articol pe rulare
 * (generarea durează 1-3 minute), ca să nu blocheze nimic și să reziste la
 * timeout-uri. Pagina de admin face poll și repornește cronul dacă a adormit.
 */
class HZ_Bulk {

    const HOOK = 'hz_bulk_process';
    const LOCK = 'hz_bulk_lock';
    const WATCHDOG = 'hz_bulk_watchdog';

    public function init() {
        add_action(self::HOOK, array($this, 'process_tick'));
        // v3.8.3: watchdog care se auto-vindecă - curăță o rulare moartă (lock rămas)
        // și repornește coada SINGUR, fără „Deblochează" manual.
        add_filter('cron_schedules', array($this, 'cron_intervals'));
        add_action(self::WATCHDOG, array($this, 'watchdog_tick'));
        if (!wp_next_scheduled(self::WATCHDOG)) {
            wp_schedule_event(time() + 120, 'hz_5min', self::WATCHDOG);
        }
        add_action('wp_ajax_hzb_enqueue', array($this, 'ajax_enqueue'));
        add_action('wp_ajax_hzb_status', array($this, 'ajax_status'));
        add_action('wp_ajax_hzb_quota', array($this, 'ajax_quota'));
        add_action('wp_ajax_hzb_retry', array($this, 'ajax_retry'));
        add_action('wp_ajax_hzb_clear', array($this, 'ajax_clear'));
        add_action('wp_ajax_hzb_toggle', array($this, 'ajax_toggle'));
        add_action('wp_ajax_hzb_backfill', array($this, 'ajax_backfill'));
    }

    /** Interval de 5 min pentru watchdog (WP are din oficiu doar hourly/twicedaily/daily). */
    public function cron_intervals($schedules) {
        if (empty($schedules['hz_5min'])) {
            $schedules['hz_5min'] = array('interval' => 300, 'display' => 'La 5 minute (HZ)');
        }
        return $schedules;
    }

    /** Tick-ul watchdog-ului (rulează în fundal): doar încearcă recuperarea unei rulări moarte. */
    public function watchdog_tick() {
        $this->maybe_recover();
    }

    /* ---- Coada (stocată în opțiune, fără autoload) ---- */

    private function queue() {
        $q = get_option('hzb_queue', array());
        return is_array($q) ? $q : array();
    }

    private function save_queue($q) {
        update_option('hzb_queue', array_values($q), 'no');
    }

    /**
     * Programează procesarea imediat, dacă nu rulează deja ceva.
     *
     * v3.30.2: dacă există DEJA o rulare programată, dar mult mai târziu, o mutăm în
     * față. Altfel coada rămâne blocată ore în șir fără niciun semn: blocajul de
     * imagini (cotă atinsă) își programează revenirea la 00:00 UTC, iar de acolo
     * `wp_next_scheduled` întorcea mereu un timestamp „valid", deci Reîncearcă /
     * Pornește / Deblochează chemau kick() degeaba — nu se mai programa nimic apropiat
     * și panoul nici măcar nu avea ce avertisment să afișeze (next_run != 0).
     */
    private function kick($delay = 1) {
        if ((int) get_option('hzb_stopped', 0)) { return; } // oprit de user
        $when = time() + max(1, (int) $delay);
        $next = wp_next_scheduled(self::HOOK);
        if ($next && $next > $when + 60) {
            wp_unschedule_event($next, self::HOOK);
            $next = false;
        }
        if (!$next) {
            wp_schedule_single_event($when, self::HOOK);
        }
        if (!get_transient(self::LOCK) && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Auto-vindecare: dacă o rulare a MURIT și a lăsat lock-ul (coada stă degeaba, dar
     * panoul arată „rulează"), îl curățăm și repornim SINGURI - fără „Deblochează" manual.
     * NU atingem o rulare care chiar e în curs (item „working" pornit recent), ca să nu
     * producem un dublu. Apelată la fiecare poll de status + de watchdog-ul de 5 min.
     * @return bool true dacă a recuperat o rulare moartă.
     */
    public function maybe_recover() {
        if ((int) get_option('hzb_stopped', 0)) { return false; } // oprit voit de user
        if (!get_transient(self::LOCK)) { return false; }          // nu e blocat

        $q = $this->queue();
        $has_working = false; $fresh_working = false;
        foreach ($q as $item) {
            if ($item['s'] !== 'working') { continue; }
            $has_working = true;
            $started = isset($item['started']) ? (int) $item['started'] : 0;
            // „working" pornit de sub 20 min = rulare probabil VIE (generarea durează
            // 1-3 min); n-o atingem, ca să nu iasă al doilea articol cu același titlu.
            if ($started && (time() - $started) <= 20 * MINUTE_IN_SECONDS) { $fresh_working = true; break; }
        }
        if ($fresh_working) { return false; } // rulare reală în curs -> lăsăm în pace

        // Lock fără rulare vie. Dacă nu e „working" deloc, dăm o mică grație (fereastra
        // de ms dintre punerea lock-ului și marcarea itemului „working" în process_tick).
        $lock_at = (int) get_option('hzb_lock_at', 0);
        if (!$has_working && $lock_at && (time() - $lock_at) < 3 * MINUTE_IN_SECONDS) { return false; }

        // Recuperare (identic cu „unlock", dar automat): scoatem lock-ul și repunem la
        // rând articolele rămase „în lucru" dintr-o rulare moartă, apoi repornim coada.
        delete_transient(self::LOCK);
        delete_option('hzb_lock_at');
        $n = 0;
        foreach ($q as &$item) {
            if ($item['s'] === 'working') { $item['s'] = 'pending'; $n++; }
        }
        unset($item);
        if ($n > 0) { $this->save_queue($q); }
        $this->kick(2);
        return true;
    }

    /** Normalizare titlu pentru comparat dubluri (spații, majuscule). */
    private function norm_title($t) {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $t)));
    }

    /** ID-ul unui articol existent cu acest titlu (0 dacă nu există). */
    private function existing_post_id($title) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','future','private') AND post_title = %s ORDER BY ID ASC LIMIT 1",
            wp_strip_all_tags($title)
        ));
    }

    /** True dacă există deja un articol (orice stare relevantă) cu acest titlu. */
    private function post_title_exists($title) {
        return $this->existing_post_id($title) > 0;
    }

    /* ---- AJAX ---- */

    public function ajax_enqueue() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        if (!class_exists('CW_API') || !CW_API::has_key()) {
            wp_send_json_error(array('message' => 'Configurează întâi cheia API (OpenAI sau Anthropic) în tab-ul AI Writer.'));
        }

        $titles_raw = isset($_POST['titles']) ? sanitize_textarea_field(wp_unslash($_POST['titles'])) : '';
        $titles = array();
        foreach (preg_split('/\r\n|\r|\n/', $titles_raw) as $line) {
            $line = trim(preg_replace('/^\s*(?:\d+[\.\)]\s*|[-*•]\s*)/u', '', $line));
            if ($line !== '' && mb_strlen($line) >= 3) {
                $titles[] = mb_substr($line, 0, 200);
            }
        }
        $titles = array_values(array_unique($titles));
        if (empty($titles)) {
            wp_send_json_error(array('message' => 'Lista e goală — pune câte un titlu pe linie.'));
        }
        if (count($titles) > 100) {
            wp_send_json_error(array('message' => 'Maximum 100 de titluri per lot.'));
        }

        $cat    = isset($_POST['category']) ? (int) $_POST['category'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'draft';
        $model  = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $extra  = isset($_POST['extra']) ? sanitize_textarea_field(wp_unslash($_POST['extra'])) : '';

        $rows = array();
        foreach ($titles as $t) {
            $rows[] = array('title' => $t, 'cat' => $cat);
        }
        $res = $this->add_items($rows, array(
            'status' => $status,
            'model'  => $model,
            'extra'  => $extra,
            // Bifa „Căutare web" din formular: se aplică DOAR lotului ăsta.
            'web'    => isset($_POST['web']) ? (int) $_POST['web'] : '',
        ));

        $msg = sprintf('%d articole adăugate în coadă.', $res['added']);
        if ($res['skipped'] > 0) {
            $msg .= sprintf(' %d sărite (deja în coadă sau articol existent cu același titlu).', $res['skipped']);
        }
        wp_send_json_success(array('message' => $msg));
    }

    /**
     * Adaugă articole în coadă. Folosit și de formularul din admin, și de comanda
     * venită prin REST din panoul central (app.targetseo.ro).
     *
     * $rows: array de array('title', 'cat' => int, 'cat_name' => string, 'links' => array(anchor/url/rel))
     * $opts: status, model, extra, batch, length, images, create_category
     *
     * Categoria: ID valid > categorie cu acel NUME > (dacă e cerut) creare > default site.
     * Numele contează pentru comenzile venite din panoul central: acolo alegi „Articole
     * sponsorizate" pentru tot lotul, iar ID-ul diferă de la site la site.
     *
     * Anti-dubluri: sărim titlurile deja în coadă SAU care au deja un articol pe site
     * (lista poate fi lipită de două ori sau poate conține subiecte deja scrise).
     *
     * @return array array('added','skipped','queued')
     */
    public function add_items($rows, $opts = array()) {
        $status = isset($opts['status']) ? (string) $opts['status'] : 'draft';
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) { $status = 'draft'; }
        $model  = isset($opts['model']) ? (string) $opts['model'] : '';
        $extra  = mb_substr(isset($opts['extra']) ? (string) $opts['extra'] : '', 0, 2000);
        $batch  = isset($opts['batch']) ? substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $opts['batch']), 0, 40) : '';
        $len    = isset($opts['length']) ? (int) $opts['length'] : 0;
        $img    = isset($opts['images']) && $opts['images'] !== '' ? max(0, min(self::MAX_IMAGES, (int) $opts['images'])) : null;
        $imgsrc = (isset($opts['img_source']) && in_array($opts['img_source'], array('magnific', 'ai'), true)) ? $opts['img_source'] : '';
        $imgstyle = isset($opts['img_style']) ? mb_substr(trim((string) $opts['img_style']), 0, 500) : '';
        $mkcat  = !empty($opts['create_category']);
        // v3.16.0: 'review' = articol editorial cu produse (vezi HZ_Review). Orice
        // altceva = articolul obișnuit, pe șablonul editorial al site-ului.
        $mode   = (isset($opts['mode']) && $opts['mode'] === 'review') ? 'review' : '';
        $created = array();
        // Căutare web per lot: null = las setarea site-ului, 0/1 = o forțez.
        $web = (isset($opts['web']) && $opts['web'] !== '' && $opts['web'] !== null) ? (int) !!$opts['web'] : null;
        // Data de publicare per lot: null = las setarea site-ului, 0 = data curentă (la zi),
        // 1 = backdatare random (împrăștiate în ultima lună).
        $rd = (isset($opts['random_date']) && $opts['random_date'] !== '' && $opts['random_date'] !== null) ? (int) !!$opts['random_date'] : null;

        $q     = $this->queue();
        $known = array();
        foreach ($q as $item) {
            $known[$this->norm_title($item['t'])] = 1;
        }

        $added   = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '' || mb_strlen($title) < 3) { $skipped++; continue; }
            $title = mb_substr($title, 0, 200);

            $key = $this->norm_title($title);
            if (isset($known[$key]) || $this->post_title_exists($title)) {
                $skipped++;
                continue;
            }
            $known[$key] = 1;

            $links = array();
            if (!empty($row['links']) && class_exists('HZ_Links')) {
                $links = HZ_Links::sanitize_list($row['links']);
            }

            // Produsele articolului editorial devin ȘI linkuri (ancoră = numele
            // produsului, rel=sponsored), ca să refolosim garanția de inserare din
            // HZ_Links. Produsele intră PRIMELE: dacă lotul cere și linkuri editoriale,
            // pe alea le tăiem noi la plafon, nu produsele plătite.
            $prod = array();
            if ($mode === 'review' && !empty($row['products']) && class_exists('HZ_Review')) {
                $prod = HZ_Review::sanitize_products($row['products']);
                if (count($prod) < HZ_Review::MIN_PRODUCTS) {
                    $skipped++;
                    unset($known[$key]); // titlul rămâne disponibil pentru o comandă corectă
                    continue;
                }
                // v3.16.1: doar produsele FĂRĂ poză se leagă în text. Cele cu poză își
                // au linkul în blocul numerotat, iar dacă le legam și în text ajungeau
                // la trei linkuri fiecare (nume + poză + legendă).
                if (class_exists('HZ_Links')) {
                    $links = HZ_Links::sanitize_list(
                        array_merge(HZ_Review::to_links($prod, true), $links),
                        HZ_Review::MAX_PRODUCTS + 2
                    );
                }
            }

            $q[] = array(
                't'       => $title,
                's'       => 'pending',
                'cat'     => $this->resolve_category(
                    isset($row['cat']) ? $row['cat'] : 0,
                    isset($row['cat_name']) ? $row['cat_name'] : '',
                    $mkcat,
                    $created
                ),
                'st'      => $status,
                'model'   => $model,
                'extra'   => $extra,
                'post_id' => 0,
                'err'     => '',
                'note'    => '',
                'cost'    => 0.0,
                'links'   => $links,
                'mode'    => $mode,
                'prod'    => $prod,
                'b'       => $batch,
                'len'     => $len > 0 ? max(300, $len) : 0,
                'img'     => $img,
                'imgsrc'  => $imgsrc,
                'imgstyle'=> $imgstyle,
                'web'     => $web,
                'rd'      => $rd,
                'lnk'     => array('total' => count($links), 'done' => 0, 'forced' => 0),
            );
            $added++;
        }

        $this->save_queue($q);
        if ($added > 0) { $this->kick(); }

        return array(
            'added'   => $added,
            'skipped' => $skipped,
            'queued'  => count($q),
            'created_categories' => array_keys($created),
        );
    }

    /**
     * ID-ul categoriei cerute: ID valid > categorie cu acel nume > creare (dacă e
     * permisă) > 0 (categoria implicită a site-ului).
     *
     * Numele e sursa de adevăr pentru comenzile din panoul central: „Articole
     * sponsorizate" are alt ID pe fiecare site, iar pe site-urile noi nici nu există.
     */
    private function resolve_category($id, $name, $create, &$created) {
        $id = (int) $id;
        if ($id > 0 && get_term($id, 'category') instanceof WP_Term) {
            return $id;
        }
        $name = trim(wp_strip_all_tags((string) $name));
        if ($name === '') { return 0; }

        $term = get_term_by('name', $name, 'category');
        if ($term instanceof WP_Term) { return (int) $term->term_id; }

        if (!$create) { return 0; }

        $new = wp_insert_term($name, 'category');
        if (is_wp_error($new)) {
            // Cursă cu alt request sau nume deja existent sub alt părinte.
            $exists = term_exists($name, 'category');
            return is_array($exists) ? (int) $exists['term_id'] : 0;
        }
        $created[$name] = 1;
        return (int) $new['term_id'];
    }

    /**
     * Starea articolelor dintr-un lot trimis din panoul central + starea coadei.
     * Fără $batch, raportează toată coada.
     */
    public function batch_status($batch = '', $with_items = true) {
        $items   = array();
        $pending = 0;
        foreach ($this->queue() as $item) {
            if ($item['s'] === 'pending' || $item['s'] === 'working') { $pending++; }
            if (!$with_items) { continue; }
            if ($batch !== '' && (!isset($item['b']) || $item['b'] !== $batch)) { continue; }
            $pid = (int) $item['post_id'];
            $lnk = isset($item['lnk']) && is_array($item['lnk']) ? $item['lnk'] : array();
            $items[] = array(
                'title'       => (string) $item['t'],
                'status'      => (string) $item['s'],
                'post_id'     => $pid,
                'url'         => $pid ? get_permalink($pid) : '',
                'edit_url'    => $pid ? get_edit_post_link($pid, 'raw') : '',
                'error'       => (string) $item['err'],
                'note'        => (string) $item['note'],
                'cost'        => (float) $item['cost'],
                'links_total' => (int) (isset($lnk['total']) ? $lnk['total'] : 0),
                'links_done'  => (int) (isset($lnk['done']) ? $lnk['done'] : 0),
                'links_forced'=> (int) (isset($lnk['forced']) ? $lnk['forced'] : 0),
            );
        }

        // Auto-vindecare: curăță o rulare moartă (lock rămas) și repornește coada -
        // fără „Deblochează" manual. Rulează la FIECARE poll de status al aplicației.
        $this->maybe_recover();
        // Plasă de siguranță: dacă e treabă de făcut dar cronul a adormit (site fără
        // trafic), îl repornim.
        if ($pending > 0 && !get_transient(self::LOCK)) { $this->kick(); }

        return array(
            'items'        => $items,
            'queue_pending'=> $pending,
            'stopped'      => (int) get_option('hzb_stopped', 0),
            'paused'       => (string) get_option('hzb_paused_reason', ''),
            'gap'          => (int) get_option('hzb_gap', 120),
            // Lock ținut, dar nimic „în lucru" = rulare moartă; panoul oferă deblocarea.
            'locked'       => get_transient(self::LOCK) ? 1 : 0,
            // Când e programată următoarea rulare (0 = nicio rulare programată — atunci
            // coada stă pe loc, oricâte articole ar avea).
            'next_run'     => (int) wp_next_scheduled(self::HOOK),
            'now'          => time(),
            'cron_off'     => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 1 : 0,
        );
    }

    /** Comenzi din panoul central: start / stop / retry / clear (doar ce nu e în lucru). */
    public function control($action) {
        switch ($action) {
            case 'start':
                update_option('hzb_stopped', 0, 'no');
                $this->kick();
                return 'Procesarea a fost pornită.';
            case 'stop':
                update_option('hzb_stopped', 1, 'no');
                wp_clear_scheduled_hook(self::HOOK);
                return 'Procesarea a fost oprită.';
            case 'retry':
                $q = $this->queue();
                $n = 0;
                $pending = 0;
                foreach ($q as &$item) {
                    if ($item['s'] === 'error') { $item['s'] = 'pending'; $item['err'] = ''; $n++; }
                    if ($item['s'] === 'pending') { $pending++; }
                }
                unset($item);
                $this->save_queue($q);
                // „Reîncearcă" e o comandă explicită de reluare: ridicăm și oprirea, și
                // pauza automată (cotă de imagini), altfel kick() iese pe loc și userul
                // vede butonul apăsat, dar coada nu pornește niciodată.
                update_option('hzb_stopped', 0, 'no');
                delete_option('hzb_paused_reason');
                if ($pending > 0) { $this->kick(); }
                return $pending > 0
                    ? sprintf('%d articole repuse în coadă (%d de procesat).', $n, $pending)
                    : 'Nimic de reluat: coada nu are articole cu eroare.';
            case 'clear':
                $q = array();
                foreach ($this->queue() as $item) {
                    if ($item['s'] === 'working') { $q[] = $item; }
                }
                $this->save_queue($q);
                delete_option('hzb_paused_reason');
                return 'Coada a fost golită.';

            // Lock-ul e un transient de 30 min pus la începutul fiecărui articol. Dacă
            // rularea moare (timeout PHP, worker ucis), lock-ul rămâne și coada stă pe
            // loc, aparent fără motiv — asta îl scoate și repornește procesarea.
            case 'unlock':
                delete_transient(self::LOCK);
                $q = $this->queue();
                $n = 0;
                foreach ($q as &$item) {
                    if ($item['s'] === 'working') { $item['s'] = 'pending'; $n++; }
                }
                unset($item);
                if ($n > 0) { $this->save_queue($q); }
                $this->kick();
                return $n > 0
                    ? sprintf('Coadă deblocată; %d articole rămase „în lucru" au fost repuse la rând.', $n)
                    : 'Coadă deblocată.';
        }
        return '';
    }

    public function ajax_status() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }

        $q = $this->queue();
        $pending = 0;
        foreach ($q as $item) {
            if ($item['s'] === 'pending' || $item['s'] === 'working') { $pending++; }
        }
        // Auto-vindecare + plasă de siguranță: curăță o rulare moartă și repornește.
        $this->maybe_recover();
        if ($pending > 0 && !get_transient(self::LOCK)) {
            $this->kick(); // nu face nimic dacă procesarea e oprită de user
        }

        $items = array();
        foreach ($q as $i => $item) {
            $items[] = array(
                'i'         => $i,
                'title'     => $item['t'],
                'status'    => $item['s'],
                'error'     => (string) $item['err'],
                'note'      => (string) $item['note'],
                'cost'      => (float) $item['cost'],
                'edit_link' => $item['post_id'] ? get_edit_post_link((int) $item['post_id'], 'raw') : '',
            );
        }
        $exhausted = HZ_Magnific::has_key() ? HZ_Magnific::is_exhausted() : false;
        wp_send_json_success(array(
            'items'     => $items,
            'pending'   => $pending,
            'downloads' => $exhausted
                ? 'cotă epuizată'
                : (HZ_Magnific::account_downloads_today() . '/' . HZ_Magnific::daily_limit()),
            'exhausted' => $exhausted ? 1 : 0,
            'reset'     => wp_date('d.m H:i', HZ_Magnific::next_reset_ts()),
            'paused'    => (string) get_option('hzb_paused_reason', ''),
            'stopped'   => (int) get_option('hzb_stopped', 0),
        ));
    }

    /** Câte poze Magnific s-au consumat azi și câte au mai rămas (contor local). */
    public function ajax_quota() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        $central = HZ_Magnific::central_today();
        $limit   = HZ_Magnific::daily_limit();
        if ($central !== null) {
            $used  = $central;
            $scope = 'descărcate prin plugin, pe toate site-urile cu aceeași cheie';
        } else {
            $used  = HZ_Magnific::downloads_today();
            $scope = 'doar pe acest site (contorul central e momentan indisponibil)';
        }
        // Dacă Magnific a refuzat deja azi, contorul nostru e irelevant: cota reală a
        // contului include și descărcări făcute în afara plugin-ului.
        wp_send_json_success(array(
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
            'exhausted' => HZ_Magnific::is_exhausted() ? 1 : 0,
            'reset'     => wp_date('d.m H:i', HZ_Magnific::next_reset_ts()),
            'scope'     => $scope,
        ));
    }

    /**
     * Completează pozele pe articolele deja generate care au rămas fără imagine
     * (ex. cota Magnific s-a terminat în timpul lotului). NU regenerează textul și
     * NU creează articole noi — doar caută imagini și le pune pe articolul existent.
     * Lucrează în loturi mici, ca să nu depășească timpul unui request AJAX.
     */
    public function ajax_backfill() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        if (!HZ_Magnific::has_key()) {
            wp_send_json_error(array('message' => 'Pune întâi cheia API Magnific în setările de mai jos.'));
        }
        if (HZ_Magnific::limit_reached()) {
            wp_send_json_error(array('message' => sprintf(
                'Cota de descărcări Magnific e epuizată azi. Încearcă după %s.',
                wp_date('d.m H:i', HZ_Magnific::next_reset_ts())
            )));
        }
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $images = max(1, min(self::MAX_IMAGES, (int) get_option('hzb_images', 1)));
        $batch  = 5; // per apas; userul mai apasă o dată pentru următorul lot
        $q      = $this->queue();
        $filled = 0;
        $left   = 0;
        $note   = '';

        foreach ($q as $i => $item) {
            if ($item['s'] !== 'done' || empty($item['post_id'])) { continue; }
            $pid = (int) $item['post_id'];
            if (!get_post($pid) || has_post_thumbnail($pid)) { continue; }

            if ($filled >= $batch) { $left++; continue; }

            $img = $this->fetch_images((string) $item['t'], (string) $item['model'], $images, (string) ($item['imgsrc'] ?? ''), (string) ($item['imgstyle'] ?? ''));
            if (empty($img['ids'])) {
                $note = $img['note'];
                // Cotă terminată în timpul lotului: ne oprim, restul rămân pentru mâine.
                // v3.10.0: pe flagul explicit, nu pe potrivire de text în mesajul de eroare.
                if (!empty($img['blocked'])) {
                    $left++;
                    break;
                }
                continue;
            }

            set_post_thumbnail($pid, $img['ids'][0]);
            foreach ($img['ids'] as $att) {
                wp_update_post(array('ID' => $att, 'post_parent' => $pid));
            }
            // Imaginile suplimentare intră în corpul articolului existent.
            if (count($img['ids']) > 1) {
                $post = get_post($pid);
                if ($post instanceof WP_Post) {
                    $content = $this->insert_images($post->post_content, array_slice($img['ids'], 1));
                    wp_update_post(array('ID' => $pid, 'post_content' => wp_slash($content)));
                }
            }
            $q[$i]['note'] = count($img['ids']) . ' imagini (completate ulterior)';
            $filled++;
        }

        $this->save_queue($q);
        // Recalculăm câte au rămas fără poză, ca mesajul să fie exact.
        $left = 0;
        foreach ($this->queue() as $item) {
            if ($item['s'] === 'done' && !empty($item['post_id'])
                && get_post((int) $item['post_id']) && !has_post_thumbnail((int) $item['post_id'])) {
                $left++;
            }
        }

        if ($filled === 0 && $left === 0) {
            wp_send_json_success(array('message' => 'Toate articolele din coadă au deja imagine.'));
        }
        $msg = sprintf('%d articole au primit imagini.', $filled);
        if ($left > 0) {
            $msg .= sprintf(' Au mai rămas %d fără poză — mai apasă o dată.', $left);
        }
        if ($filled === 0 && $note !== '') {
            $msg = 'Nu s-a putut completa: ' . $note;
        }
        wp_send_json_success(array('message' => $msg));
    }

    /** Oprește / repornește procesarea coadei (articolul în lucru se termină). */
    public function ajax_toggle() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        $stop = isset($_POST['stop']) ? (int) $_POST['stop'] : 1;
        update_option('hzb_stopped', $stop ? 1 : 0, 'no');
        if (!$stop) {
            $this->kick();
        } else {
            wp_clear_scheduled_hook(self::HOOK);
        }
        wp_send_json_success(array(
            'message' => $stop
                ? 'Procesarea a fost oprită. Articolul aflat în lucru se termină, restul așteaptă.'
                : 'Procesarea a fost repornită.',
        ));
    }

    public function ajax_retry() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        $q = $this->queue();
        $n = 0;
        foreach ($q as &$item) {
            if ($item['s'] === 'error') {
                $item['s']   = 'pending';
                $item['err'] = '';
                $n++;
            }
        }
        unset($item);
        $this->save_queue($q);
        if ($n > 0) { $this->kick(); }
        wp_send_json_success(array('message' => sprintf('%d articole repuse în coadă.', $n)));
    }

    public function ajax_clear() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        // Ștergem din coadă tot ce nu e în lucru chiar acum. Articolele deja
        // create rămân neatinse — coada e doar evidența procesării.
        $q = array();
        foreach ($this->queue() as $item) {
            if ($item['s'] === 'working') { $q[] = $item; }
        }
        $this->save_queue($q);
        delete_option('hzb_paused_reason');
        wp_send_json_success(array('message' => 'Coada a fost golită.'));
    }

    /* ---- Procesare (un articol per rulare de cron) ---- */

    public function process_tick() {
        if ((int) get_option('hzb_stopped', 0)) { return; }
        if (get_transient(self::LOCK)) { return; }
        if (!class_exists('CW_API') || !CW_API::has_key()) { return; }
        // v3.4.8: 30 min, nu 15. Un articol lent (API încet + descărcări de imagini)
        // depășea vechiul TTL, lock-ul expira și următorul cron intra peste el.
        set_transient(self::LOCK, 1, 30 * MINUTE_IN_SECONDS);
        update_option('hzb_lock_at', time(), 'no'); // v3.8.3: momentul lock-ului, pt auto-vindecare
        if (function_exists('set_time_limit')) { @set_time_limit(600); }

        $q   = $this->queue();
        $idx = null;
        foreach ($q as $i => $item) {
            if ($item['s'] === 'pending') { $idx = $i; break; }
            // v3.4.8: un item „working" se reia DOAR dacă e vechi. Înainte îl reluam
            // imediat, presupunând că rularea anterioară a murit — dar de cele mai
            // multe ori ea era doar în curs (generarea durează 1-3 minute), iar
            // reluarea producea încă un articol cu același titlu.
            $started = isset($item['started']) ? (int) $item['started'] : 0;
            if ($item['s'] === 'working' && (!$started || (time() - $started) > 20 * MINUTE_IN_SECONDS)) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            delete_transient(self::LOCK);
            return;
        }

        // NU scriem articole fără poze. Dacă sursa de imagini nu poate livra (cotă
        // atinsă, refuz al contului sau cheie lipsă), coada așteaptă — articolul nici
        // măcar nu se generează, ca să nu plătim un text pe care nu-l putem publica.
        $images = $this->images_for($q[$idx]);
        if ($images > 0) {
            $block = self::images_blocked((string) ($q[$idx]['imgsrc'] ?? ''));
            if ($block !== null) {
                update_option('hzb_paused_reason', $block['msg'], 'no');
                delete_transient(self::LOCK);
                // Cota se resetează singură la 00:00 UTC, deci ne reprogramăm exact
                // atunci. Cheia lipsă NU se rezolvă de la sine: acolo coada stă până o
                // pune userul (salvarea cheii repornește coada, vezi HZ_Admin).
                if ($block['at'] && !wp_next_scheduled(self::HOOK)) {
                    wp_schedule_single_event($block['at'], self::HOOK);
                }
                return;
            }
        }
        delete_option('hzb_paused_reason');

        $q[$idx]['s']       = 'working';
        $q[$idx]['started'] = time(); // v3.4.8: de aici se măsoară „prea vechi = reia"
        $this->save_queue($q);

        $result = $this->process_item($q[$idx]);

        // Recitim coada: între timp userul poate a adăugat/șters itemi.
        $q = $this->queue();
        if (isset($q[$idx])) {
            $q[$idx] = $result;
            $this->save_queue($q);
        }

        delete_transient(self::LOCK);

        // Mai e ceva de lucru? Înlănțuim următoarea rulare, cu pauza dintre
        // articole setată de user (ritm natural de publicare + menajează API-urile).
        foreach ($this->queue() as $item) {
            if ($item['s'] === 'pending') {
                $gap = (int) get_option('hzb_gap', 120);
                $this->kick(max(5, min(3600, $gap)));
                break;
            }
        }
    }

    /* Nota itemului spune mereu CE s-a întâmplat cu imaginile — un articol fără
     * poză nu e o eroare a articolului, dar motivul trebuie să fie vizibil în coadă. */

    /** Procesează un item: generare articol + imagini. Returnează itemul actualizat. */
    private function process_item($item) {
        $title = (string) $item['t'];
        $model = CW_API::resolve_model((string) $item['model']);
        $cost  = 0.0;

        // 1) Conținutul: același șablon și system prompt editorial ca butonul
        // „Articol nou" din editor — stil deja pus la punct per site.
        // v3.10.0: dacă o rulare anterioară a scris textul dar s-a oprit fiindcă nu mai
        // erau poze, îl refolosim — altfel am plăti de două ori același articol.
        $content   = '';
        $link_note = '';
        $searches  = 0;
        $queries   = 0;
        $saved     = (isset($item['gen']) && is_array($item['gen']) && !empty($item['gen']['html']))
            ? $item['gen']
            : null;
        if ($saved) {
            // Linkurile cerute sunt deja aplicate în textul salvat; NU le mai trecem o
            // dată prin HZ_Links (ar insera dubluri) și nici prin strip_external.
            $content   = (string) $saved['html'];
            $link_note = isset($saved['ln']) ? (string) $saved['ln'] : '';
            $searches  = isset($saved['s']) ? (int) $saved['s'] : 0;
            $queries   = isset($saved['q']) ? (int) $saved['q'] : 0;
            $cost     += isset($saved['c']) ? (float) $saved['c'] : 0.0;
            if (isset($saved['lnk'])) { $item['lnk'] = $saved['lnk']; }
        } else {
            $links = (isset($item['links']) && is_array($item['links'])) ? $item['links'] : array();
            // v3.16.0 — „editorial cu produse": prompt propriu (structura pe criterii de
            // alegere), iar numele produselor sunt DEJA în el. Deci nu mai trece și prin
            // prompt_hint, care ar cere a doua oară aceleași expresii.
            $review = (isset($item['mode']) && $item['mode'] === 'review')
                && class_exists('HZ_Review') && !empty($item['prod']);

            $brief = $title;
            if (trim((string) $item['extra']) !== '') {
                $brief .= "\n\nInstrucțiuni suplimentare pentru acest lot de articole:\n" . trim((string) $item['extra']);
            }
            // Ancorele cerute din panoul central intră în brief: modelul le scrie în text,
            // iar linkul propriu-zis îl punem noi după generare (HZ_Links).
            if (!$review && !empty($links) && class_exists('HZ_Links')) {
                $brief .= HZ_Links::prompt_hint($links);
            }
            // Cu căutare web îi spunem ce zi e (altfel „acum" înseamnă data antrenamentului)
            // și îi cerem explicit să nu inventeze cifre.
            $web_on = (isset($item['web']) && $item['web'] !== null && $item['web'] !== '')
                ? (int) $item['web']
                : (int) get_option('cw_web_search', 0);
            if ($web_on === 1) {
                $brief .= "\n\nAstăzi este " . wp_date('j F Y') . ". Caută informații actuale înainte să scrii, "
                    . "folosește DOAR date verificate în surse și nu inventa cifre, prețuri sau termene. "
                    . "NU insera linkuri sau adrese de site (URL) în articol și NU menționa nume de site-uri "
                    . ".ro (ex. „exemplu.ro”), cu excepția instituțiilor oficiale ale statului. Când citezi o "
                    . "cifră recentă, spune tipul sursei (ex. „date oficiale”, „un studiu de specialitate”), "
                    . "fără să dai numele domeniului sau linkul.";
            }
            $words = (isset($item['len']) && (int) $item['len'] > 0)
                ? (int) $item['len']
                : ($review ? HZ_Review::DEFAULT_WORDS : (int) get_option('hzb_length', 1000));
            if ($review) {
                // Prompt editorial propriu: criterii de alegere + recomandări argumentate.
                // Instrucțiunile de lot ($item['extra']) și nota despre căutarea web sunt
                // deja în $brief, dar $brief conține și titlul — îl scoatem, ca să nu ajungă
                // de două ori în prompt.
                $tail   = trim(substr($brief, strlen($title)));
                $prompt = HZ_Review::prompt($title, $item['prod'], $words, $tail);
            } else {
                $tpl = get_option('cw_article_prompt', CW_Admin::default_article_prompt());
                $prompt = str_replace(
                    array('{{continut}}', '{{cuvinte}}'),
                    array($brief, (string) max(300, $words)),
                    $tpl
                );
            }

            // Căutare web doar pentru ARTICOL (nu și pentru micro-apelul de traducere a
            // interogării de imagini), și doar dacă lotul a cerut-o explicit.
            $web = (isset($item['web']) && $item['web'] !== null && $item['web'] !== '') ? (int) $item['web'] : null;
            $web_filter = null;
            if ($web !== null) {
                $web_filter = function () use ($web) { return $web; };
                add_filter('cw_web_search', $web_filter, 99);
            }

            // Eticheta de cost: articolele „editorial cu produse" se contorizeaza separat.
            $ctx    = $review ? 'review' : 'bulk';
            $result = CW_Usage::with_context($ctx, function () use ($model, $prompt) {
                return CW_API::generate($model, $prompt);
            });

            if ($web_filter) { remove_filter('cw_web_search', $web_filter, 99); }

            if (is_wp_error($result)) {
                // Unele erori vin DUPA ce apelul a fost taxat (ex. raspuns gol la modelele
                // cu rationament): pastram costul, ca sa se vada corect in coada.
                $edata = $result->get_error_data();
                if (is_array($edata) && isset($edata['cost'])) { $cost += (float) $edata['cost']; }
                $item['s']    = 'error';
                $item['err']  = $result->get_error_message();
                $item['cost'] = $cost;
                return $item;
            }
            $cost += (float) $result['cost'];
            $searches = isset($result['searches']) ? (int) $result['searches'] : 0;
            $queries  = isset($result['queries']) ? (int) $result['queries'] : 0;

            $content = $this->clean_html($result['text']);
            if (strlen($content) < 500) {
                $item['s']   = 'error';
                $item['err'] = 'Conținut generat prea scurt (' . strlen($content) . ' caractere).';
                $item['cost'] = $cost;
                return $item;
            }

            // 1a) Curățăm referințele externe puse de MODEL (link-uri + nume de site-uri .ro
            // concurente, mai ales din căutarea web); păstrăm doar site-urile OFICIALE ale
            // statului. Rulăm ÎNAINTE de apply(), ca linkurile CERUTE de user să nu fie atinse.
            if (class_exists('HZ_Links')) { $content = HZ_Links::strip_external($content); }

            // 1b) Linkurile cerute din panoul central. Se pun ÎNAINTE de imagini, ca
            // ancora să fie căutată doar în text, nu în legendele figurilor.
            $link_note = '';
            if (!empty($links) && class_exists('HZ_Links')) {
                $applied     = HZ_Links::apply($content, $links);
                $content     = $applied['content'];
                $item['lnk'] = array(
                    'total'  => (int) $applied['total'],
                    'done'   => (int) $applied['done'],
                    'forced' => (int) $applied['forced'],
                );
                $link_note = HZ_Links::note($applied);
            }

            // 1c) Articolele cu produse: nota de dezvăluire a afilierii (obligatorie —
            // Google pentru conținut cu linkuri plătite, DSA/ANPC pentru comunicare
            // comercială) + verificarea că a ieșit editorial, nu catalog. Avertismentul
            // nu blochează publicarea (articolul e deja plătit), dar apare în panou.
            if ($review) {
                $content = HZ_Review::add_disclosure($content);
                $warn    = HZ_Review::warnings($content, $item['prod']);
                if ($warn !== '') {
                    $link_note = trim($warn . ' · ' . $link_note, " \t·");
                }
            }
        }

        // 2) Imaginile: interogare de căutare în engleză (stocul e indexat în
        // engleză), generată de model din titlu — apel minuscul, cost neglijabil.
        $images   = $this->images_for($item);
        $img      = $this->fetch_images($title, $model, $images, (string) ($item['imgsrc'] ?? ''), (string) ($item['imgstyle'] ?? ''));
        $att_ids  = $img['ids'];
        $img_note = $img['note'];
        $cost    += $img['cost'];

        // Cota s-a golit ÎNTRE verificarea de dinainte de generare și acum (o poate
        // consuma alt site cu aceeași cheie, cât scriem noi 1-3 minute). Nu publicăm
        // articolul fără poză: îl punem la loc la rând CU TEXTUL PĂSTRAT, iar coada se
        // oprește la tick-ul următor și revine la resetul cotei.
        if ($images > 0 && empty($att_ids) && !empty($img['blocked'])) {
            $item['s']    = 'pending';
            $item['cost'] = $cost;
            $item['err']  = '';
            $item['gen']  = array(
                'html' => $content,
                'ln'   => $link_note,
                's'    => $searches,
                'q'    => $queries,
                'c'    => $cost,
                'lnk'  => isset($item['lnk']) ? $item['lnk'] : null,
            );
            $item['note'] = 'Text gata, aștept poze: ' . $img_note;
            return $item;
        }
        unset($item['gen']); // publicăm acum, textul salvat nu mai e necesar

        // Imaginile suplimentare (după cover) intră în corp, distribuite după H2-uri.
        if (count($att_ids) > 1) {
            $content = $this->insert_images($content, array_slice($att_ids, 1));
        }

        // 3) Articolul.
        $status = in_array($item['st'], array('publish', 'draft', 'pending'), true) ? $item['st'] : 'draft';
        $postarr = array(
            'post_title'    => wp_strip_all_tags($title),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_author'   => $this->author_id(),
            'post_category' => array($this->category_id((int) $item['cat'])),
        );
        // Dată random în luna curentă + luna trecută (backdatare, ca articolele bulk să
        // nu aibă toate același timestamp). Opțional (hzb_random_date, implicit pornit).
        $rand = $this->random_publish_date($item);
        if ($rand) {
            $postarr['post_date']     = $rand['local'];
            $postarr['post_date_gmt'] = $rand['gmt'];
        }
        // v3.4.8 — ULTIMA BARIERĂ CONTRA DUBLURILOR.
        // Verificarea de la adăugarea în coadă nu ajută aici: între timp poate să fi
        // apărut un articol cu același titlu — inclusiv o încercare ANTERIOARĂ a
        // noastră, dacă itemul a rămas „working" și a fost reluat (lock expirat pe o
        // generare lentă sau transient pierdut la golirea cache-ului de obiecte).
        // Generarea durează 1-3 minute, iar articolul se insera abia la final, deci
        // fiecare reluare crea încă o copie. Acum ne oprim înainte de inserare.
        $existing = $this->existing_post_id($title);
        if ($existing) {
            $item['s']       = 'done';
            $item['post_id'] = $existing;
            $item['cost']    = $cost;
            $item['err']     = '';
            $item['note']    = 'Exista deja un articol cu acest titlu - duplicat evitat';
            return $item;
        }

        $post_id = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($post_id)) {
            $item['s']    = 'error';
            $item['err']  = $post_id->get_error_message();
            $item['cost'] = $cost;
            return $item;
        }
        update_post_meta($post_id, '_hzb_bulk', current_time('Y-m-d'));

        // Pozele produselor: se pot atașa abia acum, după ce articolul are un ID.
        // Blocurile intră după paragraful în care e recomandat fiecare produs, nu
        // într-o grilă la final (vezi HZ_Review::insert_product_blocks).
        if (!empty($item['mode']) && $item['mode'] === 'review'
            && !empty($item['prod']) && class_exists('HZ_Review')) {
            $prod_atts = HZ_Review::sideload_images($item['prod'], $post_id);
            if (!empty($prod_atts)) {
                $with_blocks = HZ_Review::insert_product_blocks($content, $item['prod'], $prod_atts);
                if ($with_blocks !== $content) {
                    wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash($with_blocks)));
                    $content = $with_blocks;
                    // Marcaj pentru stilul blocurilor: CSS-ul se încarcă doar pe
                    // articolele care chiar au produse.
                    update_post_meta($post_id, '_hz_review', 1);
                }
            }
            $prod_note = count($prod_atts) . '/' . count($item['prod']) . ' poze produs';
        }

        if (!empty($att_ids)) {
            set_post_thumbnail($post_id, $att_ids[0]);
            foreach ($att_ids as $att) {
                wp_update_post(array('ID' => $att, 'post_parent' => $post_id));
            }
        }

        $item['s']       = 'done';
        $item['post_id'] = (int) $post_id;
        $item['cost']    = $cost;
        $item['err']     = '';
        if (count($att_ids) === 0) {
            $item['note'] = 'Fără imagine' . ($img_note !== '' ? ': ' . $img_note : '');
        } else {
            $item['note'] = count($att_ids) . ' imagini' . ($img_note !== '' ? ' (' . $img_note . ')' : '');
        }
        if (!empty($prod_note)) {
            $item['note'] = $prod_note . ' · ' . $item['note'];
        }
        if ($link_note !== '') {
            $item['note'] = $link_note . ' · ' . $item['note'];
        }
        if ($searches > 0) {
            // Un apel de căutare poate grupa mai multe interogări — le arătăm pe amândouă,
            // ca să se vadă că plafonul e pe APELURI, nu pe interogări.
            $item['note'] = $searches . ' căutări web'
                . ($queries > $searches ? ' (' . $queries . ' interogări)' : '')
                . ' · ' . $item['note'];
        }
        if (!empty($result['incomplete'])) {
            $item['note'] = 'ATENȚIE: text tăiat la limita de tokeni · ' . $item['note'];
        }
        return $item;
    }

    /**
     * Poate livra sursa de imagini o poză acum? null = da. Altfel array('msg', 'at'),
     * unde `at` e momentul în care are rost să reîncercăm singuri (0 = nu se rezolvă
     * de la sine). Un articol fără poză nu se publică, deci întrebarea se pune ÎNAINTE
     * de generare — nu după ce am plătit textul.
     *
     * $source vine de pe item ('ai' = generare OpenAI, altfel stoc Magnific); gol =
     * setarea site-ului. Fără el, un lot pe generare AI se bloca pe cota Magnific,
     * care n-are nicio treabă cu el.
     */
    public static function images_blocked($source = '') {
        $source = $source !== '' ? $source : (string) get_option('hzb_img_source', 'magnific');
        if ($source === 'ai') {
            if (!class_exists('HZ_ImageGen') || !HZ_ImageGen::has_key()) {
                return array(
                    'msg' => 'Nu e configurată cheia OpenAI, deci nu putem genera imagini cu AI și '
                           . 'articolele ar ieși fără poze — coada așteaptă. Pune cheia în setări și pornește singură.',
                    'at'  => 0,
                );
            }
            return null;
        }
        if (!HZ_Magnific::has_key()) {
            return array(
                'msg' => 'Nu e configurată cheia API Magnific, deci articolele ar ieși fără poze — '
                       . 'coada așteaptă. Pune cheia în setări (tab Articole Bulk) și pornește singură.',
                'at'  => 0,
            );
        }
        if (HZ_Magnific::limit_reached()) {
            // Cota Magnific se resetează la 00:00 UTC — reluăm exact atunci, nu la
            // miezul nopții local (în România ar însemna 2-3 ore de așteptare inutilă
            // sau, invers, o repornire prea devreme care lovește iar refuzul).
            $t = HZ_Magnific::next_reset_ts();
            return array(
                'msg' => sprintf(
                    'Cota zilnică de descărcări Magnific e atinsă — nu scriem articole fără poze. '
                    . 'Coada continuă singură la %s (resetul e la 00:00 UTC).',
                    wp_date('d.m.Y H:i', $t)
                ),
                'at'  => $t,
            );
        }
        return null;
    }

    /** Câte imagini vrea acest articol: override din lot, altfel setarea site-ului. */
    /**
     * v3.13.0: UN SINGUR cadru per articol — coverul. Nu mai completăm și altă
     * imagine în corpul articolului, nici din setarea site-ului, nici din
     * override-ul lotului trimis de panou. (Ca să revii: scoate MAX_IMAGES.)
     */
    const MAX_IMAGES = 1;

    private function images_for($item) {
        if (isset($item['img']) && $item['img'] !== null && $item['img'] !== '') {
            return max(0, min(self::MAX_IMAGES, (int) $item['img']));
        }
        return max(0, min(self::MAX_IMAGES, (int) get_option('hzb_images', 1)));
    }

    /**
     * Caută pe Magnific și descarcă în Media până la $limit imagini pentru un titlu.
     * Interogarea se traduce în engleză cu AI (stocul e indexat în engleză) — apel
     * minuscul. Returnează array('ids', 'note', 'cost'); folosit și la generare, și
     * la completarea ulterioară a articolelor rămase fără poză.
     */
    private function fetch_images($title, $model, $limit, $source = '', $style = '') {
        // `blocked` = n-avem DE UNDE lua poze (cotă/credite/cheie), spre deosebire de
        // „am căutat și n-am găsit". Doar primul caz oprește publicarea articolului.
        $out = array('ids' => array(), 'note' => '', 'cost' => 0.0, 'blocked' => false);
        if ($limit < 1) { return $out; }

        // Sursa imaginilor: 'ai' = generare OpenAI (imagine unică/articol), altfel stock Magnific.
        $source = $source !== '' ? $source : (string) get_option('hzb_img_source', 'magnific');
        $ai = ($source === 'ai');

        if ($ai) {
            if (!class_exists('HZ_ImageGen') || !HZ_ImageGen::has_key()) {
                $out['note'] = 'cheia OpenAI lipsește pentru generarea imaginilor';
                $out['blocked'] = true;
                return $out;
            }
        } elseif (!HZ_Magnific::has_key()) {
            $out['note']    = 'cheia API Magnific nu e configurată în setările de mai jos';
            $out['blocked'] = true;
            return $out;
        }

        // Fără căutare web aici: e un micro-apel de traducere, n-are nevoie de internet.
        $qr = CW_Usage::with_context('imagini', function () use ($model, $title) {
            return CW_API::no_web(function () use ($model, $title) {
                return CW_API::generate($model, 'Translate this Romanian article title into a short English stock photo search query (3-5 generic, visual keywords). Reply with ONLY the keywords, nothing else: ' . $title, 100);
            });
        });
        $query = is_wp_error($qr) ? '' : trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $qr['text']));
        if (!is_wp_error($qr)) { $out['cost'] += (float) $qr['cost']; }
        if ($query === '') { $query = $title; }

        // --- Sursa AI: generam $limit imagini din cuvintele cheie ---
        if ($ai) {
            for ($k = 0; $k < $limit; $k++) {
                $att = HZ_ImageGen::sideload($query, 0, $title, $style);
                if (is_wp_error($att)) {
                    $out['note'] = $att->get_error_message();
                    $m = strtolower($out['note']);
                    // Credit/cota OpenAI epuizata -> blocat azi (coada asteapta), ca la Magnific.
                    if (strpos($m, 'quota') !== false || strpos($m, 'credit') !== false || strpos($m, 'billing') !== false) {
                        $out['blocked'] = true;
                        break;
                    }
                    continue; // o eroare punctuala nu opreste restul
                }
                $out['ids'][] = $att;
            }
            if (empty($out['ids']) && $out['note'] === '') {
                $out['note'] = 'Generarea imaginii nu a produs rezultat pentru: ' . $query;
            }
            return $out;
        }

        $found = HZ_Magnific::search($query, 12);
        if (is_wp_error($found)) {
            $out['note'] = $found->get_error_message();
            return $out;
        }

        $used = HZ_Magnific::used_ids();
        foreach ($found as $r) {
            if (count($out['ids']) >= $limit) { break; }
            if (in_array($r['id'], $used, true)) { continue; }
            $att = HZ_Magnific::sideload($r['id'], 0, $title);
            if (is_wp_error($att)) {
                $out['note'] = $att->get_error_message();
                // Cota locală SAU cea a contului e goală: nu mai încercăm nimic azi
                // (tick-ul următor pune coada pe pauză până mâine).
                if (in_array($att->get_error_code(), array('hzb_limit', 'hzb_credits'), true)) {
                    $out['blocked'] = true;
                    break;
                }
                continue; // o resursă coruptă nu oprește restul
            }
            HZ_Magnific::mark_used($r['id']);
            $out['ids'][] = $att;
        }
        if (empty($out['ids']) && $out['note'] === '') {
            $out['note'] = 'Nicio imagine de stock găsită pentru: ' . $query;
        }
        return $out;
    }

    /**
     * REFACE pozele articolelor deja publicate dintr-un lot, cu alt stil/sursa.
     * Inlocuieste imaginea reprezentativa (coverul) si sterge vechea imagine daca
     * era pusa de noi (Magnific sau AI) — ca sa nu umplem biblioteca media.
     *
     * Lucreaza in REPRIZE: $after = indexul de la care continuam (paginare stabila
     * peste coada), cu buget de timp, ca panoul sa poata apela repetat.
     *
     * @return array ['processed','replaced','failed','after','done','note']
     */
    public function reimage_batch($batch, $opts = array()) {
        $out = array('processed' => 0, 'replaced' => 0, 'failed' => 0, 'skipped_no_image' => 0, 'after' => 0, 'done' => true, 'note' => '');

        $source = isset($opts['source']) && in_array($opts['source'], array('magnific', 'ai'), true) ? $opts['source'] : '';
        $style  = isset($opts['style']) ? mb_substr(trim((string) $opts['style']), 0, 500) : '';
        // Implicit REFACEM doar articolele care AU deja poza (asta inseamna „refa pozele").
        // Cu only_with_image = 0 completam si articolele ramase fara poza.
        $onlyWith = !isset($opts['only_with_image']) || (int) $opts['only_with_image'] === 1;
        $after  = isset($opts['after']) ? max(0, (int) $opts['after']) : 0;
        $max    = isset($opts['max']) ? max(1, min(10, (int) $opts['max'])) : 3;
        $budget = 45; // secunde per reprize
        $start  = time();

        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $q = $this->queue();
        $n = count($q);
        $i = $after;

        for (; $i < $n; $i++) {
            if ($out['processed'] >= $max) { break; }
            if ((time() - $start) >= $budget) { break; }

            $item = $q[$i];
            if ($batch !== '' && (!isset($item['b']) || $item['b'] !== $batch)) { continue; }
            if (($item['s'] ?? '') !== 'done' || empty($item['post_id'])) { continue; }
            $pid = (int) $item['post_id'];
            if (!get_post($pid)) { continue; }

            $old = (int) get_post_thumbnail_id($pid);
            // „Refa pozele" = doar unde EXISTA deja o poza; cele fara raman neatinse.
            if ($onlyWith && $old <= 0) { $out['skipped_no_image']++; continue; }

            $img = $this->fetch_images(
                (string) $item['t'],
                (string) $item['model'],
                1,                                   // doar coverul
                $source !== '' ? $source : (string) ($item['imgsrc'] ?? ''),
                $style !== '' ? $style : (string) ($item['imgstyle'] ?? '')
            );
            $out['processed']++;

            if (empty($img['ids'])) {
                $out['failed']++;
                $out['note'] = $img['note'];
                if (!empty($img['blocked'])) { $i++; break; } // cota/cheie -> ne oprim curat
                continue;
            }

            set_post_thumbnail($pid, (int) $img['ids'][0]);
            wp_update_post(array('ID' => $pid, 'post_modified' => current_time('mysql')));

            // Stergem vechea imagine DOAR daca era pusa de noi (stock Magnific sau AI).
            if ($old > 0 && $old !== (int) $img['ids'][0]) {
                $ours = get_post_meta($old, '_hzb_magnific_id', true) !== '' || get_post_meta($old, '_hz_imagegen', true) !== '';
                if ($ours) { wp_delete_attachment($old, true); }
            }

            // Retinem stilul folosit pe item, ca o rulare viitoare sa stie cu ce s-a facut.
            if ($style !== '') { $q[$i]['imgstyle'] = $style; }
            if ($source !== '') { $q[$i]['imgsrc'] = $source; }
            $out['replaced']++;
        }

        $this->save_queue($q);

        // Mai exista articole de refacut dupa indexul curent?
        $more = false;
        for ($j = $i; $j < $n; $j++) {
            $it = $q[$j];
            if ($batch !== '' && (!isset($it['b']) || $it['b'] !== $batch)) { continue; }
            if (($it['s'] ?? '') !== 'done' || empty($it['post_id'])) { continue; }
            if ($onlyWith && (int) get_post_thumbnail_id((int) $it['post_id']) <= 0) { continue; }
            $more = true; break;
        }
        $out['after'] = $i;
        $out['done']  = !$more;
        return $out;
    }

    /** Inserează imaginile în corp: după al 2-lea, apoi al 4-lea subtitlu H2. */
    private function insert_images($content, $att_ids) {
        $parts = preg_split('/(<\/h2>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 3) {
            // Sub 1 H2 complet: punem imaginile la mijlocul textului, între paragrafe.
            foreach ($att_ids as $att) {
                $content .= "\n" . $this->figure_html($att);
            }
            return $content;
        }
        // Reconstruim: după închiderea H2-ului nr. 2, 4, 6... intră câte o imagine
        // (adică imaginea apare la începutul secțiunii respective).
        $out = '';
        $h2  = 0;
        $img = 0;
        foreach ($parts as $part) {
            $out .= $part;
            if (strtolower($part) === '</h2>') {
                $h2++;
                if ($h2 % 2 === 0 && $img < count($att_ids)) {
                    $out .= "\n" . $this->figure_html($att_ids[$img]) . "\n";
                    $img++;
                }
            }
        }
        // Ce n-a încăput (prea puține H2-uri) intră la final, înaintea ultimului paragraf.
        while ($img < count($att_ids)) {
            $out .= "\n" . $this->figure_html($att_ids[$img]);
            $img++;
        }
        return $out;
    }

    private function figure_html($att_id) {
        // alt GOL, intentionat: pozele de stock sunt decorative, nu descriu articolul.
        $img = wp_get_attachment_image($att_id, 'large', false, array('alt' => '', 'loading' => 'lazy'));
        return '<figure class="wp-block-image size-large">' . $img . '</figure>';
    }

    private function clean_html($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = preg_replace('/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text);
        return trim($text);
    }

    private function author_id() {
        $chosen = (int) get_option('hzb_author', 0);
        if ($chosen > 0 && get_userdata($chosen)) {
            return $chosen;
        }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    /**
     * Dată de publicare random în [prima zi a lunii TRECUTE 00:00 → ACUM], în fusul
     * site-ului. Întoarce array('local','gmt') sau null dacă opțiunea e dezactivată.
     * Toate datele sunt în trecut → articolele se publică imediat, dar backdatate.
     */
    private function random_publish_date($item = null) {
        // Override per lot (rd): null/absent = setarea site-ului; 0 = data curentă; 1 = backdate.
        $on = (is_array($item) && isset($item['rd']) && $item['rd'] !== null && $item['rd'] !== '')
            ? (int) $item['rd']
            : (int) get_option('hzb_random_date', 1);
        if (!$on) { return null; }
        $tz = wp_timezone();
        try {
            $now   = new DateTimeImmutable('now', $tz);
            $start = $now->modify('first day of last month')->setTime(0, 0, 0);
        } catch (Exception $e) {
            return null;
        }
        $a = $start->getTimestamp();
        $b = $now->getTimestamp();
        if ($b <= $a) { return null; }
        $ts = wp_rand($a, $b);
        return array(
            'local' => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s'),
            'gmt'   => gmdate('Y-m-d H:i:s', $ts),
        );
    }

    private function category_id($chosen) {
        if ($chosen > 0) {
            $term = get_term($chosen, 'category');
            if ($term instanceof WP_Term) {
                return (int) $term->term_id;
            }
        }
        return (int) get_option('default_category', 1);
    }
}
