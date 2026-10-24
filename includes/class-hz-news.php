<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Știri din RSS (v3.50.0): preia zilnic știri de pe digi24.ro pe secțiunile
 * Actualitate, Politică, Economie și Externe, le RESCRIE complet cu AI (implicit
 * gpt-5.4-mini, pe cheia OpenAI a site-ului) și le publică pe categoria potrivită.
 *
 * Regulile cerute de user (24.10.2026):
 * - textul se scrie de la zero (alt titlu, altă structură), fără „Sursa" la final
 *   și fără niciun link; dacă originalul atribuie informația chiar lui Digi24,
 *   formularea devine „conform digi24.ro"; v3.50.3: primul paragraf se încheie cu
 *   „conform digi24.ro", link nofollow către știrea originală (cerința userului);
 * - pozele digi24 NU se preiau (sunt licențiate agențiilor: Profimedia, Inquam...).
 *   Coverul vine din Wikimedia Commons sau Openverse (licențe libere, cu creditul
 *   autorului doar ca legendă în Media, nu în text — deci doar CC0 / domeniu public;
 *   și doar dacă subiectul e o persoană/un loc anume), altfel e o imagine
 *   AI generată de la zero din subiect;
 * - pornit implicit DOAR pe comunicateinpresa.ro și revistabravo.ro (vezi
 *   default_hosts()); pe restul flotei modulul există, dar e oprit.
 *
 * Ritmul: cronul de 5 minute verifică, pe fiecare secțiune, câte știri ar fi trebuit
 * publicate până acum (N pe zi, întinse uniform între ora de start și cea de final)
 * și scrie CEL MULT una pe tick — așa nu ies 20 de articole în același minut, iar
 * un request de cron nu ține mai mult de 2-3 minute.
 */
class HZ_News {

    const HOOK  = 'hz_news_tick';
    const LOCK  = 'hz_news_lock';
    const SEEN  = 'hzn_seen';          // url_hash => [ts, motiv] (fără autoload)
    const TODAY = 'hzn_today';         // ['d' => Y-m-d, 'c' => [secțiune => n]]

    const META_SRC     = '_hzn_source';
    const META_SECTION = '_hzn_section';

    const DEFAULT_MODEL = 'gpt-5.4-mini';
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** Cât ținem minte un URL deja văzut (preluat sau respins). */
    const SEEN_DAYS = 10;

    /**
     * Secțiunile digi24. `match` se aplică pe calea URL-ului articolului; Politică e o
     * subsecțiune a Actualității, deci Actualitatea o exclude explicit.
     */
    public static function sections() {
        return array(
            'actualitate' => array(
                'label' => 'Actualitate',
                'list'  => 'https://www.digi24.ro/stiri/actualitate',
                'match' => '#^/stiri/actualitate/(?!politica/)#',
            ),
            'politica' => array(
                'label' => 'Politică',
                'list'  => 'https://www.digi24.ro/stiri/actualitate/politica',
                'match' => '#^/stiri/actualitate/politica/#',
            ),
            'economie' => array(
                'label' => 'Economie',
                'list'  => 'https://www.digi24.ro/stiri/economie',
                'match' => '#^/stiri/economie/#',
            ),
            'externe' => array(
                'label' => 'Externe',
                'list'  => 'https://www.digi24.ro/stiri/externe',
                'match' => '#^/stiri/externe/#',
            ),
        );
    }

    /** Site-urile pe care modulul pornește singur la update (restul flotei: oprit). */
    public static function default_hosts() {
        return array('comunicateinpresa.ro', 'revistabravo.ro');
    }

    public static function defaults() {
        $d = array(
            'hzn_enabled'    => 0,
            'hzn_per_cat'    => 5,
            'hzn_start_hour' => 7,
            'hzn_end_hour'   => 22,
            'hzn_status'     => 'publish',
            'hzn_model'      => self::DEFAULT_MODEL,
            'hzn_author'     => 0,
            'hzn_images'     => 1,
            'hzn_free_photos'=> 0,
            'hzn_source_photo'=> 0,
            'hzn_nsi_banner' => 1,
            'hzn_max_age'    => 24,
            'hzn_rss_url'    => 'https://www.digi24.ro/rss',
        );
        foreach (array_keys(self::sections()) as $key) {
            $d['hzn_on_' . $key]  = 1;
            $d['hzn_cat_' . $key] = 0; // 0 = categoria cu numele secțiunii (creată la nevoie)
        }
        return $d;
    }

