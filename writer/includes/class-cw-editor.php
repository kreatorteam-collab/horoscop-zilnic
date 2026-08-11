<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Integrarea în editor: meta box „Claude Writer" pe ecranele de editare + handlere AJAX.
 * Funcționează atât în Editorul Clasic, cât și în Gutenberg (inserare via JS).
 */
class CW_Editor {

    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));

        add_action('wp_ajax_cw_generate', array($this, 'ajax_generate'));
    }

    public function add_meta_box() {
        $screens = apply_filters('cw_post_types', array('post', 'page'));
        foreach ($screens as $screen) {
            add_meta_box(
                'cw_panel',
                __('AI Writer', 'claude-writer'),
                array($this, 'render_meta_box'),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function enqueue($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) { return; }

        wp_enqueue_style('cw-editor', CW_URL . 'assets/css/editor.css', array(), CW_VERSION);
        wp_enqueue_script('cw-editor', CW_URL . 'assets/js/editor.js', array('jquery'), CW_VERSION, true);

        wp_localize_script('cw-editor', 'CW', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'restUrl'   => esc_url_raw(rest_url('claude-writer/v1/stream')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'nonce'     => wp_create_nonce('cw_nonce'),
            'models'    => CW_Models::all(),
            'default'   => CW_Models::default_model(),
            'stream'    => (bool) get_option('cw_stream_enabled', 0),
            'hasKey'    => CW_API::has_key(),
            'disclaimer'=> (string) get_option('cw_disclaimer', CW_Admin::default_disclaimer()),
            'i18n'      => array(
                'working'  => __('Se generează…', 'claude-writer'),
                'noKey'    => __('Configurează întâi cheia API în Setări → AI Writer.', 'claude-writer'),
                'error'    => __('Eroare', 'claude-writer'),
                'cost'     => __('Cost', 'claude-writer'),
                'inserted' => __('Inserat în editor.', 'claude-writer'),
                'titleSet' => __('Titlu setat.', 'claude-writer'),
                'copied'   => __('Copiat.', 'claude-writer'),
            ),
        ));
    }

    public function render_meta_box($post) {
        $models  = CW_Models::all();
        $default = CW_Models::default_model();
        ?>
        <div class="cw-box">
            <?php if (!CW_API::has_key()) : ?>
                <p class="cw-warn"><?php esc_html_e('Nicio cheie API configurată (OpenAI sau Anthropic).', 'claude-writer'); ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=claude-writer')); ?>"><?php esc_html_e('Configurează', 'claude-writer'); ?></a>
                </p>
            <?php endif; ?>

            <label class="cw-label"><?php esc_html_e('Model', 'claude-writer'); ?></label>
            <select id="cw-model" class="widefat">
                <optgroup label="OpenAI (GPT)">
                <?php foreach ($models as $id => $m) : if ($m['provider'] !== 'openai') { continue; } ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $default); ?>>
                        <?php echo esc_html($m['label'] . ' — ' . $m['tagline']); ?>
                    </option>
                <?php endforeach; ?>
                </optgroup>
                <optgroup label="Anthropic (Claude)">
                <?php foreach ($models as $id => $m) : if ($m['provider'] !== 'anthropic') { continue; } ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $default); ?>>
                        <?php echo esc_html($m['label'] . ' — ' . $m['tagline']); ?>
                    </option>
                <?php endforeach; ?>
                </optgroup>
            </select>

            <label class="cw-label"><?php esc_html_e('Instrucțiuni suplimentare (opțional)', 'claude-writer'); ?></label>
            <textarea id="cw-subject" class="widefat" rows="3" placeholder="<?php esc_attr_e('Titlul articolului e folosit automat ca subiect. Aici poți adăuga detalii: unghi, public țintă, ce să includă etc. Lasă gol dacă nu e nevoie.', 'claude-writer'); ?>"></textarea>

            <label class="cw-label"><?php esc_html_e('Lungime', 'claude-writer'); ?></label>
            <select id="cw-length" class="widefat">
                <option value="600"><?php esc_html_e('Scurt (~600 cuvinte)', 'claude-writer'); ?></option>
                <option value="1000" selected><?php esc_html_e('Standard (~900–1000 cuvinte)', 'claude-writer'); ?></option>
                <option value="2000"><?php esc_html_e('Ghid lung (~1500–2000 cuvinte)', 'claude-writer'); ?></option>
            </select>

            <div class="cw-actions">
                <button type="button" class="button button-primary cw-btn" data-action="article"><?php esc_html_e('Generează articol', 'claude-writer'); ?></button>
                <button type="button" class="button cw-btn" data-action="rewrite"><?php esc_html_e('Rescrie conținutul', 'claude-writer'); ?></button>
                <button type="button" class="button cw-btn" data-action="title"><?php esc_html_e('Titlu SEO', 'claude-writer'); ?></button>
                <button type="button" class="button cw-btn" data-action="keywords"><?php esc_html_e('Cuvinte cheie', 'claude-writer'); ?></button>
            </div>

            <div id="cw-status" class="cw-status" style="display:none;"></div>

            <!-- Folosit doar pentru cuvinte cheie (articolul/rescrierea intră direct în editor) -->
            <div id="cw-output" class="cw-output" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * Construiește promptul final pentru o acțiune.
     */
    public static function build_prompt($action, $subject, $content, $length) {
        $length = (int) $length;
        switch ($action) {
            case 'rewrite':
                $tpl = get_option('cw_rewrite_prompt', CW_Admin::default_rewrite_prompt());
                return str_replace('{{continut}}', $content, $tpl);
            case 'title':
                $tpl = get_option('cw_title_prompt', CW_Admin::default_title_prompt());
                $base = $content !== '' ? $content : $subject;
                return str_replace('{{continut}}', $base, $tpl);
            case 'keywords':
                $tpl = get_option('cw_keywords_prompt', CW_Admin::default_keywords_prompt());
                $base = $content !== '' ? $content : $subject;
                return str_replace('{{continut}}', $base, $tpl);
            case 'article':
            default:
                $tpl = get_option('cw_article_prompt', CW_Admin::default_article_prompt());
                $tpl = str_replace('{{continut}}', $subject, $tpl);
                $tpl = str_replace('{{cuvinte}}', (string) $length, $tpl);
                return $tpl;
        }
    }

    public function ajax_generate() {
        check_ajax_referer('cw_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permisiune refuzată.', 'claude-writer')));
        }

        $model   = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $action  = isset($_POST['act']) ? sanitize_key($_POST['act']) : 'article';
        $subject = isset($_POST['subject']) ? sanitize_textarea_field(wp_unslash($_POST['subject'])) : '';
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        $length  = isset($_POST['length']) ? (int) $_POST['length'] : 1000;

        $model = CW_API::resolve_model($model);

        if ($action === 'article' && $subject === '') {
            wp_send_json_error(array('message' => __('Scrie întâi titlul articolului (sau completează instrucțiuni în modul).', 'claude-writer')));
        }
        if (in_array($action, array('rewrite', 'title', 'keywords'), true) && $content === '' && $subject === '') {
            wp_send_json_error(array('message' => __('Nu există conținut în editor pentru această acțiune.', 'claude-writer')));
        }

        $prompt = self::build_prompt($action, $subject, $content, $length);

        // Titlu/cuvinte cheie au nevoie de puțini tokeni.
        $short = in_array($action, array('title', 'keywords'), true);
        $max   = $short ? 256 : null;

        // Titlurile și cuvintele cheie se scot din textul existent — fără căutare web
        // (ar plăti o căutare pentru un răspuns de 10 cuvinte). Articolul și rescrierea
        // respectă setarea site-ului.
        $result = $short
            ? CW_API::no_web(function () use ($model, $prompt, $max) { return CW_API::generate($model, $prompt, $max); })
            : CW_API::generate($model, $prompt, $max);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'text'  => $result['text'],
            'cost'  => $result['cost'],
            'usage' => $result['usage'],
            'action'=> $action,
        ));
    }
}
