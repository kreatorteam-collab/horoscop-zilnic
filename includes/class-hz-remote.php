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
 */
class HZ_Remote {

    /** Fereastra acceptată pentru marcajul de timp din cerere (contra reluării). */
    const MAX_SKEW = 900;

    public function init() {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register() {
        $routes = array('info', 'enqueue', 'status', 'control');
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
            'ai_limit_hit'    => class_exists('CW_Usage') ? CW_Usage::limit_reached() : false,
            'ai_model'        => class_exists('CW_API') ? CW_API::resolve_model('') : '',
            'ai_month_cost'   => class_exists('CW_Usage') ? round((float) CW_Usage::month_cost(), 4) : 0,
            'magnific_key'    => HZ_Magnific::has_key(),
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
