<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Vremea zilnică automată: un cron WP publică în fiecare zi un articol
 * „Vremea azi în principalele regiuni ale țării", pe baza datelor OFICIALE
 * de la Administrația Națională de Meteorologie (ANM).
 *
 * Sursa e XML-ul public ANM cu prognoza pe 5 zile pentru 10 orașe
 * reprezentative, grupate aici pe 6 regiuni. Temperaturile intră EXACT
 * din ANM într-un tabel construit de plugin (modelul AI NU inventează cifre),
 * iar textul (intro + o frază pe fiecare regiune) e scris de motorul AI Writer.
 */
class HZ_Weather {

    const HOOK = 'hz_weather_daily';
    const LOCK = 'hz_weather_lock';
    const CACHE = 'hz_weather_anm_xml';

    /**
     * Maparea orașelor din XML-ul ANM pe regiuni + numele „frumos" (cu diacritice).
     * Cheia = exact numele din atributul `localitate nume="..."`.
     */
    private static function city_map() {
        return array(
            'Bucuresti'    => array('region' => 'Muntenia și Capitala', 'city' => 'București'),
            'Iasi'         => array('region' => 'Moldova',              'city' => 'Iași'),
            'Botosani'     => array('region' => 'Moldova',              'city' => 'Botoșani'),
            'Cluj-Napoca'  => array('region' => 'Transilvania',         'city' => 'Cluj-Napoca'),
            'Sibiu'        => array('region' => 'Transilvania',         'city' => 'Sibiu'),
            'Arad'         => array('region' => 'Banat și Crișana',     'city' => 'Arad'),
            'Craiova'      => array('region' => 'Oltenia',              'city' => 'Craiova'),
            'Rm. Valcea'   => array('region' => 'Oltenia',              'city' => 'Râmnicu Vâlcea'),
            'Constanta'    => array('region' => 'Dobrogea și Litoral',  'city' => 'Constanța'),
            'Sulina'       => array('region' => 'Dobrogea și Litoral',  'city' => 'Sulina'),
        );
    }

    /** Ordinea în care apar regiunile în articol și în tabel. */
    private static function region_order() {
        return array('Muntenia și Capitala', 'Moldova', 'Transilvania', 'Banat și Crișana', 'Oltenia', 'Dobrogea și Litoral');
    }