    public function init() {
        add_action(self::HOOK, array($this, 'tick'));
        add_action('init', array($this, 'maybe_schedule'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_hzn_test', array($this, 'ajax_test'));
        add_action('update_option_hzn_enabled', array($this, 'reschedule'), 10, 0);
        // Setări schimbate (aici sau serverul / cheia din AI Writer) = eroarea veche nu mai e relevantă.
        foreach (array('hzn_model', 'hzn_enabled', 'cw_openai_base', 'cw_openai_api_key', 'cw_default_model') as $opt) {
            add_action('update_option_' . $opt, function () { update_option('hzn_last_error', '', 'no'); }, 10, 0);
        }
    }

    /* ---------------- programare ---------------- */

    public function maybe_schedule() {
        if (!(int) get_option('hzn_enabled', 0)) {
            if (wp_next_scheduled(self::HOOK)) { wp_clear_scheduled_hook(self::HOOK); }
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            // `hz_5min` îl înregistrează HZ_Bulk (cron_schedules), pe același init.
            wp_schedule_event(time() + 60, 'hz_5min', self::HOOK);
        }
    }

    public function reschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Pornit pe site-urile din default_hosts(), o singură dată (alegerea userului rămâne). */
    public static function seed() {
        foreach (self::defaults() as $name => $value) {
            add_option($name, $value, '', 'no');
        }
        if (get_option('hzn_seeded')) { return; }
        $host = strtolower(preg_replace('#^www\.#i', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        if (in_array($host, self::default_hosts(), true)) {
            update_option('hzn_enabled', 1, 'no');
        }
        update_option('hzn_seeded', 1, 'no');
    }

    /* ---------------- rulare ---------------- */

    public function tick() {
        if (!(int) get_option('hzn_enabled', 0)) { return; }
        $section = $this->due_section();
        if ($section === '') { return; }
        $this->run_one($section, false);
    }

    /**
     * Secțiunea cu cea mai mare restanță față de ritmul zilei ('' = nimic de făcut).
     * Ritmul: ținta de acum = N * (cât a trecut din fereastră), rotunjită în sus,
     * deci prima știre a fiecărei secțiuni iese imediat după ora de start.
     */
    private function due_section() {
        $per   = max(1, min(30, (int) get_option('hzn_per_cat', 5)));
        $start = max(0, min(23, (int) get_option('hzn_start_hour', 7)));
        $end   = max($start + 1, min(24, (int) get_option('hzn_end_hour', 22)));

        $now  = new DateTimeImmutable('now', wp_timezone());
        $hour = (int) $now->format('G') + ((int) $now->format('i')) / 60;
        if ($hour < $start || $hour >= $end) { return ''; }

        $target = (int) min($per, floor($per * ($hour - $start) / ($end - $start)) + 1);
        $done   = $this->today_counts();

        $best = '';
        $gap  = 0;
        foreach (array_keys(self::sections()) as $key) {
            if (!(int) get_option('hzn_on_' . $key, 1)) { continue; }
            if (get_transient('hzn_empty_' . $key)) { continue; } // n-a avut știri noi de curând
            $miss = $target - (isset($done[$key]) ? (int) $done[$key] : 0);
            if ($miss > $gap) { $gap = $miss; $best = $key; }
        }
        return $best;
    }

    private function today_counts() {
        $t = get_option(self::TODAY, array());
        if (!is_array($t) || !isset($t['d']) || $t['d'] !== current_time('Y-m-d')) { return array(); }
        return isset($t['c']) && is_array($t['c']) ? $t['c'] : array();
    }

    private function count_today($section) {
        $c = $this->today_counts();
        $c[$section] = (isset($c[$section]) ? (int) $c[$section] : 0) + 1;
        update_option(self::TODAY, array('d' => current_time('Y-m-d'), 'c' => $c), 'no');
    }

    /**
     * Preia, rescrie și publică O știre din secțiune. $test = ciornă, fără să
     * intre în contorul zilei.
     */
    public function run_one($section, $test = false) {
        $sections = self::sections();
        if (!isset($sections[$section])) { return new WP_Error('hzn_section', 'Secțiune necunoscută.'); }
        if (!class_exists('CW_API') || !CW_API::has_key()) {
            return $this->fail('Nicio cheie AI configurată în Setări → AI Writer.');
        }
        if (get_transient(self::LOCK)) { return new WP_Error('hzn_lock', 'O preluare e deja în curs.'); }
        set_transient(self::LOCK, 1, 10 * MINUTE_IN_SECONDS);
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        try {
            $result = $this->process($section, $test);
        } finally {
            delete_transient(self::LOCK);
        }

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'hzn_none') {
                // Nimic nou pe secțiune: o lăsăm în pace 30 de minute, fără să fie „eroare".
                set_transient('hzn_empty_' . $section, 1, 30 * MINUTE_IN_SECONDS);
                return $result;
            }
            return $this->fail($sections[$section]['label'] . ': ' . $result->get_error_message());
        }
        if (!$test) { $this->count_today($section); }
        update_option('hzn_last_run', current_time('Y-m-d H:i') . ' — ' . $sections[$section]['label'] . ': ' . $result['title'], 'no');
        update_option('hzn_last_error', '', 'no');
        return $result;
    }

    private function fail($message) {
        update_option('hzn_last_error', current_time('Y-m-d H:i') . ' — ' . $message, 'no');
        return new WP_Error('hzn_fail', $message);
    }

    private function process($section, $test) {
        // Câteva încercări: un articol video / live / prea scurt se sare și trecem la următorul.
        $tries = 0;
        $fetched = 0;
        // v3.51.9: cu categoria pe „automat", știrile care nu se potrivesc cu nicio
        // categorie existentă a site-ului se sar (cerința userului) — decizia vine dintr-un
        // apel scurt ÎNAINTE de rescriere, ca să nu plătim rescrieri aruncate.
        $auto = (int) get_option('hzn_cat_' . $section, 0) === 0 && self::site_categories();
        foreach ($this->candidates($section) as $url) {
            if ($this->seen($url)) { continue; }
            if ($tries >= 4 || ++$fetched > 12) { break; }

            $art = $this->fetch_article($url);
            if (is_wp_error($art)) {
                $this->mark_seen($url, $art->get_error_code());
                continue;
            }
            if ($this->post_exists($url, $art['title'])) {
                $this->mark_seen($url, 'exista');
                continue;
            }

            $cat = '';
            if ($auto) {
                $cat = $this->classify($art);
                if (is_wp_error($cat)) {
                    if (in_array($cat->get_error_code(), array('cw_no_key', 'cw_limit', 'cw_api', 'cw_http'), true)) { return $cat; }
                    $cat = '';
                }
                if ($cat === '') {
                    $this->mark_seen($url, 'fara_categorie');
                    continue;
                }
            }

            $tries++;
            $rw = $this->rewrite($art, $cat);
            if (is_wp_error($rw)) {
                // Eroare de API (cheie, cotă, rețea): NU marcăm articolul, îl reluăm data viitoare.
                if (in_array($rw->get_error_code(), array('cw_no_key', 'cw_limit', 'cw_api', 'cw_http'), true)) {
                    return $rw;
                }
                $this->mark_seen($url, $rw->get_error_code());
                continue;
            }

            $this->mark_seen($url, 'preluat');
            return $this->publish($section, $url, $art, $rw, $test);
        }
        return new WP_Error('hzn_none', 'Nicio știre nouă de preluat.');
    }

    /* ---------------- sursa ---------------- */

    /** URL-urile de articol din secțiune, cele noi primele (pagina secțiunii + RSS). */
    private function candidates($section) {
        $sec  = self::sections()[$section];
        $urls = array();
        foreach (array($sec['list'], (string) get_option('hzn_rss_url', 'https://www.digi24.ro/rss')) as $src) {
            if ($src === '') { continue; }
            foreach ($this->links_from($src) as $u) {
                $path = (string) wp_parse_url($u, PHP_URL_PATH);
                if (preg_match($sec['match'], $path)) { $urls[$u] = true; }
            }
        }
        return array_keys($urls);
    }

    /** Linkurile de articol dintr-o pagină sau un feed, cache 15 minute. */
    private function links_from($src) {
        $key    = 'hzn_links_' . md5($src);
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }

        $body = $this->http_get($src);
        $out  = array();
        if ($body !== '') {
            // href="…" din pagini + <link>…</link> / <guid>…</guid> din RSS.
            preg_match_all('#(?:href=["\']|<link>|<guid[^>]*>)\s*(?:<!\[CDATA\[)?\s*([^"\'<\s]+)#i', $body, $m);
            foreach ($m[1] as $raw) {
                $u = $this->article_url(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
                if ($u !== '') { $out[$u] = true; }
            }
        }
        $out = array_keys($out);
        set_transient($key, $out, $body !== '' ? 15 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        return $out;
    }

    /** URL canonic de articol digi24 sau '' (listări, taguri, video, live). */
    private function article_url($u) {
        $u = trim($u);
        if (strpos($u, '//') === 0) { $u = 'https:' . $u; }
        if (strpos($u, '/') === 0) { $u = 'https://www.digi24.ro' . $u; }
        $p = wp_parse_url($u);
        if (empty($p['host']) || !preg_match('#(^|\.)digi24\.ro$#i', $p['host']) || empty($p['path'])) { return ''; }
        $path = rtrim($p['path'], '/');
        // /stiri/{secțiune}/…/{slug-lung}: slug-ul are cel puțin 4 cuvinte.
        if (!preg_match('#^/stiri/[a-z0-9-]+(/[a-z0-9-]+)*/([a-z0-9]+-){3,}[a-z0-9]+$#', $path)) { return ''; }
        if (preg_match('#(^|/)(live|video|galerie|foto)(-|/|$)#', $path)) { return ''; }
        return 'https://www.digi24.ro' . $path;
    }

    private function http_get($url, $timeout = 25) {
        $r = wp_remote_get($url, array(
            'timeout'     => $timeout,
            'redirection' => 3,
            'user-agent'  => self::UA,
            'headers'     => array('Accept-Language' => 'ro-RO,ro;q=0.9'),
        ));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return ''; }
        return (string) wp_remote_retrieve_body($r);
    }

    /**
     * Titlul, data și textul articolului. Textul: `articleBody` din JSON-LD dacă
     * există, altfel paragrafele din containerul cu cel mai mult text (rezistă la
     * schimbările de clase CSS ale site-ului).
     */
    private function fetch_article($url) {
        $html = $this->http_get($url);
        if ($html === '') { return new WP_Error('hzn_fetch', 'Pagina nu s-a putut descărca.'); }

        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $title = $this->meta($xp, 'og:title');
        if ($title === '') {
            $h1 = $xp->query('//h1');
            $title = $h1->length ? trim($h1->item(0)->textContent) : '';
        }
        $title = trim(preg_replace('#\s*[|\-–]\s*Digi24\s*$#iu', '', $title));

        $published = $this->meta($xp, 'article:published_time');
        $body      = '';
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $j = json_decode(trim($s->textContent), true);
            if (!is_array($j)) { continue; }
            $nodes = isset($j['@graph']) && is_array($j['@graph']) ? $j['@graph'] : (isset($j[0]) ? $j : array($j));
            foreach ($nodes as $n) {
                if (!is_array($n)) { continue; }
                if ($published === '' && !empty($n['datePublished'])) { $published = (string) $n['datePublished']; }
                if ($body === '' && !empty($n['articleBody']) && is_string($n['articleBody'])) { $body = $n['articleBody']; }
            }
        }
        if ($published === '') {
            $t = $xp->query('//time[@datetime]');
            if ($t->length) { $published = (string) $t->item(0)->getAttribute('datetime'); }
        }

        $max_age = max(1, (int) get_option('hzn_max_age', 24));
        $ts = $published !== '' ? strtotime($published) : false;
        if ($ts && $ts < time() - $max_age * HOUR_IN_SECONDS) {
            return new WP_Error('hzn_old', 'Știre mai veche de ' . $max_age . ' ore.');
        }

        $paras = $body !== '' ? $this->split_text($body) : $this->main_paragraphs($xp);
        $paras = array_values(array_filter($paras, array($this, 'keep_paragraph')));
        $text  = implode("\n\n", $paras);

        if ($title === '' || mb_strlen($text) < 600) {
            return new WP_Error('hzn_short', 'Text prea scurt (probabil video sau live).');
        }
        if (mb_strlen($text) > 9000) { $text = mb_substr($text, 0, 9000); }

        // Poza originală: NU se descarcă și nu se publică. Adresa ei merge doar la modelul
        // de rescriere, ca să DESCRIE subiectul pentru colajul AI (v3.50.5).
        $image = $this->meta($xp, 'og:image');
        if (!preg_match('#^https://#i', $image)) { $image = ''; }

        return array('url' => $url, 'title' => $title, 'text' => $text, 'image' => $image,
                     'image_credit' => $this->photo_credit($xp, $html), 'published' => $ts ? (int) $ts : 0);
    }

    /**
     * Creditul pozei principale, exact cum îl scrie sursa („Foto: Profimedia"), sau ''.
     * Întâi legenda (<figcaption>), apoi primul „Foto: …" din pagină.
     */
    private function photo_credit(DOMXPath $xp, $html) {
        $re = '/\b(?:sursa\s+)?foto(?:grafie)?\s*:\s*([^<>"\n|]{2,80})/iu';
        foreach ($xp->query('//figcaption') as $fc) {
            if (preg_match($re, preg_replace('/\s+/u', ' ', $fc->textContent), $m)) { return 'Foto: ' . trim($m[1], " \t.,;"); }
        }
        if (preg_match($re, wp_strip_all_tags($html), $m)) { return 'Foto: ' . trim($m[1], " \t.,;"); }
        return '';
    }

    private function meta(DOMXPath $xp, $prop) {
        $n = $xp->query('//meta[@property="' . $prop . '" or @name="' . $prop . '"]/@content');
        return $n->length ? trim(html_entity_decode($n->item(0)->nodeValue, ENT_QUOTES, 'UTF-8')) : '';
    }

    private function split_text($body) {
        $body = html_entity_decode(wp_strip_all_tags($body), ENT_QUOTES, 'UTF-8');
        return array_map('trim', preg_split('/\n\s*\n|\r\n\s*\r\n/', $body));
    }

    /** Paragrafele (p, h2, h3, li) din containerul cu cel mai mult text în <p>. */
    private function main_paragraphs(DOMXPath $xp) {
        $best = null;
        $best_len = 0;
        foreach ($xp->query('//p/..') as $parent) {
            $len = 0;
            foreach ($parent->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE && strtolower($c->nodeName) === 'p') {
                    $len += mb_strlen(trim($c->textContent));
                }
            }
            if ($len > $best_len) { $best_len = $len; $best = $parent; }
        }
        if (!$best) { return array(); }
        $out = array();
        foreach ($xp->query('.//p | .//h2 | .//h3 | .//li', $best) as $n) {
            $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
            if ($t !== '') { $out[] = $t; }
        }
        return $out;
    }

