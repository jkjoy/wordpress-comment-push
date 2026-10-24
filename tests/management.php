<?php

/** Real WordPress command-management integration checks; no live bot requests. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) { http_response_code(403); exit; }
if (!function_exists('wpcpush_management_receive')) { WP_CLI::error('Activate a comment-push version with comment management first.'); }

global $wpcpush_mtest_cases, $wpcpush_mtest_posts, $wpcpush_mtest_users, $wpcpush_mtest_comments;
global $wpcpush_mtest_http, $wpcpush_mtest_router, $wpcpush_mtest_sequence, $wpcpush_mtest_admin;
$wpcpush_mtest_cases = [];
$wpcpush_mtest_posts = [];
$wpcpush_mtest_users = [];
$wpcpush_mtest_comments = [];
$wpcpush_mtest_http = [];
$wpcpush_mtest_router = null;
$wpcpush_mtest_sequence = 0;
$wpcpush_mtest_admin = 0;
$wpcpush_mtest_user_before = get_current_user_id();
$wpcpush_mtest_results = [];

// Keep interception in place through fixture cleanup and raw option restoration.
$wpcpush_mtest_http_guard = static function ($preempt, array $args, string $url) {
    $GLOBALS['wpcpush_mtest_http'][] = ['url' => $url, 'args' => $args];
    $router = $GLOBALS['wpcpush_mtest_router'];
    return is_callable($router) ? $router($args, $url) : new WP_Error('wpcpush_management_test_blocked', 'External HTTP is blocked by management tests.');
};
$wpcpush_mtest_mail_guard = static fn() => true;
add_filter('pre_http_request', $wpcpush_mtest_http_guard, PHP_INT_MIN, 3);
add_filter('pre_wp_mail', $wpcpush_mtest_mail_guard, PHP_INT_MIN);
$wpcpush_mtest_before = wpcpush_mtest_snapshot();

function wpcpush_mtest(string $name, callable $callback): void { $GLOBALS['wpcpush_mtest_cases'][$name] = $callback; }
function wpcpush_mtest_check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function wpcpush_mtest_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) { throw new RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function wpcpush_mtest_snapshot(): array
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = 'cron' ORDER BY option_name",
        $wpdb->esc_like('wpcpush_') . '%'
    ), ARRAY_A);
}
function wpcpush_mtest_clear(bool $includeCron = false): void
{
    global $wpdb;
    foreach (wpcpush_mtest_snapshot() as $row) {
        if (!$includeCron && $row['option_name'] === 'cron') { continue; }
        $wpdb->delete($wpdb->options, ['option_name' => $row['option_name']]);
        wp_cache_delete($row['option_name'], 'options');
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}
function wpcpush_mtest_fresh(): void
{
    wpcpush_mtest_clear();
    $GLOBALS['wpcpush_mtest_http'] = [];
    $GLOBALS['wpcpush_mtest_router'] = null;
    wp_set_current_user(0);
}
function wpcpush_mtest_user(string $role): int
{
    $id = wp_insert_user(['user_login' => 'cp_manage_fixture_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $GLOBALS['wpcpush_mtest_users'][] = (int)$id;
    return (int)$id;
}
function wpcpush_mtest_admin(): int
{
    if (!$GLOBALS['wpcpush_mtest_admin']) { $GLOBALS['wpcpush_mtest_admin'] = wpcpush_mtest_user('administrator'); }
    return $GLOBALS['wpcpush_mtest_admin'];
}
function wpcpush_mtest_config(string $channel = 'qq', array $changes = []): array
{
    $config = array_replace(wpcpush_defaults(), [
        'qq_app_id' => '123456', 'qq_app_secret' => 'management-fixture-secret', 'qq_target_type' => 'c2c', 'qq_target_id' => 'ADMIN_OPENID',
        'manager_user_id' => wpcpush_mtest_admin(), 'qq_manage_enabled' => $channel === 'qq', 'weixin_manage_enabled' => $channel === 'weixin',
        'qq_manage_since' => time() - 1, 'weixin_manage_since' => time() - 1,
    ], $changes);
    if ($channel === 'weixin') {
        wpcpush_write('weixin_account', ['token' => 'weixin-management-fixture-token', 'account_id' => 'bot@im.bot', 'owner_id' => 'owner@im.wechat', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
        wpcpush_write('weixin_sync', ['get_updates_buf' => 'fixture-start-cursor', 'candidates' => ['owner@im.wechat'], 'context_tokens' => ['owner@im.wechat' => 'fixture-context']]);
        $config['weixin_user_id'] = 'owner@im.wechat';
    }
    wpcpush_save_config($config);
    return wpcpush_config();
}
function wpcpush_mtest_post(): int
{
    $id = wp_insert_post(['post_title' => 'Management Integration Fixture', 'post_content' => 'Disposable fixture.', 'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open'], true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $GLOBALS['wpcpush_mtest_posts'][] = (int)$id;
    return (int)$id;
}
function wpcpush_mtest_comment(string $status = '0', string $body = '管理测试原评论', string $type = 'comment', ?int $postId = null): int
{
    $id = wp_insert_comment([
        'comment_post_ID' => $postId ?? wpcpush_mtest_post(), 'comment_author' => '管理测试访客',
        'comment_author_email' => 'never-disclose@example.test', 'comment_author_IP' => '192.0.2.91',
        'comment_content' => $body, 'comment_approved' => $status, 'comment_type' => $type,
    ]);
    wpcpush_mtest_check((bool)$id, 'Could not create fixture comment');
    $GLOBALS['wpcpush_mtest_comments'][] = (int)$id;
    return (int)$id;
}
function wpcpush_mtest_incoming(string $text, array $changes = [], string $channel = 'qq'): array
{
    $sequence = ++$GLOBALS['wpcpush_mtest_sequence'];
    return array_replace([
        'id' => 'management-fixture-message-' . $sequence,
        'sender_id' => $channel === 'weixin' ? 'owner@im.wechat' : 'ADMIN_OPENID',
        'target_id' => $channel === 'weixin' ? 'owner@im.wechat' : 'ADMIN_OPENID',
        'target_type' => $channel === 'weixin' ? 'private' : 'c2c', 'text' => $text, 'created_at' => time(),
    ], $changes);
}
function wpcpush_mtest_receive(string $text, array $changes = [], string $channel = 'qq'): ?string
{
    return wpcpush_management_receive($channel, wpcpush_mtest_incoming($text, $changes, $channel));
}
function wpcpush_mtest_ack(?string $reply): string
{
    wpcpush_mtest_check(is_string($reply) && $reply !== '', 'Authorized command did not return an acknowledgement');
    return $reply;
}
function wpcpush_mtest_replies(int $parent): array
{
    return get_comments(['parent' => $parent, 'status' => 'all', 'type' => 'comment', 'orderby' => 'comment_ID', 'order' => 'ASC']);
}
function wpcpush_mtest_wp_response(array $data, int $status = 200): array
{
    return ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => $status, 'message' => 'Fixture'], 'cookies' => [], 'filename' => null];
}
function wpcpush_mtest_transport(array &$calls, array $responses): Closure
{
    return static function (string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6) use (&$calls, &$responses): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
        if (!$responses) { throw new RuntimeException('Unexpected fixture transport request'); }
        $response = array_shift($responses);
        if ($response instanceof Throwable) { throw $response; }
        return $response;
    };
}
function wpcpush_mtest_qq_event(string $text, array $changes = [], bool $group = false): array
{
    return array_replace_recursive([
        'op' => 0, 't' => $group ? 'GROUP_AT_MESSAGE_CREATE' : 'C2C_MESSAGE_CREATE',
        'id' => 'gateway-event-' . ++$GLOBALS['wpcpush_mtest_sequence'],
        'd' => [
            'id' => 'qq-inbound-' . $GLOBALS['wpcpush_mtest_sequence'], 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'content' => $text,
            'author' => $group ? ['member_openid' => 'BOUND_MEMBER_OPENID'] : ['user_openid' => 'ADMIN_OPENID'],
            'group_openid' => 'BOUND_GROUP_OPENID',
        ],
    ], $changes);
}
function wpcpush_mtest_signed_webhook(array $event, array $headerChanges = []): array
{
    $raw = wp_json_encode($event);
    $timestamp = (string)time();
    $config = wpcpush_config();
    $secret = $config['qq_app_secret'];
    $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
    $pair = sodium_crypto_sign_seed_keypair($seed);
    $headers = array_replace([
        'x-bot-appid' => $config['qq_app_id'], 'x-signature-timestamp' => $timestamp,
        'x-signature-ed25519' => bin2hex(sodium_crypto_sign_detached($timestamp . $raw, sodium_crypto_sign_secretkey($pair))),
    ], $headerChanges);
    return wpcpush_qq_webhook_handle('POST', $raw, $headers);
}
function wpcpush_mtest_weixin_message(string $text, array $changes = []): array
{
    return array_replace([
        'message_id' => (string)(900000 + ++$GLOBALS['wpcpush_mtest_sequence']), 'message_type' => 1,
        'from_user_id' => 'owner@im.wechat', 'to_user_id' => 'bot@im.bot', 'context_token' => 'fixture-incoming-context',
        'create_time_ms' => (int)floor(microtime(true) * 1000), 'item_list' => [['type' => 1, 'text_item' => ['text' => $text]]],
    ], $changes);
}

wpcpush_mtest('management parser accepts the documented exact commands', static function (): void {
    foreach (['评论帮助', '待审评论', '待审评论 2', '查看评论 123', '通过评论 123', '驳回评论 123', '垃圾评论 123', '删除评论 123', '恢复评论 123', '回复评论 123 已收到，谢谢！'] as $text) {
        $parsed = wpcpush_management_parse($text);
        wpcpush_mtest_check(is_array($parsed) && is_string($parsed['action'] ?? null) && $parsed['action'] !== '', 'Rejected command: ' . $text);
        if (str_contains($text, '123')) { wpcpush_mtest_same(123, $parsed['comment_id']); }
    }
    wpcpush_mtest_same(2, wpcpush_management_parse('待审评论 2')['page']);
    wpcpush_mtest_same('已收到，谢谢！', wpcpush_management_parse('回复评论 123 已收到，谢谢！')['body']);
});

wpcpush_mtest('management parser rejects malformed IDs and chained commands', static function (): void {
    foreach (['你好', '通过评论', '通过评论 -1', '通过评论 0', '通过评论 1.5', '通过评论 1e3', '通过评论 999999999999999999999999999999', '通过评论 1 尾随命令', "通过评论 1\n删除评论 2", '回复评论 1', '回复评论 1   ', '待审评论 0', '待审评论 -1', '前缀查看评论 1'] as $text) {
        wpcpush_mtest_same(null, wpcpush_management_parse($text), 'Accepted malformed command: ' . $text);
    }
});

wpcpush_mtest('ordinary messages and disabled management do not create command claims', static function (): void {
    wpcpush_mtest_config('qq', ['qq_manage_enabled' => false]);
    $id = wpcpush_mtest_comment();
    $before = wpcpush_mtest_snapshot();
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id));
    wpcpush_mtest_same(null, wpcpush_mtest_receive('你好机器人'));
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    wpcpush_mtest_same($before, wpcpush_mtest_snapshot());
});

wpcpush_mtest('help list and detail use real WordPress comments without exposing visitor privacy', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    $help = wpcpush_mtest_ack(wpcpush_mtest_receive('评论帮助'));
    wpcpush_mtest_check(str_contains($help, '通过评论') && str_contains($help, '回复评论'));
    $list = wpcpush_mtest_ack(wpcpush_mtest_receive('待审评论'));
    wpcpush_mtest_check(str_contains($list, (string)$id) && str_contains($list, '管理测试原评论'));
    $detail = wpcpush_mtest_ack(wpcpush_mtest_receive('查看评论 ' . $id));
    wpcpush_mtest_check(str_contains($detail, 'Management Integration Fixture') && str_contains($detail, '管理测试原评论'));
    foreach ([$list, $detail] as $text) {
        wpcpush_mtest_check(!str_contains($text, 'never-disclose@example.test') && !str_contains($text, '192.0.2.91'));
    }
});

wpcpush_mtest('approve reject and spam commands change the persisted comment status', static function (): void {
    wpcpush_mtest_config();
    foreach ([['通过评论', '0', '1'], ['驳回评论', '1', '0'], ['垃圾评论', '0', 'spam']] as [$command, $before, $after]) {
        $id = wpcpush_mtest_comment($before);
        wpcpush_mtest_ack(wpcpush_mtest_receive($command . ' ' . $id));
        wpcpush_mtest_same($after, get_comment($id)->comment_approved, $command . ' did not persist');
    }
});

wpcpush_mtest('delete only trashes a comment and restore recovers its previous status', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    wpcpush_mtest_ack(wpcpush_mtest_receive('删除评论 ' . $id));
    wpcpush_mtest_check(get_comment($id) instanceof WP_Comment, 'Delete permanently removed a comment');
    wpcpush_mtest_same('trash', get_comment($id)->comment_approved);
    wpcpush_mtest_ack(wpcpush_mtest_receive('恢复评论 ' . $id));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
});

wpcpush_mtest('reply persists one child with the bound WordPress manager identity', static function (): void {
    $config = wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    $originalUser = wpcpush_mtest_user('subscriber');
    wp_set_current_user($originalUser);
    wpcpush_mtest_ack(wpcpush_mtest_receive('回复评论 ' . $id . ' 管理员回复测试'));
    $replies = wpcpush_mtest_replies($id);
    wpcpush_mtest_same(1, count($replies));
    wpcpush_mtest_same($config['manager_user_id'], (int)$replies[0]->user_id);
    wpcpush_mtest_same($id, (int)$replies[0]->comment_parent);
    wpcpush_mtest_same((int)get_comment($id)->comment_post_ID, (int)$replies[0]->comment_post_ID);
    wpcpush_mtest_check(str_contains($replies[0]->comment_content, '管理员回复测试'));
    wpcpush_mtest_same('1', $replies[0]->comment_approved);
    wpcpush_mtest_same($originalUser, get_current_user_id(), 'Management did not restore the original current user');
});

wpcpush_mtest('missing comments do not create replies or alter existing comments', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    $before = get_comment($id)->to_array();
    wpcpush_mtest_ack(wpcpush_mtest_receive('回复评论 999999999 不存在的评论'));
    wpcpush_mtest_same($before, get_comment($id)->to_array());
    wpcpush_mtest_same([], get_comments(['parent' => 999999999, 'status' => 'all']));
});

wpcpush_mtest('management replies preserve backslashes quotes and Chinese text exactly', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    $body = '中文路径 C:\\path\\folder，单引号\'、双引号"和反斜杠\\均保留';
    wpcpush_mtest_ack(wpcpush_mtest_receive('回复评论 ' . $id . ' ' . $body));
    $replies = wpcpush_mtest_replies($id);
    wpcpush_mtest_same(1, count($replies));
    wpcpush_mtest_same($body, $replies[0]->comment_content);
});

wpcpush_mtest('spam and subsequent approval use native WordPress spam hooks and metadata', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    $spamCount = 0;
    $unspamCount = 0;
    $onSpam = static function ($commentId) use ($id, &$spamCount): void { if ((int)$commentId === $id) { $spamCount++; } };
    $onUnspam = static function ($commentId) use ($id, &$unspamCount): void { if ((int)$commentId === $id) { $unspamCount++; } };
    add_action('spammed_comment', $onSpam);
    add_action('unspammed_comment', $onUnspam);
    try {
        wpcpush_mtest_ack(wpcpush_mtest_receive('垃圾评论 ' . $id));
        wpcpush_mtest_same(1, $spamCount);
        wpcpush_mtest_same('spam', get_comment($id)->comment_approved);
        wpcpush_mtest_same('1', get_comment_meta($id, '_wp_trash_meta_status', true));
        wpcpush_mtest_ack(wpcpush_mtest_receive('通过评论 ' . $id));
        wpcpush_mtest_same(1, $unspamCount);
        wpcpush_mtest_same('1', get_comment($id)->comment_approved);
        wpcpush_mtest_same('', get_comment_meta($id, '_wp_trash_meta_status', true));
    } finally {
        remove_action('spammed_comment', $onSpam);
        remove_action('unspammed_comment', $onUnspam);
    }
});

wpcpush_mtest('QQ private commands require the exact bound sender and recipient', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    foreach ([['sender_id' => 'FOREIGN_OPENID'], ['target_id' => 'FOREIGN_OPENID'], ['target_type' => 'group'], ['sender_id' => ''], ['target_id' => '']] as $changes) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, $changes));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
});

wpcpush_mtest('WordPress manager must still have comment moderation permission', static function (): void {
    wpcpush_mtest_config('qq', ['manager_user_id' => wpcpush_mtest_user('subscriber')]);
    $id = wpcpush_mtest_comment();
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id));
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
});

wpcpush_mtest('per-comment WordPress permission is enforced and current user restored on rejection', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    $deny = static function (array $caps, string $cap, int $userId, array $args) use ($id): array {
        return $cap === 'edit_comment' && (int)($args[0] ?? 0) === $id ? ['do_not_allow'] : $caps;
    };
    add_filter('map_meta_cap', $deny, 99, 4);
    $original = wpcpush_mtest_user('subscriber');
    wp_set_current_user($original);
    try {
        wpcpush_management_receive('qq', wpcpush_mtest_incoming('通过评论 ' . $id));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
        wpcpush_mtest_same($original, get_current_user_id());
    } finally { remove_filter('map_meta_cap', $deny, 99); }
});

wpcpush_mtest('QQ group commands require both the bound group and its separately bound member', static function (): void {
    $config = wpcpush_mtest_config('qq', ['qq_target_type' => 'group', 'qq_target_id' => 'BOUND_GROUP_OPENID']);
    $id = wpcpush_mtest_comment();
    $incoming = ['target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'sender_id' => 'BOUND_MEMBER_OPENID'];
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, $incoming));
    wpcpush_write('qq_manager', ['identity' => wpcpush_qq_identity($config), 'target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'actor_id' => 'BOUND_MEMBER_OPENID']);
    foreach ([['sender_id' => 'OTHER_GROUP_MEMBER'], ['target_id' => 'OTHER_GROUP'], ['sender_id' => 'BOUND_GROUP_OPENID']] as $bad) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, array_replace($incoming, $bad)));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
    wpcpush_mtest_ack(wpcpush_mtest_receive('通过评论 ' . $id, $incoming));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
});

wpcpush_mtest('QQ group manager records from another bot identity never authorize a command', static function (): void {
    wpcpush_mtest_config('qq', ['qq_target_type' => 'group', 'qq_target_id' => 'BOUND_GROUP_OPENID']);
    wpcpush_write('qq_manager', ['identity' => 'stale-bot-identity', 'target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'actor_id' => 'BOUND_MEMBER_OPENID']);
    $id = wpcpush_mtest_comment();
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, ['target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'sender_id' => 'BOUND_MEMBER_OPENID']));
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
});

wpcpush_mtest('QQ channels cannot execute management commands', static function (): void {
    wpcpush_mtest_config('qq', ['qq_target_type' => 'channel', 'qq_target_id' => 'CHANNEL_ID']);
    $id = wpcpush_mtest_comment();
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, ['target_type' => 'channel', 'target_id' => 'CHANNEL_ID']));
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
});

wpcpush_mtest('old pre-activation future and missing-timestamp messages cannot execute', static function (): void {
    wpcpush_mtest_config('qq', ['qq_manage_since' => time() - 60]);
    $id = wpcpush_mtest_comment();
    foreach ([time() - 301, time() - 61, time() + 31, 0] as $createdAt) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, ['created_at' => $createdAt]));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
});

wpcpush_mtest('missing and non-scalar message IDs cannot create management actions', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    foreach (['', [], 1.25] as $badId) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, ['id' => $badId]));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
});

wpcpush_mtest('a repeated message cannot repeat or replace an already claimed action', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    $incoming = wpcpush_mtest_incoming('回复评论 ' . $id . ' 只能出现一次');
    wpcpush_mtest_ack(wpcpush_management_receive('qq', $incoming));
    wpcpush_mtest_same(null, wpcpush_management_receive('qq', $incoming));
    wpcpush_mtest_same(null, wpcpush_management_receive('qq', array_replace($incoming, ['text' => '删除评论 ' . $id])));
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
    global $wpdb;
    $claims = $wpdb->get_results($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wpcpush_command_') . '%'), ARRAY_A);
    wpcpush_mtest_check(count($claims) >= 1);
    foreach ($claims as $claim) {
        wpcpush_mtest_check(ctype_digit($claim['option_value']) && !is_serialized($claim['option_value']), 'Claim must store only an expiry timestamp');
        wpcpush_mtest_check(in_array($claim['autoload'], ['no', 'off', 'auto-off'], true), 'Command claims must not autoload');
    }
});

wpcpush_mtest('stale negative option cache cannot bypass a command claim', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1');
    $incoming = wpcpush_mtest_incoming('回复评论 ' . $id . ' 缓存测试只回复一次');
    wpcpush_mtest_ack(wpcpush_management_receive('qq', $incoming));
    $notoptions = wp_cache_get('notoptions', 'options');
    $stale = is_array($notoptions) ? $notoptions : [];
    foreach (wpcpush_mtest_snapshot() as $row) {
        if (str_starts_with($row['option_name'], 'wpcpush_command_')) {
            $stale[$row['option_name']] = true;
            wp_cache_delete($row['option_name'], 'options');
        }
    }
    wp_cache_set('notoptions', $stale, 'options');
    try {
        wpcpush_mtest_same(null, wpcpush_management_receive('qq', $incoming));
        wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
    } finally {
        if ($notoptions === false) { wp_cache_delete('notoptions', 'options'); }
        else { wp_cache_set('notoptions', $notoptions, 'options'); }
    }
});

wpcpush_mtest('management result stores a safe summary without comment reply or credential contents', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment('1', 'PRIVATE_COMMENT_BODY_6d912');
    wpcpush_mtest_ack(wpcpush_mtest_receive('回复评论 ' . $id . ' PRIVATE_REPLY_BODY_4a029'));
    $summary = wp_json_encode(wpcpush_read('management_result'));
    wpcpush_mtest_check($summary !== '[]', 'No management result was recorded');
    foreach (['PRIVATE_COMMENT_BODY_6d912', 'PRIVATE_REPLY_BODY_4a029', 'management-fixture-secret', 'never-disclose@example.test', '192.0.2.91'] as $private) {
        wpcpush_mtest_check(!str_contains($summary, $private), 'Private content leaked into management result');
    }
});

wpcpush_mtest('QQ Webhook rejects forged commands before any mutation or command claim', static function (): void {
    wpcpush_mtest_config();
    $id = wpcpush_mtest_comment();
    $event = wpcpush_mtest_qq_event('通过评论 ' . $id);
    $before = wpcpush_mtest_snapshot();
    foreach ([['x-signature-ed25519' => str_repeat('0', 128)], ['x-bot-appid' => '654321'], ['x-signature-timestamp' => '1']] as $headers) {
        $result = wpcpush_mtest_signed_webhook($event, $headers);
        wpcpush_mtest_check($result['status'] >= 400 && $result['status'] < 500);
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
        wpcpush_mtest_same($before, wpcpush_mtest_snapshot());
    }
});

wpcpush_mtest('signed QQ Webhook executes once and passive acknowledgement uses the inbound message ID', static function (): void {
    $config = wpcpush_mtest_config();
    wpcpush_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => 'fixture-qq-token', 'expires_at' => time() + 3600]);
    $GLOBALS['wpcpush_mtest_router'] = static fn() => wpcpush_mtest_wp_response(['id' => 'fixture-ack-id']);
    $id = wpcpush_mtest_comment('1');
    $event = wpcpush_mtest_qq_event('回复评论 ' . $id . ' QQ签名回复');
    wpcpush_mtest_same(200, wpcpush_mtest_signed_webhook($event)['status']);
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
    wpcpush_mtest_check(count($GLOBALS['wpcpush_mtest_http']) >= 1, 'No passive acknowledgement request was made');
    $payload = json_decode($GLOBALS['wpcpush_mtest_http'][0]['args']['body'], true);
    wpcpush_mtest_same($event['d']['id'], $payload['msg_id']);
    wpcpush_mtest_same(1, $payload['msg_seq']);
    $event['id'] .= '-second-gateway-envelope';
    wpcpush_mtest_same(200, wpcpush_mtest_signed_webhook($event)['status']);
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
});

wpcpush_mtest('QQ acknowledgement timeout cannot cause a duplicate reply on gateway redelivery', static function (): void {
    $config = wpcpush_mtest_config();
    wpcpush_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => 'fixture-qq-token', 'expires_at' => time() + 3600]);
    $GLOBALS['wpcpush_mtest_router'] = static fn() => new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    $id = wpcpush_mtest_comment('1');
    $event = wpcpush_mtest_qq_event('回复评论 ' . $id . ' 回执失败也只回复一次');
    try { wpcpush_mtest_signed_webhook($event); } catch (RuntimeException) { /* Claim must survive an ambiguous acknowledgement. */ }
    try { wpcpush_mtest_signed_webhook($event); } catch (RuntimeException) { }
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
    wpcpush_mtest_check(get_comment($id) instanceof WP_Comment);
});

