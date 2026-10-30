<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Comandă de la distanță pentru modulul Articole Bulk — panoul „AI Writer flotă"
 * de pe app.targetseo.ro trimite aici loturi de articole (titluri + categorie +
 * linkurile/ancorele cerute), citește progresul și poate porni/opri coada.
 *
 * Autentificare: HMAC-SHA256 pe corpul cererii, cu `magpress_deploy_secret` —
 * secretul per site pe care tema Magpress îl generează și îl trimite prin ping
 * către targetseo.ro. Același mecanism ca /magpress/v1/deploy, deci nu introducem
 * o cheie nouă de administrat. Fără temă Magpress (secret absent) endpointul e mort.
 *
 * Rute (toate POST, corp JSON):
 *   hz/v1/info     -> versiune plugin, chei configurate, categorii, starea coadei
 *   hz/v1/enqueue  -> adaugă articole în coadă
 *   hz/v1/status   -> progresul unui lot
 *   hz/v1/control  -> start | stop | retry | clear
 *   hz/v1/settings -> suprascrie chei API, cheia Magnific, modelul implicit, limitele
 *   hz/v1/costs    -> cheltuiala AI pe zi, defalcata pe modul si pe model
 *   hz/v1/reimage  -> reface pozele articolelor deja publicate dintr-un lot (alt stil/sursa)
 *   hz/v1/upgrade  -> v3.53.0: actualizeaza pluginul ACUM din repo-ul GitHub (nu mai
 *                     astepti auto-update-ul WP, care verifica doar de 2 ori pe zi)
 */
class HZ_Remote {

    /** Fereastra acceptată pentru marcajul de timp din cerere (contra reluării). */
    const MAX_SKEW = 900;

    public function init() {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register() {
        $routes = array('info', 'enqueue', 'status', 'control', 'settings', 'rewrite', 'golinks', 'trash', 'costs', 'reimage', 'upgrade');
        foreach ($routes as $route) {
            register_rest_route('hz/v1', '/' . $route, array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_' . $route),
                'permission_callback' => '__return_true', // validăm HMAC manual
            ));
        }
    }

    /* ---------- autentificare ---------- */

