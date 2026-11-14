<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v3.57.0: preluarea articolelor între site-urile din flotă („Preluare articole" din
 * app.targetseo.ro) - înlocuiește WPeMatico. Site-ul NU citește singur niciun feed:
 * panoul central cere articolele noi de la site-ul sursă (hz/v1/export) și le trimite
 * site-ului țintă (hz/v1/import), ambele semnate HMAC ca restul rutelor hz/v1.
 *
 * Reguli fixe, indiferent ce cere panoul:
 *  - sursa nu dă mai departe articole preluate la rândul lor de pe alt site din flotă
 *    (fără lanțuri / bucle; știrile rescrise de modulul „Știri" SE dau, v3.57.1),
 *    advertoriale, articole cu linkuri rel="sponsored" sau cu „(P)" în titlu;
 *  - ținta trece conținutul prin wp_kses_post, nu creează același articol de două
 *    ori (după adresa sursei, apoi după titlu) și descarcă poza doar de pe domeniul
 *    sursei, prin wp_safe_remote_get (fără adrese interne), verificând că e imagine.
 */
class HZ_Syndicate {

    const META_SRC   = '_hz_syn_source';     // adresa articolului original
    const META_SITE  = '_hz_syn_site';       // domeniul sursei
    const META_CANON = '_hz_syn_canonical';  // 1 = canonical spre sursă
    const META_RULE  = '_hz_syn_rule';       // regula din panou care l-a adus

    const MAX_EXPORT  = 10;
    const MAX_CONTENT = 400000;              // octeți; peste, articolul nu se preia
    const MAX_IMAGE   = 15728640;            // 15 MB

    public function init() {
        add_filter('get_canonical_url', array($this, 'canonical'), 20, 2);
        add_filter('wpseo_canonical', array($this, 'canonical_seo'), 20);
        add_filter('rank_math/frontend/canonical', array($this, 'canonical_seo'), 20);
        add_filter('aioseo_canonical_url', array($this, 'canonical_seo'), 20);
    }

    /* ---------------- canonical ---------------- */

    private static function canonical_for($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !get_post_meta($post_id, self::META_CANON, true)) { return ''; }
        $src = (string) get_post_meta($post_id, self::META_SRC, true);
        return preg_match('#^https?://#i', $src) ? esc_url_raw($src) : '';
    }

    public function canonical($url, $post) {
        $src = self::canonical_for($post instanceof WP_Post ? $post->ID : 0);
        return $src !== '' ? $src : $url;
    }

    public function canonical_seo($url) {
        if (!is_singular('post')) { return $url; }
        $src = self::canonical_for(get_queried_object_id());
        return $src !== '' ? $src : $url;
    }

    /* ---------------- utilitare ---------------- */

    public static function bare_host($url) {
        $h = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $h);
    }

    /** $host e domeniul $base sau un subdomeniu al lui? */
    public static function same_site($host, $base) {
        $host = preg_replace('/^www\./', '', strtolower((string) $host));
        $base = preg_replace('/^www\./', '', strtolower((string) $base));
        if ($host === '' || $base === '') { return false; }
        return $host === $base || substr($host, -strlen('.' . $base)) === '.' . $base;
    }

    private static function categories() {
        $out = array();
        foreach (get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 300)) as $t) {
            if (!($t instanceof WP_Term)) { continue; }
            $out[] = array(
                'id'    => (int) $t->term_id,
                'name'  => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'slug'  => $t->slug,
                'count' => (int) $t->count,
            );
        }
        usort($out, function ($a, $b) { return $b['count'] - $a['count']; });
        return $out;
    }

    private static function authors() {
        $out = array();
        $users = get_users(array(
            'role__in' => array('administrator', 'editor', 'author'),
            'number'   => 100, 'orderby' => 'display_name', 'fields' => array('ID', 'display_name'),
        ));
        foreach ($users as $u) {
            $out[] = array('id' => (int) $u->ID, 'name' => (string) $u->display_name);
        }
        return $out;
    }

    /** Ce vede panoul când construiește o regulă: categorii, autori, ultimul articol. */
    public static function probe() {
        global $wpdb;
        return array(
            'ok'             => true,
            'plugin_version' => defined('HZ_VERSION') ? HZ_VERSION : '',
            'site_name'      => get_bloginfo('name'),
            'categories'     => self::categories(),
            'authors'        => self::authors(),
            'max_id'         => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'post'"),
        );
    }

    /* ---------------- export (site-ul sursă) ---------------- */

    /** Motivul pentru care articolul nu pleacă mai departe ('' = pleacă). */
    public static function skip_reason(WP_Post $p, array $terms) {
        if (get_post_meta($p->ID, self::META_SRC, true)) { return 'preluat la rândul lui'; }
        if (post_password_required($p) || $p->post_password !== '') { return 'protejat cu parolă'; }
        if (preg_match('/\(\s*P\s*\)/u', $p->post_title)) { return 'advertorial (P)'; }
        foreach ($terms as $t) {
            if (preg_match('#advertorial|sponsor|publireportaj|comunicat|publicitate|parteneri#i', $t->slug)) {
                return 'categorie de advertoriale';
            }
        }
        if (preg_match('#rel\s*=\s*["\'][^"\']*sponsored#i', $p->post_content)) { return 'are linkuri sponsored'; }
        if (strlen($p->post_content) > self::MAX_CONTENT) { return 'articol prea mare'; }
        if (trim(wp_strip_all_tags($p->post_content)) === '') { return 'fără text'; }
        return '';
    }

    /**
     * Body: { after_id, since?, categories?: [id], max?, back? }
     *  - articolele publicate cu ID > after_id (în ordinea ID-ului), plus cele cu ID mai
     *    mic publicate după `since` (programate / ciorne publicate între timp);
     *  - `back` = N (fără articole): întoarce doar cursorul de la care regula ar prelua
     *    și ultimele N articole deja existente.
     * Răspuns: { ok, items: [...], cursor, skipped: [{id,title,reason}], more }
     */
    public static function export(array $d) {
        global $wpdb;

        $after = isset($d['after_id']) ? max(0, (int) $d['after_id']) : 0;
        $max   = isset($d['max']) ? max(1, min(self::MAX_EXPORT, (int) $d['max'])) : 5;
        $cats  = array();
        if (isset($d['categories']) && is_array($d['categories'])) {
            $cats = array_values(array_unique(array_filter(array_map('intval', $d['categories']))));
            $cats = array_slice($cats, 0, 200);
        }
        $cat_sql = '';
        if (!empty($cats)) {
            $cat_sql = " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = 'category' AND tt.term_id IN (" . implode(',', $cats) . '))';
        }
        $base = "FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status = 'publish'" . $cat_sql;

        if (isset($d['back'])) {
            $back = max(0, min(50, (int) $d['back']));
            $top  = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'post'");
            if ($back === 0) { return array('ok' => true, 'cursor' => $top); }
            $ids = $wpdb->get_col("SELECT p.ID $base ORDER BY p.ID DESC LIMIT " . $back);
            return array('ok' => true, 'cursor' => empty($ids) ? $top : max(0, (int) min($ids) - 1));
        }

        $scan = $max * 4;
        $ids  = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID $base AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $after, $scan
        )));
        $more = count($ids) >= $scan;

        // Articolele cu ID mai vechi, publicate abia după ultima rulare.
        $since = isset($d['since']) ? (string) $d['since'] : '';
        $late  = array();
        if ($after > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            $late = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID $base AND p.ID <= %d AND p.post_date_gmt > %s ORDER BY p.ID ASC LIMIT %d",
                $after, $since, $max
            )));
        }

        $items = array(); $skipped = array(); $cursor = $after;
        foreach (array_merge($late, $ids) as $id) {
            if (count($items) >= $max) { $more = true; break; }
            $p = get_post($id);
            if ($id > $cursor) { $cursor = $id; }
            if (!($p instanceof WP_Post)) { continue; }

            $terms = get_the_terms($id, 'category');
            $terms = is_array($terms) ? $terms : array();
            $why   = self::skip_reason($p, $terms);
            if ($why !== '') {
                if (count($skipped) < 30) {
                    $skipped[] = array('id' => $id, 'title' => $p->post_title, 'reason' => $why);
                }
                continue;
            }
            $items[] = self::export_item($p, $terms);
        }

        return array('ok' => true, 'items' => $items, 'cursor' => $cursor, 'skipped' => $skipped, 'more' => $more);
    }

    private static function export_item(WP_Post $p, array $terms) {
        $cats = array();
        foreach ($terms as $t) {
            $cats[] = array('id' => (int) $t->term_id, 'name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'slug' => $t->slug);
        }
        $tags = array();
        $tt   = get_the_terms($p->ID, 'post_tag');
        if (is_array($tt)) {
            foreach (array_slice($tt, 0, 15) as $t) { $tags[] = html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'); }
        }
        $image = null;
        $thumb = (int) get_post_thumbnail_id($p->ID);
        if ($thumb > 0) {
            $url = wp_get_attachment_image_url($thumb, 'full');
            if ($url) {
                $image = array(
                    'url'     => $url,
                    'alt'     => (string) get_post_meta($thumb, '_wp_attachment_image_alt', true),
                    'caption' => (string) wp_get_attachment_caption($thumb),
                );
            }
        }
        return array(
            'id'         => (int) $p->ID,
            'title'      => $p->post_title,
            'content'    => $p->post_content,
            'excerpt'    => $p->post_excerpt,
            'date_gmt'   => $p->post_date_gmt,
            'link'       => get_permalink($p),
            'categories' => $cats,
            'tags'       => $tags,
            'image'      => $image,
        );
    }

    /* ---------------- import (site-ul țintă) ---------------- */

    /**
     * Linkurile din textul preluat: cele spre site-ul sursă se desfac (rămâne textul),
     * restul după $mode: keep | nofollow | strip.
     */
    public static function clean_links($html, $source_host, $mode) {
        return preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use ($source_host, $mode) {
            $href = '';
            if (preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#is', $m[1], $h)) { $href = trim(html_entity_decode($h[2], ENT_QUOTES, 'UTF-8')); }
            if ($href === '' || $href[0] === '#') { return $m[2]; }
            $host = self::bare_host($href);
            if ($host === '' || self::same_site($host, $source_host)) { return $m[2]; } // relativ sau intern sursei
            if ($mode === 'strip') { return $m[2]; }
            if ($mode !== 'nofollow') { return $m[0]; }

            $rel = array('nofollow', 'noopener');
            if (preg_match('#\brel\s*=\s*(["\'])(.*?)\1#is', $m[1], $r)) {
                $rel = array_unique(array_merge(preg_split('/\s+/', strtolower(trim($r[2])), -1, PREG_SPLIT_NO_EMPTY), $rel));
            }
            $attrs = preg_replace('#\s*\brel\s*=\s*(["\']).*?\1#is', '', $m[1]);
            return '<a' . $attrs . ' rel="' . esc_attr(implode(' ', $rel)) . '">' . $m[2] . '</a>';
        }, (string) $html);
    }

    private static function resolve_category($id, $name, $create) {
        $id = (int) $id;
        if ($id > 0 && get_term($id, 'category') instanceof WP_Term) { return $id; }
        $name = trim(wp_strip_all_tags((string) $name));
        if ($name === '') { return 0; }
        $term = get_term_by('name', $name, 'category');
        if ($term instanceof WP_Term) { return (int) $term->term_id; }
        if (!$create) { return 0; }
        $new = wp_insert_term(mb_substr($name, 0, 190), 'category');
        if (is_wp_error($new)) {
            $exists = term_exists($name, 'category');
            return is_array($exists) ? (int) $exists['term_id'] : 0;
        }
        return (int) $new['term_id'];
    }

    private static function author_id($wanted) {
        $wanted = (int) $wanted;
        if ($wanted > 0 && user_can($wanted, 'publish_posts')) { return $wanted; }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    private static function existing($source_url, $title) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status <> 'trash' LIMIT 1",
            self::META_SRC, $source_url
        ));
        if ($id > 0) { return array($id, 'source'); }
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post'
             AND post_status IN ('publish','draft','future','pending') AND post_title = %s LIMIT 1",
            $title
        ));
        return $id > 0 ? array($id, 'title') : array(0, '');
    }

    /**
     * Body: { source_url, title, content, excerpt?, date_gmt?, category_id?, category_name?,
     *         create_category?, tags?: [..], image?: {url, alt, caption}, status?, author?,
     *         attribution?, canonical?, keep_date?, links?: keep|nofollow|strip, rule? }
     * Răspuns: { ok, post_id, link, duplicate?: 'source'|'title', image }
     */
    public static function import(array $d) {
        $src = isset($d['source_url']) ? esc_url_raw(trim((string) $d['source_url']), array('http', 'https')) : '';
        $src_host = self::bare_host($src);
        if ($src === '' || $src_host === '') {
            return new WP_Error('bad_source', 'Adresa articolului sursă lipsește sau nu e validă.');
        }
        if (self::same_site($src_host, self::bare_host(home_url()))) {
            return new WP_Error('same_site', 'Sursa și ținta sunt același site.');
        }

        $title = trim(wp_strip_all_tags(isset($d['title']) ? (string) $d['title'] : ''));
        $raw   = isset($d['content']) ? (string) $d['content'] : '';
        if ($title === '' || mb_strlen($title) > 300) { return new WP_Error('bad_title', 'Titlul lipsește sau e prea lung.'); }
        if (strlen($raw) > self::MAX_CONTENT) { return new WP_Error('too_big', 'Articolul e prea mare.'); }

        list($dup, $dup_by) = self::existing($src, $title);
        if ($dup > 0) {
            return array('ok' => true, 'duplicate' => $dup_by, 'post_id' => $dup, 'link' => get_permalink($dup), 'image' => '');
        }

        $mode = isset($d['links']) ? (string) $d['links'] : 'nofollow';
        if (!in_array($mode, array('keep', 'nofollow', 'strip'), true)) { $mode = 'nofollow'; }

        // Curățarea vine ÎNAINTE de orice altceva: conținutul e al altui site, chiar dacă e din flotă.
        $content = wp_kses_post($raw);
        $content = self::clean_links($content, $src_host, $mode);
        if (trim(wp_strip_all_tags($content)) === '') { return new WP_Error('empty', 'Articolul nu are text.'); }

        if (!empty($d['attribution'])) {
            $content .= "\n\n" . '<p class="hz-syn-source"><em>Sursa: <a href="' . esc_url($src)
                      . '" target="_blank" rel="nofollow noopener">' . esc_html($src_host) . '</a></em></p>';
        }

        $status = isset($d['status']) ? (string) $d['status'] : 'publish';
        if (!in_array($status, array('publish', 'draft', 'pending'), true)) { $status = 'draft'; }

        $cat = self::resolve_category(
            isset($d['category_id']) ? $d['category_id'] : 0,
            isset($d['category_name']) ? $d['category_name'] : '',
            !empty($d['create_category'])
        );
        if ($cat <= 0) { $cat = (int) get_option('default_category', 1); }

        $post = array(
            'post_type'     => 'post',
            'post_title'    => $title,
            'post_content'  => $content,
            'post_excerpt'  => isset($d['excerpt']) ? wp_kses_post(mb_substr((string) $d['excerpt'], 0, 2000)) : '',
            'post_status'   => $status,
            'post_author'   => self::author_id(isset($d['author']) ? $d['author'] : 0),
            'post_category' => array($cat),
        );
        $date = isset($d['date_gmt']) ? (string) $d['date_gmt'] : '';
        if (!empty($d['keep_date']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)
            && strtotime($date . ' UTC') !== false && strtotime($date . ' UTC') <= time()) {
            $post['post_date_gmt'] = $date;
            $post['post_date']     = get_date_from_gmt($date);
        }

        $post_id = wp_insert_post(wp_slash($post), true);
        if (is_wp_error($post_id)) { return $post_id; }

        update_post_meta($post_id, self::META_SRC, $src);
        update_post_meta($post_id, self::META_SITE, $src_host);
        if (!empty($d['canonical'])) { update_post_meta($post_id, self::META_CANON, 1); }
        if (!empty($d['rule'])) { update_post_meta($post_id, self::META_RULE, (int) $d['rule']); }

        if (isset($d['tags']) && is_array($d['tags'])) {
            $tags = array();
            foreach (array_slice($d['tags'], 0, 10) as $t) {
                $t = trim(wp_strip_all_tags((string) $t));
                if ($t !== '' && mb_strlen($t) <= 60) { $tags[] = $t; }
            }
            if (!empty($tags)) { wp_set_post_tags($post_id, $tags, false); }
        }

        $img_note = 'fără imagine';
        if (isset($d['image']) && is_array($d['image']) && !empty($d['image']['url'])) {
            $img = self::sideload(
                (string) $d['image']['url'], $src_host, $post_id,
                isset($d['image']['alt']) && $d['image']['alt'] !== '' ? (string) $d['image']['alt'] : $title,
                isset($d['image']['caption']) ? (string) $d['image']['caption'] : '',
                $src
            );
            if (is_wp_error($img)) {
                $img_note = $img->get_error_message();
            } else {
                set_post_thumbnail($post_id, $img);
                $img_note = 'ok';
            }
        }

        return array('ok' => true, 'post_id' => (int) $post_id, 'link' => get_permalink($post_id), 'image' => $img_note);
    }

    /** Poza articolului: doar de pe domeniul sursei, doar imagine reală, mărime limitată. */
    private static function sideload($url, $source_host, $post_id, $alt, $caption, $referer) {
        $url = esc_url_raw(trim($url), array('http', 'https'));
        if ($url === '' || !self::same_site(self::bare_host($url), $source_host)) {
            return new WP_Error('hz_syn_img_host', 'poza e pe alt domeniu decât sursa - sărită');
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam('hz-syn');
        if (!$tmp) { return new WP_Error('hz_syn_img_tmp', 'nu pot crea fișierul temporar pentru poză'); }
        $r = wp_safe_remote_get($url, array(
            'timeout' => 40, 'stream' => true, 'filename' => $tmp, 'redirection' => 2,
            'limit_response_size' => self::MAX_IMAGE,
            'headers' => array('Referer' => $referer, 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8'),
        ));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            @unlink($tmp);
            return new WP_Error('hz_syn_img_dl', 'descărcarea pozei a eșuat ('
                . (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r)) . ')');
        }
        $mime = function_exists('wp_get_image_mime') ? (string) wp_get_image_mime($tmp) : '';
        $exts = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif');
        if (!isset($exts[$mime]) || (int) @filesize($tmp) >= self::MAX_IMAGE) {
            @unlink($tmp);
            return new WP_Error('hz_syn_img_type', 'fișierul descărcat nu e o imagine validă');
        }
        $alt  = trim(wp_strip_all_tags($alt));
        $name = sanitize_file_name(sanitize_title(mb_substr($alt, 0, 80)) . '-' . substr(md5($url), 0, 6) . '.' . $exts[$mime]);
        $file = array('name' => $name, 'tmp_name' => $tmp);
        if (class_exists('HZ_Discover')) { $file = HZ_Discover::fit_sideload($file); }
        $id = media_handle_sideload($file, $post_id, $alt);
        if (is_wp_error($id)) { @unlink($file['tmp_name']); return $id; }
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $caption = trim(wp_strip_all_tags($caption));
        if ($caption !== '') { wp_update_post(array('ID' => $id, 'post_excerpt' => wp_slash(mb_substr($caption, 0, 300)))); }
        return (int) $id;
    }
}