wpcpush_mtest('QQ group adapter reads member_openid and rejects unbound group members', static function (): void {
    $config = wpcpush_mtest_config('qq', ['qq_target_type' => 'group', 'qq_target_id' => 'BOUND_GROUP_OPENID']);
    wpcpush_write('qq_manager', ['identity' => wpcpush_qq_identity($config), 'target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'actor_id' => 'BOUND_MEMBER_OPENID']);
    wpcpush_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => 'fixture-qq-token', 'expires_at' => time() + 3600]);
    $GLOBALS['wpcpush_mtest_router'] = static fn() => wpcpush_mtest_wp_response(['id' => 'fixture-ack-id']);
    $id = wpcpush_mtest_comment();
    $foreign = wpcpush_mtest_qq_event('<@123456> 通过评论 ' . $id, ['d' => ['author' => ['member_openid' => 'OTHER_MEMBER']]], true);
    wpcpush_mtest_signed_webhook($foreign);
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    wpcpush_mtest_signed_webhook(wpcpush_mtest_qq_event('<@123456> 通过评论 ' . $id, [], true));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
});

wpcpush_mtest('QQ group binding captures the signed member identity for later management', static function (): void {
    $config = wpcpush_mtest_config('qq', ['qq_target_type' => 'group', 'qq_target_id' => 'BOUND_GROUP_OPENID']);
    $binding = wpcpush_qq_binding_start();
    $event = wpcpush_mtest_qq_event('<@123456> 绑定通知 ' . $binding['code'], [], true);
    wpcpush_mtest_same(200, wpcpush_mtest_signed_webhook($event)['status']);
    $manager = wpcpush_read('qq_manager');
    wpcpush_mtest_same('BOUND_MEMBER_OPENID', $manager['actor_id']);
    wpcpush_mtest_same('BOUND_GROUP_OPENID', $manager['target_id']);
    wpcpush_mtest_same('group', $manager['target_type']);
    wpcpush_mtest_same(wpcpush_qq_identity($config), $manager['identity']);
    wpcpush_mtest_same([], wpcpush_read('qq_binding'));
});

