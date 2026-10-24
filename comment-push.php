<?php
/**
 * Plugin Name: QQ / 微信评论推送
 * Plugin URI: https://github.com/jkjoy/wordpress-comment-push
 * Description: QQ / 微信新评论通知与机器人评论管理，支持审核、回复和回收站操作。
 * Version: 1.2.0
 * Author: 老孙
 * Author URI: https://www.imsun.org/
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/jkjoy/wordpress-comment-push
 * Text Domain: comment-push
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

define('WPCPUSH_VERSION', '1.2.0');
define('WPCPUSH_FILE', __FILE__);

foreach (['config', 'http', 'management', 'qq', 'qq-webhook', 'weixin', 'notifications'] as $module) {
    require_once __DIR__ . '/' . $module . '.php';
}

require_once __DIR__ . '/updater.php';

// This insertion hook also covers REST and CLI comments without repeating on moderation.
add_action('wp_insert_comment', 'wpcpush_comment_created', 30, 2);
register_activation_hook(__FILE__, 'wpcpush_management_schedule');
register_deactivation_hook(__FILE__, 'wpcpush_management_deactivate');

if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
