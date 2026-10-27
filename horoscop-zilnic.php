<?php
/**
 * Plugin Name: AI Writer & Conținut Zilnic
 * Plugin URI: https://targetseo.ro/
 * Description: Generează și rescrie articole SEO direct în editor (GPT-5.6 și Claude), creează articole în bulk cu imagini de stock relevante (Magnific) și publică automat conținut zilnic: horoscopul cu toate cele 12 zodii și vremea pe regiuni (date oficiale ANM). Un singur plugin: chei API, costuri, editor, bulk, horoscop și vreme.
 * Version: 3.53.0
 * Author: Eduard / TargetSEO
 * Text Domain: horoscop-zilnic
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HZ_VERSION', '3.53.0');
define('HZ_PATH', plugin_dir_path(__FILE__));
define('HZ_URL', plugin_dir_url(__FILE__));

// Motorul AI Writer e INCLUS (v2.0.0). Dacă vechiul plugin separat „claude-writer"
// mai e activ pe site, clasele lui (identice) au prioritate în acest request —
// îl dezactivăm și îl ștergem mai jos, iar din requestul următor rulează copia inclusă.
if (!class_exists('CW_API')) {
    require_once HZ_PATH . 'writer/writer.php';
}

require_once HZ_PATH . 'includes/class-hz-horoscope.php';
require_once HZ_PATH . 'includes/class-hz-weather.php';
require_once HZ_PATH . 'includes/class-hz-magnific.php';
require_once HZ_PATH . 'includes/class-hz-imagegen.php';
require_once HZ_PATH . 'includes/class-hz-links.php';
require_once HZ_PATH . 'includes/class-hz-review.php';
require_once HZ_PATH . 'includes/class-hz-golinks.php';
require_once HZ_PATH . 'includes/class-hz-bulk.php';
require_once HZ_PATH . 'includes/class-hz-rewrite.php';
require_once HZ_PATH . 'includes/class-hz-news.php';
require_once HZ_PATH . 'includes/class-hz-remote.php';
require_once HZ_PATH . 'includes/class-hz-admin.php';
require_once HZ_PATH . 'includes/class-hz-updater.php';

function hz_init() {
    (new HZ_Admin())->init();
    (new HZ_Horoscope())->init();
    (new HZ_Weather())->init();
    (new HZ_Bulk())->init();
    (new HZ_News())->init();     // v3.50.0: știri preluate și rescrise (digi24)
    (new HZ_Remote())->init();   // v3.5.0: comenzi din panoul central (HMAC)
    (new HZ_GoLinks())->init(); // v3.16.0: redirector /go/{slug} pentru linkuri de afiliere
    (new HZ_Updater())->init();
    HZ_ImageGen::boot();        // v3.38.0: miniaturi in WebP, daca optiunea e pornita
}
add_action('plugins_loaded', 'hz_init');

/**
 * v3.9.0: modulul „Refresh conținut" a fost SCOS din plugin.
 *
 * Articolele deja procesate păstrează în conținut blocurile de idei-cheie și
 * întrebări frecvente — deci stilul lor de frontend rămâne, altfel s-ar strica
 * mii de articole publicate. Restul (cron, admin, rută REST) a dispărut, iar
 * opțiunile hzr_* și meta-urile _hzr_* rămân în baza de date ca plasă de
 * siguranță (backup-ul conținutului original stă în _hzr_backup).
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular('post')) { return; }
    $id = get_queried_object_id();
    if (!$id) { return; }
    if (get_post_meta($id, '_hzr_done', true)) {
        wp_enqueue_style('hz-refresh', HZ_URL . 'assets/css/refresh.css', array(), HZ_VERSION);
    }
    // v3.16.0: blocurile de produs din articolele „editorial cu produse". Stilul se
    // incarca doar pe articolele care chiar au asa ceva.
    if (get_post_meta($id, '_hz_review', true)) {
        wp_enqueue_style('hz-review', HZ_URL . 'assets/css/review.css', array(), HZ_VERSION);
    }
});

/**
 * v2.0.0: AI Writer e inclus aici — eliminăm pluginul vechi „claude-writer" de pe site.
 * Ștergere DIRECTĂ a folderului, FĂRĂ uninstall.php (uninstall-ul lui ar șterge
 * opțiunile cw_* — adică exact cheile API și setările pe care le folosim acum).
 */