wpcpush_mtest('QQ acknowledgement failure and duplicate delete never permanently remove a comment', static function (): void {
    $config = wpcpush_mtest_config();
    wpcpush_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => 'fixture-qq-token', 'expires_at' => time() + 3600]);
    $GLOBALS['wpcpush_mtest_router'] = static fn() => new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    $id = wpcpush_mtest_comment('1');
    $event = wpcpush_mtest_qq_event('删除评论 ' . $id);
    $trashCount = 0;
    $onTrash = static function ($commentId) use ($id, &$trashCount): void { if ((int)$commentId === $id) { $trashCount++; } };
    add_action('trashed_comment', $onTrash);
    try {
        wpcpush_mtest_signed_webhook($event);
        wpcpush_mtest_signed_webhook($event);
        wpcpush_mtest_check(get_comment($id) instanceof WP_Comment);
        wpcpush_mtest_same('trash', get_comment($id)->comment_approved);
        wpcpush_mtest_same(1, $trashCount);
    } finally { remove_action('trashed_comment', $onTrash); }
});

wpcpush_mtest('rebinding the same QQ group revokes management until an administrator explicitly enables it again', static function (): void {
    $id = wpcpush_mtest_comment();
    $config = wpcpush_mtest_config('qq', ['qq_enabled' => true, 'qq_target_type' => 'group', 'qq_target_id' => 'BOUND_GROUP_OPENID']);
    wpcpush_write('qq_manager', ['identity' => wpcpush_qq_identity($config), 'target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID', 'actor_id' => 'OLD_BOUND_MEMBER']);
    $binding = wpcpush_qq_binding_start();
    $event = wpcpush_mtest_qq_event('<@123456> 绑定通知 ' . $binding['code'], ['d' => ['author' => ['member_openid' => 'NEW_BOUND_MEMBER']]], true);
    wpcpush_mtest_same(200, wpcpush_mtest_signed_webhook($event)['status']);
    $rebound = wpcpush_config();
    wpcpush_mtest_same(true, $rebound['qq_enabled'], 'Notification opt-in was lost during rebinding');
    wpcpush_mtest_same(false, $rebound['qq_manage_enabled'], 'New group member inherited a previous management opt-in');
    wpcpush_mtest_same(0, $rebound['qq_manage_since']);
    wpcpush_mtest_same('NEW_BOUND_MEMBER', wpcpush_read('qq_manager')['actor_id']);
    $target = ['target_type' => 'group', 'target_id' => 'BOUND_GROUP_OPENID'];
    foreach (['OLD_BOUND_MEMBER', 'NEW_BOUND_MEMBER'] as $actor) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, array_replace($target, ['sender_id' => $actor])));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
    wp_set_current_user(wpcpush_mtest_admin());
    wpcpush_save_config(array_replace($rebound, ['qq_manage_enabled' => true, 'qq_manage_since' => time()]));
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, array_replace($target, ['sender_id' => 'OLD_BOUND_MEMBER'])));
    wpcpush_mtest_ack(wpcpush_mtest_receive('通过评论 ' . $id, array_replace($target, ['sender_id' => 'NEW_BOUND_MEMBER'])));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
});

