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
 *
 * v3.60.0 — `apply_title`: DOAR titlul nou, pentru copiile al căror text e deja diferit de
 * original (titlu identic, text scris separat). Fără wp_update_post: data „modificat” rămâne.
 */
class HZ_Retitle {

    const META_BACKUP = '_hz_rt_backup';
    const META_AT     = '_hz_rt_at';
    const PROTECTED_RE = '/advertorial|sponsoriz|sponsored|promovat|recomandari|comunicat|publicitar|parteneri/i';
    /** Extensiile pe care un domeniu scris ca text (fără www / protocol) contează ca adresă de site. */
    const TEXT_TLDS = 'ro|com|net|org|eu|info|biz|shop|store|online|site|md|bg|hu|de|it|fr|es|uk|io|co';
    const SEO_KEYS = array('_seopress_titles_title', '_seopress_titles_desc', '_seopress_social_fb_title', '_seopress_social_twitter_title');

    private static function host($url) {
        $h = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('~^www\.~', '', $h);
    }

    /**
     * Domeniile externe din conținut (fără site-ul însuși) — REGULA DE AUR, strict (v3.59.1):
     * orice adresă spre alt domeniu blochează articolul, nu doar <a href="…">: link fără
     * ghilimele, poză / iframe / embed de pe alt site, adresă scrisă ca text („www.firma.ro”,
     * „https://…”). Un articol cu link spre alt site poate fi advertorial: nu se atinge.
     */
    public static function external_hosts($content) {
        $content = (string) $content;
        if ($content === '') { return array(); }
        $own = self::host(home_url('/'));
        $hosts = array();
        // Orice URL absolut sau relativ la protocol: href / src / data-* / text (cu sau fără ghilimele).
        if (preg_match_all('~(?:https?:)?//([a-z0-9.-]+\.[a-z]{2,})~i', $content, $m)) {
            foreach ($m[1] as $h) { $hosts[] = $h; }
        }
        // Domenii scrise ca text, fără protocol: „www.firma.ro”.
        if (preg_match_all('~\bwww\.([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})~i', $content, $m)) {
            foreach ($m[1] as $h) { $hosts[] = $h; }
        }
        // v3.60.1: domeniu scris ca text, fără www („comandă de pe firma.ro”, „office@firma.ro”) —
        // în advertorialele vechi linkul e uneori scos și rămâne doar numele site-ului. Doar pe
        // extensiile uzuale, ca să nu prindem „3.5” sau „Node.js”.
        $text = wp_strip_all_tags(str_replace('>', '> ', $content));
        if (preg_match_all('~(?<![\w.@-])(?:[a-z0-9-]+@)?((?:[a-z0-9-]+\.)+(?:' . self::TEXT_TLDS . '))(?![\w-])~iu', $text, $m)) {
            foreach ($m[1] as $h) { $hosts[] = $h; }
        }
        $out = array();
        foreach ($hosts as $h) {
            $h = preg_replace('~^www\.~', '', strtolower(rtrim($h, '.')));
            if ($h === '' || $h === $own) { continue; }
            $out[$h] = true;
        }
        // rel="sponsored" fără domeniu prins mai sus: tot advertorial.
        if (empty($out) && preg_match('~rel\s*=\s*["\']?[^"\'>]*\bsponsored\b~i', $content)) {
            $out['rel=sponsored'] = true;
        }
        // Redirectorul propriu de afiliere (/go/{slug}, HZ_GoLinks) = link spre alt site, mascat.
        if (preg_match('~href\s*=\s*["\']?(?:https?://[^/"\'\s>]+)?/go/[a-z0-9_-]+~i', $content)) {
            $out['/go/ (afiliere)'] = true;
        }
        return array_keys($out);
    }

    /**
     * Advertorial după categorie SAU după etichetă (v3.60.1: un articol etichetat „advertorial” /
     * „sponsorizat” fără categoria de advertoriale e tot advertorial).
     */
    public static function is_sponsored($post_id) {
        foreach (array('category', 'post_tag') as $tax) {
            $terms = get_the_terms((int) $post_id, $tax);
            if (!is_array($terms)) { continue; }
            foreach ($terms as $t) {
                if (preg_match(self::PROTECTED_RE, (string) $t->slug) || preg_match(self::PROTECTED_RE, (string) $t->name)) { return true; }
            }
        }
        return false;
    }