add_action('init', function () {
    if (get_option('hz_cw_removed')) { return; }
    $old_plugin = 'claude-writer/claude-writer.php';
    if (file_exists(WP_PLUGIN_DIR . '/' . $old_plugin)) {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins($old_plugin, true); // silent: fără hook-uri de deactivare
        hz_delete_dir(WP_PLUGIN_DIR . '/claude-writer');
    }
    update_option('hz_cw_removed', 1); // autoload yes: verificarea să nu coste un query
}, 99);

/**
 * Curățenie v3.8.2: sonda temporară folosită ca să aflăm ce formă de căutare web
 * acceptă API-urile (`includes/class-hz-probe.php`) nu mai face parte din plugin.
 * Upgrade-ul copiază peste fișiere, NU șterge ce a dispărut din pachet — deci pe
 * site-ul unde a fost instalată o ștergem noi, o singură dată.
 */
add_action('init', function () {
    if (get_option('hz_probe_removed_2')) { return; }
    $f = HZ_PATH . 'includes/class-hz-probe.php';
    if (file_exists($f)) { @unlink($f); }
    update_option('hz_probe_removed_2', 1, 'no');
}, 98);

function hz_delete_dir($dir) {
    if (!is_dir($dir)) { return; }
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = $dir . '/' . $item;
        if (is_dir($path)) { hz_delete_dir($path); } else { @unlink($path); }
    }
    @rmdir($dir);
}

/**
 * Migrare la update: seed pentru opțiunile noi pe site-urile deja instalate
 * (activation hook-ul nu rulează la update). Pe `init`, ca să meargă și fără
 * vizită în wp-admin.
 */
