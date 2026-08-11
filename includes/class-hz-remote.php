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
 */
class HZ_Remote {

    /** Fereastra acceptată pentru marcajul de timp din cerere (contra reluării). */
    const MAX_SKEW = 900;

    public function init() {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register() {
        $routes = array('info', 'enqueue', 'status', 'control', 'settings');
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
            'ai_key'          => class_exists('CW_API') && CW_API::has_key(),
            'ai_openai'       => class_exists('CW_API') && CW_API::has_provider_key('openai'),
            'ai_anthropic'    => class_exists('CW_API') && CW_API::has_provider_key('anthropic'),
            'ai_limit_hit'    => class_exists('CW_Usage') ? CW_Usage::limit_reached() : false,
            'ai_model'        => class_exists('CW_API') ? CW_API::resolve_model('') : '',
            'ai_model_set'    => (string) get_option('cw_default_model', ''),
            'ai_month_cost'   => class_exists('CW_Usage') ? round((float) CW_Usage::month_cost(), 4) : 0,
            'ai_month_limit'  => (float) get_option('cw_monthly_cost_limit', 0),
            'models'          => class_exists('CW_Models') ? self::model_list() : array(),
            'magnific_key'    => HZ_Magnific::has_key(),
            // Amprenta cheii Magnific (acelasi hash ca in contorul central) — panoul
            // poate spune CARE cheie e pe site, fara sa vada niciodata cheia.
            'magnific_hash'   => HZ_Magnific::key_hash(),
            'magnific_limit'  => HZ_Magnific::daily_limit(),
            'magnific_left'   => HZ_Magnific::has_key()
                ? max(0, HZ_Magnific::daily_limit() - HZ_Magnific::account_downloads_today())
                : 0,
            'categories'      => $cats,
            'default_category'=> (int) get_option('default_category', 1),
            'defaults'        => array(
                'images' => (int) get_option('hzb_images', 2),
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
            // Categoria lipsă se creează DOAR dacă panoul cere explicit.
            'create_category' => !empty($data['create_category']),
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
     * Body: { set: { openai_key, anthropic_key, magnific_key, model, monthly_limit,
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
            'openai_key'    => 'cw_openai_api_key',
            'anthropic_key' => 'cw_api_key',
            'magnific_key'  => 'hzb_magnific_key',
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

        if (isset($set['model']) && trim((string) $set['model']) !== '') {
            $model = sanitize_text_field((string) $set['model']);
            if (CW_Models::exists($model)) {
                update_option('cw_default_model', $model);
                $changed[] = 'model';
            } else {
                $skipped[] = 'model necunoscut: ' . $model;
            }
        }

        $numbers = array(
            'monthly_limit'        => array('cw_monthly_cost_limit', 0, 10000),
            'magnific_daily_limit' => array('hzb_daily_limit', 1, 1000),
            'images'               => array('hzb_images', 0, 3),
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
                'ai_model'       => CW_API::resolve_model(''),
                'ai_month_limit' => (float) get_option('cw_monthly_cost_limit', 0),
                'magnific_key'   => HZ_Magnific::has_key(),
                'magnific_hash'  => HZ_Magnific::key_hash(),
                'magnific_limit' => HZ_Magnific::daily_limit(),
            ),
        );
    }

    /** start | stop | retry | clear */
    public function handle_control(WP_REST_Request $req) {
        $data = $this->auth($req);
        if ($data instanceof WP_REST_Response) { return $data; }

        $action = isset($data['action']) ? sanitize_key((string) $data['action']) : '';
        if (!in_array($action, array('start', 'stop', 'retry', 'clear'), true)) {
            return new WP_REST_Response(array('error' => 'bad_action'), 400);
        }
        $bulk = new HZ_Bulk();
        return array('ok' => true, 'message' => $bulk->control($action));
    }
}