    /**
     * REGULA DE AUR, indiferent de starea articolului: 'sponsored' (categorie de advertoriale),
     * 'links' (adresă spre alt domeniu în conținut / rezumat) sau '' (se poate atinge).
     */
    public static function golden($post_id, &$hosts = array()) {
        $hosts = array();
        $p = get_post((int) $post_id);
        if (!$p) { return ''; }
        if (self::is_sponsored($p->ID)) { return 'sponsored'; }
        $hosts = self::external_hosts($p->post_content . ' ' . $p->post_excerpt);
        return $hosts ? 'links' : '';
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
        if (self::is_sponsored($p->ID)) { return 'sponsored'; }
        $hosts = self::external_hosts($p->post_content . ' ' . $p->post_excerpt);
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

    /** Meta SEO care conțin titlul (le scoatem la schimbarea titlului, ca SEOPress să folosească titlul nou). */
    const SEO_TITLE_KEYS = array('_seopress_titles_title', '_seopress_social_fb_title', '_seopress_social_twitter_title');

    /**
     * v3.60.0 — DOAR TITLUL NOU, textul rămâne (copii cu titlu identic, dar text deja diferit de
     * original — ex. fișele de plante scrise separat pe fiecare site din aceeași listă de titluri).
     * Regula de aur ca la rescriere (`reason`). Titlul se scrie direct în DB, deci data „modificat”
     * NU se schimbă (textul e același; altfel articolul ar afișa „Actualizat la” fără motiv).
     * Adresa (slug) rămâne. Titlul vechi + meta SEO de titlu intră în `_hz_rt_backup` (mode = title),
     * deci `restore` îl pune la loc.
     * @return true|WP_Error
     */
    public static function apply_title($post_id, $title) {
        global $wpdb;
        $title = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $title)));
        if ($title === '' || mb_strlen($title) > 200) { return new WP_Error('hz_rt_title', 'Titlu gol sau prea lung'); }
        $hosts = array();
        $why = self::reason($post_id, $hosts);
        if ($why !== '') { return new WP_Error('hz_rt_' . $why, self::reason_label($why, $hosts)); }
        $p = get_post((int) $post_id);
        if (wp_strip_all_tags($p->post_title) === $title) { return new WP_Error('hz_rt_same', 'Titlul nou e același cu cel vechi'); }

        $seo = array();
        foreach (self::SEO_TITLE_KEYS as $k) {
            $v = get_post_meta($p->ID, $k, true);
            if ($v !== '' && $v !== false) { $seo[$k] = $v; }
        }
        $backup = array('mode' => 'title', 't' => $p->post_title, 'c' => '', 'e' => $p->post_excerpt, 'seo' => $seo, 'at' => current_time('mysql'));
        update_post_meta($p->ID, self::META_BACKUP, wp_slash(wp_json_encode($backup)));

        $ok = $wpdb->update($wpdb->posts, array('post_title' => $title), array('ID' => $p->ID), array('%s'), array('%d'));
        if ($ok === false) {
            delete_post_meta($p->ID, self::META_BACKUP);
            return new WP_Error('hz_rt_db', 'Nu am putut scrie titlul: ' . $wpdb->last_error);
        }
        foreach (array_keys($seo) as $k) { delete_post_meta($p->ID, $k); }
        update_post_meta($p->ID, self::META_AT, current_time('Y-m-d'));
        self::after_direct_write($p->ID);
        return true;
    }

    /** După o scriere directă în DB: cache-ul WordPress și pagina din LiteSpeed se golesc. */
    private static function after_direct_write($post_id) {
        clean_post_cache($post_id);
        if (has_action('litespeed_purge_post')) { do_action('litespeed_purge_post', $post_id); }
    }

    /** Titluri noi pe mai multe articole: [{id, title}] -> [{id, ok, error}] (max. 200 pe apel). */
    public static function apply_titles(array $items) {
        $out = array();
        foreach (array_slice($items, 0, 200) as $it) {
            $id = isset($it['id']) ? (int) $it['id'] : 0;
            if ($id <= 0) { continue; }
            $r = self::apply_title($id, isset($it['title']) ? (string) $it['title'] : '');
            $out[] = is_wp_error($r)
                ? array('id' => $id, 'ok' => false, 'code' => str_replace('hz_rt_', '', $r->get_error_code()), 'error' => $r->get_error_message())
                : array('id' => $id, 'ok' => true);
        }
        return $out;
    }

    /** Pune la loc titlul, textul și meta SEO de dinainte. */
    public static function restore($post_id) {
        global $wpdb;
        $post_id = (int) $post_id;
        $raw = get_post_meta($post_id, self::META_BACKUP, true);
        $b = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($b) || !isset($b['t'], $b['c'])) {
            return array('id' => $post_id, 'ok' => false, 'error' => 'fără copie de rezervă');
        }
        $p = get_post($post_id);
        if (!$p) { return array('id' => $post_id, 'ok' => false, 'error' => 'articol inexistent'); }
        if (($b['mode'] ?? '') === 'title') {
            // v3.60.0: doar titlul fusese schimbat (direct în DB) -> doar titlul revine, tot fără „modificat”.
            if ($wpdb->update($wpdb->posts, array('post_title' => $b['t']), array('ID' => $post_id), array('%s'), array('%d')) === false) {
                return array('id' => $post_id, 'ok' => false, 'error' => 'Nu am putut scrie titlul: ' . $wpdb->last_error);
            }
            foreach ((array) ($b['seo'] ?? array()) as $k => $v) {
                if (in_array($k, self::SEO_TITLE_KEYS, true)) { update_post_meta($post_id, $k, wp_slash($v)); }
            }
            delete_post_meta($post_id, self::META_BACKUP);
            delete_post_meta($post_id, self::META_AT);
            self::after_direct_write($post_id);
            return array('id' => $post_id, 'ok' => true, 'title' => $b['t'], 'mode' => 'title');
        }
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