wpcpush_mtest('Weixin commands require the bound scanner owner and an unexpired account', static function (): void {
    wpcpush_mtest_config('weixin');
    $id = wpcpush_mtest_comment();
    foreach ([['sender_id' => 'stranger@im.wechat'], ['target_id' => 'stranger@im.wechat'], ['target_type' => 'group']] as $bad) {
        wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, $bad, 'weixin'));
        wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    }
    $account = wpcpush_read('weixin_account');
    wpcpush_write('weixin_account', array_replace($account, ['owner_id' => 'different-owner@im.wechat']));
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, [], 'weixin'));
    wpcpush_write('weixin_account', array_replace($account, ['expired' => true]));
    wpcpush_mtest_same(null, wpcpush_mtest_receive('通过评论 ' . $id, [], 'weixin'));
    wpcpush_write('weixin_account', $account);
    wpcpush_mtest_ack(wpcpush_mtest_receive('通过评论 ' . $id, [], 'weixin'));
    wpcpush_mtest_same('1', get_comment($id)->comment_approved);
});

wpcpush_mtest('Weixin polling skips pre-enable messages while consuming a current owner command', static function (): void {
    $config = wpcpush_mtest_config('weixin', ['weixin_manage_since' => time() - 60]);
    $oldId = wpcpush_mtest_comment();
    $newId = wpcpush_mtest_comment();
    $calls = [];
    $messages = [
        wpcpush_mtest_weixin_message('通过评论 ' . $oldId, ['create_time_ms' => ($config['weixin_manage_since'] - 1) * 1000]),
        wpcpush_mtest_weixin_message('通过评论 ' . $newId),
    ];
    $mock = wpcpush_mtest_transport($calls, [['status' => 200, 'data' => ['ret' => 0, 'get_updates_buf' => 'fixture-next-cursor', 'msgs' => $messages]], ['status' => 200, 'data' => ['ret' => 0]]]);
    wpcpush_weixin_sync($mock);
    wpcpush_mtest_same('0', get_comment($oldId)->comment_approved);
    wpcpush_mtest_same('1', get_comment($newId)->comment_approved);
    wpcpush_mtest_same('fixture-next-cursor', wpcpush_read('weixin_sync')['get_updates_buf']);
});