add_action('init', function () {
    if (get_option('hz_db_version', '') === HZ_VERSION) {
        return;
    }

    // v1.1.0: system prompt propriu de horoscop (înlocuiește promptul de articole
    // din Claude Writer la generare).
    add_option('hz_system_prompt', HZ_Horoscope::default_system_prompt(), '', 'no');

    // v1.1.0: promptul zilnic implicit s-a scurtat (structura a urcat în system
    // prompt). Îl înlocuim DOAR dacă userul nu l-a editat (conține încă textul vechi).
    $old_prompt = get_option('hz_prompt', '');
    if ($old_prompt !== '' && strpos($old_prompt, 'înlocuiește regulile de structură') !== false) {
        update_option('hz_prompt', HZ_Horoscope::default_prompt(), 'no');
    }

    // v1.1.1: fără liniuța medie (–) în text — intervalele zodiilor trec pe cratimă
    // simplă și regula anti-liniuță acoperă și – (patch chirurgical, păstrează editările).
    $sys = (string) get_option('hz_system_prompt', '');
    if ($sys !== '') {
        $new = str_replace(
            '– fără emoji și fără liniuță lungă (—); folosește virgulă, punct sau paranteze',
            '– fără emoji. INTERZISE în text liniuța lungă (—) și liniuța medie (–): când ai nevoie de o liniuță folosește DOAR cratima simplă (-), altfel virgulă, punct sau paranteze',
            $sys
        );
        // „21 martie – 20 aprilie" -> „21 martie - 20 aprilie" (doar între date calendaristice)
        $new = preg_replace('/(\d{1,2} \p{L}+) – (\d{1,2} \p{L}+)/u', '$1 - $2', $new);
        if (is_string($new) && $new !== $sys) {
            update_option('hz_system_prompt', $new, 'no');
        }
    }

    // v1.1.3: categoria devine alegere din dropdown (ID). Dacă site-ul folosea deja o
    // categorie după nume, o preselectăm ca userul să nu reconfigureze nimic.
    if (false === get_option('hz_category_id', false)) {
        $seed = 0;
        $name = trim((string) get_option('hz_category', ''));
        if ($name !== '') {
            $term = get_term_by('name', $name, 'category');
            if ($term instanceof WP_Term) { $seed = (int) $term->term_id; }
        }
        add_option('hz_category_id', $seed, '', 'no');
    }

    // v3.1.4: autor pentru horoscop („Publică drept"). 0 = fallback pe primul admin.
    add_option('hz_author', 0, '', 'no');

    // v3.0.0: modul nou „Vreme" (articol zilnic cu prognoza pe regiuni, sursă ANM).
    // Seed pentru opțiunile hzw_* pe site-urile deja instalate (add_option nu suprascrie
    // dacă userul a salvat deja ceva).
    $weather_defaults = array(
        'hzw_enabled'       => 0,
        'hzw_hour'          => 8,
        'hzw_status'        => 'publish',
        'hzw_model'         => '',
        'hzw_category'      => 'Vremea',
        'hzw_category_id'   => 0,
        'hzw_author'        => 0,
        'hzw_source_url'    => HZ_Weather::default_source_url(),
        'hzw_image'         => 0,
        'hzw_cite_source'   => 1,
        'hzw_cache_hours'   => 3,
        'hzw_title'         => HZ_Weather::default_title(),
        'hzw_prompt'        => HZ_Weather::default_prompt(),
        'hzw_system_prompt' => HZ_Weather::default_system_prompt(),
    );
    foreach ($weather_defaults as $name => $value) {
        add_option($name, $value, '', 'no');
    }

    // v3.1.0: modul nou „Articole Bulk" (generare în lot + imagini de stock Magnific).
    foreach (hz_bulk_defaults() as $name => $value) {
        add_option($name, $value, '', 'no');
    }

    // v3.9.0: modulul „Refresh conținut" (v3.2.0-3.8.6) a fost scos. Pe site-urile
    // unde rula, oprim procesarea ACUM: fără cron nu mai pornește nimic, dar un
    // eveniment deja programat ar rămâne agățat în wp_cron până la o dezactivare.
    // Opțiunile hzr_* NU se șterg — dacă modulul revine, setările sunt pe loc.
    if (!get_option('hzr_module_removed')) {
        wp_clear_scheduled_hook('hz_refresh_tick');
        delete_transient('hz_refresh_lock');
        delete_transient('hzr_current');
        update_option('hzr_module_removed', HZ_VERSION, 'no');
    }

    // v3.1.10: backdatarea articolelor bulk trebuie pornită pe TOATĂ flota. add_option
    // de mai sus nu atinge site-urile unde opțiunea există deja pe 0 (salvată înainte
    // ca bifa să apară în formular), deci o forțăm O SINGURĂ DATĂ, cu flag propriu —
    // dacă userul o debifează după asta, update-urile viitoare nu i-o mai reactivează.
    if (!get_option('hzb_rd_forced')) {
        update_option('hzb_random_date', 1, 'no');
        update_option('hzb_rd_forced', 1, 'no');
    }

    // v3.8.5: limita zilnică de descărcări Magnific urcă de la 90 la 100 (cota reală a
    // contului). Ridicăm DOAR site-urile rămase pe vechea valoare implicită — dacă
    // userul a pus manual altceva, alegerea lui rămâne. O singură dată, cu flag propriu.
    if (!get_option('hzb_dl100_forced')) {
        if ((int) get_option('hzb_daily_limit', 100) === 90) {
            update_option('hzb_daily_limit', 100, 'no');
        }
        update_option('hzb_dl100_forced', 1, 'no');
    }

    // v3.13.0: UN SINGUR cadru per articol (coverul). Site-urile rămase pe vechea
    // valoare implicită (2 = cover + 1 în articol) coboară la 1; dacă userul a pus
    // manual „Fără imagini", alegerea lui rămâne. O singură dată, cu flag propriu.
    if (!get_option('hzb_img1_forced')) {
        if ((int) get_option('hzb_images', 1) > 1) {
            update_option('hzb_images', 1, 'no');
        }
        update_option('hzb_img1_forced', 1, 'no');
    }

    // v3.50.0: modulul „Știri" — opțiunile hzn_*, pornit singur doar pe site-urile
    // din HZ_News::default_hosts() (comunicateinpresa.ro, revistabravo.ro).
    HZ_News::seed();

    update_option('hz_db_version', HZ_VERSION, 'no');
});

