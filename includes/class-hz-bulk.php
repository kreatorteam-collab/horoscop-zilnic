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

    public function init() {
        add_action(self::HOOK, array($this, 'process_tick'));
        add_action('wp_ajax_hzb_enqueue', array($this, 'ajax_enqueue'));
        add_action('wp_ajax_hzb_status', array($this, 'ajax_status'));
        add_action('wp_ajax_hzb_quota', array($this, 'ajax_quota'));
        add_action('wp_ajax_hzb_retry', array($this, 'ajax_retry'));
        add_action('wp_ajax_hzb_clear', array($this, 'ajax_clear'));
        add_action('wp_ajax_hzb_toggle', array($this, 'ajax_toggle'));
        add_action('wp_ajax_hzb_backfill', array($this, 'ajax_backfill'));
    }

    /* ---- Coada (stocată în opțiune, fără autoload) ---- */

    private function queue() {
        $q = get_option('hzb_queue', array());
        return is_array($q) ? $q : array();
    }

    private function save_queue($q) {
        update_option('hzb_queue', array_values($q), 'no');
    }

    /** Programează procesarea imediat, dacă nu rulează deja ceva. */
    private function kick($delay = 1) {
        if ((int) get_option('hzb_stopped', 0)) { return; } // oprit de user
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::HOOK);
        }
        if (!get_transient(self::LOCK) && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /** Normalizare titlu pentru comparat dubluri (spații, majuscule). */
    private function norm_title($t) {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $t)));
    }

    /** True dacă există deja un articol (orice stare relevantă) cu acest titlu. */
    private function post_title_exists($title) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','future','private') AND post_title = %s LIMIT 1",
            wp_strip_all_tags($title)
        ));
        return !empty($found);
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
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) { $status = 'draft'; }
        $model  = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $extra  = isset($_POST['extra']) ? sanitize_textarea_field(wp_unslash($_POST['extra'])) : '';
        $extra  = mb_substr($extra, 0, 2000);

        // Anti-dubluri: sărim titlurile care sunt deja în coadă SAU au deja un
        // articol pe site (publicat/ciornă/programat) — lista poate fi lipită de
        // două ori sau poate conține subiecte deja scrise.
        $q = $this->queue();
        $known = array();
        foreach ($q as $item) {
            $known[$this->norm_title($item['t'])] = 1;
        }
        $added   = 0;
        $skipped = 0;
        foreach ($titles as $t) {
            $key = $this->norm_title($t);
            if (isset($known[$key]) || $this->post_title_exists($t)) {
                $skipped++;
                continue;
            }
            $known[$key] = 1;
            $q[] = array(
                't'       => $t,
                's'       => 'pending',
                'cat'     => $cat,
                'st'      => $status,
                'model'   => $model,
                'extra'   => $extra,
                'post_id' => 0,
                'err'     => '',
                'note'    => '',
                'cost'    => 0.0,
            );
            $added++;
        }
        $this->save_queue($q);
        if ($added > 0) { $this->kick(); }

        $msg = sprintf('%d articole adăugate în coadă.', $added);
        if ($skipped > 0) {
            $msg .= sprintf(' %d sărite (deja în coadă sau articol existent cu același titlu).', $skipped);
        }
        wp_send_json_success(array('message' => $msg));
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
        // Plasă de siguranță: dacă există lucru de făcut dar cronul a adormit
        // (site fără trafic, event pierdut), îl repornim din poll-ul paginii.
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

        $images = max(1, min(3, (int) get_option('hzb_images', 2)));
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

            $img = $this->fetch_images((string) $item['t'], (string) $item['model'], $images);
            if (empty($img['ids'])) {
                $note = $img['note'];
                // Cotă terminată în timpul lotului: ne oprim, restul rămân pentru mâine.
                if (stripos($note, 'epuizat') !== false || stripos($note, 'Limita') !== false) {
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
                    $content = $this->insert_images($post->post_content, array_slice($img['ids'], 1), (string) $item['t']);
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
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);
        if (function_exists('set_time_limit')) { @set_time_limit(600); }

        $q   = $this->queue();
        $idx = null;
        foreach ($q as $i => $item) {
            // Un item rămas „working" = rularea anterioară a murit în mijloc; îl reluăm.
            if ($item['s'] === 'pending' || $item['s'] === 'working') { $idx = $i; break; }
        }
        if ($idx === null) {
            delete_transient(self::LOCK);
            return;
        }

        // Vrem imagini la fiecare articol: dacă limita zilnică Magnific e atinsă,
        // punem coada pe pauză până mâine în loc să publicăm articole fără poze.
        $images = max(0, min(3, (int) get_option('hzb_images', 2)));
        if ($images > 0 && HZ_Magnific::has_key() && HZ_Magnific::limit_reached()) {
            // Cota Magnific se resetează la 00:00 UTC — reluăm exact atunci, nu la
            // miezul nopții local (în România ar însemna 2-3 ore de așteptare inutilă
            // sau, invers, o repornire prea devreme care lovește iar refuzul).
            $t = HZ_Magnific::next_reset_ts();
            update_option('hzb_paused_reason', sprintf(
                'Cota zilnică de descărcări Magnific e atinsă — coada continuă singură la %s (resetul e la 00:00 UTC).',
                wp_date('d.m.Y H:i', $t)
            ), 'no');
            delete_transient(self::LOCK);
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event($t, self::HOOK);
            }
            return;
        }
        delete_option('hzb_paused_reason');

        $q[$idx]['s'] = 'working';
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
        $brief = $title;
        if (trim((string) $item['extra']) !== '') {
            $brief .= "\n\nInstrucțiuni suplimentare pentru acest lot de articole:\n" . trim((string) $item['extra']);
        }
        $tpl = get_option('cw_article_prompt', CW_Admin::default_article_prompt());
        $prompt = str_replace(
            array('{{continut}}', '{{cuvinte}}'),
            array($brief, (string) max(300, (int) get_option('hzb_length', 1000))),
            $tpl
        );

        $result = CW_API::generate($model, $prompt);
        if (is_wp_error($result)) {
            $item['s']   = 'error';
            $item['err'] = $result->get_error_message();
            return $item;
        }
        $cost += (float) $result['cost'];

        $content = $this->clean_html($result['text']);
        if (strlen($content) < 500) {
            $item['s']   = 'error';
            $item['err'] = 'Conținut generat prea scurt (' . strlen($content) . ' caractere).';
            $item['cost'] = $cost;
            return $item;
        }

        // 2) Imaginile: interogare de căutare în engleză (stocul e indexat în
        // engleză), generată de model din titlu — apel minuscul, cost neglijabil.
        $images   = max(0, min(3, (int) get_option('hzb_images', 2)));
        $img      = $this->fetch_images($title, $model, $images);
        $att_ids  = $img['ids'];
        $img_note = $img['note'];
        $cost    += $img['cost'];

        // Imaginile suplimentare (după cover) intră în corp, distribuite după H2-uri.
        if (count($att_ids) > 1) {
            $content = $this->insert_images($content, array_slice($att_ids, 1), $title);
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
        $rand = $this->random_publish_date();
        if ($rand) {
            $postarr['post_date']     = $rand['local'];
            $postarr['post_date_gmt'] = $rand['gmt'];
        }
        $post_id = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($post_id)) {
            $item['s']    = 'error';
            $item['err']  = $post_id->get_error_message();
            $item['cost'] = $cost;
            return $item;
        }
        update_post_meta($post_id, '_hzb_bulk', current_time('Y-m-d'));

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
        return $item;
    }

    /**
     * Caută pe Magnific și descarcă în Media până la $limit imagini pentru un titlu.
     * Interogarea se traduce în engleză cu AI (stocul e indexat în engleză) — apel
     * minuscul. Returnează array('ids', 'note', 'cost'); folosit și la generare, și
     * la completarea ulterioară a articolelor rămase fără poză.
     */
    private function fetch_images($title, $model, $limit) {
        $out = array('ids' => array(), 'note' => '', 'cost' => 0.0);
        if ($limit < 1) { return $out; }
        if (!HZ_Magnific::has_key()) {
            $out['note'] = 'cheia API Magnific nu e configurată în setările de mai jos';
            return $out;
        }

        $qr = CW_API::generate($model, 'Translate this Romanian article title into a short English stock photo search query (3-5 generic, visual keywords). Reply with ONLY the keywords, nothing else: ' . $title, 100);
        $query = is_wp_error($qr) ? '' : trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $qr['text']));
        if (!is_wp_error($qr)) { $out['cost'] += (float) $qr['cost']; }
        if ($query === '') { $query = $title; }

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
                if (in_array($att->get_error_code(), array('hzb_limit', 'hzb_credits'), true)) { break; }
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

    /** Inserează imaginile în corp: după al 2-lea, apoi al 4-lea subtitlu H2. */
    private function insert_images($content, $att_ids, $alt) {
        $parts = preg_split('/(<\/h2>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 3) {
            // Sub 1 H2 complet: punem imaginile la mijlocul textului, între paragrafe.
            foreach ($att_ids as $att) {
                $content .= "\n" . $this->figure_html($att, $alt);
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
                    $out .= "\n" . $this->figure_html($att_ids[$img], $alt) . "\n";
                    $img++;
                }
            }
        }
        // Ce n-a încăput (prea puține H2-uri) intră la final, înaintea ultimului paragraf.
        while ($img < count($att_ids)) {
            $out .= "\n" . $this->figure_html($att_ids[$img], $alt);
            $img++;
        }
        return $out;
    }

    private function figure_html($att_id, $alt) {
        $img = wp_get_attachment_image($att_id, 'large', false, array('alt' => $alt, 'loading' => 'lazy'));
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
    private function random_publish_date() {
        if (!(int) get_option('hzb_random_date', 1)) { return null; }
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