wpcpush_mtest('Weixin poll ignores foreign group outbound missing-time and float-ID command messages', static function (): void {
    wpcpush_mtest_config('weixin');
    $id = wpcpush_mtest_comment();
    $messages = [];
    foreach ([['from_user_id' => 'stranger@im.wechat'], ['group_id' => 'some-group'], ['message_type' => 2], ['create_time_ms' => 0], ['message_id' => 9007199254740992.0]] as $bad) {
        $messages[] = wpcpush_mtest_weixin_message('通过评论 ' . $id, $bad);
    }
    $calls = [];
    wpcpush_weixin_sync(wpcpush_mtest_transport($calls, [['status' => 200, 'data' => ['ret' => 0, 'get_updates_buf' => 'fixture-safe-cursor', 'msgs' => $messages]]]));
    wpcpush_mtest_same('0', get_comment($id)->comment_approved);
    wpcpush_mtest_same(1, count($calls), 'Ignored command generated an outgoing acknowledgement');
});

wpcpush_mtest('Weixin cursor retry and acknowledgement failure cannot duplicate a reply', static function (): void {
    wpcpush_mtest_config('weixin');
    $id = wpcpush_mtest_comment('1');
    $message = wpcpush_mtest_weixin_message('回复评论 ' . $id . ' 微信重投只回复一次');
    $response = ['status' => 200, 'data' => ['ret' => 0, 'get_updates_buf' => 'fixture-retry-cursor', 'msgs' => [$message]]];
    $firstCalls = [];
    try { wpcpush_weixin_sync(wpcpush_mtest_transport($firstCalls, [$response, new RuntimeException('simulated ack timeout', 28)])); }
    catch (RuntimeException) { }
    $secondCalls = [];
    wpcpush_weixin_sync(wpcpush_mtest_transport($secondCalls, [$response, ['status' => 200, 'data' => ['ret' => 0]]]));
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
});