    /** @return array|WP_REST_Response payload-ul decodat sau răspunsul de eroare. */
    private function auth(WP_REST_Request $req) {
        $secret = (string) get_option('magpress_deploy_secret', '');
        if (strlen($secret) < 32) {
            return new WP_REST_Response(array('error' => 'no_secret'), 403);
        }
        $body = (string) $req->get_body();
        $sig  = (string) $req->get_header('X-Magpress-Signature');
        if ($sig === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $sig)) {
            return new WP_REST_Response(array('error' => 'bad_signature'), 403);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_REST_Response(array('error' => 'bad_body'), 400);
        }
        $ts = isset($data['ts']) ? (int) $data['ts'] : 0;
        if ($ts > 0 && abs(time() - $ts) > self::MAX_SKEW) {
            return new WP_REST_Response(array('error' => 'stale_request'), 403);
        }
        return $data;
    }

    /* ---------- rute ---------- */

    /** Ce poate face site-ul acum: chei, categorii, coadă, setări implicite. */
    /**
     * Cheltuiala AI a site-ului: totalul lunar, defalcarea pe model (istorica) și
     * evidența ZILNICĂ pe module (bulk, review, imagini, horoscop, vreme, rewrite,
     * editor). Datele zilnice există doar de la v3.22.0 încoace — înainte nu se
     * înregistra cine cheltuie, doar cât.
     */
    /**
     * Reface pozele articolelor deja publicate dintr-un lot, cu alt stil/sursa.
     * Body: { batch, style, source, after, max }. Se apeleaza REPETAT (dupa `after`)
     * pana cand `done` = true.
     */
    public function handle_reimage(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $batch = isset($data['batch']) ? (string) $data['batch'] : '';
        if ($batch === '') {
            return new WP_REST_Response(array('error' => 'no_batch'), 400);
        }

        $bulk = new HZ_Bulk();
        $res  = $bulk->reimage_batch($batch, array(
            'style'  => isset($data['style']) ? (string) $data['style'] : '',
            'source' => isset($data['source']) ? sanitize_key((string) $data['source']) : '',
            'after'  => isset($data['after']) ? (int) $data['after'] : 0,
            'max'    => isset($data['max']) ? (int) $data['max'] : 3,
            'only_with_image' => isset($data['only_with_image']) ? (int) $data['only_with_image'] : 1,
            'mode'   => isset($data['mode']) ? sanitize_key((string) $data['mode']) : '',
        ));

        return array_merge(array('ok' => true), $res);
    }

    public function handle_costs(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        if (!class_exists('CW_Usage')) {
            return array('ok' => false, 'error' => 'writer_missing');
        }

        $days = isset($data['days']) ? (int) $data['days'] : 30;
        $daily = CW_Usage::daily($days);

        $monthly = get_option('cw_cost_monthly', array());
        $by      = get_option('cw_usage_by_model', array());

        return array(
            'ok'             => true,
            'plugin_version' => defined('HZ_VERSION') ? HZ_VERSION : '',
            'site_name'      => get_bloginfo('name'),
            'zi_curenta'     => date('Y-m-d'),
            'luna_curenta'   => CW_Usage::month_cost(),
            'limita_lunara'  => (float) get_option('cw_monthly_cost_limit', 0),
            'total_apeluri'  => (int) get_option('cw_calls_total', 0),
            'total_cost'     => (float) get_option('cw_cost_total', 0),
            'model_implicit' => (string) get_option('cw_default_model', ''),
            'lunar'          => is_array($monthly) ? $monthly : array(),
            'pe_model'       => is_array($by) ? $by : array(),
            'zilnic'         => $daily,
            'contexte'       => CW_Usage::contexts(),
        );
    }

    public function handle_info(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $cats = array();
        foreach (get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 200)) as $term) {
            if (!($term instanceof WP_Term)) { continue; }
            $cats[] = array(
                'id'    => (int) $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => (int) $term->count,
            );
        }
        usort($cats, function ($a, $b) { return $b['count'] - $a['count']; });

        $bulk  = new HZ_Bulk();
        $state = $bulk->batch_status('', false); // doar contoarele, fără lista de articole

        return array(
            'ok'              => true,
            'plugin_version'  => defined('HZ_VERSION') ? HZ_VERSION : '',
            'site_name'       => get_bloginfo('name'),
            // Panoul Profitshare întreabă înainte de a trimite un lot „review": pe
            // site-urile rămase pe o versiune veche modul lipsește, iar lotul ar fi
            // scris ca articol obișnuit, fără structura pe criterii.
            'review_mode'     => class_exists('HZ_Review'),
            // v3.39.0: cate sabloane de stil a luat site-ul din platforma (0 = nu a
            // ajuns la endpoint sau lista e goala) - se vede in panoul „Poza cu AI".
            'imagegen_presets' => class_exists('HZ_ImageGen') ? count(HZ_ImageGen::presets()) : 0,
            // Câte linkuri de afiliere are site-ul în redirector (0 = niciunul, deci
            // articolele cu produse ar duce în gol).
            'go_links'        => class_exists('HZ_GoLinks') ? count(HZ_GoLinks::map()) : 0,
            'ai_key'          => class_exists('CW_API') && CW_API::has_key(),
            // Amprentele cheilor OpenAI pe module (ultimele 4 caractere), ca sa se poata
            // verifica de la distanta ce cheie foloseste fiecare, fara sa expuna cheia.
            'openai_keys'     => class_exists('CW_API') ? array(
                'implicit' => CW_API::openai_key_last4(''),
                'horoscop' => CW_API::openai_key_last4('horoscop'),
                'vreme'    => CW_API::openai_key_last4('vreme'),
                'imagini'  => CW_API::openai_key_last4('imagini'),
            ) : array(),
            'ai_openai'       => class_exists('CW_API') && CW_API::has_provider_key('openai'),
            'openai_base'     => class_exists('CW_API') ? CW_API::openai_base() : '',
            'ai_anthropic'    => class_exists('CW_API') && CW_API::has_provider_key('anthropic'),
            'ai_limit_hit'    => class_exists('CW_Usage') ? CW_Usage::limit_reached() : false,
            'ai_model'        => class_exists('CW_API') ? CW_API::resolve_model('') : '',
            'ai_model_set'    => (string) get_option('cw_default_model', ''),
            // Amprenta prompturilor editoriale: cât de lungi sunt, un hash scurt (ca
            // să vedem care site-uri au ACELAȘI text) și dacă mai sunt pe varianta
            // implicită a pluginului. Promptul în sine NU pleacă de pe site.
            'prompts'         => self::prompt_fingerprints(),
            // Textul integral al prompturilor, DOAR la cerere explicită (`with_prompts`)
            // — panoul are nevoie de el ca să pornească de la ce e deja pe site, nu ca
            // să-l suprascrie orbește. Apelul e semnat HMAC, ca tot restul rutei.
            'prompt_text'     => empty($data['with_prompts']) ? null : array(
                'system'  => (string) get_option('cw_system_prompt', ''),
                'article' => (string) get_option('cw_article_prompt', ''),
                'system_prev'  => (string) get_option('cw_system_prompt_prev', ''),
                'article_prev' => (string) get_option('cw_article_prompt_prev', ''),
            ),

            // Conținutul zilnic: pornit/oprit, ora, ultima rulare și modelul propriu
            // (gol = folosește modelul implicit al site-ului). Panoul poate spune
            // astfel CARE site-uri consumă zilnic, nu doar ce model au setat.
            'horoscope_on'    => (int) get_option('hz_enabled', 0),
            'horoscope_hour'  => (int) get_option('hz_hour', 7),
            'horoscope_last'  => (string) get_option('hz_last_run', ''),
            'horoscope_model' => (string) get_option('hz_model', ''),
            'weather_on'      => (int) get_option('hzw_enabled', 0),
            'weather_hour'    => (int) get_option('hzw_hour', 8),
            'weather_last'    => (string) get_option('hzw_last_run', ''),
            'weather_model'   => (string) get_option('hzw_model', ''),
            // v3.50.0: modulul Știri (digi24 rescris).
            'news_on'         => (int) get_option('hzn_enabled', 0),
            'news_per_cat'    => (int) get_option('hzn_per_cat', 5),
            'news_per_section'=> class_exists('HZ_News') ? array_combine(array_keys(HZ_News::sections()), array_map(array('HZ_News', 'per_day'), array_keys(HZ_News::sections()))) : array(),
            'news_model'      => (string) get_option('hzn_model', ''),
            'news_last'       => (string) get_option('hzn_last_run', ''),
            'news_error'      => (string) get_option('hzn_last_error', ''),
            'ai_month_cost'   => class_exists('CW_Usage') ? round((float) CW_Usage::month_cost(), 4) : 0,
            'ai_month_limit'  => (float) get_option('cw_monthly_cost_limit', 0),
            'web_search'      => (int) get_option('cw_web_search', 0),
            'web_search_max'  => (int) get_option('cw_web_search_max', 3),
            'models'          => class_exists('CW_Models') ? self::model_list() : array(),
            'magnific_key'    => HZ_Magnific::has_key(),
            // Amprenta cheii Magnific (acelasi hash ca in contorul central) — panoul
            // poate spune CARE cheie e pe site, fara sa vada niciodata cheia.
            'magnific_hash'   => HZ_Magnific::key_hash(),
            'magnific_limit'  => HZ_Magnific::daily_limit(),
            'magnific_left'   => HZ_Magnific::has_key()
                ? max(0, HZ_Magnific::daily_limit() - HZ_Magnific::account_downloads_today())
                : 0,
            // Etnia impusă persoanelor din poze, gol = fără filtru (stock + imagini AI).
            'people_ethnicity'=> HZ_Magnific::ethnicity(),
            // Ultima rotire automata de cheie Magnific (gol = n-a fost nevoie).
            'magnific_rotated'=> HZ_Magnific::last_rotation(),
            'categories'      => $cats,
            'default_category'=> (int) get_option('default_category', 1),
            'defaults'        => array(
                'images' => (int) get_option('hzb_images', 1),
                'length' => (int) get_option('hzb_length', 1000),
                'gap'    => (int) get_option('hzb_gap', 120),
            ),
            'queue_pending'   => (int) $state['queue_pending'],
            'stopped'         => (int) $state['stopped'],
            'paused'          => (string) $state['paused'],
        );
    }

    /**
     * Adaugă articole în coadă.
     * Body: { batch, status, model, extra, length, images, gap, start,
     *         items: [ { title, category_id, links: [{anchor,url,rel}] } ] }
     */
    public function handle_enqueue(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        if (!class_exists('CW_API') || !CW_API::has_key()) {
            return new WP_REST_Response(array('error' => 'no_ai_key',
                'message' => 'Site-ul nu are cheie API (OpenAI/Anthropic) configurată.'), 400);
        }
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        if (empty($items)) {
            return new WP_REST_Response(array('error' => 'no_items'), 400);
        }
        if (count($items) > 200) {
            return new WP_REST_Response(array('error' => 'too_many', 'message' => 'Maximum 200 de articole per lot.'), 400);
        }

        $rows = array();
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $rows[] = array(
                'title'    => isset($it['title']) ? sanitize_text_field((string) $it['title']) : '',
                'cat'      => isset($it['category_id']) ? (int) $it['category_id'] : 0,
                'cat_name' => isset($it['category_name']) ? sanitize_text_field((string) $it['category_name']) : '',
                'links'    => isset($it['links']) ? $it['links'] : array(),
                // v3.16.0 — produsele articolului editorial (mode=review). Curățarea o
                // face HZ_Review::sanitize_products, în add_items.
                'products' => isset($it['products']) ? $it['products'] : array(),
            );
        }

        // Ritmul dintre articole e o setare de site, dar lotul îl poate schimba.
        if (isset($data['gap']) && (int) $data['gap'] > 0) {
            update_option('hzb_gap', max(5, min(3600, (int) $data['gap'])), 'no');
        }
        // Dacă procesarea e oprită pe site, lotul ar sta pe loc. Panoul cere explicit
        // pornirea (start=1) ca să nu repornim tăcut ceva oprit intenționat.
        if (!empty($data['start'])) {
            update_option('hzb_stopped', 0, 'no');
        }

        $bulk = new HZ_Bulk();
        $res  = $bulk->add_items($rows, array(
            'status' => isset($data['status']) ? sanitize_text_field((string) $data['status']) : 'draft',
            'model'  => isset($data['model']) ? sanitize_text_field((string) $data['model']) : '',
            'extra'  => isset($data['extra']) ? sanitize_textarea_field((string) $data['extra']) : '',
            'batch'  => isset($data['batch']) ? (string) $data['batch'] : '',
            'length' => isset($data['length']) ? (int) $data['length'] : 0,
            'images' => isset($data['images']) ? $data['images'] : '',
            // Sursa imaginilor pentru lot: 'ai' = generare OpenAI, 'magnific' = stock. Gol = setarea site-ului.
            'img_source' => isset($data['img_source']) ? sanitize_key((string) $data['img_source']) : '',
            // Stilul comun al imaginilor pentru lot (ex. „fotografii de mancare, vedere de sus").
            'img_style'  => isset($data['img_style']) ? sanitize_textarea_field((string) $data['img_style']) : '',
            // Căutare web pentru lotul ăsta: absent = setarea site-ului.
            'web'    => isset($data['web']) ? $data['web'] : '',
            // Data de publicare pentru lotul ăsta: absent = setarea site-ului, 0 = azi, 1 = backdate.
            'random_date' => isset($data['random_date']) ? $data['random_date'] : '',
            // Categoria lipsă se creează DOAR dacă panoul cere explicit.
            'create_category' => !empty($data['create_category']),
            // v3.16.0: 'review' = articol editorial cu produse (criterii de alegere +
            // recomandări argumentate + linkuri sponsored). Gol = articolul obișnuit.
            'mode'   => isset($data['mode']) ? sanitize_key((string) $data['mode']) : '',
        ));

        return array(
            'ok'      => true,
            'added'   => (int) $res['added'],
            'skipped' => (int) $res['skipped'],
            'queued'  => (int) $res['queued'],
            'stopped' => (int) get_option('hzb_stopped', 0),
            'created_categories' => $res['created_categories'],
        );
    }

    /** Progresul unui lot (sau al întregii coade dacă batch lipsește). */
    public function handle_status(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $batch = isset($data['batch']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $data['batch']) : '';
        $bulk  = new HZ_Bulk();
        $state = $bulk->batch_status($batch);
        $state['ok'] = true;
        // Contorul LOCAL, nu cel central: /status e interogat des din panou, iar
        // contorul central e un apel HTTP (6s timeout) — nu-l punem pe drumul critic.
        $state['magnific_left'] = HZ_Magnific::has_key()
            ? max(0, HZ_Magnific::daily_limit() - HZ_Magnific::downloads_today())
            : 0;
        return $state;
    }

    /** Modelele pe care le cunoaște pluginul: id => etichetă (pentru dropdown-ul din panou). */
    private static function model_list() {
        $out = array();
        foreach (CW_Models::all() as $id => $m) {
            $out[] = array(
                'id'       => $id,
                'label'    => isset($m['label']) ? $m['label'] : $id,
                'provider' => isset($m['provider']) ? $m['provider'] : '',
                'in'       => isset($m['in']) ? (float) $m['in'] : 0,
                'out'      => isset($m['out']) ? (float) $m['out'] : 0,
            );
        }
        return $out;
    }

    /**
     * Suprascrie setările site-ului din panoul central.
     * Body: { set: { openai_key, openai_key_horoscope, openai_key_weather, openai_key_images,
     *                anthropic_key, magnific_key, model, monthly_limit,
     *                magnific_daily_limit, images, length, gap },
     *         clear: [ 'magnific_key', ... ] }
     *
     * Cheile se scriu ÎN CLAR prin update_option: filtrele `pre_update_option_cw_*` /
     * `pre_update_option_hzb_magnific_key` (înregistrate la plugins_loaded, deci active
     * și în REST) le criptează exact o dată. NU cripta aici — ar ieși dublu criptat.
     * Un câmp lipsă sau gol nu atinge valoarea existentă.
     */
    public function handle_settings(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $set   = isset($data['set']) && is_array($data['set']) ? $data['set'] : array();
        $clear = isset($data['clear']) && is_array($data['clear']) ? $data['clear'] : array();
        if (empty($set) && empty($clear)) {
            return new WP_REST_Response(array('error' => 'nothing_to_set'), 400);
        }

        $changed = array();
        $skipped = array();

        $secrets = array(
            'openai_key'           => 'cw_openai_api_key',
            'openai_key_horoscope' => 'cw_openai_key_horoscope',
            'openai_key_weather'   => 'cw_openai_key_weather',
            'openai_key_images'    => 'cw_openai_key_images',
            'anthropic_key'        => 'cw_api_key',
            'magnific_key'         => 'hzb_magnific_key',
        );

        // Ștergere de cheie (rotire / cheie revocată): un câmp gol NU șterge (filtrul
        // păstrează valoarea veche), deci ștergerea trebuie cerută explicit.
        foreach ($clear as $field) {
            if (!isset($secrets[$field])) { continue; }
            delete_option($secrets[$field]);
            $changed[] = 'clear:' . $field;
            unset($set[$field]); // ștergerea bate scrierea, dacă vin amândouă
        }
        foreach ($secrets as $field => $option) {
            if (!isset($set[$field])) { continue; }
            $value = trim((string) $set[$field]);
            if ($value === '') { continue; } // gol = păstrează cheia existentă
            update_option($option, $value);
            $changed[] = $field;
        }

        // Modelul implicit al site-ului + modelele PROPRII ale conținutului zilnic.
        // Horoscopul și vremea au opțiuni separate (gol = folosesc modelul implicit),
        // deci pot rula pe ceva ieftin fără să atingem modelul editorial.
        $models = array(
            'model'           => 'cw_default_model',
            'horoscope_model' => 'hz_model',
            'weather_model'   => 'hzw_model',
        );

        // Sursa implicita de imagini + modelul de generare AI (text simplu, nu model AI de scriere).
        if (isset($set['img_source']) && in_array($set['img_source'], array('magnific', 'ai'), true)) {
            update_option('hzb_img_source', $set['img_source'], 'no');
            $changed[] = 'img_source';
        }
        // Etnia persoanelor din fotografii: valoare din HZ_Magnific::ETHNICITIES,
        // iar 'any'/'none'/gol = fără filtru (singurul câmp unde golul NU înseamnă
        // „lasă neatins", ca să se poată și opri de la distanță).
        if (isset($set['people_ethnicity'])) {
            $eth = sanitize_key((string) $set['people_ethnicity']);
            if ($eth === 'any' || $eth === 'none') { $eth = ''; }
            if ($eth === '' || in_array($eth, HZ_Magnific::ETHNICITIES, true)) {
                update_option('hzb_people_ethnicity', $eth, 'no');
                $changed[] = 'people_ethnicity';
            } else {
                $skipped[] = 'people_ethnicity necunoscut: ' . $eth;
            }
        }
        foreach (array('imagegen_model' => 'hz_imagegen_model', 'imagegen_size' => 'hz_imagegen_size', 'imagegen_quality' => 'hz_imagegen_quality', 'imagegen_style' => 'hz_imagegen_style', 'imagegen_format' => 'hz_imagegen_format', 'imagegen_compression' => 'hz_imagegen_compression', 'img_subsizes_webp' => 'hz_img_subsizes_webp', 'img_webp_quality' => 'hz_img_webp_quality') as $f => $opt) {
            if (isset($set[$f]) && trim((string) $set[$f]) !== '') {
                update_option($opt, sanitize_text_field((string) $set[$f]), 'no');
                $changed[] = $f;
            }
        }
        foreach ($models as $field => $option) {
            if (!isset($set[$field]) || trim((string) $set[$field]) === '') { continue; }
            $model = sanitize_text_field((string) $set[$field]);
            if (CW_Models::exists($model)) {
                update_option($option, $model, 'no');
                $changed[] = $field;
            } else {
                $skipped[] = $field . ' necunoscut: ' . $model;
            }
        }

        // Prompturile editoriale, împinse pe nișă din panou. Gol = neatins. Varianta
        // dinainte se salvează o dată (`*_prev`), deci revenirea nu cere backup extern.
        $prompts = array(
            'system_prompt'  => 'cw_system_prompt',
            'article_prompt' => 'cw_article_prompt',
        );
        foreach ($prompts as $field => $option) {
            if (!isset($set[$field])) { continue; }
            $value = wp_check_invalid_utf8((string) $set[$field]);
            $value = trim(str_replace(array("
", "
"), "
", $value));
            if ($value === '') { continue; }
            if (strlen($value) > 60000) {
                $skipped[] = $field . ' prea lung (' . strlen($value) . ' caractere)';
                continue;
            }
            // Șablonul de articol FĂRĂ {{continut}} ar arunca brieful (titlul +
            // instrucțiunile lotului) și ar produce articole despre nimic.
            if ($field === 'article_prompt' && strpos($value, '{{continut}}') === false) {
                $skipped[] = 'article_prompt fără {{continut}} - refuzat';
                continue;
            }
            $current = (string) get_option($option, '');
            if ($current !== '' && $current !== $value) {
                update_option($option . '_prev', $current, 'no');
            }
            update_option($option, $value, 'no');
            $changed[] = $field;
        }

        // Modulul Știri: pornit/oprit (0/1 explicit, ca la căutarea web), câte pe secțiune, modelul.
        if (isset($set['news_enabled']) && $set['news_enabled'] !== '') {
            update_option('hzn_enabled', (int) !!$set['news_enabled'], 'no');
            $changed[] = 'news_enabled';
        }
        if (isset($set['news_per_cat']) && $set['news_per_cat'] !== '') {
            update_option('hzn_per_cat', max(1, min(50, (int) $set['news_per_cat'])), 'no');
            $changed[] = 'news_per_cat';
        }
        // v3.53.3: plafon propriu pe secțiune (ex. news_per_externe = 15); 0 = valoarea globală.
        if (class_exists('HZ_News')) {
            foreach (array_keys(HZ_News::sections()) as $key) {
                if (isset($set['news_per_' . $key]) && $set['news_per_' . $key] !== '') {
                    update_option('hzn_per_' . $key, max(0, min(50, (int) $set['news_per_' . $key])), 'no');
                    $changed[] = 'news_per_' . $key;
                }
            }
        }
        if (isset($set['news_model']) && trim((string) $set['news_model']) !== '') {
            $nm = sanitize_text_field((string) $set['news_model']);
            if (CW_Models::exists($nm)) { update_option('hzn_model', $nm, 'no'); $changed[] = 'news_model'; }
            else { $skipped[] = 'news_model necunoscut: ' . $nm; }
        }

        // Serverul API pentru GPT (gateway compatibil OpenAI). 'openai' / 'default' = OpenAI direct.
        if (isset($set['openai_base']) && trim((string) $set['openai_base']) !== '') {
            $base = trim((string) $set['openai_base']);
            if (in_array(strtolower($base), array('openai', 'default'), true)) {
                delete_option('cw_openai_base');
                $changed[] = 'openai_base';
            } elseif (preg_match('#^https://[^\s]+$#i', $base)) {
                update_option('cw_openai_base', esc_url_raw(rtrim($base, '/')), 'no');
                $changed[] = 'openai_base';
            } else {
                $skipped[] = 'openai_base invalid: ' . $base;
            }
        }

        // Căutarea web e un comutator: 0/1 explicit (nu „gol = neatins", altfel n-ai
        // cum să o oprești de la distanță).
        if (isset($set['web_search']) && $set['web_search'] !== '') {
            update_option('cw_web_search', (int) !!$set['web_search'], 'no');
            $changed[] = 'web_search';
        }

        $numbers = array(
            'monthly_limit'        => array('cw_monthly_cost_limit', 0, 10000),
            'web_search_max'       => array('cw_web_search_max', 1, 10),
            'magnific_daily_limit' => array('hzb_daily_limit', 1, 1000),
            'images'               => array('hzb_images', 0, HZ_Bulk::MAX_IMAGES),
            'length'               => array('hzb_length', 300, 4000),
            'gap'                  => array('hzb_gap', 5, 3600),
        );
        foreach ($numbers as $field => $cfg) {
            if (!isset($set[$field]) || $set[$field] === '') { continue; }
            list($option, $min, $max) = $cfg;
            $value = max($min, min($max, (float) $set[$field]));
            update_option($option, $option === 'cw_monthly_cost_limit' ? $value : (int) $value, 'no');
            $changed[] = $field;
        }

        if (empty($changed)) {
            return array('ok' => true, 'changed' => array(), 'skipped' => $skipped,
                         'message' => 'Nimic de schimbat.');
        }

        return array(
            'ok'      => true,
            'changed' => $changed,
            'skipped' => $skipped,
            'after'   => array(
                'ai_openai'      => CW_API::has_provider_key('openai'),
                'ai_anthropic'   => CW_API::has_provider_key('anthropic'),
                'ai_model_set'   => (string) get_option('cw_default_model', ''),
                'horoscope_model'=> (string) get_option('hz_model', ''),
                'weather_model'  => (string) get_option('hzw_model', ''),
                'ai_model'       => CW_API::resolve_model(''),
                'ai_month_limit' => (float) get_option('cw_monthly_cost_limit', 0),
                'web_search'     => (int) get_option('cw_web_search', 0),
                'magnific_key'   => HZ_Magnific::has_key(),
                'magnific_hash'  => HZ_Magnific::key_hash(),
                'magnific_limit' => HZ_Magnific::daily_limit(),
                'people_ethnicity' => HZ_Magnific::ethnicity(),
            ),
        );
    }

    /**
     * Maparea redirectorului de afiliere: { map: { slug: url }, stats: 0|1 }
     *
     * Înlocuiește TOATĂ maparea (vezi HZ_GoLinks::set_map). Fără `map` întoarce doar
     * starea — util ca panoul să vadă ce e pe site fără să scrie nimic.
     */
    public function handle_golinks(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }
        if (!class_exists('HZ_GoLinks')) {
            return new WP_REST_Response(array('error' => 'no_module'), 400);
        }

        $out = array('ok' => true);
        if (isset($data['map']) && is_array($data['map'])) {
            $res = HZ_GoLinks::set_map($data['map']);
            $out['saved']   = (int) $res['count'];
            $out['skipped'] = (int) $res['skipped'];
        }
        $out['links'] = count(HZ_GoLinks::map());
        if (!empty($data['stats'])) {
            $out['hits'] = HZ_GoLinks::hits(isset($data['days']) ? (int) $data['days'] : 30);
        }
        return $out;
    }

    /**
     * Mută la COȘ articolele cu titlurile date. Body: { titles: [...], hard: 0|1 }
     *
     * Deliberat la Coș, nu ștergere definitivă: e o acțiune pe conținut publicat de
     * pe un panou aflat în altă parte, iar recuperarea trebuie să fie posibilă dacă
     * s-a greșit lotul. În plus, bariera anti-dubluri NU se uită în Coș, deci titlul
     * se eliberează oricum pentru regenerare — ștergerea definitivă n-ar aduce nimic
     * în plus, doar risc.
     *
     * `hard: 1` șterge definitiv, dar numai dacă panoul o cere explicit.
     */
    public function handle_trash(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $titles = isset($data['titles']) && is_array($data['titles']) ? $data['titles'] : array();
        if (empty($titles)) {
            return new WP_REST_Response(array('error' => 'no_titles'), 400);
        }
        $hard = !empty($data['hard']);
        $done = array();
        $not_found = array();

        // Potrivire TOLERANTĂ, nu egalitate exactă pe post_title: între titlul comandat
        // din panou și cel salvat de WordPress se pot strecura diferențe de spații, de
        // majuscule sau ghilimele schimbate de wptexturize. Cu egalitate strictă,
        // ștergerea „reușea" raportând zero, ceea ce arată exact ca și cum n-ar merge.
        global $wpdb;
        // Luăm ȘI articolele din coș, separat: „nu există" și „e deja în coș" sunt
        // două răspunsuri complet diferite, iar raportate la fel („negăsite") trimit
        // căutarea problemei în direcția greșită.
        $rows = $wpdb->get_results(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type = 'post'
               AND post_status IN ('publish','draft','pending','future','private','trash')
             ORDER BY ID DESC LIMIT 3000"
        );

        $index = array();
        $trash = array();
        foreach ($rows as $row) {
            $key = self::norm_title($row->post_title);
            if ($row->post_status === 'trash') {
                $trash[$key] = true;
            } else {
                $index[$key][] = (int) $row->ID;
            }
        }

        $already = 0;
        foreach (array_slice($titles, 0, 200) as $t) {
            $key = self::norm_title(wp_strip_all_tags((string) $t));
            if ($key === '') { continue; }
            if (empty($index[$key])) {
                if (!empty($trash[$key])) { $already++; }
                else { $not_found[] = mb_substr((string) $t, 0, 60); }
                continue;
            }
            foreach ($index[$key] as $id) {
                $ok = $hard ? wp_delete_post($id, true) : wp_trash_post($id);
                if ($ok) { $done[] = (int) $id; }
            }
        }

        return array(
            'ok'        => true,
            'trashed'   => count($done),
            // Erau deja în coș: nu e o eroare, e treaba deja făcută.
            'already'   => $already,
            'missing'   => count($not_found),
            // Titlurile chiar negăsite, ca panoul să poată spune CARE - altfel
            // „0 șterse" e un mesaj din care nu poți afla nimic.
            'not_found' => array_slice($not_found, 0, 5),
            'hard'      => $hard ? 1 : 0,
        );
    }

    /**
     * Titlu normalizat pentru comparat: fără majuscule, fără diacritice, cu spațiile
     * strânse și cu ghilimelele/liniuțele „frumoase" aduse la forma simplă — exact
     * transformările pe care le face wptexturize la salvare și care fac ca titlul
     * comandat să nu mai fie identic cu cel din baza de date.
     */
    private static function norm_title($t) {
        $t = wp_strip_all_tags((string) $t);
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        $t = strtr($t, array(
            '„' => '"', '”' => '"', '“' => '"', '’' => "'", '‘' => "'",
            '–' => '-', '—' => '-', '×' => 'x',
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
            'ş' => 's', 'ţ' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't',
            'Ş' => 's', 'Ţ' => 't',
        ));
        $t = preg_replace('/\s+/u', ' ', $t);
        return trim(mb_strtolower($t));
    }

    /**
     * Lungime + hash scurt + „e încă cel implicit?" pentru cele două prompturi
     * editoriale. Hash-ul e doar pentru comparat între site-uri (sha1 pe textul
     * normalizat), nu trimite conținutul promptului nicăieri.
     */
    private static function prompt_fingerprints() {
        $out = array();
        $map = array(
            'system'  => array('cw_system_prompt', 'default_system_prompt'),
            'article' => array('cw_article_prompt', 'default_article_prompt'),
        );
        foreach ($map as $key => $cfg) {
            list($option, $default_fn) = $cfg;
            $value   = (string) get_option($option, '');
            $default = (class_exists('CW_Admin') && method_exists('CW_Admin', $default_fn))
                ? (string) call_user_func(array('CW_Admin', $default_fn))
                : '';
            // Gol = site-ul folosește oricum varianta implicită la generare.
            $effective = trim($value) === '' ? $default : $value;
            $norm      = trim(preg_replace('/\s+/u', ' ', $effective));
            $out[$key] = array(
                'len'     => strlen($effective),
                'hash'    => substr(sha1($norm), 0, 12),
                'default' => (trim($value) === '' || $norm === trim(preg_replace('/\s+/u', ' ', $default))) ? 1 : 0,
            );
        }
        return $out;
    }

    /** start | stop | retry | clear */
    public function handle_control(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $action = isset($data['action']) ? sanitize_key((string) $data['action']) : '';
        if (!in_array($action, array('start', 'stop', 'retry', 'clear', 'unlock', 'cancel', 'pause_batch', 'resume_batch'), true)) {
            return new WP_REST_Response(array('error' => 'bad_action'), 400);
        }
        // Cu `batch` trimis, „retry" reia DOAR articolele cu eroare din lotul acela
        // (altfel ar relua erorile tuturor loturilor de pe site).
        $batch = isset($data['batch']) ? (string) $data['batch'] : '';
        $bulk = new HZ_Bulk();
        return array('ok' => true, 'message' => $bulk->control($action, $batch));
    }

    /**
     * Rescrie articolele publicate care au rămas cu citări de la căutarea web.
     * Body: { ids: [12,34] | scan: N, model, dry: 0|1, restore: 0|1 }
     *
     * Fără `ids` întoarce doar inventarul (câte articole are site-ul de curățat), ca
     * panoul să poată face un tur al flotei fără să scrie nimic. Un apel = cel mult
     * 3 articole: rescrierea durează zeci de secunde, iar cererea trebuie să se
     * încheie înainte de timeout-ul serverului.
     */
    public function handle_rewrite(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }
        if (!class_exists('HZ_Rewrite')) {
            return new WP_REST_Response(array('error' => 'no_module'), 400);
        }

        $ids = isset($data['ids']) && is_array($data['ids']) ? array_map('intval', $data['ids']) : array();
        // scan=N: ia singur primele N articole cu citări (nu mai trebuie lista din panou).
        if (empty($ids) && isset($data['scan'])) {
            $ids = HZ_Rewrite::find_posts((int) $data['scan']);
        }
        if (empty($ids)) {
            return array('ok' => true, 'pending' => HZ_Rewrite::count_posts(), 'done' => array());
        }
        $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 3);

        if (!empty($data['restore'])) {
            $out = array();
            foreach ($ids as $id) { $out[] = HZ_Rewrite::restore($id); }
            return array('ok' => true, 'done' => $out, 'pending' => HZ_Rewrite::count_posts());
        }

        $model = isset($data['model']) ? sanitize_text_field((string) $data['model']) : '';
        $dry   = !empty($data['dry']);
        $out   = array();
        $cost  = 0.0;
        foreach ($ids as $id) {
            $r = HZ_Rewrite::rewrite($id, $model, $dry);
            $cost += isset($r['cost']) ? (float) $r['cost'] : 0.0;
            $out[] = $r;
        }
        return array('ok' => true, 'done' => $out, 'cost' => round($cost, 5),
            'pending' => HZ_Rewrite::count_posts());
    }

    /**
     * v3.53.0: actualizează pluginul LA CERERE (butonul „Forțează update plugin"
     * din Setări flotă). Auto-update-ul WP instalează aceeași versiune singur,
     * dar abia la următoarea verificare (de 2 ori pe zi) — ruta e pentru „acum".
     *
     * Instalăm EXCLUSIV pachetul anunțat în .update-info.json de pe GitHub, și
     * doar dacă vine din repo-ul pluginului: secretul de deploy singur nu poate
     * aduce cod din altă parte (același principiu ca allowlist-ul de ZIP-uri din
     * tema Magpress). Body: { force: bool } — force reinstalează și când site-ul
     * e deja la zi (rescrie fișierele).
     */
    public function handle_upgrade(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $updater = new HZ_Updater();
        $info = $updater->info(true);
        if (!$info) {
            return new WP_REST_Response(array('error' => 'no_update_info',
                'message' => 'Nu am putut citi versiunea publicată pe GitHub.'), 502);
        }
        $remote = (string) $info['version'];
        if (empty($data['force']) && !version_compare(HZ_VERSION, $remote, '<')) {
            return array('ok' => true, 'updated' => false, 'version' => HZ_VERSION, 'remote' => $remote);
        }

        $package = (string) $info['package'];
        if (!preg_match('#^https://(github\.com|codeload\.github\.com)/kreatorteam-collab/horoscop-zilnic/#i', $package)) {
            return new WP_REST_Response(array('error' => 'bad_package',
                'message' => 'Pachetul anunțat nu vine din repo-ul pluginului.'), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        add_filter('filesystem_method', function () { return 'direct'; });
        if (!WP_Filesystem()) {
            return new WP_REST_Response(array('error' => 'filesystem_unavailable'), 500);
        }

        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) { @set_time_limit(180); }

        // install + overwrite_package suprascrie pluginul ACTIV (fix_source din
        // HZ_Updater redenumește folderul „horoscop-zilnic-main" din zip); starea
        // de activare nu se atinge, codul vechi rulează până la finalul cererii.
        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->install($package, array('overwrite_package' => true));

        if (is_wp_error($result)) {
            return new WP_REST_Response(array('error' => 'install_failed',
                'message' => $result->get_error_message()), 500);
        }
        if (!$result) {
            $errs = $skin->get_errors();
            $msg  = (is_wp_error($errs) && $errs->has_errors()) ? $errs->get_error_message() : 'install_failed';
            return new WP_REST_Response(array('error' => 'install_failed', 'message' => $msg), 500);
        }

        if (function_exists('opcache_reset')) { @opcache_reset(); }

        // Plasă de siguranță: dacă suprascrierea a lăsat pluginul dezactivat, îl
        // reactivăm (include_once pe același fișier deja încărcat = no-op, fără
        // redeclarare de clase în cererea curentă).
        $basename = 'horoscop-zilnic/horoscop-zilnic.php';
        if (function_exists('is_plugin_active') && !is_plugin_active($basename)) {
            activate_plugin($basename);
        }

        // Versiunea NOUĂ e în fișierul abia scris (constanta HZ_VERSION e cea veche).
        $ver  = $remote;
        $file = WP_PLUGIN_DIR . '/' . $basename;
        if (file_exists($file)) {
            $hdr = get_plugin_data($file, false, false);
            if (!empty($hdr['Version'])) { $ver = (string) $hdr['Version']; }
        }
        return array('ok' => true, 'updated' => true, 'from' => HZ_VERSION, 'version' => $ver);
    }
}
