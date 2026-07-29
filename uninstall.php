<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$options = array(
    'hz_enabled', 'hz_hour', 'hz_status', 'hz_model', 'hz_category', 'hz_category_id',
    'hz_source_url', 'hz_image', 'hz_title', 'hz_prompt', 'hz_system_prompt',
    'hz_auto_update', 'hz_last_run', 'hz_last_error', 'hz_db_version', 'hz_cw_removed',
    // Modul Vreme (v3.0.0) — opțiunile hzw_*:
    'hzw_enabled', 'hzw_hour', 'hzw_status', 'hzw_model', 'hzw_category', 'hzw_category_id',
    'hzw_author', 'hzw_source_url', 'hzw_image', 'hzw_cite_source', 'hzw_cache_hours', 'hzw_title',
    'hzw_prompt', 'hzw_system_prompt', 'hzw_last_run', 'hzw_last_error',
    // Modul Articole Bulk (v3.1.0) — opțiunile hzb_*:
    'hzb_magnific_key', 'hzb_images', 'hzb_image_size', 'hzb_daily_limit', 'hzb_length',
    'hzb_author', 'hzb_ai_excluded', 'hzb_queue', 'hzb_used_ids', 'hzb_dl_day', 'hzb_paused_reason',
    // Modulul AI Writer inclus (fost plugin claude-writer) — opțiunile cw_*:
    'cw_api_key', 'cw_openai_api_key', 'cw_model_default_gpt56', 'cw_model_default_sonnet',
    'cw_default_model', 'cw_effort', 'cw_temperature', 'cw_max_tokens', 'cw_stream_enabled',
    'cw_monthly_cost_limit', 'cw_system_prompt', 'cw_article_prompt', 'cw_rewrite_prompt',
    'cw_title_prompt', 'cw_keywords_prompt', 'cw_disclaimer', 'cw_auto_update', 'cw_db_version',
    'cw_calls_total', 'cw_cost_total', 'cw_cost_monthly', 'cw_usage_by_model',
    'cw_credit_error_openai', 'cw_credit_error_anthropic',
    'cw_credit_mail_openai', 'cw_credit_mail_anthropic', 'cw_alert_email',
);
foreach ($options as $opt) { delete_option($opt); }
delete_transient('cw_update_info');
delete_transient('hz_update_info');
delete_transient('hz_horo_lock');
delete_transient('hz_weather_lock');
delete_transient('hz_weather_anm_xml');
delete_transient('hz_bulk_lock');
wp_clear_scheduled_hook('hz_horoscope_daily');
wp_clear_scheduled_hook('hz_weather_daily');
wp_clear_scheduled_hook('hz_bulk_process');