wpcpush_mtest('Weixin raw JSON big integer message IDs remain exact and deduplicate safely', static function (): void {
    wpcpush_mtest_config('weixin');
    $id = wpcpush_mtest_comment('1');
    $message = wpcpush_mtest_weixin_message('回复评论 ' . $id . ' 大整数消息只回复一次', ['message_id' => '18446744073709551615']);
    $json = wp_json_encode(['ret' => 0, 'get_updates_buf' => 'fixture-bigint-cursor', 'msgs' => [$message]]);
    // A JSON number larger than PHP_INT_MAX must become a string, never a float.
    $rawJson = str_replace('"message_id":"18446744073709551615"', '"message_id":18446744073709551615', $json);
    $GLOBALS['wpcpush_mtest_router'] = static function (array $args, string $url) use ($rawJson): array {
        if (str_ends_with($url, '/getupdates')) {
            $response = wpcpush_mtest_wp_response([]);
            $response['body'] = $rawJson;
            return $response;
        }
        if (str_ends_with($url, '/sendmessage')) { return wpcpush_mtest_wp_response(['ret' => 0]); }
        throw new RuntimeException('Unexpected big-integer test endpoint');
    };
    wpcpush_weixin_sync();
    wpcpush_weixin_sync();
    wpcpush_mtest_same(1, count(wpcpush_mtest_replies($id)));
    wpcpush_mtest_same('fixture-bigint-cursor', wpcpush_read('weixin_sync')['get_updates_buf']);
});

