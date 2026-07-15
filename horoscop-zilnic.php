<?php
/**
 * Plugin Name: Horoscop Zilnic
 * Plugin URI: https://targetseo.ro/
 * Description: Publică automat horoscopul zilnic (toate cele 12 zodii), generat cu Claude prin pluginul Claude Writer. Poate prelua și rescrie complet dintr-o sursă externă sau genera de la zero.
 * Version: 1.1.3
 * Author: Eduard / TargetSEO
 * Text Domain: horoscop-zilnic
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Requires Plugins: claude-writer
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HZ_VERSION', '1.1.3');
define('HZ_PATH', plugin_dir_path(__FILE__));
define('HZ_URL', plugin_dir_url(__FILE__));

require_once HZ_PATH . 'includes/class-hz-horoscope.php';
require_once HZ_PATH . 'includes/class-hz-admin.php';
require_once HZ_PATH . 'includes/class-hz-updater.php';

function hz_init() {
    (new HZ_Admin())->init();
    (new HZ_Horoscope())->init();
    (new HZ_Updater())->init();

    // Generarea se face prin Claude Writer (cheie API, modele, cost tracking).
    if (!class_exists('CW_API')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Horoscop Zilnic:</strong> '
                . esc_html__('necesită pluginul Claude Writer instalat și activ (folosește cheia API și modelele de acolo).', 'horoscop-zilnic')
                . '</p></div>';
        });
    }
}
add_action('plugins_loaded', 'hz_init');

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

    update_option('hz_db_version', HZ_VERSION, 'no');
});

register_activation_hook(__FILE__, function () {
    $defaults = array(
        'hz_enabled'     => 0,
        'hz_hour'        => 7,
        'hz_status'      => 'publish',
        'hz_model'       => '',
        'hz_category'    => 'Horoscop',
        'hz_category_id' => 0,
        'hz_source_url'  => HZ_Horoscope::default_source_url(),
        'hz_image'       => 0,
        'hz_title'         => HZ_Horoscope::default_title(),
        'hz_prompt'        => HZ_Horoscope::default_prompt(),
        'hz_system_prompt' => HZ_Horoscope::default_system_prompt(),
        'hz_auto_update'   => 1,
    );
    foreach ($defaults as $name => $value) {
        add_option($name, $value, '', 'no');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(HZ_Horoscope::HOOK);
});