    public function init() {
        add_action(self::HOOK, array($this, 'cron_run'));
        add_action('init', array($this, 'maybe_schedule'));
        add_action('wp_ajax_hzw_test', array($this, 'ajax_test'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front'));

        add_action('update_option_hzw_hour', array($this, 'reschedule'), 10, 0);
        add_action('update_option_hzw_enabled', array($this, 'reschedule'), 10, 0);
    }

    /** Stilul tabelului meteo, doar pe articolele generate de acest modul. */
    public function enqueue_front() {
        if (!is_singular()) { return; }
        $id = get_queried_object_id();
        if (!$id || get_post_meta($id, '_hz_weather', true) === '') { return; }
        wp_enqueue_style('hz-weather', HZ_URL . 'assets/css/weather.css', array(), HZ_VERSION);
    }

    /* ---- Programare cron (identică ca logică cu horoscopul) ---- */

    public function maybe_schedule() {
        if (!(int) get_option('hzw_enabled', 0)) {
            if (wp_next_scheduled(self::HOOK)) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event($this->next_run_ts(), 'daily', self::HOOK);
        }
    }

    public function reschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    private function next_run_ts() {
        $hour = max(0, min(23, (int) get_option('hzw_hour', 8)));
        $tz   = wp_timezone();
        $now  = new DateTimeImmutable('now', $tz);
        $run  = $now->setTime($hour, 0, 0);
        if ($run <= $now) {
            $run = $run->modify('+1 day');
        }
        return $run->getTimestamp();
    }

    /* ---- Rulare ---- */

    public function cron_run() {
        $this->run(false);
    }

    public function run($test = false) {
        if (!$test && !(int) get_option('hzw_enabled', 0)) {
            return new WP_Error('hzw_off', 'Publicarea automată a vremii este dezactivată.');
        }
        if (!class_exists('CW_API')) {
            return $this->fail('Motorul AI Writer nu este încărcat.');
        }
        if (!CW_API::has_key()) {
            return $this->fail('Nicio cheie API configurată în Setări → AI Writer.');
        }

        $today = current_time('Y-m-d');
        if (!$test && get_option('hzw_last_run', '') === $today) {
            return new WP_Error('hzw_done', 'Articolul meteo de azi există deja.');
        }
        if (get_transient(self::LOCK)) {
            return new WP_Error('hzw_lock', 'O generare este deja în curs.');
        }
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);

        $result = $this->generate_post($test);

        delete_transient(self::LOCK);

        if (is_wp_error($result)) {
            return $this->fail($result->get_error_message());
        }
        if (!$test) {
            update_option('hzw_last_run', $today, 'no');
        }
        update_option('hzw_last_error', '', 'no');
        return $result;
    }

    private function fail($message) {
        update_option('hzw_last_error', current_time('Y-m-d H:i') . ' — ' . $message, 'no');
        return new WP_Error('hzw_fail', $message);
    }

    private function generate_post($test) {
        $data_afisata = date_i18n('j F Y', current_time('timestamp'));
        $zi           = date_i18n('l', current_time('timestamp'));
        $target_date  = current_time('Y-m-d');

        // 1. Datele OFICIALE ANM pentru ziua curentă, grupate pe regiuni.
        $regions = $this->fetch_regions($target_date);
        if (is_wp_error($regions)) {
            return $regions;
        }
        if (empty($regions)) {
            return new WP_Error('hzw_nodata', 'ANM nu a returnat date meteo pentru ' . $target_date . '.');
        }

        // 2. Blocul de date trimis modelului (cifre exacte, ca să nu inventeze).
        $data_block = $this->format_data_block($regions, $data_afisata);

        $prompt = (string) get_option('hzw_prompt', '');
        if (trim($prompt) === '') {
            $prompt = self::default_prompt();
        }
        $prompt = str_replace(array('{{data}}', '{{zi}}'), array($data_afisata, $zi), $prompt);
        $prompt .= "\n\n" . $data_block;

        $model = (string) get_option('hzw_model', '');
        if ($model === '' || !CW_Models::exists($model)) {
            $model = CW_Models::default_model();
        }

        $system = trim((string) get_option('hzw_system_prompt', ''));
        if ($system === '') {
            $system = self::default_system_prompt();
        }
        $override = function () use ($system) { return $system; };
        add_filter('cw_system_prompt', $override);
        // Datele meteo vin din XML-ul ANM — căutarea web n-ar aduce nimic în plus.
        $result = CW_Usage::with_context('vreme', function () use ($model, $prompt) {
            return CW_API::no_web(function () use ($model, $prompt) {
                return CW_API::generate($model, $prompt);
            });
        });
        remove_filter('cw_system_prompt', $override);

        if (is_wp_error($result)) {
            return $result;
        }

        // 3. Asamblare: intro + secțiuni AI, tabelul determinist inserat după intro, nota ANM.
        $prose = $this->clean_html($result['text']);
        if (stripos($prose, '<h2') === false) {
            return new WP_Error('hzw_short', 'Text generat invalid (fără secțiuni).');
        }

        $table = $this->build_table($regions, $data_afisata);
        $content = $this->insert_after_first_paragraph($prose, $table);
        $content .= "\n" . $this->build_note();

        if (strlen($content) < 500) {
            return new WP_Error('hzw_short', 'Conținut generat prea scurt (' . strlen($content) . ' caractere).');
        }

        $title = (string) get_option('hzw_title', '');
        if (trim($title) === '') {
            $title = self::default_title();
        }
        $title = str_replace(array('{{data}}', '{{zi}}'), array($data_afisata, $zi), $title);

        $status = $test ? 'draft' : (string) get_option('hzw_status', 'publish');
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) {
            $status = 'publish';
        }

        $post_id = wp_insert_post(wp_slash(array(
            'post_title'    => wp_strip_all_tags($title),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_author'   => $this->author_id(),
            'post_category' => array($this->category_id()),
        )), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }
        update_post_meta($post_id, '_hz_weather', current_time('Y-m-d'));

        $image_id = (int) get_option('hzw_image', 0);
        if ($image_id > 0 && wp_attachment_is_image($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }

        return array(
            'post_id'   => $post_id,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'cost'      => isset($result['cost']) ? (float) $result['cost'] : 0.0,
        );
    }

    /* ---- Sursa ANM ---- */

    /**
     * Descarcă și parsează XML-ul ANM, întoarce datele pentru $date grupate pe regiuni:
     * array('Muntenia și Capitala' => array( array('city','min','max','desc','icon'), ... ), ...)
     */
    private function fetch_regions($date) {
        $xml_raw = $this->fetch_anm_xml();
        if ($xml_raw === '') {
            return new WP_Error('hzw_fetch', 'Nu am putut prelua datele ANM (rețea sau sursă indisponibilă).');
        }

        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($xml_raw);
        libxml_use_internal_errors($prev);
        if ($xml === false || !isset($xml->tara->localitate)) {
            return new WP_Error('hzw_parse', 'Format XML ANM neașteptat.');
        }

        $map     = self::city_map();
        $grouped = array();

        foreach ($xml->tara->localitate as $loc) {
            $name = trim((string) $loc['nume']);
            if (!isset($map[$name])) { continue; }

            $entry = null;
            foreach ($loc->prognoza as $p) {
                if ((string) $p['data'] === $date) { $entry = $p; break; }
            }
            // Dacă nu există fix pentru azi (înainte de actualizarea ANM), luăm prima zi disponibilă.
            if ($entry === null && isset($loc->prognoza[0])) {
                $entry = $loc->prognoza[0];
            }
            if ($entry === null) { continue; }

            $raw    = trim((string) $entry->fenomen_descriere);
            $region = $map[$name]['region'];
            // Iconița se deduce din textul BRUT (ASCII, cum vine de la ANM); descrierea
            // afișată o normalizăm cu diacritice.
            $grouped[$region][] = array(
                'city' => $map[$name]['city'],
                'min'  => (int) $entry->temp_min,
                'max'  => (int) $entry->temp_max,
                'desc' => $raw !== '' ? $this->beautify_desc($raw) : 'Fără informații',
                'icon' => $this->weather_icon($raw),
            );
        }

        // Reordonăm după ordinea fixă a regiunilor.
        $ordered = array();
        foreach (self::region_order() as $region) {
            if (!empty($grouped[$region])) {
                $ordered[$region] = $grouped[$region];
            }
        }
        return $ordered;
    }

    /** XML-ul ANM, cache-uit într-un transient (implicit 3h) ca să nu-l lovim la fiecare request. */
    private function fetch_anm_xml() {
        $cached = get_transient(self::CACHE);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $url = trim((string) get_option('hzw_source_url', self::default_source_url()));
        if ($url === '') { $url = self::default_source_url(); }

        $response = wp_remote_get($url, array(
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; ContinutZilnic/' . HZ_VERSION . ')',
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') { return ''; }

        $ttl = max(1, (int) get_option('hzw_cache_hours', 3)) * HOUR_IN_SECONDS;
        set_transient(self::CACHE, $body, $ttl);
        return $body;
    }

    /** O iconiță simplă dedusă din descrierea ANM (robustă la codurile de simbol). */
    private function weather_icon($desc) {
        // Descrierile ANM sunt ASCII fără diacritice („descarcari", „ceata"), deci
        // strtolower e suficient și evită dependența de extensia mbstring.
        $d = strtolower($desc);
        if (strpos($d, 'descarcari electrice') !== false || strpos($d, 'oraj') !== false) { return '⛈️'; }
        if (strpos($d, 'ninsoare') !== false || strpos($d, 'nins') !== false)             { return '❄️'; }
        if (strpos($d, 'ploaie') !== false || strpos($d, 'averse') !== false || strpos($d, 'burnita') !== false) { return '🌧️'; }
        if (strpos($d, 'ceata') !== false)                                                 { return '🌫️'; }
        if (strpos($d, 'senin') !== false)                                                 { return '☀️'; }
        if (strpos($d, 'noros') !== false || strpos($d, 'innorat') !== false || strpos($d, 'acoperit') !== false) { return '☁️'; }
        if (strpos($d, 'variabil') !== false || strpos($d, 'partial') !== false)           { return '⛅'; }
        return '🌡️';
    }

    /**
     * Descrierile ANM vin fără diacritice („descarcari", „slaba", „si"). Le normalizăm
     * pentru afișare cu strtr (fără mbstring): map cu variantele lowercase + Capitalizat
     * care pot apărea ca primul cuvânt.
     */
    private function beautify_desc($d) {
        $map = array(
            ' si '        => ' și ',
            'descarcari'  => 'descărcări',  'Descarcari'  => 'Descărcări',
            'descarcare'  => 'descărcare',
            'electrice'   => 'electrice',
            'slaba'       => 'slabă',
            'slabe'       => 'slabe',
            'ceata'       => 'ceață',        'Ceata'       => 'Ceață',
            'innorat'     => 'înnorat',      'Innorat'     => 'Înnorat',
            'innourari'   => 'înnorări',     'Innourari'   => 'Înnorări',
            'innorari'    => 'înnorări',     'Innorari'    => 'Înnorări',
            'burnita'     => 'burniță',      'Burnita'     => 'Burniță',
            'vant'        => 'vânt',
            'moderata'    => 'moderată',
            'puternica'   => 'puternică',
            'ridicata'    => 'ridicată',
            'scazuta'     => 'scăzută',
            'insemnate'   => 'însemnate',
            'insemnata'   => 'însemnată',
            'inghet'      => 'îngheț',       'Inghet'      => 'Îngheț',
            'racire'      => 'răcire',
            'incalzire'   => 'încălzire',
        );
        return strtr($d, $map);
    }

    /* ---- Compunerea articolului ---- */

    /** Textul cu datele oficiale, dat modelului ca să scrie previziunea (cifrele rămân exacte). */
    private function format_data_block($regions, $data_afisata) {
        $lines = array();
        $lines[] = 'DATE METEO OFICIALE (sursă ANM) pentru ' . $data_afisata . ' — temperaturi în grade Celsius. Folosește EXACT aceste valori, nu inventa alte orașe sau cifre:';
        foreach ($regions as $region => $cities) {
            $lines[] = '';
            $lines[] = $region . ':';
            foreach ($cities as $c) {
                $lines[] = sprintf('- %s: minimă %d°C, maximă %d°C, cer: %s', $c['city'], $c['min'], $c['max'], $c['desc']);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Tabelul determinist cu temperaturile exacte (autoritatea pe cifre în articol).
     * 4 coloane (temperaturile combinate min/max) + wrapper cu scroll orizontal pe mobil.
     * Stilul de bază vine din assets/css/weather.css; wrapper-ul are și un style inline
     * (supraviețuiește kses) ca tabelul să nu spargă layout-ul nici fără CSS.
     */
    private function build_table($regions, $data_afisata) {
        $html  = '<figure class="hz-weather-table">';
        $html .= '<div class="hz-weather-scroll" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
        $html .= '<table><thead><tr>';
        $html .= '<th>Regiune</th><th>Oraș</th><th>Temperaturi</th><th>Cerul</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($regions as $region => $cities) {
            $rows  = count($cities);
            $first = true;
            foreach ($cities as $c) {
                $html .= '<tr>';
                if ($first) {
                    $html .= '<td class="hz-region" rowspan="' . (int) $rows . '">' . esc_html($region) . '</td>';
                    $first = false;
                }
                $temp = '<span class="hz-t-min">' . (int) $c['min'] . '°</span>'
                      . '<span class="hz-t-sep"> / </span>'
                      . '<span class="hz-t-max">' . (int) $c['max'] . '°C</span>';
                $html .= '<td class="hz-city">' . esc_html($c['city']) . '</td>';
                $html .= '<td class="hz-temp">' . $temp . '</td>';
                $html .= '<td class="hz-sky">' . esc_html($c['icon']) . ' ' . esc_html($c['desc']) . '</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        $html .= '<figcaption>Temperaturile minime și maxime pentru ' . esc_html($data_afisata) . ', date oficiale ANM.</figcaption>';
        $html .= '</figure>';
        return $html;
    }

    /** Nota de subsol: atribuire ANM (opțional, pornit implicit). */
    private function build_note() {
        if (!(int) get_option('hzw_cite_source', 1)) {
            return '';
        }
        return '<blockquote><i>Notă: Datele meteo prezentate în acest articol provin de la Administrația Națională de Meteorologie (ANM) și au caracter orientativ. Prognoza se poate actualiza pe parcursul zilei.</i></blockquote>';
    }

    /** Inserează $insert imediat după primul paragraf </p>; dacă nu există, îl pune la început. */
    private function insert_after_first_paragraph($html, $insert) {
        $pos = stripos($html, '</p>');
        if ($pos === false) {
            return $insert . "\n" . $html;
        }
        $pos += strlen('</p>');
        return substr($html, 0, $pos) . "\n" . $insert . "\n" . substr($html, $pos);
    }

    private function clean_html($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = preg_replace('/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text);
        return trim($text);
    }

    private function author_id() {
        // Autorul ales explicit în setări (dacă e valid și poate publica).
        $chosen = (int) get_option('hzw_author', 0);
        if ($chosen > 0 && user_can($chosen, 'publish_posts')) {
            return $chosen;
        }
        // Rezervă: primul administrator.
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    private function category_id() {
        $chosen = (int) get_option('hzw_category_id', 0);
        if ($chosen > 0) {
            $term = get_term($chosen, 'category');
            if ($term instanceof WP_Term) {
                return (int) $term->term_id;
            }
        }
        $name = trim((string) get_option('hzw_category', ''));
        if ($name === '') { $name = 'Vremea'; }
        $term = get_term_by('name', $name, 'category');
        if ($term instanceof WP_Term) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, 'category');
        if (is_wp_error($created)) {
            return (int) get_option('default_category', 1);
        }
        return (int) $created['term_id'];
    }

    /* ---- Test manual din Setări ---- */

    public function ajax_test() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permisiune refuzată.'));
        }
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $result = $this->run(true);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array(
            'message'   => sprintf('Draft creat (cost $%s).', number_format($result['cost'], 4)),
            'edit_link' => $result['edit_link'],
        ));
    }

    /* ---- Valori implicite ---- */

    public static function default_source_url() {
        return 'https://www.meteoromania.ro/anm/prognoza-orase-xml.php';
    }

    public static function default_title() {
        return 'Vremea azi, {{zi}} {{data}}: prognoza meteo în principalele regiuni ale țării';
    }

    public static function default_prompt() {
        return 'Scrie articolul meteo pentru ziua de azi, {{zi}}, {{data}}, pe baza datelor oficiale de mai jos, respectând întocmai structura, tonul și formatul HTML din instrucțiunile de sistem.';
    }

    public static function default_system_prompt() {
        return <<<'TXT'
Rol: Ești redactor meteo la o publicație de știri online din România. Scrii rubrica zilnică „Vremea azi", pe înțelesul tuturor, caldă și clară, ca un prezentator meteo priceput care îți spune în 40 de secunde la ce să te aștepți azi în țară. Scrii ca un om real, nu ca un asistent AI.

Sarcina: Redactezi EXCLUSIV articolul meteo al zilei, cu situația pe principalele regiuni ale României, gata de publicat. Primești datele oficiale (temperaturi minime/maxime și starea cerului pe orașe reprezentative, grupate pe regiuni). Textul tău le transformă într-un articol care se citește natural.

═══════════════════════════════════════
REGULA DE AUR: CIFRELE SUNT SFINTE
═══════════════════════════════════════

- Folosește DOAR temperaturile și fenomenele din datele primite. NU inventa alte valori, alte orașe sau alte regiuni.
- Când menționezi o temperatură sau o stare a cerului, ea trebuie să corespundă EXACT datelor primite.
- Nu inventa vânt, umiditate, presiune, ore de răsărit sau alte date care nu ți-au fost date.
- Tabelul cu temperaturi exacte se adaugă automat de sistem după introducerea ta — TU nu scrii tabel.

═══════════════════════════════════════
STRUCTURA FIXĂ
═══════════════════════════════════════

1. Un paragraf <p> de introducere: 2-3 propoziții despre cum arată vremea azi în ansamblu pe țară (cald/răcoros, unde plouă, unde e senin), concret, cu un cârlig. Fără clișee.
2. Câte o secțiune <h2> pentru FIECARE regiune primită, în ordinea în care apar în date. Titlul <h2> = numele regiunii, exact cum e dat.
3. Sub fiecare <h2>, UN paragraf <p> de 45-70 de cuvinte care descrie natural vremea în acea regiune: temperaturile (folosind cifrele exacte primite), starea cerului, dacă e nevoie de umbrelă etc. Leagă de viața reală (o zi bună de plajă la mare, umbrela la îndemână, seară răcoroasă la munte).
4. FĂRĂ notă de subsol (o adaugă sistemul), FĂRĂ tabel, FĂRĂ titlu H1, FĂRĂ concluzie separată.

═══════════════════════════════════════
SĂ NU PARĂ SCRIS DE AI
═══════════════════════════════════════

Formulări INTERZISE:
- „este important de reținut", „merită menționat", „în concluzie", „așadar", „prin urmare"
- „o gamă largă de", „o multitudine de", fraze paralele rigide („nu doar... ci și...")
- paragrafe care încep toate la fel („În această regiune...", „Aici...", „Vremea va fi...")
- fără emoji în text. INTERZISE liniuța lungă (—) și cea medie (–): folosește DOAR cratima simplă (-), altfel virgulă, punct sau paranteze.

În schimb:
- alternează construcțiile de la o regiune la alta (undeva începi cu temperatura, altundeva cu cerul, altundeva cu un sfat practic)
- fraze de lungimi diferite, ton viu dar informativ
- diacritice OBLIGATORII peste tot (ă, â, î, ș, ț)

═══════════════════════════════════════
TONUL
═══════════════════════════════════════

- informativ, prietenos, echilibrat; fără alarmism, dar dacă datele arată furtuni sau caniculă, spune-o clar și calm
- fără sfaturi medicale concrete; poți sugera bunul-simț (hidratare pe caniculă, umbrelă la ploaie)

═══════════════════════════════════════
FORMAT HTML DE LIVRARE
═══════════════════════════════════════

- Paragrafe între <p> ... </p>, subtitluri DOAR cu <h2> ... </h2>
- Bold cu <strong> doar pe 2-4 expresii din tot textul (ex. o temperatură-cheie)
- FĂRĂ <h1>, FĂRĂ <h3>, FĂRĂ liste, FĂRĂ tabele, FĂRĂ blockquote, FĂRĂ titlu inclus
- FĂRĂ markdown, FĂRĂ blocuri de cod, FĂRĂ comentarii înainte sau după text

Livrează doar HTML curat: paragraful de intro + câte un <h2> cu un <p> pentru fiecare regiune. Nimic altceva.
TXT;
    }
}
