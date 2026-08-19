<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Redirector propriu pentru linkurile de afiliere: {site}/go/{slug} -> destinație.
 *
 * De ce nu punem linkul de afiliere direct în articol:
 *
 *  - Produsele mor. Magazinul delistează produse continuu, iar deeplink-ul duce fix
 *    la URL-ul dat la generare. Într-un articol cu 6 produse, în câteva luni ai 2-3
 *    linkuri moarte. Cu linkul scris în HTML, îl repari în conținut — pe zeci de
 *    site-uri, în sute de articole.
 *  - Un link Profitshare invalid nu dă 404 la noi: redirectează la
 *    profitshare.ro/link/not-exist. Cititorul pleacă de pe site pe o pagină de
 *    eroare străină.
 *
 * Cu stratul ăsta, maparea slug -> destinație stă în panoul central și se împinge pe
 * flotă (hz/v1/golinks). Un produs mort se repară ÎNTR-UN SINGUR LOC și se corectează
 * instantaneu în toate articolele, fără să atingem conținutul. Tot de aici vine și
 * comutatorul de avarie: o mapare goală face linkurile inerte pe tot site-ul.
 *
 * Redirectarea e 302 (destinația se schimbă, deci nu se cache-uiește permanent) și
 * poartă noindex — pagina de redirect n-are ce căuta în index.
 */
class HZ_GoLinks {

    /** Prefixul din URL. {site}/go/{slug} */
    const PREFIX = 'go';

    /** Maparea slug => URL destinație. */
    const OPTION = 'hz_go_map';

    public function init() {
        // Pe `init`, nu pe `template_redirect`: nu depindem de regulile de rewrite și
        // nu lăsăm WP să caute degeaba o pagină care nu există.
        add_action('init', array($this, 'maybe_redirect'), 1);
    }

    /** Toate maparile (slug => url). */
    public static function map() {
        $map = get_option(self::OPTION, array());
        return is_array($map) ? $map : array();
    }

    /**
     * Înlocuiește TOATĂ maparea. Deliberat complet, nu incremental: o mapare parțială
     * ar lăsa slug-uri orfane după o eroare de rețea, iar orfanii sunt exact linkurile
     * moarte pe care stratul ăsta trebuie să le prevină.
     *
     * @return array array('count' => int, 'skipped' => int)
     */
    public static function set_map($raw) {
        $map = array();
        $skipped = 0;
        foreach ((array) $raw as $slug => $url) {
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $slug));
            $url  = esc_url_raw(trim((string) $url));
            if ($slug === '' || $url === '' || !preg_match('#^https?://#i', $url)) {
                $skipped++;
                continue;
            }
            $map[$slug] = $url;
        }
        // autoload 'no': lista poate crește, iar pe majoritatea requesturilor nu e nevoie de ea.
        update_option(self::OPTION, $map, 'no');
        return array('count' => count($map), 'skipped' => $skipped);
    }

    /**
     * Interceptează /go/{slug}. Verificarea ieftină (strpos) se face înainte de orice
     * citire din baza de date — rulează pe FIECARE request al site-ului.
     */
    public function maybe_redirect() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '' || strpos($uri, '/' . self::PREFIX . '/') === false) { return; }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        if (!preg_match('#/' . self::PREFIX . '/([a-z0-9\-]{2,64})/?$#i', $path, $m)) { return; }

        $slug = strtolower($m[1]);
        $map  = self::map();
        if (empty($map[$slug])) { return; } // slug necunoscut: lăsăm WP să dea 404-ul lui normal

        $target = $map[$slug];

        // Contor local, doar ca să se vadă în panou ce se dă click înainte să apară
        // comisioanele (care întârzie zile). Nu ține IP-uri, nu setează cookie-uri.
        $hits = get_option('hz_go_hits', array());
        if (!is_array($hits)) { $hits = array(); }
        $key = $slug . '|' . gmdate('Y-m-d');
        $hits[$key] = isset($hits[$key]) ? (int) $hits[$key] + 1 : 1;
        if (count($hits) > 2000) { $hits = array_slice($hits, -1000, null, true); }
        update_option('hz_go_hits', $hits, 'no');

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        wp_redirect($target, 302, 'HZ_GoLinks');
        exit;
    }

    /** Clickurile din ultimele $days zile, agregate pe slug (pentru hz/v1/info). */
    public static function hits($days = 30) {
        $hits = get_option('hz_go_hits', array());
        if (!is_array($hits)) { return array(); }
        $limit = gmdate('Y-m-d', time() - max(1, (int) $days) * DAY_IN_SECONDS);
        $out = array();
        foreach ($hits as $key => $n) {
            $parts = explode('|', $key);
            if (count($parts) !== 2 || $parts[1] < $limit) { continue; }
            $slug = $parts[0];
            $out[$slug] = isset($out[$slug]) ? $out[$slug] + (int) $n : (int) $n;
        }
        arsort($out);
        return $out;
    }
}
