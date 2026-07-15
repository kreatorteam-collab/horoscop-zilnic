<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Auto-update din repo GitHub public (același tipar ca Claude Writer / tema Magpress).
 *
 * Citește `.update-info.json` din repo. Dacă versiunea de pe GitHub > versiunea
 * instalată, WordPress afișează „Update available" în Plugins — update în 1 click
 * sau automat (implicit pornit, se poate opri din Setări → Horoscop Zilnic).
 */
class HZ_Updater {

    const INFO_URL  = 'https://raw.githubusercontent.com/kreatorteam-collab/horoscop-zilnic/main/.update-info.json';
    const API_URL   = 'https://api.github.com/repos/kreatorteam-collab/horoscop-zilnic/contents/.update-info.json?ref=main';
    const CACHE_KEY = 'hz_update_info';

    private $basename; // ex: horoscop-zilnic/horoscop-zilnic.php
    private $slug;     // ex: horoscop-zilnic

    public function init() {
        $this->basename = plugin_basename(HZ_PATH . 'horoscop-zilnic.php');
        $this->slug     = dirname($this->basename);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source'), 10, 4);
        add_filter('auto_update_plugin', array($this, 'auto_update'), 10, 2);

        // Link „Verifică update" sub plugin în lista de pluginuri.
        add_filter('plugin_action_links_' . $this->basename, array($this, 'action_links'));
        add_action('admin_init', array($this, 'maybe_force_check'));
    }

    /** Auto-update activat implicit (se poate opri din Setări → Horoscop Zilnic). */
    public function auto_update($update, $item) {
        if (isset($item->plugin) && $item->plugin === $this->basename) {
            return (bool) get_option('hz_auto_update', 1);
        }
        return $update;
    }