    /** Scoate rândurile care nu sunt știre: „Citește și", credite foto, abonări. */
    public function keep_paragraph($p) {
        if (mb_strlen($p) < 25) { return false; }
        return !preg_match('/^(citește și|citeste si|vezi și|vezi si|urmărește|urmareste|abonează|aboneaza|editor\s*:|foto\s*:|sursa\s*:|sursă\s*:|video\s*:|credit)/iu', $p);
    }

    /* ---------------- evidența ---------------- */

    private function seen($url) {
        $s = get_option(self::SEEN, array());
        return is_array($s) && isset($s[md5($url)]);
    }

    private function mark_seen($url, $why) {
        $s = get_option(self::SEEN, array());
        if (!is_array($s)) { $s = array(); }
        $cut = time() - self::SEEN_DAYS * DAY_IN_SECONDS;
        foreach ($s as $k => $v) {
            if (!is_array($v) || (int) $v[0] < $cut) { unset($s[$k]); }
        }
        $s[md5($url)] = array(time(), (string) $why);
        update_option(self::SEEN, $s, 'no');
    }

    /** Aceeași știre deja pe site (după sursă sau după titlul original/rescris). */
    private function post_exists($url, $title) {
        global $wpdb;
        $by_src = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_SRC, $url
        ));
        if ($by_src) { return true; }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','future','pending') AND post_title = %s LIMIT 1",
            $title
        ));
    }

    /* ---------------- rescrierea ---------------- */

    /** Modelul cu care se scriu știrile acum (setarea, corectată pentru serverul API). */
    public static function effective_model() {
        $model = (string) get_option('hzn_model', self::DEFAULT_MODEL);
        if (!CW_Models::exists($model)) { $model = self::DEFAULT_MODEL; }
        // v3.51.4: cu server API (ex. creator.ms) modelul trebuie să existe PE server;
        // altfel cădem pe modelul implicit al site-ului (sau primul de pe server).
        $gw = CW_Models::gateway();
        if ($gw && CW_Models::provider($model) === 'openai' && !isset($gw[$model])) {
            $def   = CW_Models::default_model();
            $model = isset($gw[$def]) ? $def : (string) key($gw);
        }
        $model = CW_API::resolve_model($model);
        return $model;
    }

    /**
     * Categoria existentă a site-ului în care se potrivește știrea, sau '' dacă nu se
     * potrivește clar în niciuna. Apel scurt: titlu + începutul textului.
     */
    private function classify($art) {
        $cats = self::site_categories();
        if (!$cats) { return ''; }
        $model  = self::effective_model();
        $prompt = "Știre:\nTITLU: " . $art['title'] . "\nÎNCEPUT: " . mb_substr($art['text'], 0, 700)
                . "\n\nCategoriile site-ului: " . implode(' | ', $cats)
                . "\n\nÎn ce categorie a site-ului se potrivește CLAR această știre? Răspunde DOAR cu numele categoriei, scris exact ca în listă, "
                . "sau cu NICIUNA dacă știrea nu ține de niciuna (nu forța o potrivire cu o categorie generală).";
        $system   = 'Ești editor și clasifici știri pe rubricile unui site. Răspunzi cu un singur nume de categorie sau NICIUNA, fără alt text.';
        $override = function () use ($system) { return $system; };
        add_filter('cw_system_prompt', $override);
        try {
            $r = CW_Usage::with_context('stiri', function () use ($model, $prompt) {
                return CW_API::no_web(function () use ($model, $prompt) {
                    return CW_API::generate($model, $prompt, 2000);
                });
            });
        } finally {
            remove_filter('cw_system_prompt', $override);
        }
        if (is_wp_error($r)) { return $r; }
        $ans = mb_strtolower(remove_accents(trim(wp_strip_all_tags((string) $r['text']), " \t\n\r\"'„”.«»")));
        foreach ($cats as $c) {
            if (mb_strtolower(remove_accents($c)) === $ans) { return $c; }
        }
        return '';
    }

    private function rewrite($art, $cat = '') {
        $model = self::effective_model();

        $system   = self::system_prompt();
        $override = function () use ($system) { return $system; };
        $prompt   = "TITLUL ORIGINAL: " . $art['title'] . "\n\nTEXTUL ORIGINAL:\n" . $art['text'];
        $cats     = $cat === '' ? self::site_categories() : array();
        if ($cats) {
            $prompt .= "\n\nCATEGORIILE SITE-ULUI (pentru „categorie” alegi UNA, scrisă exact ca aici): " . implode(' | ', $cats);
        }

        // Cu poza originală atașată (doar OpenAI acceptă aici imagine prin URL): modelul o
        // vede ca REFERINȚĂ DE SUBIECT pentru descrierea colajului. Dacă OpenAI nu poate
        // citi poza (CDN blocat, format), reluăm o singură dată fără ea.
        $attempts = array($prompt);
        if (!empty($art['image']) && CW_Models::provider($model) === 'openai') {
            array_unshift($attempts, array(array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => $prompt . "\n\nPOZA ORIGINALĂ: atașată mai jos, DOAR ca referință de subiect pentru „imagine_en”."),
                array('type' => 'image_url', 'image_url' => array('url' => $art['image'], 'detail' => 'low')),
            ))));
        }

        add_filter('cw_system_prompt', $override);
        try {
            foreach ($attempts as $input) {
                $result = CW_Usage::with_context('stiri', function () use ($model, $input) {
                    return CW_API::no_web(function () use ($model, $input) {
                        return CW_API::generate($model, $input, 6000);
                    });
                });
                if (!is_wp_error($result) || in_array($result->get_error_code(), array('cw_no_key', 'cw_limit'), true)) { break; }
            }
        } finally {
            remove_filter('cw_system_prompt', $override);
        }
        if (is_wp_error($result)) { return $result; }

        $raw = trim((string) $result['text']);
        $raw = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw);
        $j   = json_decode($raw, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $raw, $m)) { $j = json_decode($m[0], true); }
        if (!is_array($j) || empty($j['titlu']) || empty($j['continut'])) {
            return new WP_Error('hzn_json', 'Răspunsul AI nu a avut formatul cerut.');
        }

        $title   = trim(wp_strip_all_tags((string) $j['titlu']));
        $content = $this->clean_content((string) $j['continut']);

        $bad = $this->reject_reason($art, $title, $content);
        if ($bad !== '') { return new WP_Error('hzn_reject', $bad); }
        $content = $this->source_link($content, $art['url']);
        // Lead-ul (primul paragraf) cu bold, ca la site-urile de știri.
        $content = preg_replace('#<p>(.*?)</p>#su', '<p><strong>$1</strong></p>', $content, 1);

        return array(
            'title'     => $title,
            'content'   => $content,
            'entity'    => isset($j['imagine_subiect']) ? trim(wp_strip_all_tags((string) $j['imagine_subiect'])) : '',
            'img_en'    => isset($j['imagine_en']) ? trim(wp_strip_all_tags((string) $j['imagine_en'])) : '',
            'excerpt'   => isset($j['rezumat']) ? trim(wp_strip_all_tags((string) $j['rezumat'])) : '',
            'category'  => $cat !== '' ? $cat : (isset($j['categorie']) ? trim(wp_strip_all_tags((string) $j['categorie'])) : ''),
            'model'     => $model,
            'cost'      => isset($result['cost']) ? (float) $result['cost'] : 0.0,
        );
    }

    /** HTML permis: paragrafe, subtitluri, liste, accente. FĂRĂ linkuri, imagini, scripturi. */
    private function clean_content($html) {
        $html = preg_replace('/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', trim($html));
        $html = wp_kses($html, array(
            'p' => array(), 'h2' => array(), 'h3' => array(), 'ul' => array(), 'ol' => array(),
            'li' => array(), 'strong' => array(), 'em' => array(), 'blockquote' => array(),
        ));
        // Linii lungi în textul livrat (regula flotei): doar cratima simplă.
        $html = str_replace(array(' — ', ' – ', '—', '–'), array(' - ', ' - ', '-', '-'), $html);
        // Paragraful de „Sursa" la final nu se pune (cerința userului), chiar dacă îl scrie modelul.
        $html = preg_replace('#<p>\s*(?:<(?:em|strong)>)?\s*surs[aă]\s*:.*?</p>\s*$#isu', '', $html);
        return trim($html);
    }

    /**
     * v3.50.3: atribuirea din primul paragraf („conform digi24.ro") devine link nofollow
     * către știrea originală, ca la Gândul / Observator. Dacă modelul a uitat-o, o
     * adăugăm noi la finalul primului paragraf.
     */
    private function source_link($content, $url) {
        $a = '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow noopener">digi24.ro</a>';
        $n = 0;
        $content = preg_replace('/\bdigi24\.ro\b/iu', $a, $content, 1, $n);
        if ($n > 0) { return $content; }
        return preg_replace('#\.?\s*</p>#u', ', conform ' . $a . '.</p>', $content, 1);
    }

    /** Motivul pentru care rescrierea nu se publică ('' = e bună). */
    private function reject_reason($art, $title, $content) {
        if (mb_strlen(wp_strip_all_tags($content)) < 700) { return 'Text rescris prea scurt.'; }
        if (strpos($content, '<p>') === false) { return 'Text rescris fără paragrafe.'; }
        if (mb_strtolower($title) === mb_strtolower($art['title'])) { return 'Titlul a rămas cel original.'; }
        if (preg_match('#https?://|www\.#i', $content)) { return 'Textul rescris conține adrese web.'; }
        // Rescriere reală, nu reformulare frază cu frază: cel mult 12% din secvențele de
        // 6 cuvinte ale textului nou pot exista identic în original.
        if ($this->overlap($art['text'], wp_strip_all_tags($content)) > 0.12) {
            return 'Textul rescris e prea apropiat de original.';
        }
        return '';
    }

    private function overlap($a, $b) {
        $sh = function ($t) {
            $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(remove_accents($t)), -1, PREG_SPLIT_NO_EMPTY);
            $out = array();
            for ($i = 0; $i + 6 <= count($w); $i++) { $out[implode(' ', array_slice($w, $i, 6))] = true; }
            return $out;
        };
        $sa = $sh($a);
        $sb = $sh($b);
        if (!$sb) { return 0.0; }
        return count(array_intersect_key($sb, $sa)) / count($sb);
    }

    public static function system_prompt() {
        return <<<'TXT'
Ești redactor de știri la o publicație online din România. Primești o știre publicată de altă redacție și scrii DESPRE ACELAȘI SUBIECT un articol complet nou, cu cuvintele tale.

REGULI DE CONȚINUT
- Păstrezi exact faptele: nume, funcții, cifre, date, locuri, ce s-a întâmplat. NU inventa nimic și nu adăuga informații care nu sunt în text.
- Declarațiile pot rămâne citate scurte, între ghilimele românești („…"), atribuite persoanei care le-a făcut.
- Scrii de la zero: alt titlu, alt început, altă ordine a ideilor, alte fraze. Nu reformula textul frază cu frază și nu copia propoziții din original.
- Stil jurnalistic de agenție: piramida inversată (esențialul primul), fraze scurte și clare, verbe active, fără opinii și fără adjective inutile.
- Primul paragraf (lead-ul, 1-2 fraze) răspunde la cine, ce, când, unde și se încheie firesc cu atribuirea „conform digi24.ro" (ex. „…, conform digi24.ro."), scrisă EXACT așa, o singură dată în tot articolul. Linkul îl adaugă sistemul.
- Nu pui sursa la final, nu pui linkuri, nu pui adrese web, nu pui credite foto.

STIL
- Română corectă, cu diacritice (ă, â, î, ș, ț), ton neutru, informativ, de știre.
- 300-500 de cuvinte. Primul paragraf răspunde la ce, cine, când, unde.
- Poți folosi 1-3 subtitluri <h2> dacă știrea e lungă. Fără emoji, fără liniuță lungă (—) sau medie (–), doar cratima simplă (-).
- Titlu de știre clar, 60-100 de caractere, fără clickbait, fără ghilimele în jurul lui.

IEȘIRE: DOAR un obiect JSON valid, fără markdown, cu cheile:
{
  "titlu": "titlul nou",
  "continut": "HTML cu <p>, <h2>, <ul>, <li>, <strong> (fără <h1>, fără linkuri)",
  "rezumat": "o propoziție de maximum 160 de caractere pentru descriere",
  "categorie": "categoria site-ului cea mai potrivită știrii, aleasă din lista primită (gol dacă nu ai primit listă)",
  "imagine_subiect": "DOAR numele complet (prenume + nume) al persoanei centrale a știrii, sau numele unei clădiri / unui loc celebru (ex. «Nicușor Dan», «Palatul Parlamentului»); GOL pentru instituții, sigle, firme, acronime (ANRE, ANAF, BNR) sau subiecte generale",
  "imagine_en": "în engleză, compoziția unei imagini de tip COLAJ pentru știre, construită din CONȚINUTUL ei (nu doar din titlu): 1 personaj sau obiect principal în prim-plan + un fundal real din România + un simbol al subiectului (ex. «a Romanian traffic police officer in a fluorescent yellow POLITIA jacket writing a fine, in front of Bucharest communist-era apartment blocks with closed balconies, Romanian flag on a pole»). Dacă știrea e despre persoane reale numite (miniștri, politicieni, vedete), NU pune niciun om în prim-plan: folosești locuri, clădiri, steaguri, obiecte (ex. «Black Sea coast with cargo ships, Romanian and Turkish flags, NATO flag») — o față inventată ar fi luată drept persoana reală. Oameni doar generici (polițist, medic, pensionar), din spate sau în plan îndepărtat. Dacă primești poza originală, o folosești DOAR ca să înțelegi subiectul (locul, obiectele, tipul de persoane): descrii o scenă NOUĂ, cu altă încadrare, nu copia fotografiei."
}
TXT;
    }

    /* ---------------- publicarea ---------------- */

    private function publish($section, $url, $art, $rw, $test) {
        $status = $test ? 'draft' : (string) get_option('hzn_status', 'publish');
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) { $status = 'publish'; }

        $post_id = wp_insert_post(wp_slash(array(
            'post_title'    => $rw['title'],
            'post_content'  => $rw['content'],
            'post_excerpt'  => $rw['excerpt'],
            'post_status'   => $status,
            'post_author'   => $this->author_id(),
            'post_category' => array($this->category_id($section, isset($rw['category']) ? $rw['category'] : '')),
        )), true);
        if (is_wp_error($post_id)) { return $post_id; }

        update_post_meta($post_id, self::META_SRC, $url);
        update_post_meta($post_id, self::META_SECTION, $section);
        update_post_meta($post_id, '_hzn_model', $rw['model']);

        $img_note = 'fără imagine (bifa „Imagine cover" e oprită)';
        // v3.51.7: bifa „poza originală" pornește singură coverul, chiar dacă bifa
        // generală „Imagine cover" e debifată (altfel ieșea „fără imagine" fără motiv).
        if ((int) get_option('hzn_images', 1) || (int) get_option('hzn_source_photo', 0)) {
            $img = $this->cover($post_id, $rw, $art);
            if (is_wp_error($img)) {
                $img_note = $img->get_error_message();
            } else {
                set_post_thumbnail($post_id, $img['id']);
                $img_note = $img['source'];
                // v3.50.2: fără credit în text pentru pozele libere. v3.50.7: poza sursei
                // păstrează creditul ei („Foto: Profimedia"), doar dacă sursa îl afișează.
                if ($img['source'] === 'sursa' && $img['credit'] !== '') {
                    $credit = '<p class="hz-photo-credit"><em>' . esc_html($img['credit']) . '</em></p>';
                    wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash(get_post_field('post_content', $post_id) . "\n" . $credit)));
                }
            }
        }
        // v3.52.0: bannerul Next Stage Image (același lucru ca butonul „Generează imagine"
        // din editor): coverul pus în șablonul site-ului, cu titlul, salvat ca imagine socială.
        if ($img_note !== '' && has_post_thumbnail($post_id) && (int) get_option('hzn_nsi_banner', 1)) {
            $img_note .= ' · ' . $this->nsi_banner($post_id);
        }
        update_post_meta($post_id, '_hzn_image', $img_note);
        update_option('hzn_last_image', current_time('H:i') . ' — ' . $img_note, 'no');

        return array(
            'post_id'   => (int) $post_id,
            'title'     => $rw['title'],
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'cost'      => $rw['cost'],
            'image'     => $img_note,
        );
    }

    /**
     * Coverul: Wikimedia Commons → Openverse (doar pentru un subiect concret, licențe
     * libere, landscape) → imagine AI generată de la zero. Pozele digi24 nu se ating.
     * @return array|WP_Error ['id', 'credit', 'source']
     */
    private function cover($post_id, $rw, $art = array()) {
        // v3.50.7: poza originală a știrii, NEMODIFICATĂ, cu creditul sursei. Opțiune
        // oprită implicit; pornită de user pe răspunderea lui (drepturile pozelor de
        // agenție nu se transferă prin preluare).
        if ((int) get_option('hzn_source_photo', 0) && !empty($art['image'])) {
            $credit = isset($art['image_credit']) ? (string) $art['image_credit'] : '';
            $id = $this->sideload($art['image'], $post_id, $rw['title'], $credit);
            if (!is_wp_error($id)) { return array('id' => $id, 'credit' => $credit, 'source' => 'sursa'); }
            $src_err = 'poza sursei: ' . $id->get_error_message();
        } elseif ((int) get_option('hzn_source_photo', 0)) {
            $src_err = 'poza sursei: pagina nu are og:image';
        }
        // v3.50.6: căutarea după nume e OPRITĂ implicit — la „Cristian Popa" (BNR) a adus
        // un militar american cu același nume. Fiecare știre primește colajul AI.
        if ($rw['entity'] !== '' && (int) get_option('hzn_free_photos', 0)) {
            foreach (array('wikimedia', 'openverse') as $src) {
                $found = $src === 'wikimedia' ? $this->find_wikimedia($rw['entity']) : $this->find_openverse($rw['entity']);
                if (!$found) { continue; }
                $id = $this->sideload($found['url'], $post_id, $rw['entity'], $found['credit']);
                if (!is_wp_error($id)) {
                    return array('id' => $id, 'credit' => $found['credit'], 'source' => $src);
                }
            }
        }
        if (class_exists('HZ_ImageGen') && HZ_ImageGen::has_key()) {
            $subject = $rw['img_en'] !== '' ? $rw['img_en'] : $rw['title'];
            $id = CW_Usage::with_context('imagini', function () use ($subject, $post_id, $rw) {
                return HZ_ImageGen::sideload($subject, $post_id, $rw['title'], '', self::collage_prompt($subject));
            });
            if (!is_wp_error($id)) { return array('id' => (int) $id, 'credit' => '', 'source' => 'ai'); }
            return new WP_Error('hzn_noimg', (isset($src_err) ? $src_err . '; ' : '') . 'colaj AI: ' . $id->get_error_message());
        }
        return new WP_Error('hzn_noimg', (isset($src_err) ? $src_err . '; ' : '') . 'fără cheie OpenAI pentru imagini');
    }

    /**
     * v3.50.4: imaginea AI în stilul colajelor de știri de pe site-urile românești
     * (personaj decupat în prim-plan peste un fundal real + un simbol), cerută de user.
     * Personajele rămân generice: fără chipuri de oameni reali (fals + risc legal).
     */
    public static function collage_prompt($scene) {
        return 'Photorealistic Romanian online news thumbnail in tabloid photo-collage style: ' . trim($scene) . '. '
             . 'Composite look: the main subject is a sharp cut-out in the foreground (left or right third), '
             . 'placed over a real, slightly softer background photo, with one symbolic element. Bright daylight, '
             . 'saturated colors, high detail, landscape 3:2. Romanian context (Romanian signage, uniforms, cars, buildings). '
             . 'No close-up faces: any people are anonymous, seen from behind or far away - never a real or real-looking politician. '
             . 'No captions, no headline text, no watermark, no logos except natural uniform lettering.';
    }

    /**
     * Toate cuvintele subiectului apar ca CUVINTE ÎNTREGI în titlul fișierului, iar
     * subiectul are cel puțin 2 cuvinte (prenume + nume / nume de loc). v3.50.1: înainte
     * se căuta subșir, deci „ANRE" se potrivea în orice fișier care conținea literele
     * astea și coverul ieșea fără legătură cu știrea.
     */
    private function relevant($entity, $file_title) {
        $words = function ($t) {
            return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(remove_accents((string) $t)), -1, PREG_SPLIT_NO_EMPTY);
        };
        $hay  = array_flip($words($file_title));
        $need = array_filter($words($entity), function ($w) {
            return mb_strlen($w) >= 3 && !in_array($w, array('din', 'lui', 'pentru', 'the', 'and', 'of'), true);
        });
        if (count($need) < 2) { return false; }
        foreach ($need as $w) {
            if (!isset($hay[$w])) { return false; }
        }
        return true;
    }

    /**
     * Doar licențe fără obligație de atribuire (CC0, domeniu public): creditul nu se
     * mai afișează în articol, iar CC BY / CC BY-SA l-ar cere.
     */
    private function license_ok($lic) {
        return (bool) preg_match('/^(cc0|public domain|pd|pdm)\b/i', trim((string) $lic));
    }

    private function landscape($w, $h) {
        return $w >= 1000 && $h > 0 && $w / $h >= 1.3 && $w / $h <= 2.2;
    }

    private function find_wikimedia($entity) {
        $api = add_query_arg(array(
            'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 6,
            'gsrlimit' => 15, 'gsrsearch' => $entity . ' filetype:bitmap',
            'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 1600,
            'iiextmetadatafilter' => 'Artist|LicenseShortName',
        ), 'https://commons.wikimedia.org/w/api.php');
        $r = wp_remote_get($api, array('timeout' => 20, 'user-agent' => 'ContinutZilnic/' . HZ_VERSION . ' (' . home_url('/') . ')'));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return null; }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($j['query']['pages']) || !is_array($j['query']['pages'])) { return null; }

        $pages = array_values($j['query']['pages']);
        usort($pages, function ($a, $b) { return (isset($a['index']) ? $a['index'] : 99) - (isset($b['index']) ? $b['index'] : 99); });
        foreach ($pages as $p) {
            $ii = isset($p['imageinfo'][0]) ? $p['imageinfo'][0] : null;
            if (!$ii || !in_array(isset($ii['mime']) ? $ii['mime'] : '', array('image/jpeg', 'image/png'), true)) { continue; }
            if (!$this->landscape((int) $ii['width'], (int) $ii['height'])) { continue; }
            if (!$this->relevant($entity, isset($p['title']) ? $p['title'] : '')) { continue; }
            $meta = isset($ii['extmetadata']) ? $ii['extmetadata'] : array();
            $lic  = isset($meta['LicenseShortName']['value']) ? (string) $meta['LicenseShortName']['value'] : '';
            if (!$this->license_ok($lic)) { continue; }
            $artist = isset($meta['Artist']['value']) ? trim(html_entity_decode(wp_strip_all_tags((string) $meta['Artist']['value']), ENT_QUOTES, 'UTF-8')) : '';
            $artist = $artist !== '' ? mb_substr($artist, 0, 80) : 'autor necunoscut';
            return array(
                'url'    => !empty($ii['thumburl']) ? $ii['thumburl'] : $ii['url'],
                'credit' => 'Foto: ' . $artist . ' / Wikimedia Commons, ' . $lic,
            );
        }
        return null;
    }

    private function find_openverse($entity) {
        $api = add_query_arg(array(
            'q' => $entity, 'license_type' => 'commercial', 'aspect_ratio' => 'wide', 'size' => 'large', 'page_size' => 15,
        ), 'https://api.openverse.org/v1/images/');
        $r = wp_remote_get($api, array('timeout' => 20, 'user-agent' => 'ContinutZilnic/' . HZ_VERSION . ' (' . home_url('/') . ')'));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return null; }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($j['results']) || !is_array($j['results'])) { return null; }
        foreach ($j['results'] as $it) {
            if (empty($it['url']) || !$this->landscape((int) (isset($it['width']) ? $it['width'] : 0), (int) (isset($it['height']) ? $it['height'] : 0))) { continue; }
            if (!$this->relevant($entity, isset($it['title']) ? $it['title'] : '')) { continue; }
            $lic = strtolower((string) (isset($it['license']) ? $it['license'] : ''));
            if (!$this->license_ok($lic)) { continue; }
            $lic_txt = in_array($lic, array('cc0', 'pdm'), true) ? ($lic === 'cc0' ? 'CC0' : 'domeniu public')
                     : 'CC ' . strtoupper($lic) . (empty($it['license_version']) ? '' : ' ' . $it['license_version']);
            $creator = trim((string) (isset($it['creator']) ? $it['creator'] : ''));
            $source  = ucfirst((string) (isset($it['source']) ? $it['source'] : 'Openverse'));
            return array(
                'url'    => (string) $it['url'],
                'credit' => 'Foto: ' . ($creator !== '' ? mb_substr($creator, 0, 80) : 'autor necunoscut') . ' / ' . $source . ', ' . $lic_txt,
            );
        }
        return null;
    }

    private function sideload($url, $post_id, $alt, $credit) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // v3.51.6: descărcare cu antet de browser + Referer (download_url pleacă cu UA-ul
        // WordPress, pe care unele CDN-uri îl refuză), iar extensia vine din conținutul
        // real: o poză WebP numită .jpg e respinsă de WordPress ca „tip nepermis".
        $tmp = wp_tempnam('hzn-img');
        $r   = wp_remote_get($url, array(
            'timeout' => 60, 'stream' => true, 'filename' => $tmp, 'user-agent' => self::UA,
            'headers' => array('Referer' => 'https://www.digi24.ro/', 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8'),
        ));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            @unlink($tmp);
            return new WP_Error('hzn_img_dl', 'descărcarea pozei a eșuat (' . (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r)) . ')');
        }
        $mime = function_exists('wp_get_image_mime') ? (string) wp_get_image_mime($tmp) : '';
        $exts = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif');
        if (!isset($exts[$mime])) { @unlink($tmp); return new WP_Error('hzn_img_type', 'fișierul descărcat nu e o imagine (' . ($mime !== '' ? $mime : 'necunoscut') . ')'); }
        $ext  = $exts[$mime];
        $name = sanitize_file_name(sanitize_title($alt) . '-' . substr(md5($url), 0, 6) . '.' . $ext);
        $id   = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), $post_id, $alt);
        if (is_wp_error($id)) { @unlink($tmp); return $id; }
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        wp_update_post(array('ID' => $id, 'post_excerpt' => $credit)); // legenda = creditul
        return (int) $id;
    }

    /**
     * Rulează generatorul pluginului Next Stage Image pentru articol și înregistrează
     * rezultatul în tabela lui ({prefix}nsi_images), exact ca ajax_generate_image().
     * Întoarce o notă scurtă pentru „Ultima imagine".
     */
    private function nsi_banner($post_id) {
        if (!class_exists('Next_Stage_Image') || !method_exists('Next_Stage_Image', 'instance')) { return 'banner: Next Stage Image inactiv'; }
        $nsi = Next_Stage_Image::instance();
        if (empty($nsi->generator) || !method_exists($nsi->generator, 'generate_for_post')) { return 'banner: versiune Next Stage Image necunoscută'; }
        global $wpdb;
        $table = $wpdb->prefix . 'nsi_images';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return 'banner: tabela Next Stage Image lipsește'; }

        $r = $nsi->generator->generate_for_post($post_id);
        if (is_wp_error($r)) { return 'banner: ' . $r->get_error_message(); }
        if (empty($r['url'])) { return 'banner: răspuns gol'; }
        $wpdb->replace($table, array(
            'post_id'       => $post_id,
            'attachment_id' => isset($r['attachment_id']) ? (int) $r['attachment_id'] : 0,
            'url'           => (string) $r['url'],
        ), array('%d', '%d', '%s'));
        return 'banner';
    }

    private function author_id() {
        $chosen = (int) get_option('hzn_author', 0);
        if ($chosen > 0 && user_can($chosen, 'publish_posts')) { return $chosen; }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    /**
     * Categoriile site-ului oferite modelului (v3.51.8): fără „Necategorizat" și fără
     * rubricile automate ale pluginului (horoscop, vreme, articole sponsorizate).
     */
    public static function site_categories() {
        $skip = array('uncategorized', 'fara-categorie', 'necategorizat', 'horoscop', 'vremea', 'articole-sponsorizate');
        $out  = array();
        foreach (get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 150)) as $t) {
            if (!($t instanceof WP_Term) || in_array($t->slug, $skip, true) || (int) $t->term_id === (int) get_option('default_category', 0)) { continue; }
            $out[] = html_entity_decode($t->name, ENT_QUOTES, 'UTF-8');
        }
        return array_slice(array_values(array_unique($out)), 0, 100);
    }

    /**
     * Categoria articolului: cea aleasă manual pe secțiune; altfel categoria EXISTENTĂ
     * aleasă de AI din lista site-ului; abia la urmă numele secțiunii (creată la nevoie).
     */
    private function category_id($section, $ai_cat = '') {
        $chosen = (int) get_option('hzn_cat_' . $section, 0);
        if ($chosen > 0 && get_term($chosen, 'category') instanceof WP_Term) { return $chosen; }
        if ($ai_cat !== '') {
            $want = mb_strtolower(remove_accents($ai_cat));
            foreach (get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 150)) as $t) {
                if ($t instanceof WP_Term && mb_strtolower(remove_accents(html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'))) === $want) {
                    return (int) $t->term_id;
                }
            }
        }
        $name = self::sections()[$section]['label'];
        $term = get_term_by('name', $name, 'category');
        if ($term instanceof WP_Term) { return (int) $term->term_id; }
        $created = wp_insert_term($name, 'category');
        return is_wp_error($created) ? (int) get_option('default_category', 1) : (int) $created['term_id'];
    }

    /* ---------------- admin ---------------- */

    public function register_settings() {
        $opts = array(
            'hzn_enabled' => 'absint', 'hzn_per_cat' => 'absint', 'hzn_start_hour' => 'absint',
            'hzn_end_hour' => 'absint', 'hzn_status' => 'sanitize_key', 'hzn_model' => 'sanitize_text_field',
            'hzn_author' => 'absint', 'hzn_images' => 'absint', 'hzn_free_photos' => 'absint', 'hzn_source_photo' => 'absint', 'hzn_nsi_banner' => 'absint', 'hzn_max_age' => 'absint', 'hzn_rss_url' => 'esc_url_raw',
        );
        foreach (array_keys(self::sections()) as $key) {
            $opts['hzn_on_' . $key]  = 'absint';
            $opts['hzn_cat_' . $key] = 'absint';
        }
        foreach ($opts as $name => $cb) {
            register_setting('hzn_settings', $name, array('sanitize_callback' => $cb));
        }
    }

    public function ajax_test() {
        check_ajax_referer('hzn_test', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Permisiune refuzată.')); }
        $section = isset($_POST['section']) ? sanitize_key(wp_unslash($_POST['section'])) : 'actualitate';
        $r = $this->run_one($section, true);
        if (is_wp_error($r)) { wp_send_json_error(array('message' => $r->get_error_message())); }
        wp_send_json_success(array(
            'message'   => sprintf('Ciornă creată: „%s” (cost text $%s, imagine: %s).', $r['title'], number_format($r['cost'], 4), $r['image']),
            'edit_link' => $r['edit_link'],
        ));
    }

    /** Tab-ul „Știri" din pagina unică de setări. */
    public function render_tab() {
        if (!current_user_can('manage_options')) { return; }
        $on    = (int) get_option('hzn_enabled', 0);
        $next  = wp_next_scheduled(self::HOOK);
        $last  = (string) get_option('hzn_last_run', '');
        $err   = (string) get_option('hzn_last_error', '');
        $done  = $this->today_counts();
        $model = (string) get_option('hzn_model', self::DEFAULT_MODEL);
        ?>
            <p style="max-width:720px;">
                <?php esc_html_e('Preia știri de pe digi24.ro (Actualitate, Politică, Economie, Externe), le rescrie complet cu AI și le publică pe categoria aleasă, întinse pe parcursul zilei. Sursa apare în primul paragraf („conform digi24.ro”, link nofollow către știrea originală). Pozele digi24 NU se preiau: coverul vine din Wikimedia Commons / Openverse (doar CC0 / domeniu public, fără credit în articol) sau e o imagine AI generată de la zero.', 'horoscop-zilnic'); ?>
            </p>
            <p style="max-width:720px;">
                <?php if (!$on) { echo '<strong>' . esc_html__('Preluarea automată este oprită.', 'horoscop-zilnic') . '</strong> '; } ?>
                <?php if ($on && $next) { echo esc_html(sprintf(__('Următoarea verificare: %s.', 'horoscop-zilnic'), wp_date('d.m.Y H:i', $next))) . ' '; } ?>
                <?php
                $parts = array();
                foreach (self::sections() as $key => $s) { $parts[] = $s['label'] . ': ' . (isset($done[$key]) ? (int) $done[$key] : 0); }
                echo esc_html(__('Azi: ', 'horoscop-zilnic') . implode(', ', $parts) . '.');
                ?>
                <?php if ($last !== '') : ?><span style="display:block;"><?php echo esc_html(sprintf(__('Ultima știre: %s', 'horoscop-zilnic'), $last)); ?></span><?php endif; ?>
                <?php if ($err !== '') : ?><span style="color:#b32d2e;display:block;"><?php echo esc_html(sprintf(__('Ultima eroare: %s', 'horoscop-zilnic'), $err)); ?></span><?php endif; ?>
                <?php $li = (string) get_option('hzn_last_image', ''); if ($li !== '') : ?><span style="display:block;"><?php echo esc_html(sprintf(__('Ultima imagine: %s', 'horoscop-zilnic'), $li)); ?></span><?php endif; ?>
                <span style="display:block;"><?php echo esc_html(sprintf(__('Rulează pe: %1$s · model %2$s', 'horoscop-zilnic'),
                    CW_API::openai_base() !== CW_API::OPENAI_BASE ? CW_API::openai_base() : 'OpenAI direct', self::effective_model())); ?></span>
            </p>

            <form method="post" action="options.php" style="max-width:720px;">
                <?php settings_fields('hzn_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Preluare automată', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzn_enabled" value="0" />
                            <label><input type="checkbox" name="hzn_enabled" value="1" <?php checked(1, $on); ?> /> <?php esc_html_e('Preia și publică știri în fiecare zi', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_per_cat"><?php esc_html_e('Știri pe zi, pe secțiune', 'horoscop-zilnic'); ?></label></th>
                        <td><input type="number" min="1" max="30" name="hzn_per_cat" id="hzn_per_cat" value="<?php echo esc_attr((int) get_option('hzn_per_cat', 5)); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Interval orar', 'horoscop-zilnic'); ?></th>
                        <td>
                            <?php foreach (array('hzn_start_hour' => 7, 'hzn_end_hour' => 22) as $opt => $def) : $v = (int) get_option($opt, $def); ?>
                                <select name="<?php echo esc_attr($opt); ?>">
                                    <?php for ($i = 0; $i <= 24; $i++) : ?><option value="<?php echo esc_attr($i); ?>" <?php selected($i, $v); ?>><?php echo esc_html(sprintf('%02d:00', $i)); ?></option><?php endfor; ?>
                                </select>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Știrile se întind uniform între cele două ore (ora site-ului).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Secțiuni și categorii', 'horoscop-zilnic'); ?></th>
                        <td>
                            <?php foreach (self::sections() as $key => $s) : ?>
                                <p>
                                    <input type="hidden" name="hzn_on_<?php echo esc_attr($key); ?>" value="0" />
                                    <label style="display:inline-block;min-width:110px;"><input type="checkbox" name="hzn_on_<?php echo esc_attr($key); ?>" value="1" <?php checked(1, (int) get_option('hzn_on_' . $key, 1)); ?> /> <?php echo esc_html($s['label']); ?></label>
                                    <?php wp_dropdown_categories(array(
                                        'name' => 'hzn_cat_' . $key, 'selected' => (int) get_option('hzn_cat_' . $key, 0),
                                        'hide_empty' => false, 'hierarchical' => true,
                                        'show_option_none' => __('— automat: AI alege din categoriile site-ului —', 'horoscop-zilnic'),
                                        'option_none_value' => 0,
                                    )); ?>
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_status"><?php esc_html_e('Stare articol', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $st = get_option('hzn_status', 'publish'); ?>
                            <select name="hzn_status" id="hzn_status">
                                <option value="publish" <?php selected($st, 'publish'); ?>><?php esc_html_e('Publicat direct', 'horoscop-zilnic'); ?></option>
                                <option value="draft" <?php selected($st, 'draft'); ?>><?php esc_html_e('Ciornă (publici manual)', 'horoscop-zilnic'); ?></option>
                                <option value="pending" <?php selected($st, 'pending'); ?>><?php esc_html_e('În așteptare', 'horoscop-zilnic'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_model"><?php esc_html_e('Model', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <select name="hzn_model" id="hzn_model">
                                <?php foreach (CW_Models::all() as $id => $m) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $model); ?>><?php echo esc_html(!empty($m['gateway']) ? CW_Models::gateway_label() . ': ' . $id : $m['label'] . ' ($' . $m['in'] . ' / $' . $m['out'] . ' la 1M tokeni)'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Implicit GPT-5.4 Mini: aproximativ $0,01 pe știre.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_author"><?php esc_html_e('Publică drept', 'horoscop-zilnic'); ?></label></th>
                        <td><?php wp_dropdown_users(array(
                            'name' => 'hzn_author', 'id' => 'hzn_author', 'selected' => (int) get_option('hzn_author', 0),
                            'who' => 'authors', 'include_selected' => true,
                            'show_option_none' => __('— Administrator implicit —', 'horoscop-zilnic'), 'option_none_value' => 0,
                        )); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Imagine cover', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzn_images" value="0" />
                            <label><input type="checkbox" name="hzn_images" value="1" <?php checked(1, (int) get_option('hzn_images', 1)); ?> /> <?php esc_html_e('Colaj AI generat din conținutul știrii (~$0,05)', 'horoscop-zilnic'); ?></label><br />
                            <input type="hidden" name="hzn_source_photo" value="0" />
                            <label><input type="checkbox" name="hzn_source_photo" value="1" <?php checked(1, (int) get_option('hzn_source_photo', 0)); ?> /> <?php esc_html_e('Folosește poza originală a știrii, cu creditul sursei („Foto: …”), pe răspunderea ta: pozele de agenție (Profimedia, Inquam etc.) pot aduce facturi pentru drepturi de autor', 'horoscop-zilnic'); ?></label><br />
                            <input type="hidden" name="hzn_nsi_banner" value="0" />
                            <label><input type="checkbox" name="hzn_nsi_banner" value="1" <?php checked(1, (int) get_option('hzn_nsi_banner', 1)); ?> <?php disabled(!class_exists('Next_Stage_Image')); ?> /> <?php esc_html_e('Generează și imaginea Social Media cu banner (Next Stage Image), ca butonul „Generează imagine”', 'horoscop-zilnic'); ?><?php if (!class_exists('Next_Stage_Image')) { echo ' — ' . esc_html__('pluginul nu e activ pe site', 'horoscop-zilnic'); } ?></label><br />
                            <input type="hidden" name="hzn_free_photos" value="0" />
                            <label><input type="checkbox" name="hzn_free_photos" value="1" <?php checked(1, (int) get_option('hzn_free_photos', 0)); ?> /> <?php esc_html_e('Încearcă întâi Wikimedia / Openverse după numele persoanei (CC0; risc de poză cu altă persoană cu același nume)', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_max_age"><?php esc_html_e('Vechime maximă', 'horoscop-zilnic'); ?></label></th>
                        <td><input type="number" min="1" max="168" name="hzn_max_age" id="hzn_max_age" value="<?php echo esc_attr((int) get_option('hzn_max_age', 24)); ?>" class="small-text" /> <?php esc_html_e('ore', 'horoscop-zilnic'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzn_rss_url"><?php esc_html_e('Feed RSS suplimentar', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="url" name="hzn_rss_url" id="hzn_rss_url" class="large-text" value="<?php echo esc_attr(get_option('hzn_rss_url', 'https://www.digi24.ro/rss')); ?>" />
                            <p class="description"><?php esc_html_e('Pe lângă paginile secțiunilor. Articolele se împart pe secțiuni după adresă.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salvează setările', 'horoscop-zilnic')); ?>
            </form>

            <hr />
            <p>
                <select id="hzn-test-section">
                    <?php foreach (self::sections() as $key => $s) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($s['label']); ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="button button-secondary" id="hzn-test" <?php disabled(!CW_API::has_key()); ?>><?php esc_html_e('Preia o știre de test (ciornă)', 'horoscop-zilnic'); ?></button>
                <span id="hzn-test-result"></span>
            </p>
            <script>
            (function () {
                var b = document.getElementById('hzn-test'), out = document.getElementById('hzn-test-result');
                if (!b) { return; }
                b.addEventListener('click', function () {
                    b.disabled = true; out.textContent = <?php echo wp_json_encode(__('Se preia și se rescrie (1-3 minute)...', 'horoscop-zilnic')); ?>;
                    var fd = new FormData();
                    fd.append('action', 'hzn_test');
                    fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('hzn_test')); ?>);
                    fd.append('section', document.getElementById('hzn-test-section').value);
                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            out.textContent = (j.data && j.data.message) || 'Eroare.';
                            if (j.success && j.data.edit_link) {
                                var a = document.createElement('a'); a.href = j.data.edit_link; a.textContent = ' Deschide ciorna'; out.appendChild(a);
                            }
                        })
                        .catch(function () { out.textContent = 'Eroare de rețea.'; })
                        .then(function () { b.disabled = false; });
                });
            })();
            </script>
        <?php
    }
}
