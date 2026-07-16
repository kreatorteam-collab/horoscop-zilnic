<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$options = array(
    'hz_enabled', 'hz_hour', 'hz_status', 'hz_model', 'hz_category', 'hz_category_id',
    'hz_source_url', 'hz_image', 'hz_title', 'hz_prompt', 'hz_system_prompt',
    'hz_auto_update', 'hz_last_run', 'hz_last_error', 'hz_db_version', 'hz_cw_removed',
    // Modulul AI Writer inclus (fost plugin claude-writer) — opțiunile cw_*:
    'cw_api_key', 'cw_openai_api_key', 'cw_model_default_gpt56', 'cw_model_default_sonnet',
    'cw_default_model', 'cw_effort', 'cw_temperature', 'cw_max_tokens', 'cw_stream_enabled',
    'cw_monthly_cost_limit', 'cw_system_prompt', 'cw_article_prompt', 'cw_rewrite_prompt',
    'cw_title_prompt', 'cw_keywords_prompt', 'cw_disclaimer', 'cw_auto_update', 'cw_db_version',
    'cw_calls_total', 'cw_cost_total', 'cw_cost_monthly', 'cw_usage_by_model',
    'cw_credit_error_openai', 'cw_credit_error_anthropic',
    'cw_credit_mail_openai', 'cw_credit_mail_anthropic',
);
foreach ($options as $opt) { delete_option($opt); }
delete_transient('cw_update_info');
delete_transient('hz_update_info');
delete_transient('hz_horo_lock');
wp_clear_scheduled_hook('hz_horoscope_daily');
