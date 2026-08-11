<?php
/**
 * Plugin Name: AI Writer & Conținut Zilnic
 * Plugin URI: https://targetseo.ro/
 * Description: Generează și rescrie articole SEO direct în editor (GPT-5.6 și Claude), creează articole în bulk cu imagini de stock relevante (Magnific), împrospătează articolele existente cu întrebări frecvente și idei-cheie (schema FAQPage) și publică automat conținut zilnic: horoscopul cu toate cele 12 zodii și vremea pe regiuni (date oficiale ANM). Un singur plugin: chei API, costuri, editor, bulk, refresh, horoscop și vreme.
 * Version: 3.5.1
 * Author: Eduard / TargetSEO
 * Text Domain: horoscop-zilnic
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HZ_VERSION', '3.5.1');
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
require_once HZ_PATH . 'includes/class-hz-links.php';
require_once HZ_PATH . 'includes/class-hz-bulk.php';
require_once HZ_PATH . 'includes/class-hz-remote.php';
require_once HZ_PATH . 'includes/class-hz-refresh.php';
require_once HZ_PATH . 'includes/class-hz-admin.php';
require_once HZ_PATH . 'includes/class-hz-updater.php';

function hz_init() {
    (new HZ_Admin())->init();
    (new HZ_Horoscope())->init();
    (new HZ_Weather())->init();
    (new HZ_Bulk())->init();
    (new HZ_Remote())->init();   // v3.5.1: comenzi bulk din panoul central (HMAC)
    (new HZ_Refresh())->init();
    (new HZ_Updater())->init();
}
add_action('plugins_loaded', 'hz_init');

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

    // v3.2.0: modul nou „Refresh conținut" (FAQ + idei-cheie pe articolele existente).
    // Vine OPRIT (hzr_enabled = 0): userul îl pornește per site după ce verifică un test.
    foreach (HZ_Refresh::defaults() as $name => $value) {
        add_option($name, $value, '', 'no');
    }

    // v3.1.10: backdatarea articolelor bulk trebuie pornită pe TOATĂ flota. add_option
    // de mai sus nu atinge site-urile unde opțiunea există deja pe 0 (salvată înainte
    // ca bifa să apară în formular), deci o forțăm O SINGURĂ DATĂ, cu flag propriu —
    // dacă userul o debifează după asta, update-urile viitoare nu i-o mai reactivează.
    if (!get_option('hzb_rd_forced')) {
        update_option('hzb_random_date', 1, 'no');
        update_option('hzb_rd_forced', 1, 'no');
    }

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
        'hzb_images'       => 2,
        'hzb_image_size'   => 'large',
        'hzb_daily_limit'  => 90,
        'hzb_gap'          => 120,
        'hzb_length'       => 1000,
        'hzb_author'       => 0,
        'hzb_ai_excluded'  => 1,
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
    foreach (HZ_Refresh::defaults() as $name => $value) {
        add_option($name, $value, '', 'no');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(HZ_Horoscope::HOOK);
    wp_clear_scheduled_hook(HZ_Weather::HOOK);
    wp_clear_scheduled_hook(HZ_Bulk::HOOK);
    wp_clear_scheduled_hook(HZ_Refresh::HOOK);
});
