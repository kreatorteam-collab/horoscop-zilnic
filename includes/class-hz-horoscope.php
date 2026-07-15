<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Horoscop zilnic automat: un cron WP generează și publică în fiecare zi
 * un articol cu toate cele 12 zodii. Generarea se face prin pluginul
 * Claude Writer (CW_API): aceeași cheie per site, cost tracking,
 * limită lunară și continuare automată.
 *
 * Opțional, pornește de la o sursă externă (URL): textul e preluat
 * și rescris complet, nu copiat.
 */
class HZ_Horoscope {

    const HOOK = 'hz_horoscope_daily';
    const LOCK = 'hz_horo_lock';

    public function init() {
        add_action(self::HOOK, array($this, 'cron_run'));
        add_action('init', array($this, 'maybe_schedule'));
        add_action('wp_ajax_hz_test', array($this, 'ajax_test'));

        // La schimbarea orei sau la oprire, reprogramăm/curățăm cronul.
        add_action('update_option_hz_hour', array($this, 'reschedule'), 10, 0);
        add_action('update_option_hz_enabled', array($this, 'reschedule'), 10, 0);
    }

    /* ---- Programare cron ---- */

    public function maybe_schedule() {
        if (!(int) get_option('hz_enabled', 0)) {
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
        // maybe_schedule() reprogramează la următorul request, cu noile setări.
    }

    /** Următoarea apariție a orei configurate, în fusul orar al site-ului. */
    private function next_run_ts() {
        $hour = max(0, min(23, (int) get_option('hz_hour', 7)));
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

    /**
     * @param bool $test true = rulare manuală de probă (creează DRAFT, ignoră dedup-ul zilnic)
     * @return array|WP_Error array('post_id','edit_link','cost') la succes
     */
    public function run($test = false) {
        if (!$test && !(int) get_option('hz_enabled', 0)) {
            return new WP_Error('hz_off', 'Horoscopul zilnic este dezactivat.');
        }
        if (!class_exists('CW_API')) {
            return $this->fail('Pluginul Claude Writer nu este activ.');
        }
        if (!CW_API::has_key()) {
            return $this->fail('Cheia API Anthropic nu este configurată în Claude Writer.');
        }

        $today = current_time('Y-m-d');
        if (!$test && get_option('hz_last_run', '') === $today) {
            return new WP_Error('hz_done', 'Horoscopul de azi există deja.');
        }
        if (get_transient(self::LOCK)) {
            return new WP_Error('hz_lock', 'O generare este deja în curs.');
        }
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);

        $result = $this->generate_post($test);

        delete_transient(self::LOCK);

        if (is_wp_error($result)) {
            return $this->fail($result->get_error_message());
        }
        if (!$test) {
            update_option('hz_last_run', $today, 'no');
        }
        update_option('hz_last_error', '', 'no');
        return $result;
    }

    private function fail($message) {
        update_option('hz_last_error', current_time('Y-m-d H:i') . ' — ' . $message, 'no');
        return new WP_Error('hz_fail', $message);
    }

    private function generate_post($test) {
        $data = date_i18n('j F Y', current_time('timestamp'));
        $zi   = date_i18n('l', current_time('timestamp'));

        $prompt = (string) get_option('hz_prompt', '');
        if (trim($prompt) === '') {
            $prompt = self::default_prompt();
        }
        $prompt = str_replace(array('{{data}}', '{{zi}}'), array($data, $zi), $prompt);

        // Sursă externă opțională: preluăm textul și cerem rescriere completă.
        $source_url = trim((string) get_option('hz_source_url', ''));
        if ($source_url !== '') {
            $source = $this->fetch_source($source_url);
            if ($source !== '') {
                $prompt .= "\n\nFolosește DOAR ca sursă de inspirație pentru previziuni horoscopul de mai jos. "
                    . "RESCRIE totul complet, în cuvintele tale, cu structura cerută mai sus. "
                    . "NU copia formulări, propoziții sau titluri din sursă.\n\nSURSĂ:\n" . $source;
            }
        }

        $model = (string) get_option('hz_model', '');
        if ($model === '' || !CW_Models::exists($model)) {
            $model = CW_Models::default_model();
        }

        $result = CW_API::generate($model, $prompt);
        if (is_wp_error($result)) {
            return $result;
        }

        $content = $this->clean_html($result['text']);
        if (strlen($content) < 500 || stripos($content, '<h2') === false) {
            return new WP_Error('hz_short', 'Conținut generat invalid sau prea scurt (' . strlen($content) . ' caractere).');
        }

        $title = (string) get_option('hz_title', '');
        if (trim($title) === '') {
            $title = self::default_title();
        }
        $title = str_replace(array('{{data}}', '{{zi}}'), array($data, $zi), $title);

        $status = $test ? 'draft' : (string) get_option('hz_status', 'publish');
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) {
            $status = 'publish';
        }

        // wp_insert_post așteaptă date „slashed" (le trece prin wp_unslash intern).
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
        update_post_meta($post_id, '_hz_horoscope', current_time('Y-m-d'));

        // Imagine reprezentativă: un attachment din Media, refolosit zilnic (opțional).
        $image_id = (int) get_option('hz_image', 0);
        if ($image_id > 0 && wp_attachment_is_image($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }

        return array(
            'post_id'   => $post_id,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'cost'      => isset($result['cost']) ? (float) $result['cost'] : 0.0,
        );
    }

    /** Scoate eventualele garduri ```html ... ``` și un H1/titlu rătăcit la început. */
    private function clean_html($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = preg_replace('/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text);
        return trim($text);
    }

    private function fetch_source($url) {
        $response = wp_remote_get($url, array(
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; HoroscopZilnic/' . HZ_VERSION . ')',
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $html = (string) wp_remote_retrieve_body($response);
        // Eliminăm script/style înainte de strip, altfel rămâne cod în text.
        $html = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
        return mb_substr($text, 0, 12000);
    }

    private function author_id() {
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    private function category_id() {
        $name = trim((string) get_option('hz_category', ''));
        if ($name === '') { $name = 'Horoscop'; }
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
        // Generarea durează 30–120s; ridicăm limita unde serverul permite.
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
        return 'https://www.astrocafe.ro/horoscop/zilnic';
    }

    public static function default_title() {
        return 'Horoscop {{zi}}, {{data}}. Previziuni pentru toate zodiile';
    }

    public static function default_prompt() {
        return <<<'TXT'
Scrie horoscopul zilnic COMPLET pentru {{zi}}, {{data}}.

Structura EXACTĂ (înlocuiește regulile de structură și de lungime din instrucțiunile de sistem):
– Începe cu un <p> scurt de introducere: 2–3 propoziții despre energia generală a zilei, concrete, fără clișee astrologice generice.
– Apoi câte o secțiune <h2> pentru FIECARE dintre cele 12 zodii, în această ordine: Berbec, Taur, Gemeni, Rac, Leu, Fecioară, Balanță, Scorpion, Săgetător, Capricorn, Vărsător, Pești. În H2 pune zodia și intervalul de date, ex.: „Berbec (21 martie – 20 aprilie)".
– Pentru fiecare zodie: UN paragraf de 80–110 cuvinte care atinge natural dragostea, cariera sau banii și energia/starea zilei. Fără sub-subtitluri pe capitole.
– Variază tonul și construcțiile între zodii: nu începe fiecare paragraf la fel, nu folosi aceleași verbe și aceleași formule la toate.
– Ton pozitiv-realist, cald, de publicație lifestyle. Fără preziceri catastrofale, fără sfaturi medicale sau financiare concrete, fără promisiuni absolute.
– NU adăuga secțiune de întrebări frecvente.
– Încheie cu nota informativă obligatorie în <blockquote> cu <i>, formulată pentru horoscop: caracter de divertisment, nu bază pentru decizii importante.
Livrează DOAR HTML curat (p, h2, strong, i, blockquote), fără markdown, fără blocuri de cod, fără H1 și fără titlu.
TXT;
    }
}
