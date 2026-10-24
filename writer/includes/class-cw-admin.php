<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pagina de setări + dashboard de cost.
 */
class CW_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_cw_test_key', array($this, 'ajax_test_key'));
        add_action('wp_ajax_cw_test_openai_key', array($this, 'ajax_test_openai_key'));
        add_action('wp_ajax_cw_list_models', array($this, 'ajax_list_models'));
        // Alt server API = altă listă de modele: o luăm din nou la următoarea deschidere.
        add_action('update_option_cw_openai_base', function () {
            delete_transient('cw_gw_fresh');
            delete_option('cw_gateway_models');
        }, 10, 0);
        add_action('admin_notices', array($this, 'credit_notices'));

        // La salvare: dacă e gol (câmp mascat) păstrăm cheia veche; altfel criptăm noua cheie.
        $encrypt_on_save = function ($value, $old) {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                return $old; // câmp lăsat gol -> păstrează cheia (criptată) existentă
            }
            return CW_Crypto::encrypt($value);
        };
        add_filter('pre_update_option_cw_api_key', $encrypt_on_save, 10, 2);
        add_filter('pre_update_option_cw_openai_api_key', $encrypt_on_save, 10, 2);
        // Chei OpenAI per modul (horoscop / vreme / imagini) — aceeasi criptare la salvare.
        add_filter('pre_update_option_cw_openai_key_horoscope', $encrypt_on_save, 10, 2);
        add_filter('pre_update_option_cw_openai_key_weather', $encrypt_on_save, 10, 2);
        add_filter('pre_update_option_cw_openai_key_images', $encrypt_on_save, 10, 2);
    }

    public function menu() {
        // Pagina UNICĂ de setări a pluginului: tab „AI Writer" + „Horoscop Zilnic" + „Vreme".
        add_options_page(
            __('AI Writer & Conținut Zilnic', 'claude-writer'),
            __('AI Writer & Conținut Zilnic', 'claude-writer'),
            'manage_options',
            'claude-writer',
            array($this, 'render_page')
        );
    }

    public function enqueue($hook) {
        if ($hook !== 'settings_page_claude-writer') { return; }
        wp_enqueue_style('cw-admin', CW_URL . 'assets/css/admin.css', array(), CW_VERSION);
        wp_enqueue_script('cw-admin', CW_URL . 'assets/js/admin.js', array('jquery'), CW_VERSION, true);
        wp_localize_script('cw-admin', 'CWAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cw_admin_nonce'),
        ));
    }

    /** Banner roșu în tot wp-admin cât timp un provider e rămas fără credit. */
    public function credit_notices() {
        if (!current_user_can('manage_options')) { return; }
        $labels = array('openai' => 'OpenAI (GPT)', 'anthropic' => 'Anthropic (Claude)');
        foreach ($labels as $provider => $label) {
            $err = get_option('cw_credit_error_' . $provider);
            if (!is_array($err) || empty($err['time'])) { continue; }
            echo '<div class="notice notice-error"><p><strong>AI Writer:</strong> ' . esc_html(sprintf(
                /* translators: %s: numele providerului */
                __('Creditele API %s s-au terminat — generarea (articole + horoscop zilnic) eșuează. Alimentează contul; alerta dispare singură la prima generare reușită.', 'claude-writer'),
                $label
            )) . '</p></div>';
        }
    }

    public function register_settings() {
        $opts = array(
            'cw_api_key'            => 'cw_sanitize_key',
            'cw_openai_api_key'     => 'cw_sanitize_key',
            'cw_openai_base'        => 'esc_url_raw',
            'cw_openai_key_images'  => 'cw_sanitize_key',
            'cw_default_model'      => 'sanitize_text_field',
            'cw_effort'             => 'sanitize_text_field',
            'cw_temperature'        => 'cw_sanitize_float',
            'cw_max_tokens'         => 'absint',
            'cw_stream_enabled'     => 'absint',
            'cw_monthly_cost_limit' => 'cw_sanitize_float',
            'cw_web_search'         => 'absint',
            'cw_web_search_max'     => 'absint',
            'cw_web_search_cost'    => 'cw_sanitize_float',
            'cw_system_prompt'      => 'cw_sanitize_prompt',
            'cw_article_prompt'     => 'cw_sanitize_prompt',
            'cw_rewrite_prompt'     => 'cw_sanitize_prompt',
            'cw_title_prompt'       => 'cw_sanitize_prompt',
            'cw_keywords_prompt'    => 'cw_sanitize_prompt',
            'cw_disclaimer'         => 'sanitize_textarea_field',
            'cw_alert_email'        => 'sanitize_email',
        );
        foreach ($opts as $name => $cb) {
            register_setting('cw_settings', $name, array('sanitize_callback' => $cb));
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) { return; }

        $allowed  = array('writer', 'horoscop', 'vreme', 'bulk', 'stiri');
        $tab      = (isset($_GET['tab']) && in_array($_GET['tab'], $allowed, true)) ? $_GET['tab'] : 'writer';
        $base_url = admin_url('options-general.php?page=claude-writer');

        // v3.51.3: cu server API setat, lista lui de modele se ia singură (cache 12 ore,
        // la eșec reîncercăm după 30 de minute) — fără să mai apeși vreun buton.
        if (CW_API::openai_base() !== CW_API::OPENAI_BASE && !get_transient('cw_gw_fresh')) {
            $r = CW_API::refresh_gateway_models();
            set_transient('cw_gw_fresh', 1, is_wp_error($r) ? 30 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS);
        }
        ?>
        <div class="wrap cw-wrap">
            <h1><?php esc_html_e('AI Writer & Conținut Zilnic', 'claude-writer'); ?></h1>

            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url($base_url); ?>" class="nav-tab <?php echo $tab === 'writer' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('AI Writer', 'claude-writer'); ?></a>
                <a href="<?php echo esc_url($base_url . '&tab=horoscop'); ?>" class="nav-tab <?php echo $tab === 'horoscop' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Horoscop Zilnic', 'claude-writer'); ?></a>
                <a href="<?php echo esc_url($base_url . '&tab=vreme'); ?>" class="nav-tab <?php echo $tab === 'vreme' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Vreme', 'claude-writer'); ?></a>
                <a href="<?php echo esc_url($base_url . '&tab=bulk'); ?>" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Articole Bulk', 'claude-writer'); ?></a>
                <a href="<?php echo esc_url($base_url . '&tab=stiri'); ?>" class="nav-tab <?php echo $tab === 'stiri' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Știri', 'claude-writer'); ?></a>
            </h2>

        <?php
        if ($tab === 'horoscop') {
            if (class_exists('HZ_Admin')) {
                (new HZ_Admin())->render_tab();
            }
            echo '</div>';
            return;
        }
        if ($tab === 'vreme') {
            if (class_exists('HZ_Admin')) {
                (new HZ_Admin())->render_weather_tab();
            }
            echo '</div>';
            return;
        }
        if ($tab === 'bulk') {
            if (class_exists('HZ_Admin')) {
                (new HZ_Admin())->render_bulk_tab();
            }
            echo '</div>';
            return;
        }
        if ($tab === 'stiri') {
            if (class_exists('HZ_News')) {
                (new HZ_News())->render_tab();
            }
            echo '</div>';
            return;
        }
        $models  = CW_Models::all();
        $by      = get_option('cw_usage_by_model', array());
        $total   = (float) get_option('cw_cost_total', 0);
        $calls   = (int) get_option('cw_calls_total', 0);
        $month   = CW_Usage::month_cost();
        $limit   = (float) get_option('cw_monthly_cost_limit', 0);
        ?>
            <?php
            // Avertisment: modelul implicit e pe un provider fără cheie configurată.
            $default_provider = CW_Models::provider(CW_Models::default_model());
            if (!CW_API::has_provider_key($default_provider) && CW_API::has_key()) :
                $missing = $default_provider === 'openai' ? 'OpenAI' : 'Anthropic';
            ?>
                <div class="notice notice-warning"><p><?php echo esc_html(sprintf(
                    __('Modelul implicit (%1$s) are nevoie de cheia API %2$s, care nu e configurată — între timp generarea folosește automat celălalt provider. Adaugă cheia %2$s mai jos.', 'claude-writer'),
                    CW_Models::get(CW_Models::default_model())['label'],
                    $missing
                )); ?></p></div>
            <?php endif; ?>

            <div class="cw-grid">
                <!-- Dashboard cost -->
                <div class="cw-card">
                    <h2><?php esc_html_e('Cost & utilizare', 'claude-writer'); ?></h2>
                    <div class="cw-stats">
                        <div class="cw-stat">
                            <span class="cw-stat-num">$<?php echo esc_html(number_format($month, 2)); ?></span>
                            <span class="cw-stat-lbl"><?php esc_html_e('luna curentă', 'claude-writer'); ?></span>
                        </div>
                        <div class="cw-stat">
                            <span class="cw-stat-num">$<?php echo esc_html(number_format($total, 2)); ?></span>
                            <span class="cw-stat-lbl"><?php esc_html_e('total', 'claude-writer'); ?></span>
                        </div>
                        <div class="cw-stat">
                            <span class="cw-stat-num"><?php echo esc_html($calls); ?></span>
                            <span class="cw-stat-lbl"><?php esc_html_e('generări', 'claude-writer'); ?></span>
                        </div>
                    </div>

                    <?php if ($limit > 0) : ?>
                        <?php $pct = min(100, ($month / $limit) * 100); ?>
                        <div class="cw-bar"><span style="width:<?php echo esc_attr($pct); ?>%"></span></div>
                        <p class="cw-muted"><?php echo esc_html(sprintf(__('%1$s din limita de $%2$s pe lună', 'claude-writer'), '$' . number_format($month, 2), number_format($limit, 2))); ?></p>
                    <?php endif; ?>

                    <table class="cw-table">
                        <thead><tr>
                            <th><?php esc_html_e('Model', 'claude-writer'); ?></th>
                            <th><?php esc_html_e('Generări', 'claude-writer'); ?></th>
                            <th><?php esc_html_e('Cost', 'claude-writer'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($models as $id => $m) :
                            $row = isset($by[$id]) ? $by[$id] : array('calls' => 0, 'cost' => 0); ?>
                            <tr>
                                <td><?php echo esc_html($m['label']); ?></td>
                                <td><?php echo esc_html((int) $row['calls']); ?></td>
                                <td>$<?php echo esc_html(number_format((float) $row['cost'], 4)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="cw-muted"><?php esc_html_e('Prețuri / 1M tokeni (input/output): GPT-5.6 Luna 1$/6$ · Terra 2.5$/15$ · Sol 5$/30$ · GPT-5.5 5$/30$ · GPT-5.4 2.5$/15$ · 5.4 Mini 0.75$/4.5$ · GPT-5.1 1.25$/10$ · Claude Haiku 1$/5$ · Sonnet 3$/15$ · Opus 5$/25$.', 'claude-writer'); ?></p>
                </div>

                <!-- Setări -->
                <div class="cw-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('cw_settings'); ?>

                        <h2><?php esc_html_e('Conexiune', 'claude-writer'); ?></h2>
                        <?php
                        $stored_oai   = (string) get_option('cw_openai_api_key', '');
                        $has_oai      = trim($stored_oai) !== '';
                        $is_enc_oai   = CW_Crypto::is_encrypted($stored_oai);
                        $stored_key   = (string) get_option('cw_api_key', '');
                        $has_key      = trim($stored_key) !== '';
                        $is_enc       = CW_Crypto::is_encrypted($stored_key);
                        ?>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Cheie API OpenAI (modelele GPT)', 'claude-writer'); ?></label>
                            <input type="text" name="cw_openai_api_key" id="cw_openai_api_key" class="widefat"
                                value="" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                data-lpignore="true" data-1p-ignore="true" data-form-type="other" readonly
                                onfocus="this.removeAttribute('readonly');"
                                placeholder="<?php echo $has_oai ? esc_attr__('configurată — lipește o cheie nouă doar dacă vrei s-o schimbi', 'claude-writer') : 'sk-proj-...'; ?>" />
                            <button type="button" class="button" id="cw-test-openai-key"><?php esc_html_e('Testează cheia salvată', 'claude-writer'); ?></button>
                            <span id="cw-test-openai-result" class="cw-test-result"></span>
                            <button type="button" class="button" id="cw-list-models"><?php esc_html_e('Vezi modelele disponibile', 'claude-writer'); ?></button>
                            <span id="cw-list-models-result" class="cw-test-result" style="display:block;white-space:pre-wrap;margin-top:6px;"></span>
                            <?php if ($has_oai && $is_enc_oai) : ?>
                                <span class="cw-muted" style="display:block;margin-top:4px;">🔒 <?php esc_html_e('Cheia este stocată criptat (AES-256-GCM).', 'claude-writer'); ?></span>
                            <?php elseif ($has_oai && !$is_enc_oai) : ?>
                                <span class="cw-muted" style="display:block;margin-top:4px;">ℹ️ <?php esc_html_e('Cheia este stocată în clar (criptarea nu a fost posibilă pe acest server).', 'claude-writer'); ?></span>
                            <?php endif; ?>
                        </p>
                        <p>
                            <label class="cw-label" for="cw_openai_base"><?php esc_html_e('Server API pentru modelele GPT (opțional)', 'claude-writer'); ?></label>
                            <input type="url" name="cw_openai_base" id="cw_openai_base" class="widefat"
                                value="<?php echo esc_attr(get_option('cw_openai_base', '')); ?>" placeholder="https://api.openai.com/v1" />
                            <label class="cw-label" for="cw_openai_key_images" style="margin-top:10px;"><?php esc_html_e('Cheie OpenAI pentru imagini AI (opțional)', 'claude-writer'); ?></label>
                            <input type="text" name="cw_openai_key_images" id="cw_openai_key_images" class="widefat" value="" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');"
                                placeholder="<?php echo trim((string) get_option('cw_openai_key_images', '')) !== '' ? esc_attr__('configurată — lipește o cheie nouă doar dacă vrei s-o schimbi', 'claude-writer') : 'sk-proj-...'; ?>" />
                            <span class="cw-muted" style="display:block;margin-top:4px;"><?php esc_html_e('Pusă aici, imaginile AI (colajele) se generează la OpenAI direct, chiar dacă textul merge prin alt server.', 'claude-writer'); ?></span>
                            <span class="cw-muted" style="display:block;margin-top:4px;"><?php esc_html_e('Gol = OpenAI direct. Pentru un server compatibil OpenAI (ex. https://api.ns.fm/v1) pune adresa lui, iar mai sus cheia primită de la el. Se folosește pentru text, căutare web și imagini.', 'claude-writer'); ?></span>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Cheie API Anthropic (modelele Claude)', 'claude-writer'); ?></label>
                            <input type="text" name="cw_api_key" id="cw_api_key" class="widefat"
                                value="" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                data-lpignore="true" data-1p-ignore="true" data-form-type="other" readonly
                                onfocus="this.removeAttribute('readonly');"
                                placeholder="<?php echo $has_key ? esc_attr__('configurată — lipește o cheie nouă doar dacă vrei s-o schimbi', 'claude-writer') : 'sk-ant-...'; ?>" />
                            <span class="cw-muted" style="display:block;margin-top:4px;"><?php esc_html_e('Dacă browserul completează automat câmpurile, șterge tot și lipește cheia ta reală înainte să salvezi.', 'claude-writer'); ?></span>
                            <button type="button" class="button" id="cw-test-key"><?php esc_html_e('Testează cheia salvată', 'claude-writer'); ?></button>
                            <span id="cw-test-result" class="cw-test-result"></span>
                            <?php if ($has_key && $is_enc) : ?>
                                <span class="cw-muted" style="display:block;margin-top:4px;">🔒 <?php esc_html_e('Cheia este stocată criptat (AES-256-GCM).', 'claude-writer'); ?></span>
                            <?php elseif ($has_key && !$is_enc) : ?>
                                <span class="cw-muted" style="display:block;margin-top:4px;">ℹ️ <?php esc_html_e('Cheia este stocată în clar (criptarea nu a fost posibilă pe acest server).', 'claude-writer'); ?></span>
                            <?php endif; ?>
                        </p>

                        <h2><?php esc_html_e('Model & generare', 'claude-writer'); ?></h2>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Model implicit', 'claude-writer'); ?></label>
                            <select name="cw_default_model" class="widefat">
                                <?php $dm = CW_Models::default_model(); $gw = CW_Models::gateway(); ?>
                                <?php if ($gw) : // server API setat: doar modelele de pe el (OpenAI direct nu mai e folosit) ?>
                                <optgroup label="<?php echo esc_attr(CW_Models::gateway_label()); ?>">
                                <?php foreach ($gw as $id => $m) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $dm); ?>><?php echo esc_html($m['label'] === $id ? $id : $m['label'] . ' (' . $id . ')'); ?></option>
                                <?php endforeach; ?>
                                </optgroup>
                                <?php else : ?>
                                <optgroup label="OpenAI (GPT)">
                                <?php foreach ($models as $id => $m) : if ($m['provider'] !== 'openai' || !empty($m['gateway'])) { continue; } ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $dm); ?>>
                                        <?php echo esc_html($m['label'] . ' — ' . $m['desc']); ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <optgroup label="Anthropic (Claude)">
                                <?php foreach ($models as $id => $m) : if ($m['provider'] !== 'anthropic') { continue; } ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $dm); ?>>
                                        <?php echo esc_html($m['label'] . ' — ' . $m['desc']); ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Effort / raționament (GPT-5.6, Sonnet & Opus)', 'claude-writer'); ?></label>
                            <?php $eff = get_option('cw_effort', 'medium'); ?>
                            <select name="cw_effort" class="widefat">
                                <option value="low"    <?php selected($eff, 'low'); ?>><?php esc_html_e('Low — cel mai ieftin', 'claude-writer'); ?></option>
                                <option value="medium" <?php selected($eff, 'medium'); ?>><?php esc_html_e('Medium — echilibrat', 'claude-writer'); ?></option>
                                <option value="high"   <?php selected($eff, 'high'); ?>><?php esc_html_e('High — calitate maximă', 'claude-writer'); ?></option>
                            </select>
                            <span class="cw-muted"><?php esc_html_e('Controlul de cost/profunzime. Pe GPT-5.6 se trimite ca reasoning_effort, pe Sonnet/Opus ca effort (Haiku nu îl suportă).', 'claude-writer'); ?></span>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Temperature (doar Claude Haiku & Sonnet; GPT-5.6 nu o acceptă) — controlează creativitatea', 'claude-writer'); ?> — <span id="cw-temp-val"><?php echo esc_html(get_option('cw_temperature', 0.8)); ?></span></label>
                            <input type="range" name="cw_temperature" id="cw_temperature" min="0" max="1" step="0.1" value="<?php echo esc_attr(get_option('cw_temperature', 0.8)); ?>" class="widefat" />
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Max tokens per generare', 'claude-writer'); ?></label>
                            <input type="number" name="cw_max_tokens" class="widefat" min="256" max="16000" step="100" value="<?php echo esc_attr(get_option('cw_max_tokens', 8000)); ?>" />
                            <span class="cw-muted"><?php esc_html_e('În română ~400–500 cuvinte la 1000 tokeni (diacriticele consumă mai mult). 8000 acoperă un articol de ~2000 de cuvinte; plătești doar cât se generează efectiv. Pentru articole lungi activează streaming-ul.', 'claude-writer'); ?></span>
                        </p>
                        <p>
                            <label><input type="checkbox" name="cw_stream_enabled" value="1" <?php checked(1, (int) get_option('cw_stream_enabled', 0)); ?> /> <?php esc_html_e('Activează streaming (text afișat în timp real)', 'claude-writer'); ?></label>
                        </p>
                        
                        <p>
                            <label><input type="checkbox" name="cw_web_search" value="1" <?php checked(1, (int) get_option('cw_web_search', 0)); ?> /> <?php esc_html_e('Caută pe web înainte de a scrie (informații la zi)', 'claude-writer'); ?></label>
                            <span class="cw-muted"><?php esc_html_e('Modelul face căutări reale și citează surse — util pentru subiecte care depind de „acum" (prețuri, legislație, noutăți). Costă în plus ~$0.01 per căutare, peste tokeni, și adaugă zeci de secunde. NU se aplică pe streaming (butoanele din editor). Poate fi pornit și doar pentru anumite loturi, din panoul central.', 'claude-writer'); ?></span>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Căutări maxime per articol', 'claude-writer'); ?></label>
                            <input type="number" name="cw_web_search_max" class="widefat" min="1" max="10" step="1" value="<?php echo esc_attr(get_option('cw_web_search_max', 3)); ?>" />
                            <span class="cw-muted"><?php esc_html_e('Plafonul e pe APELURI de căutare (unitatea taxată). Un apel poate trimite mai multe interogări deodată, deci în notă poți vedea „2 căutări (5 interogări)" — se plătesc cele 2. Pe Claude limita e impusă de API; pe GPT o cerem prin buget de apeluri + instrucțiune în prompt (măsurat: se respectă, dar e o cerință, nu un zid).', 'claude-writer'); ?></span>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Cost estimat per căutare ($) — doar pentru evidența costurilor', 'claude-writer'); ?></label>
                            <input type="number" name="cw_web_search_cost" class="widefat" min="0" step="0.001" value="<?php echo esc_attr(get_option('cw_web_search_cost', 0.01)); ?>" />
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Limită lunară de cheltuieli ($, 0 = fără limită)', 'claude-writer'); ?></label>
                            <input type="number" name="cw_monthly_cost_limit" class="widefat" min="0" step="1" value="<?php echo esc_attr(get_option('cw_monthly_cost_limit', 0)); ?>" />
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('E-mail pentru alerte (credite API epuizate)', 'claude-writer'); ?></label>
                            <input type="email" name="cw_alert_email" class="widefat" value="<?php echo esc_attr(get_option('cw_alert_email', 'kreatorteam@gmail.com')); ?>" />
                            <span class="cw-muted"><?php esc_html_e('Când o cheie API rămâne fără credit, alerta pleacă aici (max una la 3 zile per provider).', 'claude-writer'); ?></span>
                        </p>

                        <h2><?php esc_html_e('Prompturi', 'claude-writer'); ?></h2>
                        <p>
                            <label class="cw-label"><?php esc_html_e('System prompt (stilul de scriere)', 'claude-writer'); ?></label>
                            <textarea name="cw_system_prompt" class="widefat cw-mono" rows="8"><?php echo esc_textarea(get_option('cw_system_prompt', self::default_system_prompt())); ?></textarea>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Articol — folosește {{continut}} și {{cuvinte}}', 'claude-writer'); ?></label>
                            <textarea name="cw_article_prompt" class="widefat cw-mono" rows="4"><?php echo esc_textarea(get_option('cw_article_prompt', self::default_article_prompt())); ?></textarea>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Rescriere — folosește {{continut}}', 'claude-writer'); ?></label>
                            <textarea name="cw_rewrite_prompt" class="widefat cw-mono" rows="3"><?php echo esc_textarea(get_option('cw_rewrite_prompt', self::default_rewrite_prompt())); ?></textarea>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Titlu — folosește {{continut}}', 'claude-writer'); ?></label>
                            <textarea name="cw_title_prompt" class="widefat cw-mono" rows="2"><?php echo esc_textarea(get_option('cw_title_prompt', self::default_title_prompt())); ?></textarea>
                        </p>
                        <p>
                            <label class="cw-label"><?php esc_html_e('Cuvinte cheie — folosește {{continut}}', 'claude-writer'); ?></label>
                            <textarea name="cw_keywords_prompt" class="widefat cw-mono" rows="2"><?php echo esc_textarea(get_option('cw_keywords_prompt', self::default_keywords_prompt())); ?></textarea>
                        </p>

                        <p>
                            <label class="cw-label"><?php esc_html_e('Notă de final (disclaimer) — rezervă garantată', 'claude-writer'); ?></label>
                            <textarea name="cw_disclaimer" class="widefat cw-mono" rows="3"><?php echo esc_textarea(get_option('cw_disclaimer', self::default_disclaimer())); ?></textarea>
                            <span class="cw-muted"><?php esc_html_e('Apare ca blockquote la finalul fiecărui articol. Dacă modelul scrie deja o notă, o folosim pe a lui; altfel se pune asta. Adaptează la nișa site-ului (ex. „consultă medicul" pe site medical).', 'claude-writer'); ?></span>
                        </p>

                        <?php submit_button(__('Salvează setările', 'claude-writer')); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_test_key() {
        check_ajax_referer('cw_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permisiune refuzată.', 'claude-writer')));
        }
        // Testăm EXACT cheia care va fi folosită la generare (cea salvată + decriptată),
        // nu cea tastată. Așa „conexiune OK" garantează că și generarea va merge.
        $key = CW_API::api_key();
        if ($key === '') {
            wp_send_json_error(array('message' => __('Salvează întâi cheia, apoi testează.', 'claude-writer')));
        }

        $response = wp_remote_post(CW_API::ENDPOINT, array(
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => CW_API::VERSION,
                'content-type'      => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 8,
                'messages'   => array(array('role' => 'user', 'content' => 'ping')),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success(array('message' => __('Cheie validă, conexiune OK.', 'claude-writer')));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $msg  = isset($body['error']['message']) ? $body['error']['message'] : __('Cheie invalidă.', 'claude-writer');
        wp_send_json_error(array('message' => sprintf('(%d) %s', $code, $msg)));
    }

    public function ajax_test_openai_key() {
        check_ajax_referer('cw_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permisiune refuzată.', 'claude-writer')));
        }
        $key = CW_API::openai_key();
        if ($key === '') {
            wp_send_json_error(array('message' => __('Salvează întâi cheia, apoi testează.', 'claude-writer')));
        }

        $response = wp_remote_post(CW_API::openai_url('chat/completions'), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'content-type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                // v3.51.1: modelul implicit al site-ului (dacă e GPT), nu unul fix — un
                // gateway poate să nu aibă gpt-5.6-luna, iar testul ar pica degeaba.
                'model'                 => CW_Models::provider(CW_Models::default_model()) === 'openai' ? CW_Models::default_model() : 'gpt-5.4-mini',
                'max_completion_tokens' => 16,
                'messages'              => array(array('role' => 'user', 'content' => 'ping')),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success(array('message' => __('Cheie validă, conexiune OK.', 'claude-writer')));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $msg  = isset($body['error']['message']) ? $body['error']['message'] : __('Cheie invalidă.', 'claude-writer');
        wp_send_json_error(array('message' => sprintf('(%d) %s', $code, $msg)));
    }

    /**
     * v3.51.1: lista modelelor de pe serverul GPT configurat (GET {server}/models), cu
     * cheia salvată — ca să vezi ce nume de model acceptă un gateway (ex. api.ns.fm).
     */
    public function ajax_list_models() {
        check_ajax_referer('cw_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permisiune refuzată.', 'claude-writer')));
        }
        $ids = CW_API::refresh_gateway_models();
        if (is_wp_error($ids)) {
            wp_send_json_error(array('message' => $ids->get_error_message()));
        }
        $known = array_filter($ids, function ($id) { return array_key_exists($id, CW_Models::builtin()); });
        wp_send_json_success(array(
            'message' => sprintf(__('%1$d modele pe %2$s. Recunoscute de plugin: %3$s', 'claude-writer'),
                count($ids), CW_API::openai_base(), $known ? implode(', ', $known) : __('niciunul', 'claude-writer'))
                . "\n" . implode(', ', $ids)
                . (CW_API::openai_base() !== CW_API::OPENAI_BASE ? "\n" . __('Lista e salvată: modelele apar în „Model implicit” (reîncarcă pagina).', 'claude-writer') : ''),
        ));
    }

    /* ---- Prompturi implicite ---- */

    public static function default_system_prompt() {
        return <<<'TXT'
Rol: Ești redactor senior la o publicație online din România, cu 10+ ani de experiență în jurnalism digital. Ai scris pentru publicații precum libertatea.ro, elle.ro, avantaje.ro, click.ro sau viva.ro. Cunoști foarte bine ce funcționează pe Google Discover și ce aduce trafic organic constant. Scrii ca un om real, cu opinii, observații și exemple din viața de zi cu zi, nu ca un asistent AI care livrează informații sterile.

Sarcina: Pe baza temei, titlului sau brief-ului primit, redactează un articol complet, original și captivant, gata de publicat. Articolul trebuie să se citească natural, să aibă voce editorială proprie și să nu lase nicio urmă că ar fi fost scris de o inteligență artificială.

═══════════════════════════════════════
ADAPTARE LA NIȘĂ
═══════════════════════════════════════

Înainte de redactare, identifică din brief nișa articolului și ajustează automat:

– vocabularul la nivelul publicului acelei nișe (mai tehnic pentru auto/tehnologie, mai conversațional pentru lifestyle/parenting, mai precis pentru sănătate/financiar)
– exemplele concrete relevante pentru domeniu (mărci, situații, scenarii reale specifice nișei)
– tipul de specialist recomandat în disclaimer (medic, dermatolog, nutriționist, avocat, consultant financiar, mecanic auto, electrician autorizat, veterinar, psiholog, agent imobiliar etc.)
– tonul – prietenos-conversațional pentru lifestyle/beauty/parenting, mai sobru pentru financiar/juridic/medical, entuziast-curios pentru tehnologie/auto, cald-empatic pentru sănătate/relații

Nișe sensibile – reguli suplimentare obligatorii:

– Medical / farmaceutic: nu da diagnostice, nu recomanda doze, nu sugera tratamente specifice. Folosește formulări precum „discută cu medicul tău”, „specialiștii recomandă de regulă”, „în general”. Marchează clar că nu înlocuiește consultul medical.
– Juridic: nu da sfaturi juridice punctuale. Explică reguli și principii generale, dar trimite la avocat pentru situații individuale. Verifică dacă legislația citată e încă în vigoare în 2026.
– Financiar / investiții / credite: nu da recomandări de investiții, nu sugera produse bancare concrete drept „cele mai bune”. Marchează clar că nu este sfat financiar personalizat.
– Sănătate mintală: ton empatic, fără minimalizare, fără rețete simpliste. Recomandă specialist (psiholog, psihiatru) pentru situații serioase.
– Parenting: respectă diversitatea stilurilor de creștere, evită tonul de „singura cale corectă”.

═══════════════════════════════════════
REGULA #1: ARTICOLUL NU TREBUIE SĂ PARĂ SCRIS DE AI
═══════════════════════════════════════

Modele de exprimare INTERZISE (semnale clare de text AI):
– „într-o lume în continuă schimbare”, „în peisajul actual”, „în era digitală”, „în zilele noastre”
– „este esențial”, „este crucial”, „joacă un rol esențial/crucial/important”
– „nu doar că..., ci și...”
– „merită menționat că”, „este important de reținut că”, „trebuie subliniat faptul că”
– „în concluzie”, „prin urmare”, „așadar”, „pe scurt”, „în final”
– „o gamă largă de”, „o multitudine de”, „o varietate de opțiuni”
– „soluție ideală”, „alegerea perfectă”, „opțiunea optimă”
– „să exploreze”, „să navigheze prin”, „să descopere lumea”
– fraze paralele rigide („nu doar X, dar și Y; nu doar A, dar și B”)
– concluzii care rezumă tot ce s-a spus mai sus
– liste cu bullet-uri perfect simetrice, fiecare început cu verb la infinitiv

În schimb, scrie ca un om:
– începe propoziții cu „Și”, „Dar”, „Pentru că” atunci când curge natural
– alternează fraze foarte scurte (3–5 cuvinte) cu fraze medii și lungi
– folosește expresii reale din limba vorbită: „de fapt”, „pe bune”, „chestia e că”, „așa se face că”, „nu prea”, „cam așa”, „de regulă” (adaptate la tonul nișei)
– pune întrebări retorice care chiar invită cititorul să se gândească
– strecoară o observație personală, o paranteză, un mic comentariu lateral
– admite nuanțe și excepții („depinde”, „nu întotdeauna”, „uneori funcționează, alteori nu”)
– folosește exemple concrete, cu cifre, situații, nume reale de produse/locuri
– fără emoji. INTERZISE în text liniuța lungă (—) și liniuța medie (–): când ai nevoie de o liniuță folosește DOAR cratima simplă (-), altfel virgulă, punct sau paranteze

═══════════════════════════════════════
LEAD-UL (primul paragraf)
═══════════════════════════════════════

Primul paragraf decide totul. Trebuie să:
– pornească de la o situație concretă, o întrebare, o cifră surprinzătoare sau o observație care prinde
– spună imediat de ce subiectul contează pentru cititor (beneficiul direct)
– conțină cuvântul-cheie principal în mod natural
– fie scurt: 2–4 propoziții, maximum 60 de cuvinte
– NU înceapă cu definiții, contexte istorice, generalități

Modele de lead care funcționează:
– întrebare directă către cititor
– scenă concretă din viața reală
– cifră sau date noi care surprind
– afirmație contraintuitivă urmată de explicație

═══════════════════════════════════════
OPTIMIZARE SEO (natural, nu forțat)
═══════════════════════════════════════

– Identifică din brief cuvântul-cheie principal și 2–3 secundare
– Cuvântul-cheie principal apare: în primul paragraf, în cel puțin un H2, în corpul articolului de 3–5 ori (în funcție de lungime)
– Cuvintele-cheie secundare și sinonimele apar natural în subtitluri și paragrafe
– Folosește termeni înrudiți semantic (LSI keywords), nu doar repetiții
– Subtitlurile H2 trebuie să conțină cuvinte pe care oamenii chiar le caută în Google
– Lungime: articol standard 900–1000 cuvinte; pentru ghiduri, tutoriale, liste pas-cu-pas sau teme complexe extinde la 1500–2000 de cuvinte (fără a umfla artificial)
– Răspunde clar la întrebarea principală în primele 100 de cuvinte (pentru featured snippets)
– Include cel puțin o secțiune cu informații sub formă de listă scurtă (Google iubește asta pentru rich results)

═══════════════════════════════════════
OPTIMIZARE GOOGLE DISCOVER
═══════════════════════════════════════

Google Discover prioritizează articole care:
– au un unghi proaspăt, util și actual (nu reciclează informații generale)
– promit o utilitate clară încă din titlu și lead
– au paragrafe scurte și ușor de scanat pe mobil
– conțin informații cu valoare adăugată (sfaturi practice, comparații, exemple concrete)
– NU au titluri clickbait agresive, dar sunt suficient de atrăgătoare

Unghiul editorial trebuie să fie unul dintre:
– „cum să faci X” (utilitate directă)
– „de ce se întâmplă Y” (curiozitate + explicație)
– „ce s-a schimbat în 2026 la Z” (actualitate)
– „top/comparație/recomandări reale” (decizie de cumpărare)
– „greșeli pe care le fac majoritatea” (avertisment util)

═══════════════════════════════════════
DOCUMENTARE ȘI ACURATEȚE
═══════════════════════════════════════

– Pentru prețuri, topuri, produse, statistici, reguli legale, recomandări medicale sau orice date care se pot schimba, folosește DOAR informații de care ești sigur; dacă nu ești sigur, formulează prudent în loc să inventezi
– Folosește doar branduri, instituții, produse și servicii REALE, verificabile, disponibile în România în 2026
– NU inventa: statistici, citate, studii, nume de specialiști, cifre exacte, prețuri
– Dacă nu ai date sigure, formulează prudent („în general”, „de obicei”, „mulți specialiști recomandă”)
– Pentru topuri de produse: prezintă caracteristici reale, diferențe practice, situații concrete în care un produs e mai potrivit decât altul
– Nu menționa magazinele/site-urile de unde se cumpără produsele, decât dacă utilizatorul cere explicit

═══════════════════════════════════════
STRUCTURA ARTICOLULUI
═══════════════════════════════════════

1. Lead (fără titlu în livrare) – 2–4 propoziții care prind
2. Primul H2 – răspunde direct la întrebarea principală
3. 3–5 secțiuni H2 cu dezvoltare logică
4. H3 doar acolo unde un H2 trebuie spart în subteme
5. Paragrafe scurte: 2–4 propoziții, maximum 60–70 de cuvinte
6. Liste doar când chiar ajută la claritate (nu pentru a umfla)
7. Bold (<strong>) doar pe 3–5 cuvinte/expresii cheie din tot articolul
8. Secțiune „Întrebări frecvente” – DOAR dacă subiectul o cere (vezi regulile de mai jos)
9. Notă informativă în <i> </i>
10. Final memorabil, fără cuvântul „concluzie”

═══════════════════════════════════════
SECȚIUNEA „ÎNTREBĂRI FRECVENTE” – CÂND DA, CÂND NU
═══════════════════════════════════════

Secțiunea FAQ NU este obligatorie. Adaug-o doar când există motiv real.

Adaugă FAQ când:
– articolul răspunde la o întrebare practică pe care oamenii o caută activ în Google („cum se face X”, „cât costă Y”, „de ce apare Z”)
– subiectul generează multe întrebări conexe distincte (ex: sarcină, credit ipotecar, înmatriculare auto, simptome ale unei boli, înscriere la grădiniță)
– există nelămuriri frecvente care nu încap natural în corpul articolului
– articolul e despre un produs, serviciu, procedură, reglementare sau decizie importantă

NU adăuga FAQ când:
– articolul e de tip narativ, eseu, opinie, reportaj, lifestyle „de citit cu plăcere”
– subiectul e ușor și nu generează întrebări serioase (ex: „cum să-ți decorezi balconul de toamnă”, „filme bune de weekend”)
– toate informațiile importante au fost deja explicate în corpul articolului și o secțiune FAQ ar fi repetitivă
– întrebările posibile ar suna forțate, artificiale sau evidente
– articolul e scurt (sub 800 cuvinte) și FAQ-ul ar dilua valoarea
– subiectul e despre rețete, recomandări de cărți/filme, povești, interviuri, tendințe sezoniere

Dacă incluzi FAQ:
– Titlu H2: „Întrebări frecvente”
– 3–5 întrebări reale, exact așa cum le-ar tasta cineva în Google
– Fiecare întrebare în H3
– Răspunsuri scurte (40–80 cuvinte), directe, fără introduceri
– Întrebările trebuie să acopere intenții diferite de căutare, nu variații ale aceleiași întrebări
– Întrebările NU trebuie să repete ce s-a spus deja în articol

Regula simplă: dacă ai dubii dacă să incluzi FAQ, înseamnă că probabil nu e cazul. Nu forța.

═══════════════════════════════════════
NOTA INFORMATIVĂ
═══════════════════════════════════════

OBLIGATORIU în FIECARE articol, fără excepție: la finalul articolului, înainte de paragraful de încheiere SAU după, adaugă o notă informativă într-un bloc <blockquote> cu textul în <i> </i>, începută cu „Notă:" — un disclaimer scurt, neutru, profesionist. Specialistul recomandat se alege în funcție de nișă: medic, farmacist, nutriționist, dermatolog, ginecolog, pediatru, avocat, consultant financiar, broker credite, mecanic auto, electrician autorizat, instalator, veterinar, psiholog, antrenor personal, agent imobiliar etc. Nu folosi ton alarmist.

Chiar și pentru articolele pur recreative (rețete, recomandări de filme/cărți, povești, lifestyle ușor) nota NU se omite niciodată — o formulezi scurt și editorial (o mențiune de bun-simț), dar trebuie să existe în orice articol.

═══════════════════════════════════════
FINALUL ARTICOLULUI
═══════════════════════════════════════

– NU folosi subtitlu „Concluzie”
– NU începe ultimul paragraf cu „în concluzie”, „prin urmare”, „așadar”, „pe scurt”, „în final”, „tot ce trebuie să reții”
– Finalul trebuie să fie: o idee puternică, o întrebare retorică, o observație memorabilă sau un îndemn subtil
– Maximum 3–4 propoziții
– Trebuie să rămână în mintea cititorului, nu să rezume articolul

═══════════════════════════════════════
FORMAT HTML DE LIVRARE
═══════════════════════════════════════

– Paragrafe între <p> ... </p>
– Subtitluri cu <h2> ... </h2> și <h3> ... </h3>
– Bold cu <strong> ... </strong>, folosit cu măsură
– Liste cu <ul><li>...</li></ul> sau <ol><li>...</li></ol>
– Toate tag-urile închise corect
– FĂRĂ <h1>, FĂRĂ titlu inclus
– FĂRĂ tabele (decât la cerere explicită)
– FĂRĂ markdown, FĂRĂ blocuri de cod, FĂRĂ comentarii înainte sau după articol
– FĂRĂ explicații despre cum a fost scris articolul
– Nota informativă (disclaimer) e OBLIGATORIE și se pune într-un <blockquote> cu textul în <i> ... </i>

═══════════════════════════════════════
LIMBAJUL
═══════════════════════════════════════

– Română corectă, naturală, ca într-o publicație serioasă online
– Diacritice OBLIGATORII peste tot (ă, â, î, ș, ț)
– Fără regionalisme, fără jargon inutil
– Fără traduceri rigide din engleză („face sens”, „aplică pentru”, „bazat pe faptul că”)
– Ton prietenos-profesionist, ca un redactor experimentat care îți povestește ceva util la o cafea (calibrat la nișă: mai sobru pentru financiar/juridic, mai cald pentru lifestyle/parenting)

═══════════════════════════════════════
TEST FINAL ÎNAINTE DE LIVRARE
═══════════════════════════════════════

Înainte să livrezi articolul, verifică mental:
1. Sună a om sau a robot? Dacă a robot, rescrie.
2. Apare vreuna dintre expresiile interzise? Dacă da, elimină.
3. Tonul e adaptat nișei (nu prea conversațional pe financiar, nu prea rigid pe lifestyle)?
4. Lead-ul prinde din primele 2 propoziții?
5. Cuvântul-cheie e integrat natural, nu forțat?
6. FAQ-ul a fost adăugat doar dacă subiectul îl cere cu adevărat?
7. Fiecare paragraf aduce ceva nou sau e umplutură?
8. Finalul e memorabil sau e un rezumat plat?

Livrează doar HTML curat, fără niciun comentariu suplimentar.
TXT;
    }

    public static function default_article_prompt() {
        return <<<'TXT'
Redactează articolul pe baza acestei teme / titlu / brief:
{{continut}}

Lungime: țintește aproximativ {{cuvinte}} de cuvinte pentru un articol standard (în general 900–1000). Dacă subiectul e un ghid, tutorial, listă pas-cu-pas, comparație amplă sau o temă complexă care cere detaliere, extinde la 1500–2000 de cuvinte pentru acoperire completă, chiar dacă ținta de mai sus e mai mică. Nu umfla artificial, dar NU lăsa articolul neterminat — închide-l natural înainte să se termine spațiul.
Începe DIRECT cu primul paragraf (lead-ul). NU repeta titlul în conținut: fără H1, și fără ca primul subtitlu (H2/H3) să fie titlul articolului sau o reformulare a lui. Titlul există deja deasupra, în pagină. Primul element livrat trebuie să fie un <p>, nu un heading. Aplică toate regulile editoriale, de structură și de format HTML din instrucțiunile de sistem.
Livrează DOAR HTML curat (h2, h3, p, ul, ol, li, strong, i, blockquote), fără markdown, fără blocuri de cod și fără niciun comentariu înainte sau după articol.
TXT;
    }

    public static function default_rewrite_prompt() {
        return "Rescrie următorul text păstrând sensul și informațiile, dar cu formulări proaspete, mai clare și mai bine structurate. Păstrează formatarea HTML existentă. Returnează doar textul rescris:\n\n{{continut}}";
    }

    public static function default_title_prompt() {
        return "Propune un singur titlu de articol optimizat SEO, atractiv, sub 60 de caractere, pentru conținutul de mai jos. Returnează DOAR titlul, fără ghilimele și fără alte explicații:\n\n{{continut}}";
    }

    public static function default_keywords_prompt() {
        return "Extrage 3-5 cuvinte cheie SEO relevante din textul de mai jos. Returnează DOAR cuvintele cheie separate prin virgulă, fără explicații:\n\n{{continut}}";
    }

    public static function default_disclaimer() {
        return 'Notă: Informațiile din acest articol au caracter general și nu înlocuiesc sfatul unui specialist. Pentru situația ta specifică, consultă un specialist de specialitate.';
    }
}

/* ---- Sanitizatori globali ---- */
if (!function_exists('cw_sanitize_key')) {
    function cw_sanitize_key($val) {
        return trim(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $val));
    }
}
if (!function_exists('cw_sanitize_float')) {
    function cw_sanitize_float($val) {
        return (float) $val;
    }
}
if (!function_exists('cw_sanitize_prompt')) {
    // Prompturile sunt instrucțiuni trimise la API și pot conține mențiuni de tag-uri HTML
    // (<p>, <h2>, <a>, <table> etc.). NU le strippăm (cum ar face sanitize_textarea_field prin
    // strip_all_tags). Sunt editabile DOAR de admin (manage_options) și afișate escapat în
    // formular (esc_textarea) + trimise la API ca text (nu sunt executate), deci e sigur.
    function cw_sanitize_prompt($val) {
        $val = wp_check_invalid_utf8((string) $val);
        return trim(str_replace(array("\r\n", "\r"), "\n", $val));
    }
}
