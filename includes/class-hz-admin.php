<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pagina de setări: Setări → Horoscop Zilnic.
 */
class HZ_Admin {

    public function init() {
        // Setările horoscopului sunt un TAB în pagina unică „AI Writer & Horoscop"
        // (Setări → AI Writer & Horoscop → tab Horoscop Zilnic) — fără meniu propriu.
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'redirect_legacy_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_hz_import_image', array($this, 'ajax_import_image'));

        // Cheia Magnific se stochează criptat, ca și cheile AI: câmp gol la salvare
        // = păstrează cheia existentă.
        add_filter('pre_update_option_hzb_magnific_key', function ($value, $old) {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') { return $old; }
            return CW_Crypto::encrypt($value);
        }, 10, 2);

        // Cheie nouă = alt cont, altă cotă: ștergem contorul și blocajul de azi și
        // repornim coada imediat (altfel ar aștepta degeaba până la resetul UTC).
        add_action('update_option_hzb_magnific_key', function () {
            HZ_Magnific::reset_daily_state();
            delete_option('hzb_paused_reason');
            wp_clear_scheduled_hook(HZ_Bulk::HOOK);
            if (!(int) get_option('hzb_stopped', 0)) {
                wp_schedule_single_event(time() + 5, HZ_Bulk::HOOK);
            }
        }, 10, 0);
    }

    /** Vechiul link Setări → Horoscop Zilnic redirecționează la tab-ul din pagina unică. */
    public function redirect_legacy_page() {
        if (isset($_GET['page']) && $_GET['page'] === 'horoscop-zilnic') {
            wp_safe_redirect(admin_url('options-general.php?page=claude-writer&tab=horoscop'));
            exit;
        }
    }

    public function enqueue($hook) {
        if ($hook !== 'settings_page_claude-writer') { return; }
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
            'hz_author'      => 'absint',
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

        // Modul Vreme (v3.0.0) — grup de setări separat.
        $wopts = array(
            'hzw_enabled'       => 'absint',
            'hzw_hour'          => 'absint',
            'hzw_status'        => 'sanitize_text_field',
            'hzw_model'         => 'sanitize_text_field',
            'hzw_category'      => 'sanitize_text_field',
            'hzw_category_id'   => 'absint',
            'hzw_author'        => 'absint',
            'hzw_source_url'    => 'esc_url_raw',
            'hzw_image'         => 'absint',
            'hzw_cite_source'   => 'absint',
            'hzw_cache_hours'   => 'absint',
            'hzw_title'         => 'hz_sanitize_prompt',
            'hzw_prompt'        => 'hz_sanitize_prompt',
            'hzw_system_prompt' => 'hz_sanitize_prompt',
        );
        foreach ($wopts as $name => $cb) {
            register_setting('hzw_settings', $name, array('sanitize_callback' => $cb));
        }

        // Modul Articole Bulk (v3.1.0) — grup de setări separat.
        $bopts = array(
            'hzb_magnific_key' => 'sanitize_text_field',
            'hzb_images'       => 'absint',
            'hzb_image_size'   => 'sanitize_text_field',
            'hzb_daily_limit'  => 'absint',
            'hzb_gap'          => 'absint',
            'hzb_length'       => 'absint',
            'hzb_author'       => 'absint',
            'hzb_ai_excluded'  => 'absint',
            'hzb_people_ethnicity' => 'sanitize_key',
            'hzb_random_date'  => 'absint',
        );
        foreach ($bopts as $name => $cb) {
            register_setting('hzb_settings', $name, array('sanitize_callback' => $cb));
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

    /** Conținutul tab-ului „Horoscop Zilnic" din pagina unică de setări. */
    public function render_tab() {
        if (!current_user_can('manage_options')) { return; }

        $has_key = CW_API::has_key();
        $models  = CW_Models::all();

        $on    = (int) get_option('hz_enabled', 0);
        $next  = wp_next_scheduled(HZ_Horoscope::HOOK);
        $last  = (string) get_option('hz_last_run', '');
        $err   = (string) get_option('hz_last_error', '');
        ?>
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
                        <th scope="row"><label for="hz_author"><?php esc_html_e('Publică drept', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users(array(
                                'name'              => 'hz_author',
                                'id'                => 'hz_author',
                                'selected'          => (int) get_option('hz_author', 0),
                                'who'               => 'authors',
                                'include_selected'  => true,
                                'show_option_none'  => __('— Administrator implicit —', 'horoscop-zilnic'),
                                'option_none_value' => 0,
                            ));
                            ?>
                            <p class="description"><?php esc_html_e('Utilizatorul afișat ca autor al articolelor de horoscop. „Administrator implicit" = primul administrator al site-ului.', 'horoscop-zilnic'); ?></p>
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
        <?php
    }

    /** Conținutul tab-ului „Vreme" din pagina unică de setări. */
    public function render_weather_tab() {
        if (!current_user_can('manage_options')) { return; }

        $has_key = CW_API::has_key();
        $models  = CW_Models::all();

        $on   = (int) get_option('hzw_enabled', 0);
        $next = wp_next_scheduled(HZ_Weather::HOOK);
        $last = (string) get_option('hzw_last_run', '');
        $err  = (string) get_option('hzw_last_error', '');
        ?>
            <?php if (!$has_key) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Configurează întâi cheia API (OpenAI sau Anthropic) în Setări → AI Writer.', 'horoscop-zilnic'); ?></p></div>
            <?php endif; ?>

            <p style="max-width:640px;">
                <?php esc_html_e('Publică zilnic un articol cu vremea pe principalele regiuni ale țării (Muntenia, Moldova, Transilvania, Banat și Crișana, Oltenia, Dobrogea), pe baza datelor oficiale de la Administrația Națională de Meteorologie (ANM). Temperaturile intră exact din ANM într-un tabel; textul e scris de AI.', 'horoscop-zilnic'); ?>
            </p>
            <p style="max-width:640px;">
                <?php if ($last !== '') { echo esc_html(sprintf(__('Ultima generare: %s.', 'horoscop-zilnic'), $last)) . ' '; } ?>
                <?php if ($on && $next) { echo esc_html(sprintf(__('Următoarea rulare: %s.', 'horoscop-zilnic'), wp_date('d.m.Y H:i', $next))); } ?>
                <?php if (!$on) { echo '<strong>' . esc_html__('Publicarea automată este oprită.', 'horoscop-zilnic') . '</strong>'; } ?>
                <?php if ($err !== '') : ?><span style="color:#b32d2e;display:block;"><?php echo esc_html(sprintf(__('Ultima eroare: %s', 'horoscop-zilnic'), $err)); ?></span><?php endif; ?>
            </p>

            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields('hzw_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Publicare automată', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzw_enabled" value="0" />
                            <label><input type="checkbox" name="hzw_enabled" value="1" <?php checked(1, $on); ?> /> <?php esc_html_e('Publică zilnic articolul cu vremea pe regiuni (un articol pe zi)', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_hour"><?php esc_html_e('Ora publicării', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <select name="hzw_hour" id="hzw_hour">
                                <?php $hh = (int) get_option('hzw_hour', 8);
                                for ($i = 0; $i < 24; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($i, $hh); ?>><?php echo esc_html(sprintf('%02d:00', $i)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Ora site-ului. ANM își actualizează prognoza în ziua precedentă (în jur de 15:30), deci dimineața datele sunt proaspete.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_status"><?php esc_html_e('Stare articol', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $hs = get_option('hzw_status', 'publish'); ?>
                            <select name="hzw_status" id="hzw_status">
                                <option value="publish" <?php selected($hs, 'publish'); ?>><?php esc_html_e('Publicat direct', 'horoscop-zilnic'); ?></option>
                                <option value="draft"   <?php selected($hs, 'draft'); ?>><?php esc_html_e('Ciornă (publici manual)', 'horoscop-zilnic'); ?></option>
                                <option value="pending" <?php selected($hs, 'pending'); ?>><?php esc_html_e('În așteptare', 'horoscop-zilnic'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_model"><?php esc_html_e('Model', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $hm = get_option('hzw_model', ''); ?>
                            <select name="hzw_model" id="hzw_model">
                                <option value="" <?php selected($hm, ''); ?>><?php esc_html_e('Modelul implicit din AI Writer', 'horoscop-zilnic'); ?></option>
                                <?php foreach ($models as $id => $m) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $hm); ?>><?php echo esc_html($m['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Textul meteo e scurt; Haiku e ieftin și suficient. Cifrele vin oricum din ANM, nu de la model.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_category_id"><?php esc_html_e('Categoria', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_categories(array(
                                'name'              => 'hzw_category_id',
                                'id'                => 'hzw_category_id',
                                'selected'          => (int) get_option('hzw_category_id', 0),
                                'hide_empty'        => false,
                                'hierarchical'      => true,
                                'show_option_none'  => __('— Vremea (se creează automat) —', 'horoscop-zilnic'),
                                'option_none_value' => 0,
                            ));
                            ?>
                            <p class="description"><?php esc_html_e('Categoria în care se publică articolele meteo. Dacă nu alegi nimic, se folosește (și se creează la nevoie) categoria „Vremea".', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_author"><?php esc_html_e('Publică drept', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users(array(
                                'name'              => 'hzw_author',
                                'id'                => 'hzw_author',
                                'selected'          => (int) get_option('hzw_author', 0),
                                'who'               => 'authors',
                                'include_selected'  => true,
                                'show_option_none'  => __('— Administrator implicit —', 'horoscop-zilnic'),
                                'option_none_value' => 0,
                            ));
                            ?>
                            <p class="description"><?php esc_html_e('Utilizatorul afișat ca autor al articolelor de vreme. „Administrator implicit" = primul administrator al site-ului.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_source_url"><?php esc_html_e('Sursă date (XML ANM)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="url" name="hzw_source_url" id="hzw_source_url" class="large-text" value="<?php echo esc_attr(get_option('hzw_source_url', HZ_Weather::default_source_url())); ?>" />
                            <p class="description"><?php esc_html_e('Feed-ul oficial ANM cu prognoza pe orașe. Schimbă-l doar dacă ANM mută adresa.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Atribuire sursă', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzw_cite_source" value="0" />
                            <label><input type="checkbox" name="hzw_cite_source" value="1" <?php checked(1, (int) get_option('hzw_cite_source', 1)); ?> /> <?php esc_html_e('Adaugă la final nota „Date oferite de ANM" (recomandat, dă credibilitate)', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Imagine reprezentativă (cover)', 'horoscop-zilnic'); ?></th>
                        <td>
                            <?php $himg = (int) get_option('hzw_image', 0); ?>
                            <input type="hidden" name="hzw_image" id="hzw_image" value="<?php echo esc_attr($himg); ?>" />
                            <div id="hzw-image-preview" style="margin-bottom:8px;">
                                <?php if ($himg > 0) { echo wp_get_attachment_image($himg, array(150, 150)); } ?>
                            </div>
                            <button type="button" class="button" id="hzw-image-pick"><?php esc_html_e('Alege din galerie / Încarcă', 'horoscop-zilnic'); ?></button>
                            <button type="button" class="button" id="hzw-image-remove" <?php disabled($himg <= 0); ?>><?php esc_html_e('Elimină imaginea', 'horoscop-zilnic'); ?></button>
                            <p class="description" style="margin-top:10px;"><?php esc_html_e('sau importă dintr-un URL extern (imaginea se descarcă în Media):', 'horoscop-zilnic'); ?></p>
                            <input type="url" id="hzw-image-url" class="regular-text" placeholder="https://exemplu.ro/imagine.jpg" />
                            <button type="button" class="button" id="hzw-image-import"><?php esc_html_e('Importă în Media', 'horoscop-zilnic'); ?></button>
                            <span id="hzw-image-import-status"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_title"><?php esc_html_e('Titlu', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="text" name="hzw_title" id="hzw_title" class="large-text" value="<?php echo esc_attr(get_option('hzw_title', HZ_Weather::default_title())); ?>" />
                            <p class="description"><?php esc_html_e('Folosește {{data}} (ex. 18 iulie 2026) și {{zi}} (ex. sâmbătă).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_prompt"><?php esc_html_e('Prompt zilnic', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <textarea name="hzw_prompt" id="hzw_prompt" class="large-text code" rows="3"><?php echo esc_textarea(get_option('hzw_prompt', HZ_Weather::default_prompt())); ?></textarea>
                            <p class="description"><?php esc_html_e('Instrucțiunea trimisă zilnic. Datele oficiale ANM se adaugă automat sub ea.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzw_system_prompt"><?php esc_html_e('System prompt (stilul articolului meteo)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <textarea name="hzw_system_prompt" id="hzw_system_prompt" class="large-text code" rows="14"><?php echo esc_textarea(get_option('hzw_system_prompt', HZ_Weather::default_system_prompt())); ?></textarea>
                            <p class="description"><?php esc_html_e('Rolul, structura (intro + o secțiune pe regiune), tonul și regula „cifrele sunt sfinte". Doar pentru articolul meteo.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salvează setările', 'horoscop-zilnic')); ?>
            </form>

            <hr />
            <p>
                <button type="button" class="button button-secondary" id="hzw-test" <?php disabled(!$has_key); ?>><?php esc_html_e('Generează un test (ciornă)', 'horoscop-zilnic'); ?></button>
                <span id="hzw-test-result"></span>
            </p>
            <p class="description" style="max-width:640px;"><?php esc_html_e('Creează acum o ciornă de probă cu setările SALVATE (salvează întâi modificările; durează 1–2 minute). Trage datele live de la ANM. Nu afectează rularea zilnică.', 'horoscop-zilnic'); ?></p>
        <?php
    }

    /** Conținutul tab-ului „Articole Bulk" din pagina unică de setări. */
    public function render_bulk_tab() {
        if (!current_user_can('manage_options')) { return; }

        $has_ai_key  = CW_API::has_key();
        $has_mg_key  = HZ_Magnific::has_key();
        $models      = CW_Models::all();
        ?>
            <?php if (!$has_ai_key) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Configurează întâi cheia API (OpenAI sau Anthropic) în tab-ul AI Writer.', 'horoscop-zilnic'); ?></p></div>
            <?php endif; ?>
            <?php if (!$has_mg_key) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Pune cheia API Magnific în setările de mai jos ca articolele să primească imagini de stock. Fără cheie, articolele se generează fără imagini.', 'horoscop-zilnic'); ?></p></div>
            <?php endif; ?>

            <p style="max-width:720px;">
                <?php esc_html_e('Dai lista de titluri, alegi categoria, iar pluginul generează articolele pe rând (AI, stilul editorial din AI Writer) și le pune automat imagini de stock relevante din Magnific: prima ca imagine reprezentativă, restul în corpul articolului.', 'horoscop-zilnic'); ?>
            </p>

            <h2><?php esc_html_e('Lot nou de articole', 'horoscop-zilnic'); ?></h2>
            <table class="form-table" role="presentation" style="max-width:720px;">
                <tr>
                    <th scope="row"><label for="hzb-titles"><?php esc_html_e('Titluri (unul pe linie)', 'horoscop-zilnic'); ?></label></th>
                    <td>
                        <textarea id="hzb-titles" class="large-text code" rows="8" placeholder="Cum alegi anvelopele de iarnă potrivite&#10;Top 10 destinații de city-break în 2026&#10;..."></textarea>
                        <p class="description"><?php esc_html_e('Maximum 100 de titluri per lot. Numerotarea (1., 2., -) e curățată automat.', 'horoscop-zilnic'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hzb-category"><?php esc_html_e('Categoria', 'horoscop-zilnic'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_categories(array(
                            'name'              => 'hzb-category',
                            'id'                => 'hzb-category',
                            'hide_empty'        => false,
                            'hierarchical'      => true,
                            'show_option_none'  => __('— Categoria implicită a site-ului —', 'horoscop-zilnic'),
                            'option_none_value' => 0,
                        ));
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hzb-status"><?php esc_html_e('Stare articole', 'horoscop-zilnic'); ?></label></th>
                    <td>
                        <select id="hzb-status">
                            <option value="draft"><?php esc_html_e('Ciornă (le publici manual)', 'horoscop-zilnic'); ?></option>
                            <option value="publish"><?php esc_html_e('Publicate direct', 'horoscop-zilnic'); ?></option>
                            <option value="pending"><?php esc_html_e('În așteptare', 'horoscop-zilnic'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hzb-model"><?php esc_html_e('Model', 'horoscop-zilnic'); ?></label></th>
                    <td>
                        <select id="hzb-model">
                            <option value=""><?php esc_html_e('Modelul implicit din AI Writer', 'horoscop-zilnic'); ?></option>
                            <?php foreach ($models as $id => $m) : ?>
                                <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($m['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hzb-extra"><?php esc_html_e('Instrucțiuni suplimentare (opțional)', 'horoscop-zilnic'); ?></label></th>
                    <td>
                        <textarea id="hzb-extra" class="large-text code" rows="3" placeholder="<?php esc_attr_e('ex. Ton informal, adresare la persoana a II-a, include un exemplu practic în fiecare articol.', 'horoscop-zilnic'); ?>"></textarea>
                        <p class="description"><?php esc_html_e('Se adaugă la promptul fiecărui articol din acest lot.', 'horoscop-zilnic'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Căutare web', 'horoscop-zilnic'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="hzb-web" <?php checked(1, (int) get_option('cw_web_search', 0)); ?> />
                            <?php esc_html_e('Caută pe web pentru articolele din acest lot (informații la zi, cu surse)', 'horoscop-zilnic'); ?>
                        </label>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s = costul estimat per căutare */
                                esc_html__('Modelul face căutări reale înainte să scrie. Costă în plus ~%s per căutare (peste tokeni) și adaugă 30-60 de secunde per articol. Bifa pornește DOAR pentru lotul ăsta; setarea permanentă a site-ului e în tab-ul AI Writer.', 'horoscop-zilnic'),
                                esc_html('$' . number_format((float) get_option('cw_web_search_cost', 0.01), 3))
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="hzb-enqueue" <?php disabled(!$has_ai_key); ?>><?php esc_html_e('Adaugă în coadă și pornește', 'horoscop-zilnic'); ?></button>
                <span id="hzb-enqueue-status"></span>
            </p>

            <hr />

            <h2><?php esc_html_e('Coada de procesare', 'horoscop-zilnic'); ?></h2>
            <p>
                <span id="hzb-progress" class="description"></span>
            </p>
            <p>
                <button type="button" class="button" id="hzb-quota"><?php esc_html_e('Verifică pozele consumate azi', 'horoscop-zilnic'); ?></button>
                <span id="hzb-quota-result" style="margin-left:8px;font-weight:600;"></span>
            </p>
            <p>
                <button type="button" class="button button-primary" id="hzb-toggle" data-stopped="<?php echo (int) get_option('hzb_stopped', 0); ?>"><?php echo (int) get_option('hzb_stopped', 0) ? esc_html__('Repornește procesarea', 'horoscop-zilnic') : esc_html__('Oprește procesarea', 'horoscop-zilnic'); ?></button>
                <button type="button" class="button" id="hzb-retry"><?php esc_html_e('Reîncearcă erorile', 'horoscop-zilnic'); ?></button>
                <button type="button" class="button" id="hzb-backfill"><?php esc_html_e('Completează pozele lipsă', 'horoscop-zilnic'); ?></button>
                <button type="button" class="button" id="hzb-clear"><?php esc_html_e('Golește coada', 'horoscop-zilnic'); ?></button>
                <span class="description"><?php esc_html_e('Oprirea lasă articolul în lucru să se termine; golirea coadei NU șterge articolele deja create.', 'horoscop-zilnic'); ?></span>
            </p>
            <p>
                <span id="hzb-action-result" style="font-weight:600;"></span>
            </p>
            <p class="description" style="max-width:900px;">
                <?php esc_html_e('„Reîncearcă erorile" repune în coadă doar articolele cu stare Eroare (cele fără poză sunt Gata, nu erori). „Completează pozele lipsă" caută imagini pentru articolele deja scrise care au rămas fără cover — fără să le regenereze textul și fără articole noi.', 'horoscop-zilnic'); ?>
            </p>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Titlu', 'horoscop-zilnic'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Stare', 'horoscop-zilnic'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Cost', 'horoscop-zilnic'); ?></th>
                        <th><?php esc_html_e('Detalii', 'horoscop-zilnic'); ?></th>
                    </tr>
                </thead>
                <tbody id="hzb-queue">
                    <tr><td colspan="4"><?php esc_html_e('Se încarcă…', 'horoscop-zilnic'); ?></td></tr>
                </tbody>
            </table>

            <hr />

            <h2><?php esc_html_e('Setări imagini & generare', 'horoscop-zilnic'); ?></h2>
            <form method="post" action="options.php" style="max-width:720px;">
                <?php settings_fields('hzb_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hzb_magnific_key"><?php esc_html_e('Cheie API Magnific', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="password" name="hzb_magnific_key" id="hzb_magnific_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($has_mg_key ? __('•••••••• (cheie salvată — lasă gol ca să o păstrezi)', 'horoscop-zilnic') : ''); ?>" />
                            <p class="description"><?php esc_html_e('Din contul Magnific → API Keys. Descărcările de stock nu consumă credite AI, dar au limită zilnică per cont.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_images"><?php esc_html_e('Imagini per articol', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $bi = (int) get_option('hzb_images', 1); ?>
                            <select name="hzb_images" id="hzb_images">
                                <option value="0" <?php selected(0, $bi); ?>><?php esc_html_e('Fără imagini', 'horoscop-zilnic'); ?></option>
                                <option value="1" <?php selected(1, $bi); ?>><?php esc_html_e('1 (doar cover)', 'horoscop-zilnic'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Articolele primesc DOAR imaginea de cover — nu se mai completează și alte imagini în corpul articolului.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_image_size"><?php esc_html_e('Mărimea descărcată', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php $bs = (string) get_option('hzb_image_size', 'large'); ?>
                            <select name="hzb_image_size" id="hzb_image_size">
                                <option value="medium" <?php selected('medium', $bs); ?>><?php esc_html_e('Medie', 'horoscop-zilnic'); ?></option>
                                <option value="large" <?php selected('large', $bs); ?>><?php esc_html_e('Mare (recomandat)', 'horoscop-zilnic'); ?></option>
                                <option value="original" <?php selected('original', $bs); ?>><?php esc_html_e('Originală (fișiere mari)', 'horoscop-zilnic'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_daily_limit"><?php esc_html_e('Limită descărcări/zi', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="number" name="hzb_daily_limit" id="hzb_daily_limit" min="1" max="100" value="<?php echo esc_attr((int) get_option('hzb_daily_limit', 100)); ?>" />
                            <p class="description"><?php esc_html_e('Contul Magnific permite ~100 descărcări de stock/zi, resetate la 00:00 UTC (03:00 ora României vara, 02:00 iarna). Limita e pe CONT, nu pe site: dacă aceeași cheie e folosită pe mai multe site-uri, împarte-o între ele (ex. 5 site-uri = câte 18).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_gap"><?php esc_html_e('Pauză între articole (secunde)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="number" name="hzb_gap" id="hzb_gap" min="5" max="3600" step="5" value="<?php echo esc_attr((int) get_option('hzb_gap', 120)); ?>" />
                            <p class="description"><?php esc_html_e('Așteptare între terminarea unui articol și începerea următorului. Un ritm mai lent menajează limitele API și arată natural (ex. 300 = un articol la ~5-7 minute).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_length"><?php esc_html_e('Lungime articol (cuvinte)', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <input type="number" name="hzb_length" id="hzb_length" min="300" max="3000" step="100" value="<?php echo esc_attr((int) get_option('hzb_length', 1000)); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hzb_author"><?php esc_html_e('Publică drept', 'horoscop-zilnic'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users(array(
                                'name'              => 'hzb_author',
                                'id'                => 'hzb_author',
                                'selected'          => (int) get_option('hzb_author', 0),
                                'who'               => 'authors',
                                'include_selected'  => true,
                                'show_option_none'  => __('— Administrator implicit —', 'horoscop-zilnic'),
                                'option_none_value' => 0,
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Doar fotografii reale', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzb_ai_excluded" value="0" />
                            <label><input type="checkbox" name="hzb_ai_excluded" value="1" <?php checked(1, (int) get_option('hzb_ai_excluded', 1)); ?> /> <?php esc_html_e('Exclude imaginile generate cu AI din rezultatele de stock (recomandat)', 'horoscop-zilnic'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Persoanele din poze', 'horoscop-zilnic'); ?></th>
                        <td>
                            <?php $hzb_eth = (string) get_option('hzb_people_ethnicity', 'white'); ?>
                            <select name="hzb_people_ethnicity" id="hzb_people_ethnicity">
                                <option value="white" <?php selected($hzb_eth, 'white'); ?>><?php esc_html_e('Doar persoane europene (albe)', 'horoscop-zilnic'); ?></option>
                                <option value="" <?php selected($hzb_eth, ''); ?>><?php esc_html_e('Fără filtru', 'horoscop-zilnic'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Se aplică și la stocul Magnific, și la imaginile generate cu AI. Dacă subiectul n-are fotografii potrivite cu persoane, se caută fotografii fără persoane și abia la final una nefiltrată — ca articolul să nu rămână fără poză.', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dată de publicare', 'horoscop-zilnic'); ?></th>
                        <td>
                            <input type="hidden" name="hzb_random_date" value="0" />
                            <label><input type="checkbox" name="hzb_random_date" value="1" <?php checked(1, (int) get_option('hzb_random_date', 1)); ?> /> <?php esc_html_e('Dată random din luna curentă și luna trecută (backdatare, ca articolele să nu apară toate la aceeași dată)', 'horoscop-zilnic'); ?></label>
                            <p class="description"><?php esc_html_e('Fiecare articol primește o dată aleatoare între 1 ale lunii trecute și acum, deci se publică imediat, dar cu dată variată. Nebifat = data curentă (ca până acum).', 'horoscop-zilnic'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salvează setările', 'horoscop-zilnic')); ?>
            </form>
        <?php
    }
}

if (!function_exists('hz_sanitize_cat_csv')) {
    /** Lista de categorii excluse vine din checkbox-uri (array) și se stochează CSV. */
    function hz_sanitize_cat_csv($val) {
        if (is_array($val)) {
            $ids = array_filter(array_map('absint', $val));
            return implode(',', array_unique($ids));
        }
        $ids = array_filter(array_map('absint', explode(',', (string) $val)));
        return implode(',', array_unique($ids));
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
