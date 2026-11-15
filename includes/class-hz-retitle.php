<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v3.59.0 — Rescrierea unui articol EXISTENT, cu titlu nou (articole duplicate în flotă).
 *
 * Context: auditul flotei a găsit ~4.100 de titluri identice pe două sau mai multe
 * site-uri (~11.800 de copii), majoritatea cu text identic, publicate în același lot.
 * Panoul (app.targetseo.ro → „Articole duplicate”) păstrează originalul (cel mai vechi
 * exemplar) și trimite celelalte copii în coada AI Writer cu un titlu NOU: textul se
 * scrie de la zero, cu șablonul editorial al site-ului, ca orice articol din bulk, apoi
 * înlocuiește titlul și conținutul articolului — același ID, aceeași adresă, aceeași
 * poză, aceleași categorii, aceeași dată de publicare.
 *
 * REGULA DE AUR: articolele cu linkuri spre alte domenii și cele din categoriile de
 * advertoriale / sponsorizate / comunicate NU se ating (verificat la cerere — `check` —
 * și încă o dată chiar înainte de scriere, în coadă).
 *
 * Titlul, conținutul și meta SEO de dinainte se păstrează în `_hz_rt_backup`, deci
 * `restore` pune articolul exact cum era.
 */
class HZ_Retitle {

    const META_BACKUP = '_hz_rt_backup';
    const META_AT     = '_hz_rt_at';
    const PROTECTED_RE = '/advertorial|sponsoriz|sponsored|promovat|comunicat|publicitar|parteneri/i';
    const SEO_KEYS = array('_seopress_titles_title', '_seopress_titles_desc', '_seopress_social_fb_title', '_seopress_social_twitter_title');

    private static function host($url) {
        $h = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('~^www\.~', '', $h);
    }

    /** Domeniile externe din conținut (fără site-ul însuși). */
    public static function external_hosts($content) {
        if (stripos((string) $content, '<a') === false) { return array(); }
        if (!preg_match_all('~<a\s[^>]*href\s*=\s*["\']\s*(?:https?:)?//([^/"\'\s?#:]+)~i', (string) $content, $m)) { return array(); }
        $own = self::host(home_url('/'));
        $out = array();
        foreach ($m[1] as $h) {
            $h = preg_replace('~^www\.~', '', strtolower($h));
            if ($h === '' || $h === $own) { continue; }
            $out[$h] = true;
        }
        return array_keys($out);
    }

    /**
     * Se poate rescrie? '' = da; altfel motivul: missing | status | done | sponsored | links.
     * $hosts primește domeniile externe, pentru raport.
     */
    public static function reason($post_id, &$hosts = array()) {
        $hosts = array();
        $p = get_post((int) $post_id);
        if (!$p || $p->post_type !== 'post') { return 'missing'; }
        if ($p->post_status !== 'publish') { return 'status'; }
        if (get_post_meta($p->ID, self::META_BACKUP, true) !== '') { return 'done'; }
        foreach (wp_get_post_categories($p->ID, array('fields' => 'all')) as $t) {
            if (preg_match(self::PROTECTED_RE, (string) $t->slug) || preg_match(self::PROTECTED_RE, (string) $t->name)) { return 'sponsored'; }
        }
        $hosts = self::external_hosts($p->post_content);
        if (!empty($hosts)) { return 'links'; }
        return '';
    }

    /** Raportul pentru panou: [{id, ok, reason, title, hosts}]. */
    public static function check(array $ids) {
        $out = array();
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 500) as $id) {
            if ($id <= 0) { continue; }
            $hosts = array();
            $r = self::reason($id, $hosts);
            $p = get_post($id);
            $out[] = array(
                'id'     => $id,
                'ok'     => $r === '',
                'reason' => $r,
                'title'  => $p ? wp_strip_all_tags($p->post_title) : '',
                'hosts'  => array_slice($hosts, 0, 5),
            );
        }
        return $out;
    }

    /**
     * Pune titlul și textul nou pe articol (după un ultim `reason`). Adresa (slug), data,
     * autorul, categoriile și poza rămân. Meta SEO personalizate cu titlul vechi se scot
     * (SEOPress folosește atunci șablonul cu titlul nou) și intră în backup.
     * @return true|WP_Error
     */
    public static function apply($post_id, $title, $content) {
        $hosts = array();
        $why = self::reason($post_id, $hosts);
        if ($why !== '') { return new WP_Error('hz_rt_' . $why, self::reason_label($why, $hosts)); }
        $p = get_post((int) $post_id);

        $seo = array();
        foreach (self::SEO_KEYS as $k) {
            $v = get_post_meta($p->ID, $k, true);
            if ($v !== '' && $v !== false) { $seo[$k] = $v; }
        }
        $backup = array(
            't'   => $p->post_title,
            'c'   => $p->post_content,
            'e'   => $p->post_excerpt,
            'seo' => $seo,
            'at'  => current_time('mysql'),
        );
        // wp_slash: update_post_meta trece valoarea prin wp_unslash.
        update_post_meta($p->ID, self::META_BACKUP, wp_slash(wp_json_encode($backup)));

        $r = wp_update_post(wp_slash(array(
            'ID'           => $p->ID,
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_excerpt' => '',
            'post_name'    => $p->post_name, // adresa rămâne aceeași
        )), true);
        if (is_wp_error($r)) {
            delete_post_meta($p->ID, self::META_BACKUP);
            return $r;
        }
        foreach (array_keys($seo) as $k) { delete_post_meta($p->ID, $k); }
        update_post_meta($p->ID, self::META_AT, current_time('Y-m-d'));
        return true;
    }

    /** Pune la loc titlul, textul și meta SEO de dinainte. */
    public static function restore($post_id) {
        $post_id = (int) $post_id;
        $raw = get_post_meta($post_id, self::META_BACKUP, true);
        $b = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($b) || !isset($b['t'], $b['c'])) {
            return array('id' => $post_id, 'ok' => false, 'error' => 'fără copie de rezervă');
        }
        $p = get_post($post_id);
        if (!$p) { return array('id' => $post_id, 'ok' => false, 'error' => 'articol inexistent'); }
        $r = wp_update_post(wp_slash(array(
            'ID'           => $post_id,
            'post_title'   => $b['t'],
            'post_content' => $b['c'],
            'post_excerpt' => isset($b['e']) ? $b['e'] : '',
            'post_name'    => $p->post_name,
        )), true);
        if (is_wp_error($r)) { return array('id' => $post_id, 'ok' => false, 'error' => $r->get_error_message()); }
        foreach ((array) ($b['seo'] ?? array()) as $k => $v) {
            if (in_array($k, self::SEO_KEYS, true)) { update_post_meta($post_id, $k, wp_slash($v)); }
        }
        delete_post_meta($post_id, self::META_BACKUP);
        delete_post_meta($post_id, self::META_AT);
        return array('id' => $post_id, 'ok' => true, 'title' => $b['t']);
    }

    public static function reason_label($why, array $hosts = array()) {
        switch ($why) {
            case 'missing':   return 'Neatins: articolul nu mai există';
            case 'status':    return 'Neatins: articolul nu e publicat';
            case 'done':      return 'Neatins: a fost deja rescris';
            case 'sponsored': return 'Neatins: advertorial / sponsorizat';
            case 'links':     return 'Neatins: are linkuri spre alte domenii' . ($hosts ? ' (' . implode(', ', array_slice($hosts, 0, 3)) . ')' : '');
        }
        return 'Neatins';
    }
}
