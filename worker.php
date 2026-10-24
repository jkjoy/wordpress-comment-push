<?php
// Run with PHP CLI; this is not a public web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('DISABLE_WP_CRON', true);
require_once dirname(__DIR__, 3) . '/wp-load.php';
if (!function_exists('wpcpush_management_poll')) {
    fwrite(STDERR, "Comment Push is not active.\n");
    exit(0);
}

while (true) {
    // Long-running PHP processes must see settings changed by the web process.
    if (function_exists('wp_cache_flush_runtime') && wp_cache_supports('flush_runtime')) {
        wp_cache_flush_runtime();
    } else {
        foreach (['config', 'weixin_account', 'weixin_sync', 'qq_manager', 'receiver_status'] as $key) {
            wp_cache_delete('wpcpush_' . $key, 'options');
        }
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
    wp_cache_delete('active_plugins', 'options');
    if (!in_array('comment-push/comment-push.php', (array)get_option('active_plugins', []), true)) { exit(0); }
    try { wpcpush_management_poll('worker'); }
    catch (Throwable) { error_log('Comment push: receiver state could not be saved.'); }
    sleep(3);
}
