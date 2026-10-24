<?php

if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

wp_clear_scheduled_hook('wpcpush_weixin_poll');
delete_site_transient('wpcpush_release');
foreach (['config', 'qq_token', 'qq_binding', 'qq_webhook', 'qq_binding_lock', 'qq_manager', 'weixin_account', 'weixin_login', 'weixin_sync', 'weixin_poll_lock', 'receiver_status', 'management_result', 'result_qq', 'result_weixin'] as $key) {
    delete_option('wpcpush_' . $key);
}
global $wpdb;
$claims = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wpcpush_command_') . '%'));
foreach ($claims as $name) { delete_option($name); }