    /** Citește info-ul de pe GitHub (cache 1h). Returnează array sau false. */
    private function fetch_info($bust = false) {
        if (!$bust) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached === 'error') { return false; }
            if (is_array($cached)) { return $cached; }
        }

        // Întâi API-ul GitHub (proaspăt) — raw.githubusercontent are cache CDN de câteva
        // minute care IGNORĂ cache-buster-ul din query. Raw rămâne ca rezervă.
        $info = $this->fetch_api();
        if (!$info) { $info = $this->fetch_raw($bust); }

        if (!$info) {
            set_transient(self::CACHE_KEY, 'error', 30 * MINUTE_IN_SECONDS);
            return false;
        }

        set_transient(self::CACHE_KEY, $info, HOUR_IN_SECONDS);
        return $info;
    }

    /** Sursa principală: API GitHub (Accept: raw -> conținutul fișierului direct). */
    private function fetch_api() {
        $response = wp_remote_get(self::API_URL, array(
            'timeout' => 8,
            'headers' => array(
                'Accept'        => 'application/vnd.github.raw+json',
                'User-Agent'    => 'horoscop-zilnic-updater',
                'Cache-Control' => 'no-cache',
            ),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) { return false; }
        return self::valid_info(json_decode(wp_remote_retrieve_body($response), true));
    }

    /** Rezervă: raw.githubusercontent (cu cache-buster când forțăm verificarea). */
    private function fetch_raw($bust) {
        $url  = self::INFO_URL . ($bust ? ('?_=' . time()) : '');
        $args = array('timeout' => 8);
        if ($bust) { $args['headers'] = array('Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'); }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) { return false; }
        return self::valid_info(json_decode(wp_remote_retrieve_body($response), true));
    }

    private static function valid_info($info) {
        return (is_array($info) && !empty($info['version']) && !empty($info['package'])) ? $info : false;
    }

    /** Injectează update-ul în transientul WP dacă există o versiune nouă. */
    public function check_update($transient) {
        if (empty($transient->checked)) { return $transient; }

        $installed = isset($transient->checked[$this->basename])
            ? $transient->checked[$this->basename]
            : HZ_VERSION;

        $info = $this->fetch_info();
        if (!$info) { return $transient; }

        if (version_compare($installed, $info['version'], '<')) {
            $transient->response[$this->basename] = (object) array(
                'slug'         => $this->slug,
                'plugin'       => $this->basename,
                'new_version'  => $info['version'],
                'url'          => isset($info['url']) ? $info['url'] : '',
                'package'      => $info['package'],
                'tested'       => isset($info['tested']) ? $info['tested'] : '',
                'requires'     => isset($info['requires']) ? $info['requires'] : '',
                'requires_php' => isset($info['requires_php']) ? $info['requires_php'] : '',
            );
        } else {
            // Semnalăm explicit „fără update" — necesar pentru UI-ul de auto-update.
            $transient->no_update[$this->basename] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $info['version'],
                'url'         => isset($info['url']) ? $info['url'] : '',
                'package'     => isset($info['package']) ? $info['package'] : '',
            );
        }

        return $transient;
    }

    /** Popup „View details / Vezi detalii". */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') { return $result; }
        if (empty($args->slug) || $args->slug !== $this->slug) { return $result; }

        $info = $this->fetch_info();
        if (!$info) { return $result; }

        return (object) array(
            'name'          => isset($info['name']) ? $info['name'] : 'Horoscop Zilnic',
            'slug'          => $this->slug,
            'version'       => $info['version'],
            'requires'      => isset($info['requires']) ? $info['requires'] : '',
            'requires_php'  => isset($info['requires_php']) ? $info['requires_php'] : '',
            'tested'        => isset($info['tested']) ? $info['tested'] : '',
            'homepage'      => isset($info['url']) ? $info['url'] : '',
            'download_link' => $info['package'],
            'sections'      => array(
                'description' => isset($info['description'])
                    ? $info['description']
                    : 'Publică automat horoscopul zilnic generat cu Claude.',
                'changelog'   => isset($info['changelog_url'])
                    ? '<p><a href="' . esc_url($info['changelog_url']) . '" target="_blank" rel="noopener">Vezi modificările pe GitHub</a></p>'
                    : '',
            ),
        );
    }

    /**
     * GitHub livrează zip-ul cu folderul „horoscop-zilnic-main". Îl redenumim la
     * „horoscop-zilnic" ca update-ul să suprascrie plugin-ul existent, nu să-l dubleze.
     */
    public function fix_source($source, $remote_source, $upgrader, $hook_extra = array()) {
        // Acționăm doar pentru pachetul nostru: după hook_extra SAU după numele folderului extras.
        $ours = (!empty($hook_extra['plugin']) && $hook_extra['plugin'] === $this->basename)
             || (false !== strpos(basename(rtrim($source, '/')), 'horoscop-zilnic'));
        if (!$ours) { return $source; }

        $expected = $this->slug; // horoscop-zilnic
        $actual   = basename(rtrim($source, '/'));

        // IMPORTANT: întoarce MEREU calea cu slash final. Altfel Plugin_Upgrader::check_package()
        // face glob('.../horoscop-zilnic*.php') în loc de glob('.../horoscop-zilnic/*.php'), nu
        // găsește pluginul și raportează „Pachetul nu a putut fi instalat".
        if ($actual === $expected) { return trailingslashit($source); }

        global $wp_filesystem;
        if (!$wp_filesystem) { return $source; }

        $new_source = trailingslashit($remote_source) . $expected;
        if ($wp_filesystem->exists($new_source)) { $wp_filesystem->delete($new_source, true); }
        if ($wp_filesystem->move(untrailingslashit($source), $new_source)) {
            return trailingslashit($new_source);
        }
        return trailingslashit($source);
    }

    public function action_links($links) {
        $url = wp_nonce_url(admin_url('plugins.php?hz_force_check=1'), 'hz_force_check');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Verifică update', 'horoscop-zilnic') . '</a>';
        return $links;
    }

    /** „Verifică update acum": golește cache-ul și re-fetchează cu cache-buster. */
    public function maybe_force_check() {
        if (empty($_GET['hz_force_check']) || !current_user_can('update_plugins')) { return; }
        check_admin_referer('hz_force_check');

        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::CACHE_KEY, 'transient');
            wp_cache_delete('update_plugins', 'site-transient');
        }
        $this->fetch_info(true);

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }
}
