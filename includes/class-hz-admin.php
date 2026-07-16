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
        add_action('wp_ajax_hz_import_image', array($this, 'ajax_import_image'));
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
        wp_enqueue_media(); // modalul standard WP: galerie + upload
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
            'hz_category_id' => 'absint',
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

    /** Importă o imagine dintr-un URL extern în Media și returnează attachment ID + preview. */
    public function ajax_import_image() {
        check_ajax_referer('hz_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Permisiune refuzată.', 'horoscop-zilnic')));
        }
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            wp_send_json_error(array('message' => __('URL invalid.', 'horoscop-zilnic')));
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_sideload_image($url, 0, __('Imagine horoscop', 'horoscop-zilnic'), 'id');
        if (is_wp_error($id)) {
            wp_send_json_error(array('message' => $id->get_error_message()));
        }
        wp_send_json_success(array(
            'id'   => (int) $id,
            'html' => wp_get_attachment_image((int) $id, array(150, 150)),
        ));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) { return; }

        $has_key = CW_API::has_key();
        $models  = CW_Models::all();

        $on    = (int) get_option('hz_enabled', 0);
        $next  = wp_next_scheduled(HZ_Horoscope::HOOK);
        $last  = (string) get_option('hz_last_run', '');
        $err   = (string) get_option('hz_last_error', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Horoscop Zilnic', 'horoscop-zilnic'); ?></h1>

            <?php if (!$has_key) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Configurează întâi cheia API (OpenAI sau Anthropic) în Setări → AI Writer.', 'horoscop-zilnic'); ?></p></div>
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
                                <option value="" <?php selected($hm, ''); ?>><?php esc_html_e('Modelul implicit din AI Writer', 'horoscop-zilnic'); ?></option>
                                <?php foreach ($models as $id => $m) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $hm); ?>><?php echo esc_html($m['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Haiku reduce costul de ~5x pentru rularea zilnică; Sonnet dă text mai variat.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hz_category_id"><?php esc_html_e('Categoria', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_categories(array(
                                'name'              => 'hz_category_id',
                                'id'                => 'hz_category_id',
                                'selected'          => (int) get_option('hz_category_id', 0),
                                'hide_empty'        => false,
                                'hierarchical'      => true,
                                'show_option_none'  => __('— Horoscop (se creează automat) —', 'horoscop-zilnic'),
                                'option_none_value' => 0,
                            ));
                            ?>
                            <p class="description"><?php esc_html_e('Alege categoria existentă în care se publică articolele. Dacă nu alegi nimic, se folosește (și se creează la nevoie) categoria „Horoscop".', 'horoscop-zilnic'); ?></p>
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
                        <th scope="row"><?php esc_html_e('Imagine reprezentativă (cover)', 'horoscop-zilnic'); ?></th>
                        <td>
                            <?php $himg = (int) get_option('hz_image', 0); ?>
                            <input type="hidden" name="hz_image" id="hz_image" value="<?php echo esc_attr($himg); ?>" />
                            <div id="hz-image-preview" style="margin-bottom:8px;">
                                <?php if ($himg > 0) { echo wp_get_attachment_image($himg, array(150, 150)); } ?>
                            </div>
                            <button type="button" class="button" id="hz-image-pick"><?php esc_html_e('Alege din galerie / Încarcă', 'horoscop-zilnic'); ?></button>
                            <button type="button" class="button" id="hz-image-remove" <?php disabled($himg <= 0); ?>><?php esc_html_e('Elimină imaginea', 'horoscop-zilnic'); ?></button>
                            <p class="description" style="margin-top:10px;"><?php esc_html_e('sau importă dintr-un URL extern (imaginea se descarcă în Media):', 'horoscop-zilnic'); ?></p>
                            <input type="url" id="hz-image-url" class="regular-text" placeholder="https://exemplu.ro/imagine.jpg" />
                            <button type="button" class="button" id="hz-image-import"><?php esc_html_e('Importă în Media', 'horoscop-zilnic'); ?></button>
                            <span id="hz-image-import-status"></span>
                            <p class="description"><?php esc_html_e('Imaginea aleasă se pune ca imagine reprezentativă la fiecare articol de horoscop. După alegere apasă „Salvează setările".', 'horoscop-zilnic'); ?></p>
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
                            <p class="description"><?php esc_html_e('Rolul, structura fixă (12 zodii), tonul și regulile anti-AI — DOAR pentru horoscop. La generarea horoscopului înlocuiește system promptul de articole din AI Writer.', 'horoscop-zilnic'); ?></p>
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
