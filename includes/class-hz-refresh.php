<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Refresh conținut: îmbogățește articolele DEJA publicate cu două blocuri
 * derivate strict din textul lor, fără să inventeze informații:
 *   A) „Întrebări frecvente" la final (3-4 Q&A) — tema Magpress detectează
 *      automat secțiunea și emite schema FAQPage (v0.9.94);
 *   B) „Ce trebuie să reții" la început (3-5 idei-cheie).
 *
 * De ce așa: data articolului se schimbă DOAR odată cu o modificare reală de
 * conținut (un „touch" în masă pe post_modified arde crawl budget degeaba).
 *
 * Siguranță:
 * - conținutul original se salvează în post_meta ÎNAINTE de modificare (rollback complet);
 * - marcaj în post_meta => niciun articol nu e procesat de două ori;
 * - post_date NU se atinge (doar post_modified, natural, prin editare reală);
 * - excluderi obligatorii: categoria de advertoriale, categoriile de știri și
 *   ORICE articol care conține un link către alt domeniu (astea sunt advertoriale plătite);
 * - ritm: maximum N articole/zi/site, cu pauză între ele.
 */
class HZ_Refresh {

    const HOOK        = 'hz_refresh_tick';
    const LOCK        = 'hz_refresh_lock';
    const META_DONE   = '_hzr_done';    // data procesării
    const META_SKIP   = '_hzr_skip';    // motivul excluderii (ca să nu re-verificăm la fiecare rulare)
    const META_BACKUP = '_hzr_backup';  // conținutul original

    /** True dacă next_candidate() a golit chiar coada (vs. i s-a terminat bugetul de scanare). */
    public $exhausted = false;
    /** Motivele respingerii din ultima scanare: [motiv => nr. articole]. */
    public $last_reasons = array();

    /** Filtrul activ care fixează post_modified pe durata scrierilor unui articol. */
    private $mod_filter = null;

    /** Ora aleasă pentru articolul în lucru (vezi freeze_modified). */
    private $mod_ts = 0;

    /**
     * v3.4.6 — DECALAJ DE MINUTE pe data modificării.
     * Cron-ul hostingului rulează doar la :00/:15/:30/:45, sincronizat pe toate
     * site-urile, deci articolele primeau timestamp-uri fix pe granițele de sfert de
     * oră, identice pe zeci de domenii. Aici împingem `post_modified` cu 0-14 minute
     * ÎN INTERIORUL aceluiași sfert de oră: ziua nu se schimbă, semnalul de recrawl
     * rămâne același interval, dar momentele nu mai cad toate în același minut.
     * Garanție: niciodată înaintea datei de publicare.
     */
    private function freeze_modified($post) {
        $span = (int) $this->opt('hzr_time_jitter');
        if ($span <= 0) { return; }

        $span = min(60, $span); // minute; peste o oră n-are sens (am ieși din fereastra cron)
        $ts   = time() - wp_rand(0, $span * MINUTE_IN_SECONDS);
        $pub = (int) get_post_time('U', true, $post);
        if ($ts <= $pub) { $ts = time(); } // articol foarte recent: lăsăm ora reală
        $this->mod_ts = $ts;

        $this->mod_filter = function ($data) use ($ts) {
            $data['post_modified']     = get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts));
            $data['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $ts);
            return $data;
        };
        add_filter('wp_insert_post_data', $this->mod_filter, 99);
    }

    private function unfreeze_modified() {
        if ($this->mod_filter) {
            remove_filter('wp_insert_post_data', $this->mod_filter, 99);
            $this->mod_filter = null;
        }
    }

    /**
     * v3.4.7 — ora aleasă trebuie să fie ULTIMUL cuvânt.
     * Alte pluginuri se agață de `save_post` și re-salvează articolul după noi (pe
     * flotă o face inserarea de linkuri interne), iar `wp_update_post`-ul lor pune la
     * loc ora exactă — măsurat: decalajul supraviețuia doar pe ~jumătate din articole.
     * Scriem deci direct în tabel, pe `shutdown`, după ce toate hook-urile au terminat.
     */
    private function enforce_modified($post_id) {
        if (!$this->mod_ts) { return; }
        $ts = $this->mod_ts;
        $this->mod_ts = 0;
        add_action('shutdown', function () use ($post_id, $ts) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array(
                    'post_modified'     => get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts)),
                    'post_modified_gmt' => gmdate('Y-m-d H:i:s', $ts),
                ),
                array('ID' => (int) $post_id)
            );
            clean_post_cache((int) $post_id);
        }, 99);
    }

    public function init() {
        add_action(self::HOOK, array($this, 'process_tick'));

        add_action('wp_ajax_hzr_status',   array($this, 'ajax_status'));
        add_action('wp_ajax_hzr_toggle',   array($this, 'ajax_toggle'));
        add_action('wp_ajax_hzr_test',     array($this, 'ajax_test'));
        add_action('wp_ajax_hzr_rollback', array($this, 'ajax_rollback'));
        add_action('wp_ajax_hzr_rescan',   array($this, 'ajax_rescan'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_front'));

        // Pornirea/oprirea din setări programează sau curăță cronul imediat.
        add_action('update_option_hzr_enabled', array($this, 'on_toggle_option'), 10, 2);

        // v3.3.2 — auto-reparare: dacă modulul e pornit dar nu mai are niciun tick
        // programat (eveniment pierdut la o curățare de cron, o rulare întreruptă,
        // un plugin care șterge programările), coada s-ar opri tăcut pentru totdeauna.
        add_action('wp_loaded', array($this, 'ensure_scheduled'));
    }

    public function ensure_scheduled() {
        if (defined('DOING_CRON') && DOING_CRON) { return; } // tick-ul își reprogramează singur
        if (!(int) $this->opt('hzr_enabled') || (int) $this->opt('hzr_stopped')) { return; }
        if (wp_next_scheduled(self::HOOK)) { return; }
        if (get_transient(self::LOCK)) { return; }           // rulează chiar acum
        wp_schedule_single_event(time() + 60, self::HOOK);
    }

    /* ===================== Setări ===================== */

    public static function defaults() {
        return array(
            'hzr_enabled'       => 0,      // OFF implicit pe flotă (userul pornește per site)
            'hzr_model'         => '',     // gol = modelul implicit din AI Writer
            'hzr_daily_limit'   => 50,     // articole/zi/site
            'hzr_gap'           => 300,    // secunde între articole
            'hzr_do_faq'        => 1,
            'hzr_do_takeaways'  => 1,
            'hzr_do_meta'       => 1,      // descriere pentru Google, DOAR unde lipsește
            'hzr_meta_overwrite' => 0,     // suprascrie descrierile existente (implicit NU)
            'hzr_do_title'      => 0,      // rescrierea titlului: opt-in, vezi should_rewrite_title()
            'hzr_title_max_len' => 40,     // sub atâtea caractere titlul e considerat slab
            'hzr_faq_count'     => 4,
            'hzr_tk_count'      => 4,
            'hzr_faq_heading'   => '',     // gol = variantă aleasă determinist per site
            'hzr_tk_heading'    => '',
            // after_intro: blocul intră DUPĂ primul paragraf. Pus chiar la început, el
            // devine rezumatul automat al articolului (cardurile de pe homepage ar
            // arăta „Ideile principale…" în loc de introducere).
            'hzr_tk_pos'        => 'after_intro', // after_intro | top
            'hzr_min_words'     => 250,
            'hzr_order'         => 'DESC', // articolele recente întâi
            'hzr_max_chars'     => 8000,   // cât din articol trimitem la model
            'hzr_time_jitter'   => 14,     // minute de decalaj pe ora modificării (0 = ora exactă)
            'hzr_excluded_cats' => '',     // CSV de ID-uri (se auto-completează la prima rulare)
            'hzr_cats_seeded'   => 0,
            'hzr_stopped'       => 0,
            'hzr_prompt'        => '',
            'hzr_system_prompt' => '',
        );
    }

    public static function default_system_prompt() {
        return "Ești editor SEO pentru un site de conținut în limba română. Primești textul unui articol DEJA publicat și extragi din el, fără să inventezi nimic, idei-cheie și întrebări frecvente.\n\n"
             . "REGULI STRICTE\n"
             . "- Folosește EXCLUSIV informații care se găsesc în textul primit. Nu adăuga cifre, date, nume, prețuri, instituții sau afirmații care nu apar acolo. Dacă textul nu susține o întrebare, alege altă întrebare.\n"
             . "- Scrie în română corectă, cu diacritice, natural și la obiect, în același registru ca articolul.\n"
             . "- INTERZISE în text liniuța lungă (—) și liniuța medie (–): folosește cratima simplă (-), virgula sau punctul.\n"
             . "- Fără emoji, fără hashtag-uri, fără linkuri, fără îndemnuri de tip reclamă și fără mențiuni despre faptul că textul e generat automat.\n"
             . "- Nu da sfaturi medicale, financiare sau juridice concrete; reformulează informativ ce spune articolul.\n"
             . "- Vorbește DIRECT cu cititorul. Nu te referi niciodată la sursă: fără „articolul spune\", „textul recomandă\", „conform acestui ghid\", „mai sus\" - informația se prezintă ca atare.\n"
             . "- Nu începe toate răspunsurile la fel și nu relua întrebarea în prima propoziție a răspunsului.\n"
             . "- Evită pasivul impersonal care trădează o sursă („sunt menționate\", „sunt propuse\", „se sugerează\"): scrie afirmativ și direct.\n"
             . "- Întrebările sunt cele pe care un cititor le-ar căuta pe Google, formulate natural (nu „Ce este X?\" mecanic) și DIFERITE între ele. Fiecare răspuns se înțelege și rupt de articol: 2-4 propoziții, 40-70 de cuvinte.\n"
             . "- Ideile-cheie sunt propoziții scurte (maximum 20 de cuvinte), fiecare cu o informație distinctă. Nu repeta titlul și nu repeta aceeași idee cu alte cuvinte.\n"
             . "- Răspunzi DOAR cu JSON valid, fără explicații înainte sau după și fără blocuri de cod.";
    }

    public static function default_prompt() {
        return "Titlul actual al articolului: {{titlu}}\n\n"
             . "Textul articolului:\n\"\"\"\n{{continut}}\n\"\"\"\n\n"
             . "Returnează exact această structură JSON:\n"
             . "{\"idei\":[\"...\"],\"faq\":[{\"q\":\"...\",\"a\":\"...\"}],\"meta\":\"...\",\"titlu\":\"...\"}\n\n"
             . "- „idei\" = {{n_idei}} idei-cheie extrase din text (lista goală dacă numărul cerut e 0).\n"
             . "- „faq\" = {{n_faq}} perechi întrebare/răspuns (lista goală dacă numărul cerut e 0).\n"
             . "- „meta\" = {{cere_meta}} O descriere pentru rezultatele Google, 140-158 de caractere, care spune concret ce află cititorul. Fără ghilimele, fără numele site-ului, fără „Află mai multe\".\n"
             . "- „titlu\" = {{cere_titlu}} Un titlu mai bun pentru acest articol: maximum 65 de caractere, clar și natural, cu subiectul principal la început. Fără clickbait, fără majuscule inutile, fără ghilimele, fără anul curent dacă nu apare în text.";
    }

    private function opt($name, $fallback = null) {
        $d = self::defaults();
        if ($fallback === null && isset($d[$name])) { $fallback = $d[$name]; }
        return get_option($name, $fallback);
    }

    public function daily_limit() {
        return max(1, min(200, (int) $this->opt('hzr_daily_limit')));
    }

    /* ===================== Categorii excluse ===================== */

    /** Normalizare fără diacritice, pentru potrivirea numelor/slug-urilor de categorii. */
    private function norm($s) {
        $s = mb_strtolower(trim((string) $s));
        return strtr($s, array(
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ));
    }

    /**
     * Tiparele de nume/slug pentru categoriile excluse, pe VERSIUNI. La un update care
     * adaugă tipare noi se aplică DOAR setul nou peste lista existentă (union), ca
     * bifele puse sau scoase manual de user să nu fie rescrise.
     */
    public static function pattern_sets() {
        return array(
            // v1 (3.2.0): advertoriale + știri
            1 => array('sponsoriz', 'advertor', 'publicit', 'promovate', 'promoted',
                       'actualitate', 'stiri', 'news', 'politic', 'economie', 'economic', 'extern'),
            // v2 (3.4.0): conținut legat de o dată anume, comunicate, adult, jocuri de noroc
            2 => array('horoscop', 'astrolog', 'zodiac', 'zodii',
                       'vreme', 'meteo', 'prognoza',
                       'comunicat',
                       'adult', 'erotic', 'xxx', '18+',
                       'gambling', 'pariuri', 'cazino', 'casino', 'betting', 'noroc',
                       'poker', 'ruleta', 'loto', 'slot'),
            // v3 (3.4.1): „Știrea zilei" NU e prins de tiparul `stiri` (stirea ≠ stiri).
            // `stire` acoperă Știre / Știrea / Știrile; restul sunt variante de breaking news.
            3 => array('stire', 'ultima ora', 'ultima-ora', 'breaking'),
            // v4 (3.4.2): showbiz. Sportul NU se exclude (decizia userului) — categoriile
            // de sport rămân în procesare.
            4 => array('showbiz'),
        );
    }

    const SEED_V = 4;

    /**
     * Categoriile excluse OBLIGATORIU. Se auto-detectează la prima rulare și se
     * COMPLETEAZĂ la fiecare set nou de tipare (userul poate ajusta lista din setări).
     */
    public function seed_excluded_cats() {
        $sets = self::pattern_sets();
        // Site-urile seedate înainte de versionare (opțiunea veche hzr_cats_seeded) = v1.
        $have = (int) get_option('hzr_cats_seed_v', ((int) get_option('hzr_cats_seeded', 0)) ? 1 : 0);
        if ($have >= self::SEED_V) { return; }

        $patterns = array();
        for ($v = $have + 1; $v <= self::SEED_V; $v++) {
            if (isset($sets[$v])) { $patterns = array_merge($patterns, $sets[$v]); }
        }
        if (!$patterns) { update_option('hzr_cats_seed_v', self::SEED_V, 'no'); return; }

        $ids = array_filter(array_map('absint', explode(',', (string) get_option('hzr_excluded_cats', ''))));
        $terms = get_terms(array('taxonomy' => 'category', 'hide_empty' => false));
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if (!($t instanceof WP_Term)) { continue; }
                $hay = $this->norm($t->name) . ' ' . $this->norm($t->slug);
                foreach ($patterns as $p) {
                    if (strpos($hay, $p) !== false) { $ids[] = (int) $t->term_id; break; }
                }
            }
        }
        update_option('hzr_excluded_cats', implode(',', array_unique($ids)), 'no');
        update_option('hzr_cats_seeded', 1, 'no');
        update_option('hzr_cats_seed_v', self::SEED_V, 'no');
    }

    /** ID-urile excluse, cu tot cu subcategoriile lor. */
    public function excluded_cat_ids() {
        $this->seed_excluded_cats();
        $raw = (string) get_option('hzr_excluded_cats', '');
        $ids = array_filter(array_map('absint', explode(',', $raw)));
        foreach ($ids as $id) {
            $kids = get_term_children($id, 'category');
            if (is_array($kids)) { $ids = array_merge($ids, array_map('absint', $kids)); }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /* ===================== Eligibilitate ===================== */

    /** Host-ul site-ului, fără www. */
    private function site_host() {
        $h = wp_parse_url(home_url(), PHP_URL_HOST);
        return preg_replace('/^www\./i', '', strtolower((string) $h));
    }

    /**
     * True dacă articolul conține cel puțin un link către ALT domeniu.
     * Verificarea se face pe HOST-ul fiecărui <a href>, nu pe prezența lui „http"
     * (linkurile interne absolute sunt normale). Astea sunt advertorialele plătite.
     */
    public function has_external_link($content) {
        if (!preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', (string) $content, $m)) {
            return false;
        }
        $site = $this->site_host();
        foreach ($m[1] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
            if ($href === '' || $href[0] === '#' || $href[0] === '/') { continue; }
            if (preg_match('/^(mailto:|tel:|javascript:|data:)/i', $href)) { continue; }
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host) { continue; } // relativ => intern
            $host = preg_replace('/^www\./i', '', strtolower($host));
            if ($host !== $site) { return true; }
        }
        return false;
    }

    /**
     * True dacă articolul are deja o secțiune de întrebări frecvente.
     *
     * v3.7.6: înainte era de ajuns cuvântul „frecvente" ORIUNDE în text, deci
     * „cele mai frecvente greșeli" sau „răspundem la întrebări frecvente" scoteau
     * articolul din joc — pe un blog de marketing ieșeau zeci de false pozitive.
     * Acum cerem un TITLU (h2-h4) de FAQ sau schema FAQPage deja prezentă.
     */
    public function has_faq($content) {
        $content = (string) $content;
        if (stripos($content, 'FAQPage') !== false) { return true; }
        return (bool) preg_match(
            '/<h[2-4][^>]*>[^<]*(întrebări\s+frecvente|intrebari\s+frecvente|\bFAQ\b)/iu',
            $content
        );
    }

    /**
     * Motivul pentru care articolul NU e eligibil, sau '' dacă e eligibil.
     * Verificările de categorie se fac în query; aici rămân cele pe conținut.
     */
    public function skip_reason($post) {
        if (!($post instanceof WP_Post)) { return 'inexistent'; }
        // Conținutul zilnic generat de plugin (horoscop, vreme) e efemer și legat de o
        // dată anume — un FAQ pe „Horoscop joi, 6 august" nu are nicio valoare.
        if (get_post_meta($post->ID, '_hz_horoscope', true)) { return 'horoscop zilnic'; }
        if (get_post_meta($post->ID, '_hz_weather', true))   { return 'vremea zilnică'; }
        // v3.4.3: articolele generate cu „Articole Bulk" sunt deja conținut nou, scris
        // recent — n-au nevoie de un motiv de recrawl și n-are sens să le schimbăm data.
        if (get_post_meta($post->ID, '_hzb_bulk', true))     { return 'articol bulk (generat recent)'; }
        // Articolele marcate 18+ în editor (age-gate-ul temei) — indiferent de categorie.
        if (get_post_meta($post->ID, '_magpress_adult', true)) { return 'conținut 18+'; }

        $c = (string) $post->post_content;
        if ($this->has_external_link($c))                { return 'link extern'; }
        if ($this->has_faq($c))                          { return 'are deja FAQ'; }
        // str_word_count nu e sigur pe UTF-8 (diacriticele rup numărătoarea) → \p{L}+
        $words = preg_match_all('/\p{L}+/u', wp_strip_all_tags(strip_shortcodes($c)));
        if ($words < max(50, (int) $this->opt('hzr_min_words'))) {
            return 'prea scurt (' . $words . ' cuvinte)';
        }
        return '';
    }

    /**
     * Următorul articol de procesat. Marchează pe drum, cu _hzr_skip, articolele
     * neeligibile — ca să nu le mai citim niciodată.
     */
    public function next_candidate($max_scan = 40, $rounds = 10) {
        $excluded = $this->excluded_cat_ids();
        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => max(5, min(100, (int) $max_scan)),
            'orderby'             => 'date',
            'order'               => ($this->opt('hzr_order') === 'ASC' ? 'ASC' : 'DESC'),
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_query'          => array(
                'relation' => 'AND',
                array('key' => self::META_DONE, 'compare' => 'NOT EXISTS'),
                array('key' => self::META_SKIP, 'compare' => 'NOT EXISTS'),
            ),
        );
        if (!empty($excluded)) { $args['category__not_in'] = $excluded; }

        // Mai multe runde: articolele neeligibile primesc _hzr_skip, deci runda
        // următoare le sare din query. Fără asta, un site cu sute de horoscoape
        // zilnice la început ar raporta „nu mai sunt articole eligibile" după
        // prima pagină de rezultate, deși mai jos sunt mii.
        $this->exhausted = false;
        $this->last_reasons = array();   // ce a respins scanarea asta, pe motive
        for ($i = 0; $i < max(1, (int) $rounds); $i++) {
            $q = new WP_Query($args);
            if (empty($q->posts)) {
                $this->exhausted = true; // chiar nu mai e nimic de evaluat
                return null;
            }
            foreach ($q->posts as $p) {
                $reason = $this->skip_reason($p);
                if ($reason === '') { return $p; }
                update_post_meta($p->ID, self::META_SKIP, $reason);
                $key = preg_replace('/^prea scurt.*/u', 'prea scurt', $reason);
                $this->last_reasons[$key] = (isset($this->last_reasons[$key]) ? $this->last_reasons[$key] : 0) + 1;
            }
        }
        return null; // bugetul rundei s-a epuizat; următorul tick continuă de aici
    }

    /* ===================== Generare ===================== */

    /** Textul trimis modelului: curat, fără shortcode-uri, plafonat ca lungime. */
    private function plain_text($content) {
        $t = strip_shortcodes((string) $content);
        $t = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $t);
        $t = wp_strip_all_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace('/[ \t]*\n[ \t]*/', "\n", preg_replace('/[ \t]+/', ' ', $t)));
        $max = max(1000, min(20000, (int) $this->opt('hzr_max_chars')));
        if (mb_strlen($t) > $max) { $t = mb_substr($t, 0, $max) . '…'; }
        return $t;
    }

    /** Extrage obiectul JSON din răspunsul modelului (tolerant la text în plus). */
    private function parse_json($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```(?:json)?|```$/im', '', $text);
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) { return null; }
        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** Apel AI cu system prompt propriu (nu cel editorial de articole noi). */
    private function ask_model($post, $model, $want_meta = true, $want_title = false) {
        $n_faq = ((int) $this->opt('hzr_do_faq')) ? max(2, min(6, (int) $this->opt('hzr_faq_count'))) : 0;
        $n_tk  = ((int) $this->opt('hzr_do_takeaways')) ? max(3, min(6, (int) $this->opt('hzr_tk_count'))) : 0;

        $tpl = (string) $this->opt('hzr_prompt');
        if (trim($tpl) === '') { $tpl = self::default_prompt(); }
        $skip = 'lasă string gol, nu e nevoie de asta acum.';
        $prompt = str_replace(
            array('{{titlu}}', '{{continut}}', '{{n_faq}}', '{{n_idei}}', '{{cere_meta}}', '{{cere_titlu}}'),
            array(
                get_the_title($post),
                $this->plain_text($post->post_content),
                (string) $n_faq,
                (string) $n_tk,
                $want_meta ? '' : $skip,
                $want_title ? '' : $skip,
            ),
            $tpl
        );

        $sys = (string) $this->opt('hzr_system_prompt');
        if (trim($sys) === '') { $sys = self::default_system_prompt(); }
        $filter = function () use ($sys) { return $sys; };
        // Extragerea din text nu are nevoie de raționament adânc: 'low' dă aceeași
        // calitate la o fracțiune din cost, iar pe zeci de mii de articole contează.
        $effort = function () { return 'low'; };

        add_filter('cw_system_prompt', $filter, 99);
        add_filter('cw_effort', $effort, 99);
        // Fără căutare web: lucrăm pe textul articolului existent, iar pe zeci de mii
        // de articole căutările ar costa mai mult decât toată operațiunea.
        $res = CW_API::no_web(function () use ($model, $prompt) {
            return CW_API::generate($model, $prompt, 4000);
        });
        remove_filter('cw_effort', $effort, 99);
        remove_filter('cw_system_prompt', $filter, 99);

        return $res;
    }

    /* ===================== Construcția blocurilor ===================== */

    /**
     * Titlurile blocurilor variază PER SITE (alegere deterministă din host), ca aceleași
     * două titluri să nu apară identic pe zeci de mii de articole din 74 de domenii.
     * Editabile din setări.
     */
    private function heading_tk() {
        $custom = trim((string) $this->opt('hzr_tk_heading'));
        if ($custom !== '') { return $custom; }
        $v = array('Ce trebuie să reții', 'Pe scurt', 'Ideile principale', 'Ce e important de știut');
        return $v[hexdec(substr(md5($this->site_host()), 0, 4)) % count($v)];
    }

    /**
     * ATENȚIE: tema detectează secțiunea FAQ după expresiile „întrebări frecvente" /
     * „întrebări și răspunsuri" / „FAQ" din heading. Un titlu care nu le conține =
     * fără schema FAQPage, așa că variantele (și textul pus de user) se validează.
     */
    private function heading_faq() {
        $custom = trim((string) $this->opt('hzr_faq_heading'));
        if ($custom !== '' && preg_match('/(întrebări\s+frecvente|intrebari\s+frecvente|întrebări\s+și\s+răspunsuri|\bFAQ\b)/iu', $custom)) {
            return $custom;
        }
        $v = array('Întrebări frecvente', 'Întrebări și răspunsuri', 'Întrebări frecvente pe această temă');
        return $v[hexdec(substr(md5($this->site_host()), 4, 4)) % count($v)];
    }

    private function build_takeaways($items) {
        $li = '';
        foreach ($items as $it) {
            $t = trim(wp_strip_all_tags((string) $it));
            if ($t === '') { continue; }
            $li .= '<li>' . esc_html($t) . "</li>\n";
        }
        if ($li === '') { return ''; }
        return "<div class=\"hzr-takeaways\">\n<h2>" . esc_html($this->heading_tk()) . "</h2>\n<ul>\n" . $li . "</ul>\n</div>\n";
    }

    /**
     * Blocul FAQ în EXACT formatul pe care îl detectează tema Magpress
     * (h2 „Întrebări frecvente" + perechi h3 întrebare / p răspuns) → schema FAQPage.
     */
    private function build_faq($items) {
        $out = '';
        foreach ($items as $qa) {
            if (!is_array($qa)) { continue; }
            $q = trim(wp_strip_all_tags((string) (isset($qa['q']) ? $qa['q'] : '')));
            $a = trim(wp_strip_all_tags((string) (isset($qa['a']) ? $qa['a'] : '')));
            if (mb_strlen($q) < 5 || mb_strlen($a) < 15) { continue; }
            $out .= '<h3>' . esc_html($q) . "</h3>\n<p>" . esc_html($a) . "</p>\n";
        }
        if ($out === '') { return ''; }
        return "\n<div class=\"hzr-faq\">\n<h2>" . esc_html($this->heading_faq()) . "</h2>\n" . $out . "</div>\n";
    }

    /** Inserează blocul de idei după primul paragraf (sau chiar la început). */
    private function insert_takeaways($content, $block) {
        if ($block === '') { return $content; }
        if ($this->opt('hzr_tk_pos') !== 'top') {
            $pos = stripos($content, '</p>');
            if ($pos !== false) {
                return substr($content, 0, $pos + 4) . "\n\n" . $block . "\n" . substr($content, $pos + 4);
            }
        }
        return $block . "\n" . $content;
    }

    /* ===================== Meta description + titlu ===================== */

    /** Descrierea curentă pentru Google (SEOPress are prioritate, apoi rezumatul WP). */
    private function current_meta_desc($post_id) {
        $sp = get_post_meta($post_id, '_seopress_titles_desc', true);
        if (is_string($sp) && trim($sp) !== '') { return trim($sp); }
        $p = get_post($post_id);
        return $p ? trim((string) $p->post_excerpt) : '';
    }

    /** True dacă măcar unul dintre cele două câmpuri de descriere e gol. */
    private function needs_meta($post_id) {
        if ((int) $this->opt('hzr_meta_overwrite')) { return true; }
        if (defined('SEOPRESS_VERSION')
            && trim((string) get_post_meta($post_id, '_seopress_titles_desc', true)) === '') {
            return true;
        }
        $p = get_post($post_id);
        return $p && trim((string) $p->post_excerpt) === '';
    }

    /**
     * Scrie descrierea DOAR unde lipsește (implicit). Pe flotă multe articole au deja
     * descrieri scrise de om sau de SEOPress - nu le suprascriem fără cerere explicită.
     * Returnează true dacă s-a scris ceva.
     */
    private function apply_meta_desc($post_id, $desc) {
        $desc = trim(wp_strip_all_tags((string) $desc));
        if (mb_strlen($desc) < 60) { return false; }
        if (mb_strlen($desc) > 170) { $desc = rtrim(mb_substr($desc, 0, 167)) . '…'; }

        // Cele două câmpuri se tratează INDEPENDENT: pe multe site-uri SEOPress are deja
        // descriere (n-o atingem), dar post_excerpt e gol - iar acela e textul de pe
        // cardurile temei și baza rezumatului automat.
        $overwrite = (int) $this->opt('hzr_meta_overwrite');
        $wrote = false;
        // SEOPress e sursa descrierii pe site-urile din flotă care îl au activ.
        if (defined('SEOPRESS_VERSION')) {
            $old = (string) get_post_meta($post_id, '_seopress_titles_desc', true);
            if ($old === '' || $overwrite) {
                if ($old !== '') { update_post_meta($post_id, '_hzr_desc_old', $old); }
                update_post_meta($post_id, '_seopress_titles_desc', $desc);
                $wrote = true;
            }
        }
        $p = get_post($post_id);
        if ($p && (trim((string) $p->post_excerpt) === '' || $overwrite)) {
            if (trim((string) $p->post_excerpt) !== '') {
                update_post_meta($post_id, '_hzr_excerpt_old', $p->post_excerpt);
            }
            wp_update_post(array('ID' => $post_id, 'post_excerpt' => wp_slash($desc)));
            $wrote = true;
        }
        return $wrote;
    }

    /**
     * Titlul se rescrie DOAR unde n-avem ce pierde: titluri scurte/slabe sau
     * duplicate pe site. Un titlu care rankează nu se atinge, iar SLUG-ul (URL-ul)
     * nu se schimbă NICIODATĂ - wp_update_post păstrează post_name existent.
     */
    public function should_rewrite_title($post) {
        if (!(int) $this->opt('hzr_do_title')) { return false; }
        if (get_post_meta($post->ID, '_hzr_title_old', true)) { return false; }
        $t = trim(wp_strip_all_tags(get_the_title($post)));
        if ($t === '') { return true; }
        if (mb_strlen($t) < max(20, (int) $this->opt('hzr_title_max_len'))) { return true; }

        global $wpdb;
        $dup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_title=%s AND ID<>%d",
            $t, (int) $post->ID
        ));
        return $dup > 0;
    }

    /** Validează și aplică titlul propus. Returnează noul titlu sau ''. */
    private function apply_title($post, $new) {
        $new = trim(wp_strip_all_tags((string) $new));
        $new = trim($new, " \t\n\r\0\x0B\"'“”„");
        $old = trim(wp_strip_all_tags(get_the_title($post)));
        if (mb_strlen($new) < 20 || mb_strlen($new) > 75) { return ''; }
        if (mb_strtolower($new) === mb_strtolower($old)) { return ''; }

        update_post_meta($post->ID, '_hzr_title_old', $old);
        wp_update_post(array('ID' => $post->ID, 'post_title' => wp_slash($new))); // post_name neatins
        return $new;
    }

    /* ===================== Procesarea unui articol ===================== */

    /**
     * Procesează un articol. Returnează array('ok'=>bool,'msg'=>string,'cost'=>float).
     * Salvează întâi conținutul original (rollback), apoi scrie.
     */
    public function process_post($post) {
        if (!($post instanceof WP_Post)) {
            return array('ok' => false, 'msg' => 'Articol inexistent.', 'cost' => 0.0);
        }
        if (!class_exists('CW_API') || !CW_API::has_key()) {
            return array('ok' => false, 'msg' => 'Nicio cheie API configurată în tab-ul AI Writer.', 'cost' => 0.0);
        }
        if (get_post_meta($post->ID, self::META_DONE, true)) {
            return array('ok' => false, 'msg' => 'Articolul a fost deja procesat.', 'cost' => 0.0);
        }

        $model = CW_API::resolve_model((string) $this->opt('hzr_model'));
        // Descrierea o cerem doar dacă lipsește (sau dacă userul cere suprascriere),
        // titlul doar dacă articolul trece filtrul „n-avem ce pierde".
        $want_meta  = (int) $this->opt('hzr_do_meta') && $this->needs_meta($post->ID);
        $want_title = $this->should_rewrite_title($post);

        $res = $this->ask_model($post, $model, $want_meta, $want_title);
        if (is_wp_error($res)) {
            return array('ok' => false, 'msg' => $res->get_error_message(), 'cost' => 0.0);
        }
        $cost = isset($res['cost']) ? (float) $res['cost'] : 0.0;

        $data = $this->parse_json(isset($res['text']) ? $res['text'] : '');
        if (!$data) {
            return array('ok' => false, 'msg' => 'Răspuns AI neinterpretabil (JSON invalid).', 'cost' => $cost);
        }

        $faq_html = ((int) $this->opt('hzr_do_faq') && !empty($data['faq']) && is_array($data['faq']))
            ? $this->build_faq($data['faq']) : '';
        $tk_html  = ((int) $this->opt('hzr_do_takeaways') && !empty($data['idei']) && is_array($data['idei']))
            ? $this->build_takeaways($data['idei']) : '';

        // FAQPage are sens de la 2 perechi în sus; sub asta nu merită atinsă data articolului.
        if ($faq_html === '' && $tk_html === '') {
            update_post_meta($post->ID, self::META_SKIP, 'AI nu a returnat blocuri utile');
            return array('ok' => false, 'msg' => 'Modelul nu a returnat blocuri utilizabile.', 'cost' => $cost);
        }

        $original = (string) $post->post_content;
        update_post_meta($post->ID, self::META_BACKUP, $original); // ÎNAINTE de orice scriere

        $content = $this->insert_takeaways($original, $tk_html);
        $content = rtrim($content) . "\n" . $faq_html;

        // Toate scrierile articolului (conținut, rezumat, titlu) primesc ACEEAȘI oră,
        // decalată cu câteva minute — altfel ultima scriere ar readuce ora exactă.
        $this->freeze_modified($post);

        // post_date NU se atinge; post_modified se schimbă natural (editare reală).
        $updated = wp_update_post(array(
            'ID'           => $post->ID,
            'post_content' => wp_slash($content),
        ), true);

        if (is_wp_error($updated)) {
            $this->unfreeze_modified();
            delete_post_meta($post->ID, self::META_BACKUP);
            return array('ok' => false, 'msg' => $updated->get_error_message(), 'cost' => $cost);
        }

        update_post_meta($post->ID, self::META_DONE, current_time('mysql'));
        update_post_meta($post->ID, '_hzr_model', $model);

        $parts = array();
        if ($tk_html !== '')  { $parts[] = 'idei-cheie'; }
        if ($faq_html !== '') { $parts[] = 'FAQ'; }

        // Descrierea pentru Google (doar unde lipsea) și titlul (doar unde e slab).
        if ($want_meta && !empty($data['meta']) && $this->apply_meta_desc($post->ID, $data['meta'])) {
            $parts[] = 'descriere';
        }
        if ($want_title) {
            $nt = empty($data['titlu']) ? '' : $this->apply_title($post, $data['titlu']);
            // Transparență: dacă modelul n-a propus ceva mai bun, spunem că am păstrat titlul.
            $parts[] = $nt !== '' ? 'titlu nou („' . $nt . '")' : 'titlu păstrat';
        }

        $this->unfreeze_modified();      // de aici încolo, orice scriere revine la ora reală
        $this->enforce_modified($post->ID); // …dar ora noastră se rescrie la final, peste toți
        $this->bump_stats($cost);
        return array(
            'ok'   => true,
            'msg'  => sprintf('%s: %s adăugate.', get_the_title($post), implode(' + ', $parts)),
            'cost' => $cost,
            'id'   => (int) $post->ID,
        );
    }

    /* ===================== Cron ===================== */

    private function day_key() { return current_time('Y-m-d'); }

    private function day_state() {
        $s = get_option('hzr_day', array());
        if (!is_array($s) || !isset($s['d']) || $s['d'] !== $this->day_key()) {
            $s = array('d' => $this->day_key(), 'n' => 0);
        }
        return $s;
    }

    private function bump_stats($cost) {
        $s = $this->day_state();
        $s['n'] = (int) $s['n'] + 1;
        update_option('hzr_day', $s, 'no');

        $t = get_option('hzr_stats', array());
        if (!is_array($t)) { $t = array(); }
        $t['done'] = (int) (isset($t['done']) ? $t['done'] : 0) + 1;
        $t['cost'] = (float) (isset($t['cost']) ? $t['cost'] : 0) + (float) $cost;
        update_option('hzr_stats', $t, 'no');
    }

    /**
     * Decalaj stabil, propriu fiecărui site (0..$span secunde), derivat din domeniu.
     * Fără el, site-urile pornite în aceeași zi ajung să ruleze în pas.
     */
    private function site_offset($span = 900) {
        $span = max(1, (int) $span);
        return hexdec(substr(md5($this->site_host()), 8, 6)) % $span;
    }

    /**
     * v3.4.5 — DECALAJ ALEATOR la fiecare programare.
     * Hostingul rulează cron-ul din sfert în sfert de oră, sincronizat pe toate
     * site-urile, deci articolele cădeau la aceeași secundă pe zeci de domenii
     * (06:30:07, 06:30:08, …). Adăugăm un decalaj propriu site-ului plus unul
     * aleator la fiecare tick, ca momentele să pice pe granițe diferite și site-urile
     * să nu mai meargă în pas. Nu afectează ritmul: limita zilnică rămâne pragul real.
     */
    public function kick($delay = 5) {
        if (!(int) $this->opt('hzr_enabled')) { return; }
        if ((int) $this->opt('hzr_stopped')) { return; }
        if (!wp_next_scheduled(self::HOOK)) {
            $delay = max(1, (int) $delay);
            if ($delay >= 60) { // doar la ritmul normal, nu la pornirile imediate/test
                $delay += $this->site_offset(600) + wp_rand(0, 900);
            }
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
        if (!get_transient(self::LOCK) && function_exists('spawn_cron')) { spawn_cron(); }
    }

    public function on_toggle_option($old, $new) {
        if ((int) $new) { $this->kick(10); } else { wp_clear_scheduled_hook(self::HOOK); }
    }

    /**
     * Reluarea de mâine, după miezul nopții (limita zilnică s-a atins).
     * v3.4.5: ora diferă de la site la site (00:10 + până la ~4h30, propriu domeniului
     * plus o jumătate de oră aleatoare) — altfel toate site-urile reporneau în același
     * minut și rămâneau sincronizate toată ziua următoare.
     */
    private function schedule_tomorrow() {
        $ts = strtotime('tomorrow 00:10', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
        $ts += $this->site_offset(4 * HOUR_IN_SECONDS) + wp_rand(0, 1800);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(max(time() + 300, $ts), self::HOOK);
        }
    }

    public function process_tick() {
        if (!(int) $this->opt('hzr_enabled') || (int) $this->opt('hzr_stopped')) { return; }
        if (get_transient(self::LOCK)) { return; }
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);

        $day = $this->day_state();
        if ((int) $day['n'] >= $this->daily_limit()) {
            delete_transient(self::LOCK);
            $this->schedule_tomorrow();
            return;
        }

        $post = $this->next_candidate();
        if (!$post) {
            delete_transient(self::LOCK);
            if ($this->exhausted) {
                // Chiar nu mai sunt articole: reverificăm peste 6 ore (pot apărea noi).
                update_option('hzr_idle', current_time('mysql'), 'no');
                if (!wp_next_scheduled(self::HOOK)) {
                    wp_schedule_single_event(time() + 6 * HOUR_IN_SECONDS, self::HOOK);
                }
            } else {
                // Doar bugetul de scanare s-a epuizat (multe articole neeligibile la rând).
                // Continuăm imediat de unde am rămas — marcajele _hzr_skip sunt deja puse.
                $this->kick(30);
            }
            return;
        }
        delete_option('hzr_idle');

        if (function_exists('set_time_limit')) { @set_time_limit(300); }
        $r = $this->process_post($post);
        if (!$r['ok']) {
            update_option('hzr_last_error', $r['msg'] . ' (ID ' . $post->ID . ')', 'no');
        } else {
            delete_option('hzr_last_error');
        }

        delete_transient(self::LOCK);

        $gap = max(30, min(3600, (int) $this->opt('hzr_gap')));
        $this->kick($gap);
    }

    /* ===================== Stil frontend ===================== */

    public function enqueue_front() {
        if (!is_singular('post')) { return; }
        $id = get_queried_object_id();
        if (!$id || !get_post_meta($id, self::META_DONE, true)) { return; }
        wp_enqueue_style('hz-refresh', HZ_URL . 'assets/css/refresh.css', array(), HZ_VERSION);
    }

    /* ===================== AJAX ===================== */

    private function guard() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
    }

    /** Numere ieftine (SQL), fără să citim conținutul a mii de articole. */
    public function counts() {
        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");
        $done  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s", self::META_DONE));
        $skip  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s", self::META_SKIP));

        $excluded = $this->excluded_cat_ids();
        $in_cats = 0;
        if (!empty($excluded)) {
            $ids = implode(',', array_map('absint', $excluded));
            $in_cats = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE p.post_type='post' AND p.post_status='publish'
                   AND tt.taxonomy='category' AND tt.term_id IN ({$ids})"
            );
        }
        // Defalcarea motivelor: „Sărite: 53" nu spune nimic dacă nu vezi DE CE
        // (link extern? articole bulk? prea scurte?). Motivul e deja salvat în meta.
        $reasons = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value AS reason, COUNT(*) AS n FROM {$wpdb->postmeta}
             WHERE meta_key=%s GROUP BY meta_value ORDER BY n DESC LIMIT 12",
            self::META_SKIP
        ), ARRAY_A);
        foreach ((array) $rows as $r) {
            // „prea scurt (137 cuvinte)" -> le grupăm, altfel ies zeci de rânduri
            $label = preg_replace('/^prea scurt.*/u', 'prea scurt', (string) $r['reason']);
            $label = $label !== '' ? $label : 'nespecificat';
            $reasons[$label] = (isset($reasons[$label]) ? $reasons[$label] : 0) + (int) $r['n'];
        }
        arsort($reasons);

        return array(
            'total'     => $total,
            'excl_cats' => $in_cats,
            'done'      => $done,
            'skipped'   => $skip,
            'eligible'  => max(0, $total - $in_cats - $done - $skip),
            'reasons'   => $reasons,
        );
    }

    public function ajax_status() {
        $this->guard();
        $day   = $this->day_state();
        $stats = get_option('hzr_stats', array());
        $next  = wp_next_scheduled(self::HOOK);
        wp_send_json_success(array(
            'enabled'   => (int) $this->opt('hzr_enabled'),
            'stopped'   => (int) $this->opt('hzr_stopped'),
            'today'     => (int) $day['n'],
            'limit'     => $this->daily_limit(),
            'done'      => (int) (isset($stats['done']) ? $stats['done'] : 0),
            'cost'      => round((float) (isset($stats['cost']) ? $stats['cost'] : 0), 3),
            'next'      => $next ? wp_date('d.m H:i', $next) : '',
            'idle'      => get_option('hzr_idle', ''),
            'error'     => get_option('hzr_last_error', ''),
            'counts'    => $this->counts(),
        ));
    }

    public function ajax_toggle() {
        $this->guard();
        $stop = isset($_POST['stop']) ? (int) $_POST['stop'] : 1;
        update_option('hzr_stopped', $stop ? 1 : 0, 'no');
        if ($stop) {
            wp_clear_scheduled_hook(self::HOOK);
        } else {
            $this->kick(5);
        }
        wp_send_json_success(array('message' => $stop ? 'Procesarea a fost oprită.' : 'Procesarea a fost repornită.'));
    }

    /** Procesează pe loc 1 articol (sau un ID dat), ca userul să vadă rezultatul. */
    public function ajax_test() {
        $this->guard();
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'post') {
                wp_send_json_error(array('message' => 'ID invalid (nu e articol).'));
            }
            $reason = $this->skip_reason($post);
            if ($reason !== '') {
                wp_send_json_error(array('message' => 'Articolul nu e eligibil: ' . $reason . '.'));
            }
        } else {
            $post = $this->next_candidate();
            if (!$post) {
                // Nu lăsăm userul cu „nu merge": spunem CE a respins scanarea.
                $msg = 'Nu am găsit niciun articol eligibil.';
                if (!empty($this->last_reasons)) {
                    arsort($this->last_reasons);
                    $parts = array();
                    foreach ($this->last_reasons as $reason => $n) { $parts[] = $reason . ' (' . $n . ')'; }
                    $msg .= ' Verificate acum și respinse: ' . implode(', ', $parts)
                          . '. Pune un ID de articol în casetă ca să vezi motivul exact pentru el.';
                } elseif ($this->exhausted) {
                    $msg .= ' Toate articolele publicate au fost deja procesate sau marcate ca sărite.';
                }
                wp_send_json_error(array('message' => $msg));
            }
        }

        $r = $this->process_post($post);
        if (!$r['ok']) {
            update_option('hzr_last_error', $r['msg'], 'no');
            wp_send_json_error(array('message' => $r['msg']));
        }
        wp_send_json_success(array(
            'message' => $r['msg'],
            'link'    => get_permalink($post),
            'edit'    => get_edit_post_link($post->ID, ''),
            'title'   => get_the_title($post),
            'cost'    => round((float) $r['cost'], 4),
        ));
    }

    /**
     * Rollback: pune la loc conținutul original din backup.
     * scope=one (ultimul procesat / un ID) sau scope=all (în loturi de 25).
     */
    public function ajax_rollback() {
        $this->guard();
        global $wpdb;

        $scope = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'one';
        $id    = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if ($scope === 'one') {
            if (!$id) {
                $id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY meta_id DESC LIMIT 1",
                    self::META_DONE
                ));
            }
            if (!$id) { wp_send_json_error(array('message' => 'Nu există niciun articol procesat.')); }
            $n = $this->restore($id) ? 1 : 0;
            wp_send_json_success(array('message' => $n
                ? sprintf('Am pus la loc conținutul original pentru „%s".', get_the_title($id))
                : 'Nu am găsit backup pentru articolul respectiv.'));
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY meta_id DESC LIMIT 25",
            self::META_BACKUP
        ));
        $n = 0;
        foreach ($ids as $pid) { if ($this->restore((int) $pid)) { $n++; } }
        $left = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s", self::META_BACKUP));
        wp_send_json_success(array('message' => sprintf(
            '%d articole restaurate.%s', $n, $left > 0 ? sprintf(' Au mai rămas %d - mai apasă o dată.', $left) : ''
        )));
    }

    public function restore($post_id) {
        $backup = get_post_meta($post_id, self::META_BACKUP, true);
        if ($backup === '' || $backup === false) { return false; }

        $upd = array('ID' => (int) $post_id, 'post_content' => wp_slash($backup));

        // Titlul se pune la loc doar dacă l-am schimbat noi (slug-ul n-a fost atins).
        $old_title = get_post_meta($post_id, '_hzr_title_old', true);
        if (is_string($old_title) && $old_title !== '') {
            $upd['post_title'] = wp_slash($old_title);
        }
        // Rezumatul: dacă exista unul înainte îl restaurăm, dacă l-am creat noi îl golim.
        $old_exc = get_post_meta($post_id, '_hzr_excerpt_old', true);
        if (is_string($old_exc) && $old_exc !== '') {
            $upd['post_excerpt'] = wp_slash($old_exc);
        }
        wp_update_post($upd);

        $old_desc = get_post_meta($post_id, '_hzr_desc_old', true);
        if (is_string($old_desc) && $old_desc !== '') {
            update_post_meta($post_id, '_seopress_titles_desc', $old_desc);
        }

        foreach (array(self::META_BACKUP, self::META_DONE, '_hzr_model',
                       '_hzr_title_old', '_hzr_excerpt_old', '_hzr_desc_old') as $m) {
            delete_post_meta($post_id, $m);
        }
        return true;
    }

    /** Șterge marcajele de excludere, ca articolele să fie re-evaluate (după ce se schimbă regulile). */
    public function ajax_rescan() {
        $this->guard();
        global $wpdb;
        $n = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key=%s", self::META_SKIP));
        update_option('hzr_cats_seeded', 1, 'no'); // lista de categorii rămâne cea aleasă de user
        delete_option('hzr_idle');
        wp_send_json_success(array('message' => sprintf('%d marcaje de excludere șterse. Articolele vor fi re-evaluate.', $n)));
    }
}
