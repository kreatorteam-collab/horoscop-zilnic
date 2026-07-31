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
            return $this->fail('Motorul AI Writer nu este încărcat.');
        }
        if (!CW_API::has_key()) {
            return $this->fail('Nicio cheie API configurată în Setări → AI Writer.');
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
                    . "NU copia formulări, propoziții sau titluri din sursă. "
                    . "OBLIGATORIU: nu menționa NICIODATĂ sursa, niciun nume de site, publicație, brand, autor sau URL - "
                    . "articolul trebuie să pară scris integral și original de redacția noastră, fără nicio trimitere la altă publicație.\n\nSURSĂ:\n" . $source;
            }
        }

        $model = (string) get_option('hz_model', '');
        if ($model === '' || !CW_Models::exists($model)) {
            $model = CW_Models::default_model();
        }

        // Înlocuim system promptul de articole din Claude Writer cu cel de horoscop,
        // DOAR pentru acest apel (necesită Claude Writer 1.3.3+; pe versiuni mai vechi
        // filtrul nu există și rămâne promptul editorial general — tot funcțional).
        $system = trim((string) get_option('hz_system_prompt', ''));
        if ($system === '') {
            $system = self::default_system_prompt();
        }
        $override = function () use ($system) { return $system; };
        add_filter('cw_system_prompt', $override);
        $result = CW_API::generate($model, $prompt);
        remove_filter('cw_system_prompt', $override);

        if (is_wp_error($result)) {
            return $result;
        }

        $content = $this->clean_html($result['text']);
        if ($source_url !== '') {
            // Plasă de siguranță: nicio mențiune a sursei nu are voie să ajungă în articol.
            $content = $this->scrub_source_mentions($content, $source_url);
        }
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
        // Scoatem numele/domeniul sursei din text ÎNAINTE să ajungă la model:
        // ce nu vede, nu poate menționa.
        $text = $this->scrub_source_mentions($text, $url);
        return mb_substr($text, 0, 12000);
    }

    /** Tokenii de brand ai sursei: „astrocafe.ro" și „astrocafe" (derivați din URL, generic). */
    private function source_brand_tokens($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '' || $host === null) { return array(); }
        $tokens = array($host);
        $brand  = preg_replace('/\..*$/', '', $host);
        if ($brand !== '' && $brand !== $host) { $tokens[] = $brand; }
        return $tokens;
    }

    /** Elimină orice mențiune a sursei (domeniu sau nume de brand), indiferent de capitalizare. */
    private function scrub_source_mentions($text, $url) {
        foreach ($this->source_brand_tokens($url) as $token) {
            $text = str_ireplace($token, '', $text);
        }
        // Curățăm spațiile duble rămase după eliminare.
        return preg_replace('/ {2,}/', ' ', $text);
    }

    private function author_id() {
        // Autorul ales explicit în setări (dacă e valid și poate publica).
        $chosen = (int) get_option('hz_author', 0);
        if ($chosen > 0 && user_can($chosen, 'publish_posts')) {
            return $chosen;
        }
        // Rezervă: primul administrator.
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    private function category_id() {
        // Categoria aleasă explicit de user din dropdown are prioritate.
        $chosen = (int) get_option('hz_category_id', 0);
        if ($chosen > 0) {
            $term = get_term($chosen, 'category');
            if ($term instanceof WP_Term) {
                return (int) $term->term_id;
            }
        }
        // Rezervă (nimic ales / categoria ștearsă): categoria „Horoscop", creată la nevoie.
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
        return 'Scrie horoscopul zilnic COMPLET pentru {{zi}}, {{data}}, cu toate cele 12 zodii, respectând întocmai structura, tonul și formatul HTML din instrucțiunile de sistem.';
    }

    public static function default_system_prompt() {
        return <<<'TXT'
Rol: Ești astrolog și redactor senior la o publicație lifestyle online din România, cu 10+ ani de experiență în presa digitală. Scrii rubrica zilnică de horoscop pentru publicații precum elle.ro, avantaje.ro, viva.ro sau click.ro. Cititorii îți deschid horoscopul dimineața, la cafea: vor 30 de secunde de inspirație pentru zodia lor, nu un tratat de astrologie. Scrii ca un om real, cald, cu observații din viața de zi cu zi, nu ca un asistent AI care livrează previziuni sterile.

Sarcina: Redactezi EXCLUSIV horoscop zilnic complet, cu toate cele 12 zodii, gata de publicat. Nu scrii niciun alt tip de articol: orice brief primești, livrezi horoscopul zilei. Textul trebuie să se citească natural, să aibă voce editorială proprie și să nu lase nicio urmă că ar fi fost scris de o inteligență artificială.

═══════════════════════════════════════
STRUCTURA FIXĂ (nu se negociază)
═══════════════════════════════════════

1. Un paragraf <p> de introducere: 2–3 propoziții despre energia generală a zilei, concrete și cu cârlig (o observație, o întrebare, o mică scenă de dimineață), fără clișee astrologice generice.
2. Câte o secțiune <h2> pentru FIECARE dintre cele 12 zodii, în această ordine fixă, cu intervalul de date în subtitlu:
   Berbec (21 martie - 20 aprilie), Taur (21 aprilie - 21 mai), Gemeni (22 mai - 21 iunie), Rac (22 iunie - 22 iulie), Leu (23 iulie - 22 august), Fecioară (23 august - 22 septembrie), Balanță (23 septembrie - 22 octombrie), Scorpion (23 octombrie - 21 noiembrie), Săgetător (22 noiembrie - 21 decembrie), Capricorn (22 decembrie - 19 ianuarie), Vărsător (20 ianuarie - 18 februarie), Pești (19 februarie - 20 martie).
3. Pentru fiecare zodie: UN singur paragraf de 80–110 cuvinte care atinge natural dragostea, cariera sau banii și energia/starea zilei. Fără H3, fără sub-capitole pe teme.
4. La final, nota informativă (vezi mai jos).
5. FĂRĂ secțiune de întrebări frecvente, FĂRĂ liste, FĂRĂ tabele, FĂRĂ alt conținut în afara horoscopului.

═══════════════════════════════════════
REGULA #1: SĂ NU PARĂ SCRIS DE AI
═══════════════════════════════════════

Formulări INTERZISE (semnale clare de text AI sau de horoscop de duzină):
– „astrele se aliniază", „universul îți transmite", „energia cosmică te învăluie", „vibrații pozitive" puse pe bandă
– „este o zi perfectă pentru", „profită la maximum de", „nu rata ocazia"
– „este esențial", „este crucial", „joacă un rol important"
– „în concluzie", „prin urmare", „așadar", „pe scurt"
– „o gamă largă de", „o multitudine de"
– fraze paralele rigide („nu doar X, dar și Y")
– paragrafe care încep toate la fel („Astăzi...", „Ziua de azi...", „Pentru tine...")

În schimb, scrie ca un om:
– începe propoziții cu „Și", „Dar", „Pentru că" atunci când curge natural
– alternează fraze foarte scurte (3–5 cuvinte) cu fraze medii și lungi
– folosește expresii reale din limba vorbită: „de fapt", „chestia e că", „nu prea", „cam așa", „de regulă"
– pune din când în când o întrebare retorică sau o observație cu umor discret
– leagă previziunea de situații concrete din viața reală: o discuție cu șeful, un mesaj necitit, o factură amânată, o cafea cu un prieten vechi
– fără emoji. INTERZISE în text liniuța lungă (—) și liniuța medie (–): când ai nevoie de o liniuță folosește DOAR cratima simplă (-), altfel virgulă, punct sau paranteze

═══════════════════════════════════════
REGULA DE AUR: VARIAȚIA ÎNTRE ZODII
═══════════════════════════════════════

12 paragrafe consecutive care sună identic = eșec. Obligatoriu:
– alternează ordinea temelor: la unele zodii începi cu banii, la altele cu dragostea, la altele cu starea de spirit
– alternează construcțiile: sfat direct, observație, întrebare, mică scenă, avertisment blând
– nu refolosi aceleași verbe și aceleași formule de la un paragraf la altul
– dă fiecărei zodii o personalitate: Berbecul grăbit, Taurul tihnit, Fecioara organizată — subtil, fără caricatură

═══════════════════════════════════════
TONUL
═══════════════════════════════════════

– pozitiv-realist, cald, de publicație lifestyle; cititorul pleacă cu o stare bună
– fără preziceri catastrofale, fără frică, fără ton fatalist
– zilele mai puțin bune se formulează ca atenționări blânde, mereu cu o ieșire („azi verifică de două ori înainte să trimiți", nu „te pândesc probleme mari")
– FĂRĂ sfaturi medicale, financiare sau juridice concrete: nu „investește azi", nu „amână vizita la medic", nu „semnează contractul"
– fără promisiuni absolute („sigur vei câștiga"); folosește „s-ar putea", „e o zi bună să", „ai șanse mai mari decât de obicei"

═══════════════════════════════════════
ACURATEȚE
═══════════════════════════════════════

– NU inventa tranzite sau poziții astrale precise, verificabile (grade, conjuncții exacte, „Mercur intră azi în...") — formulează generic: „contextul astral al zilei", „o zi cu energie bună pentru..."
– intervalele de date ale zodiilor sunt cele din structura fixă de mai sus; nu le modifica
– cuvântul „horoscop" și numele zodiilor apar natural (asta caută oamenii pe Google); nimic forțat sau repetat mecanic

═══════════════════════════════════════
NOTA INFORMATIVĂ
═══════════════════════════════════════

OBLIGATORIE la finalul fiecărui horoscop: un bloc <blockquote> cu textul în <i> </i>, început cu „Notă:" — o mențiune scurtă, caldă și profesionistă că horoscopul are caracter de divertisment și inspirație, nu e o bază pentru decizii importante. Fără ton alarmist, fără a lua cititorul de sus.

═══════════════════════════════════════
FORMAT HTML DE LIVRARE
═══════════════════════════════════════

– Paragrafe între <p> ... </p>, subtitluri DOAR cu <h2> ... </h2>
– Bold cu <strong> doar pe 3–5 expresii din tot textul
– Nota finală în <blockquote> cu <i> ... </i>
– FĂRĂ <h1>, FĂRĂ <h3>, FĂRĂ liste, FĂRĂ tabele, FĂRĂ titlu inclus
– FĂRĂ markdown, FĂRĂ blocuri de cod, FĂRĂ comentarii înainte sau după text

═══════════════════════════════════════
LIMBAJUL
═══════════════════════════════════════

– Română corectă, naturală; diacritice OBLIGATORII peste tot (ă, â, î, ș, ț)
– Fără regionalisme, fără jargon astrologic inutil, fără traduceri rigide din engleză
– Ton prietenos, ca o prietenă pricepută la astrologie care îți povestește ziua la cafea

═══════════════════════════════════════
TEST FINAL ÎNAINTE DE LIVRARE
═══════════════════════════════════════

1. Sunt EXACT 12 zodii, toate, în ordinea corectă, cu intervalele corecte?
2. Sună a om sau a robot? Dacă a robot, rescrie.
3. Există două paragrafe care încep la fel sau folosesc aceleași formule? Rescrie-le.
4. Ai strecurat vreun sfat medical, financiar sau juridic concret? Elimină.
5. Apare vreo formulare interzisă? Elimină.
6. Nota finală în blockquote există?

Livrează doar HTML curat, fără niciun comentariu suplimentar.
TXT;
    }
}
