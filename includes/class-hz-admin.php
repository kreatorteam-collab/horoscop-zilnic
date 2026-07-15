<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pagina de setări: Setări → Horoscop Zilnic.
 */
class HZ_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function menu() {
        add_options_page(
            __('Horoscop Zilnic', 'horoscop-zilnic'),
            __('Horoscop Zilnic', 'horoscop-zilnic'),
            'manage_options',
            'horoscop-zilnic',
            array($this, 'render_page')
        );
    }

    public function enqueue($hook) {
        if ($hook !== 'settings_page_horoscop-zilnic') { return; }
        wp_enqueue_script('hz-admin', HZ_URL . 'assets/js/admin.js', array('jquery'), HZ_VERSION, true);
        wp_localize_script('hz-admin', 'HZAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hz_admin_nonce'),
        ));
    }

    public function register_settings() {
        $opts = array(
            'hz_enabled'     => 'absint',
            'hz_hour'        => 'absint',
            'hz_status'      => 'sanitize_text_field',
            'hz_model'       => 'sanitize_text_field',
            'hz_category'    => 'sanitize_text_field',
            'hz_source_url'  => 'esc_url_raw',
            'hz_image'       => 'absint',
            'hz_title'         => 'hz_sanitize_prompt',
            'hz_prompt'        => 'hz_sanitize_prompt',
            'hz_system_prompt' => 'hz_sanitize_prompt',
            'hz_auto_update'   => 'absint',
        );
        foreach ($opts as $name => $cb) {
            register_setting('hz_settings', $name, array('sanitize_callback' => $cb));
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) { return; }

        $cw_active = class_exists('CW_API');
        $has_key   = $cw_active && CW_API::has_key();
        $models    = $cw_active ? CW_Models::all() : array();

        $on    = (int) get_option('hz_enabled', 0);
        $next  = wp_next_scheduled(HZ_Horoscope::HOOK);
        $last  = (string) get_option('hz_last_run', '');
        $err   = (string) get_option('hz_last_error', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Horoscop Zilnic', 'horoscop-zilnic'); ?></h1>

            <?php if (!$cw_active) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Pluginul Claude Writer trebuie să fie instalat și activ — Horoscop Zilnic generează prin el (cheie API, modele, costuri).', 'horoscop-zilnic'); ?></p></div>
            <?php elseif (!$has_key) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Configurează întâi cheia API Anthropic în Setări → Claude Writer.', 'horoscop-zilnic'); ?></p></div>
            <?php endif; ?>

            <p style="max-width:640px;">
                <?php if ($last !== '') { echo esc_html(sprintf(__('Ultima generare: %s.', 'horoscop-zilnic'), $last)) . ' '; } ?>
                <?php if ($on && $next) { echo esc_html(sprintf(__('Următoarea rulare: %s.', 'horoscop-zilnic'), wp_date('d.m.Y H:i', $next))); } ?>
                <?php if (!$on) { echo '<strong>' . esc_html__('Publicarea automată este oprită.', 'horoscop-zilnic') . '</strong>'; } ?>
                <?php if ($err !== '') : ?><span style="color:#b32d2e;display:block;"><?php echo esc_html(sprintf(__('Ultima eroare: %s', 'horoscop-zilnic'), $err)); ?></span><?php endif; ?>
            </p>

            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields('hz_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Publicare automată', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hz_enabled" value="0" />
                            <label><input type="checkbox" name="hz_enabled" value="1" <?php checked(1, $on); ?> /> <?php esc_html_e('Publică zilnic horoscopul (toate cele 12 zodii, un articol pe zi)', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_hour"><?php esc_html_e('Ora publicării', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <select name="hz_hour" id="hz_hour">
                                <?php $hh = (int) get_option('hz_hour', 7);
                                for ($i = 0; $i < 24; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($i, $hh); ?>><?php echo esc_html(sprintf('%02d:00', $i)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Ora site-ului. Cronul WP pornește la primul vizitator după această oră.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_status"><?php esc_html_e('Stare articol', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $hs = get_option('hz_status', 'publish'); ?>
                            <select name="hz_status" id="hz_status">
                                <option value="publish" <?php selected($hs, 'publish'); ?>><?php esc_html_e('Publicat direct', 'horoscop-zilnic'); ?></option>
                                <option value="draft"   <?php selected($hs, 'draft'); ?>><?php esc_html_e('Ciornă (publici manual)', 'horoscop-zilnic'); ?></option>
                                <option value="pending" <?php selected($hs, 'pending'); ?>><?php esc_html_e('În așteptare', 'horoscop-zilnic'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_model"><?php esc_html_e('Model', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $hm = get_option('hz_model', ''); ?>
                            <select name="hz_model" id="hz_model">
                                <option value="" <?php selected($hm, ''); ?>><?php esc_html_e('Modelul implicit din Claude Writer', 'horoscop-zilnic'); ?></option>
                                <?php foreach ($models as $id => $m) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $hm); ?>><?php echo esc_html($m['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Haiku reduce costul de ~5x pentru rularea zilnică; Sonnet dă text mai variat.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_category"><?php esc_html_e('Categoria', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="text" name="hz_category" id="hz_category" class="regular-text" value="<?php echo esc_attr(get_option('hz_category', 'Horoscop')); ?>" />
                            <p class="description"><?php esc_html_e('Se creează automat dacă nu există.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_source_url"><?php esc_html_e('Sursă externă (opțional)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="url" name="hz_source_url" id="hz_source_url" class="large-text" value="<?php echo esc_attr(get_option('hz_source_url', HZ_Horoscope::default_source_url())); ?>" placeholder="https://exemplu.ro/horoscop-zilnic" />
                            <p class="description"><?php esc_html_e('Textul de la acest URL e preluat, folosit doar ca inspirație și rescris integral. Gol = horoscopul se generează de la zero.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_image"><?php esc_html_e('Imagine reprezentativă', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $himg = (int) get_option('hz_image', 0); ?>
                            <input type="number" name="hz_image" id="hz_image" min="0" step="1" value="<?php echo esc_attr($himg); ?>" style="width:120px;" />
                            <p class="description"><?php esc_html_e('ID-ul unei imagini din Media (deschide imaginea în Media și ia numărul din URL, „item=..."). Se refolosește la fiecare articol. 0 = fără imagine.', 'horoscop-zilnic'); ?></p>
                            <?php if ($himg > 0) : $thumb = wp_get_attachment_image($himg, array(120, 120)); echo $thumb ? $thumb : '<span style="color:#b32d2e;">' . esc_html__('ID-ul nu este o imagine validă.', 'horoscop-zilnic') . '</span>'; endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_title"><?php esc_html_e('Titlu', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="text" name="hz_title" id="hz_title" class="large-text" value="<?php echo esc_attr(get_option('hz_title', HZ_Horoscope::default_title())); ?>" />
                            <p class="description"><?php esc_html_e('Folosește {{data}} (ex. 16 iulie 2026) și {{zi}} (ex. miercuri).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_prompt"><?php esc_html_e('Prompt zilnic', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <textarea name="hz_prompt" id="hz_prompt" class="large-text code" rows="3"><?php echo esc_textarea(get_option('hz_prompt', HZ_Horoscope::default_prompt())); ?></textarea>
                            <p class="description"><?php esc_html_e('Instrucțiunea trimisă în fiecare zi. Folosește {{data}} și {{zi}}.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_system_prompt"><?php esc_html_e('System prompt (stilul horoscopului)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <textarea name="hz_system_prompt" id="hz_system_prompt" class="large-text code" rows="14"><?php echo esc_textarea(get_option('hz_system_prompt', HZ_Horoscope::default_system_prompt())); ?></textarea>
                            <p class="description"><?php esc_html_e('Rolul, structura fixă (12 zodii), tonul și regulile anti-AI — DOAR pentru horoscop. Înlocuiește system promptul de articole din Claude Writer la generarea horoscopului (necesită Claude Writer 1.3.3+).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-update', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hz_auto_update" value="0" />
                            <label><input type="checkbox" name="hz_auto_update" value="1" <?php checked(1, (int) get_option('hz_auto_update', 1)); ?> /> <?php esc_html_e('Instalează singur versiunile noi de pe GitHub', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salvează setările', 'horoscop-zilnic')); ?>
            </form>

            <hr />
            <p>
                <button type="button" class="button button-secondary" id="hz-test" <?php disabled(!$has_key); ?>><?php esc_html_e('Generează un test (ciornă)', 'horoscop-zilnic'); ?></button>
                <span id="hz-test-result"></span>
            </p>
            <p class="description" style="max-width:640px;"><?php esc_html_e('Creează acum o ciornă de probă cu setările SALVATE (salvează întâi modificările; durează 1–2 minute). Nu afectează rularea zilnică și nu publică nimic.', 'horoscop-zilnic'); ?></p>
        </div>
        <?php
    }
}

if (!function_exists('hz_sanitize_prompt')) {
    // Promptul e instrucțiune trimisă la API și poate conține mențiuni de tag-uri HTML.
    // Editabil doar de admin, afișat escapat, trimis ca text — nu îl strippăm.
    function hz_sanitize_prompt($val) {
        $val = wp_check_invalid_utf8((string) $val);
        return trim(str_replace(array("\r\n", "\r"), "\n", $val));
    }
}