/**
 * v3.4.4 — REPARARE cheie API criptată de două ori.
 * Cheile scrise printr-o unealtă externă care le cripta ea însăși au trecut A DOUA
 * oară prin filtrul `pre_update_option_cw_*_api_key` → în baza de date au rămas cu
 * două straturi, iar apelurile primeau „Incorrect API key: cwenc::…".
 * CW_Crypto::decrypt() desface acum orice număr de straturi, deci funcționarea e
 * deja restabilită; aici doar rescriem valoarea CORECT în baza de date (o singură
 * dată), pasând TEXTUL SIMPLU ca filtrul să-l cripteze exact o dată.
 */
add_action('init', function () {
    if (get_option('cw_key_fix_v1')) { return; }
    if (!class_exists('CW_Crypto')) { return; }

    foreach (array('cw_api_key', 'cw_openai_api_key') as $opt) {
        $stored = (string) get_option($opt, '');
        if ($stored === '') { continue; }
        $plain = CW_Crypto::decrypt($stored);
        // Gol = cheie iremediabil coruptă (n-o atingem, userul o reintroduce);
        // încă „cwenc::" după 3 straturi = la fel. Altfel rescriem textul simplu și
        // lăsăm filtrul să cripteze exact o dată. Pe cheile deja corecte operația e
        // inofensivă (aceeași cheie, alt IV).
        if ($plain === '' || CW_Crypto::is_encrypted($plain)) { continue; }
        update_option($opt, $plain);
    }
    update_option('cw_key_fix_v1', 1, 'no');
}, 101);

/** Valorile implicite ale modulului Articole Bulk (folosite la update ȘI la activare). */
function hz_bulk_defaults() {
    return array(
        'hzb_magnific_key' => '',
        'hzb_images'       => 1,
        'hzb_image_size'   => 'large',
        'hzb_daily_limit'  => 100,
        'hzb_gap'          => 120,
        'hzb_length'       => 1000,
        'hzb_author'       => 0,
        'hzb_ai_excluded'  => 1,
        // v3.35.0: persoanele din fotografii (stock + AI) — 'white' = aspect european.
        'hzb_people_ethnicity' => 'white',
        'hzb_random_date'  => 1,
        'hzb_stopped'      => 0,
    );
}

register_activation_hook(__FILE__, function () {
    $defaults = array(
        'hz_enabled'     => 0,
        'hz_hour'        => 7,
        'hz_status'      => 'publish',
        'hz_model'       => '',
        'hz_category'    => 'Horoscop',
        'hz_category_id' => 0,
        'hz_author'      => 0,
        'hz_source_url'  => HZ_Horoscope::default_source_url(),
        'hz_image'       => 0,
        'hz_title'         => HZ_Horoscope::default_title(),
        'hz_prompt'        => HZ_Horoscope::default_prompt(),
        'hz_system_prompt' => HZ_Horoscope::default_system_prompt(),
        'hz_auto_update'   => 1,
        // Modul Vreme (v3.0.0)
        'hzw_enabled'       => 0,
        'hzw_hour'          => 8,
        'hzw_status'        => 'publish',
        'hzw_model'         => '',
        'hzw_category'      => 'Vremea',
        'hzw_category_id'   => 0,
        'hzw_author'        => 0,
        'hzw_source_url'    => HZ_Weather::default_source_url(),
        'hzw_image'         => 0,
        'hzw_cite_source'   => 1,
        'hzw_cache_hours'   => 3,
        'hzw_title'         => HZ_Weather::default_title(),
        'hzw_prompt'        => HZ_Weather::default_prompt(),
        'hzw_system_prompt' => HZ_Weather::default_system_prompt(),
    );
    foreach ($defaults as $name => $value) {
        add_option($name, $value, '', 'no');
    }
    foreach (hz_bulk_defaults() as $name => $value) {
        add_option($name, $value, '', 'no');
    }
    HZ_News::seed();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(HZ_Horoscope::HOOK);
    wp_clear_scheduled_hook(HZ_Weather::HOOK);
    wp_clear_scheduled_hook(HZ_Bulk::HOOK);
    wp_clear_scheduled_hook(HZ_Bulk::WATCHDOG);
    wp_clear_scheduled_hook(HZ_News::HOOK);
    wp_clear_scheduled_hook('hz_refresh_tick'); // v3.9.0: modul scos, cronul poate fi rămas
});
