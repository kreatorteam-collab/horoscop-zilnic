<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$options = array(
    'hz_enabled', 'hz_hour', 'hz_status', 'hz_model', 'hz_category', 'hz_category_id', 'hz_author',
    'hz_source_url', 'hz_image', 'hz_title', 'hz_prompt', 'hz_system_prompt',
    'hz_auto_update', 'hz_last_run', 'hz_last_error', 'hz_db_version', 'hz_cw_removed',
    // Modul Vreme (v3.0.0) — opțiunile hzw_*:
    'hzw_enabled', 'hzw_hour', 'hzw_status', 'hzw_model', 'hzw_category', 'hzw_category_id',
    'hzw_author', 'hzw_source_url', 'hzw_image', 'hzw_cite_source', 'hzw_cache_hours', 'hzw_title',
    'hzw_prompt', 'hzw_system_prompt', 'hzw_last_run', 'hzw_last_error',
    // Modul Articole Bulk (v3.1.0) — opțiunile hzb_*:
    'hzb_magnific_key', 'hzb_images', 'hzb_image_size', 'hzb_daily_limit', 'hzb_length',
    'hzb_author', 'hzb_ai_excluded', 'hzb_random_date', 'hzb_queue', 'hzb_used_ids', 'hzb_dl_day', 'hzb_paused_reason',
    'hzb_stopped', 'hzb_gap', 'hzb_exhausted_day', 'hzb_rd_forced',
    // Modul Refresh conținut (v3.2.0) — opțiunile hzr_*. Atenție: post_meta-urile
    // _hzr_* (inclusiv backup-ul conținutului original) NU se șterg — sunt plasa de
    // siguranță a articolelor deja modificate.
    'hzr_enabled', 'hzr_model', 'hzr_daily_limit', 'hzr_gap', 'hzr_do_faq', 'hzr_do_takeaways',
    'hzr_faq_count', 'hzr_tk_count', 'hzr_tk_pos', 'hzr_min_words', 'hzr_order', 'hzr_max_chars',
    'hzr_excluded_cats', 'hzr_cats_seeded', 'hzr_cats_seed_v', 'hzr_time_jitter', 'hzr_stopped', 'hzr_prompt', 'hzr_system_prompt',
    'hzr_do_meta', 'hzr_meta_overwrite', 'hzr_do_title', 'hzr_title_max_len', 'hzr_faq_heading', 'hzr_tk_heading',
    'hzr_day', 'hzr_stats', 'hzr_idle', 'hzr_last_error',
    // Modulul AI Writer inclus (fost plugin claude-writer) — opțiunile cw_*:
    'cw_api_key', 'cw_openai_api_key', 'cw_openai_base', 'cw_gateway_models', 'cw_openai_key_images', 'cw_model_default_gpt56', 'cw_model_default_sonnet',
    'cw_default_model', 'cw_effort', 'cw_temperature', 'cw_max_tokens', 'cw_stream_enabled',
    'cw_monthly_cost_limit', 'cw_system_prompt', 'cw_article_prompt', 'cw_rewrite_prompt',
    'cw_title_prompt', 'cw_keywords_prompt', 'cw_disclaimer', 'cw_auto_update', 'cw_db_version',
    'cw_calls_total', 'cw_cost_total', 'cw_cost_monthly', 'cw_usage_by_model',
    'cw_credit_error_openai', 'cw_credit_error_anthropic',
    'cw_credit_mail_openai', 'cw_credit_mail_anthropic', 'cw_alert_email',
    // Modul Știri (v3.50.0) — opțiunile hzn_*:
    'hzn_enabled', 'hzn_per_cat', 'hzn_start_hour', 'hzn_end_hour', 'hzn_status', 'hzn_model',
    'hzn_author', 'hzn_images', 'hzn_free_photos', 'hzn_source_photo', 'hzn_max_age', 'hzn_rss_url', 'hzn_seeded', 'hzn_seen', 'hzn_today',
    'hzn_last_run', 'hzn_last_error', 'hzn_last_image',
    'hzn_on_actualitate', 'hzn_on_politica', 'hzn_on_economie', 'hzn_on_externe',
    'hzn_cat_actualitate', 'hzn_cat_politica', 'hzn_cat_economie', 'hzn_cat_externe',
);
foreach ($options as $opt) { delete_option($opt); }
delete_transient('cw_update_info');
delete_transient('hz_update_info');
delete_transient('hz_horo_lock');
delete_transient('hz_weather_lock');
delete_transient('hz_weather_anm_xml');
delete_transient('hz_bulk_lock');
delete_transient('hz_refresh_lock');
delete_transient('hzb_central_dl');
delete_transient('hzb_central_ex');
wp_clear_scheduled_hook('hz_horoscope_daily');
wp_clear_scheduled_hook('hz_weather_daily');
wp_clear_scheduled_hook('hz_bulk_process');
wp_clear_scheduled_hook('hz_refresh_tick');
wp_clear_scheduled_hook('hz_news_tick');
delete_transient('hz_news_lock');
