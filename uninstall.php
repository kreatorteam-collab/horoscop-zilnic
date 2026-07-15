<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$options = array(
    'hz_enabled', 'hz_hour', 'hz_status', 'hz_model', 'hz_category',
    'hz_source_url', 'hz_image', 'hz_title', 'hz_prompt', 'hz_auto_update',
    'hz_last_run', 'hz_last_error',
);
foreach ($options as $opt) { delete_option($opt); }
delete_transient('hz_update_info');
delete_transient('hz_horo_lock');
wp_clear_scheduled_hook('hz_horoscope_daily');