try {
    foreach ($wpcpush_mtest_cases as $name => $callback) {
        wpcpush_mtest_fresh();
        $level = ob_get_level();
        ob_start();
        try { $callback(); $wpcpush_mtest_results[] = 'PASS ' . $name; }
        catch (Throwable $error) { $wpcpush_mtest_results[] = 'FAIL ' . $name . "\n     " . $error->getMessage(); }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
} finally {
    try {
        $GLOBALS['wpcpush_mtest_router'] = null;
        wp_set_current_user(0);
        foreach ($wpcpush_mtest_comments as $id) { wp_delete_comment($id, true); }
        foreach ($wpcpush_mtest_posts as $id) { wp_delete_post($id, true); }
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        foreach ($wpcpush_mtest_users as $id) { wp_delete_user($id); }
        wpcpush_mtest_clear(true);
        global $wpdb;
        foreach ($wpcpush_mtest_before as $row) {
            if ($wpdb->insert($wpdb->options, $row) === false) { throw new RuntimeException('Could not restore original option: ' . $row['option_name']); }
            wp_cache_delete($row['option_name'], 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wpcpush_mtest_same($wpcpush_mtest_before, wpcpush_mtest_snapshot(), 'Original plugin options or cron were not restored');
    } finally {
        wp_set_current_user($wpcpush_mtest_user_before);
        remove_filter('pre_wp_mail', $wpcpush_mtest_mail_guard, PHP_INT_MIN);
        remove_filter('pre_http_request', $wpcpush_mtest_http_guard, PHP_INT_MIN);
    }
}
$wpcpush_mtest_failed = count(array_filter($wpcpush_mtest_results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $wpcpush_mtest_results) . "\n\n" . count($wpcpush_mtest_results) . ' management checks, ' . $wpcpush_mtest_failed . " failures.\n";
WP_CLI::halt($wpcpush_mtest_failed === 0 ? 0 : 1);
