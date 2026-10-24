<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_management_parse(string $text): ?array
{
    $text = trim($text);
    if (strlen($text) > 12000 || preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) { return null; }
    if ($text === '评论帮助') { return ['action' => 'help']; }
    if (preg_match('/\A待审评论(?:[ \t]+([1-9][0-9]{0,3}))?\z/u', $text, $matches)) {
        return ['action' => 'list', 'page' => isset($matches[1]) ? (int)$matches[1] : 1];
    }
    if (!preg_match('/\A(查看|通过|驳回|垃圾|删除|恢复|回复)评论[ \t]+([1-9][0-9]*)(?:[ \t]+([\s\S]+))?\z/u', $text, $matches)) { return null; }
    $id = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) { return null; }
    $action = ['查看' => 'view', '通过' => 'approve', '驳回' => 'hold', '垃圾' => 'spam', '删除' => 'trash', '恢复' => 'restore', '回复' => 'reply'][$matches[1]];
    $body = trim($matches[3] ?? '');
    if ($action !== 'reply' && $body !== '') { return null; }
    if ($action === 'reply' && ($body === '' || count(preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY)) > 2000)) { return null; }
    return ['action' => $action, 'comment_id' => $id, 'body' => $body];
}

function wpcpush_management_help(): string
{
    return "评论管理指令\n待审评论 [页码]\n查看评论 123\n通过评论 123\n驳回评论 123\n垃圾评论 123\n删除评论 123\n恢复评论 123\n回复评论 123 回复内容\n\n123 为评论编号；删除仅移入回收站。";
}

function wpcpush_management_authorized(string $channel, array $incoming, array $config): bool
{
    if (!in_array($channel, ['qq', 'weixin'], true) || !$config[$channel . '_manage_enabled']) { return false; }
    $manager = (int)$config['manager_user_id'];
    if ($manager <= 0 || !user_can($manager, 'manage_options') || !user_can($manager, 'moderate_comments')) { return false; }
    foreach (['id', 'sender_id', 'target_id', 'target_type', 'text'] as $key) {
        if (!isset($incoming[$key]) || !is_string($incoming[$key])) { return false; }
    }
    if (!preg_match('/\A[\x21-\x7e]{1,200}\z/', $incoming['id']) || !is_int($incoming['created_at'] ?? null)) { return false; }
    $now = time();
    $since = $config[$channel . '_manage_since'];
    if ($since <= 0 || $incoming['created_at'] < $since || $incoming['created_at'] < $now - 300 || $incoming['created_at'] > $now + 30) { return false; }
    if ($channel === 'qq') {
        if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === ''
            || $incoming['target_id'] !== $config['qq_target_id'] || $incoming['target_type'] !== $config['qq_target_type']) { return false; }
        if ($incoming['target_type'] === 'c2c') { return $incoming['sender_id'] === $config['qq_target_id']; }
        if ($incoming['target_type'] !== 'group') { return false; }
        $binding = wpcpush_read('qq_manager');
        return ($binding['identity'] ?? '') === wpcpush_qq_identity($config)
            && ($binding['target_type'] ?? '') === 'group' && ($binding['target_id'] ?? '') === $incoming['target_id']
            && !empty($binding['actor_id']) && $binding['actor_id'] === $incoming['sender_id'];
    }
    $account = wpcpush_read('weixin_account');
    return $incoming['target_type'] === 'private' && $config['weixin_user_id'] !== ''
        && $incoming['sender_id'] === $config['weixin_user_id'] && $incoming['target_id'] === $config['weixin_user_id']
        && !empty($account['token']) && empty($account['expired'])
        && (empty($account['owner_id']) || $account['owner_id'] === $incoming['sender_id']);
}

function wpcpush_management_claim(string $channel, array $incoming, array $config): bool
{
    global $wpdb;
    $identity = $channel === 'qq' ? wpcpush_qq_identity($config) : hash('sha256', (string)(wpcpush_read('weixin_account')['token'] ?? ''));
    $key = 'wpcpush_command_' . hash('sha256', wp_json_encode([$channel, $identity, $incoming['target_type'], $incoming['target_id'], $incoming['sender_id'], $incoming['id']]));
    // Insert the claim before any comment mutation; a failed receipt must never repeat an operation.
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
        $key, (string)(time() + 600), 'no'
    ));
    if ($inserted === false) { throw new RuntimeException('无法保存机器人指令状态。'); }
    if ((int)$inserted !== 1) { return false; }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",
        $wpdb->esc_like('wpcpush_command_') . '%'
    ));
    foreach ($rows as $row) {
        if (ctype_digit((string)$row->option_value) && (int)$row->option_value <= time()) { delete_option($row->option_name); }
    }
    return true;
}

function wpcpush_management_result(string $channel, bool $ok, string $message): void
{
    try { wpcpush_write('management_result', ['channel' => $channel, 'ok' => $ok, 'message' => $message, 'time' => time()]); }
    catch (Throwable) { error_log('Comment push: unable to save management status.'); }
}

function wpcpush_management_receive(string $channel, array $incoming): ?string
{
    // The CLI receiver can outlive a browser settings change or account revocation.
    foreach (['config', 'qq_manager', 'weixin_account'] as $key) { wp_cache_delete('wpcpush_' . $key, 'options'); }
    wp_cache_delete('notoptions', 'options');
    $config = wpcpush_config();
    if ($config['manager_user_id'] > 0) { clean_user_cache($config['manager_user_id']); }
    if (!wpcpush_management_authorized($channel, $incoming, $config)) { return null; }
    $command = wpcpush_management_parse($incoming['text']);
    if ($command === null) { return null; }
    $previousUser = get_current_user_id();
    try {
        if (!wpcpush_management_claim($channel, $incoming, $config)) { return null; }
        wp_set_current_user((int)$config['manager_user_id']);
        $reply = wpcpush_management_execute($command, (int)$config['manager_user_id']);
        wpcpush_management_result($channel, true, '机器人评论指令已处理。');
        return $reply;
    } catch (Throwable) {
        wpcpush_management_result($channel, false, '机器人评论操作未完成，请在 WordPress 后台核实。');
        return '操作未完成，请到 WordPress 后台确认评论状态后，再发送新的指令。';
    } finally {
        wp_set_current_user($previousUser);
    }
}

function wpcpush_management_execute(array $command, int $manager): string
{
    if ($command['action'] === 'help') { return wpcpush_management_help(); }
    if ($command['action'] === 'list') {
        $page = $command['page'];
        $comments = get_comments(['status' => 'hold', 'type__in' => ['', 'comment'], 'number' => 5, 'offset' => ($page - 1) * 5, 'orderby' => 'comment_ID', 'order' => 'DESC']);
        $lines = ['待审评论 · 第 ' . $page . ' 页'];
        foreach ($comments as $comment) {
            if (!user_can($manager, 'edit_comment', (int)$comment->comment_ID)) { continue; }
            $lines[] = '#' . $comment->comment_ID . ' · ' . wpcpush_text($comment->comment_author, 30)
                . "\n" . wpcpush_text($comment->comment_content, 100);
        }
        if (count($lines) === 1) { return '本页没有可管理的待审评论。'; }
        $lines[] = '发送“查看评论 编号”查看详情；“待审评论 ' . ($page + 1) . '”查看下一页。';
        return implode("\n\n", $lines);
    }
    $comment = get_comment($command['comment_id']);
    if (!$comment instanceof WP_Comment || !in_array($comment->comment_type, ['', 'comment'], true)
        || !get_post((int)$comment->comment_post_ID) || !user_can($manager, 'edit_comment', $command['comment_id'])) {
        return '评论不存在或当前授权账号没有管理权限。';
    }
    $id = (int)$comment->comment_ID;
    $label = '评论 #' . $id;
    if ($command['action'] === 'view') {
        $states = ['0' => '待审核', '1' => '已发布', 'spam' => '垃圾评论', 'trash' => '回收站', 'post-trashed' => '文章已删除'];
        return $label . ' · ' . ($states[(string)$comment->comment_approved] ?? '其他状态')
            . "\n文章：" . wpcpush_text((string)get_the_title((int)$comment->comment_post_ID), 100)
            . "\n评论人：" . wpcpush_text($comment->comment_author, 50)
            . "\n\n" . wpcpush_text($comment->comment_content, 400);
    }
    if ($command['action'] === 'reply') {
        if ((string)$comment->comment_approved !== '1') { return '请先通过评论，再发送回复。'; }
        $body = sanitize_textarea_field($command['body']);
        if ($body === '') { return '回复内容不能为空。'; }
        $user = get_userdata($manager);
        $replyId = wp_insert_comment(wp_slash([
            'comment_post_ID' => (int)$comment->comment_post_ID,
            'comment_parent' => $id,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_content' => $body,
            'comment_approved' => 1,
            'comment_type' => 'comment',
            'user_id' => $manager,
        ]));
        if (!$replyId) { throw new RuntimeException('无法保存回复。'); }
        return '已回复' . $label . '，回复编号 #' . $replyId . '。';
    }
    if ($command['action'] === 'trash') {
        if ((string)$comment->comment_approved === 'trash') { return $label . '已在回收站。'; }
        // Never fall through to permanent deletion when WordPress trash is disabled.
        if (defined('EMPTY_TRASH_DAYS') && (int)EMPTY_TRASH_DAYS === 0) { return '此站点已禁用回收站，请到后台处理删除操作。'; }
        if (!wp_trash_comment($id)) { throw new RuntimeException('无法移入回收站。'); }
        return '已将' . $label . '移入回收站，可发送“恢复评论 ' . $id . '”撤销。';
    }
    if ($command['action'] === 'restore') {
        if ((string)$comment->comment_approved !== 'trash') { return $label . '不在回收站。'; }
        if (!wp_untrash_comment($id)) { throw new RuntimeException('无法恢复评论。'); }
        return '已从回收站恢复' . $label . '。';
    }
    if ((string)$comment->comment_approved === 'trash') { return '请先恢复回收站中的评论。'; }
    if ($command['action'] === 'spam') {
        if ((string)$comment->comment_approved !== 'spam' && !wp_spam_comment($id)) { throw new RuntimeException('无法标记垃圾评论。'); }
        return $label . '已标记为垃圾评论。';
    }
    if ((string)$comment->comment_approved === 'spam' && !wp_unspam_comment($id)) { throw new RuntimeException('无法恢复垃圾评论。'); }
    if (!wp_set_comment_status($id, $command['action'])) { throw new RuntimeException('无法更新评论状态。'); }
    return $label . match ($command['action']) { 'approve' => '已通过审核。', 'hold' => '已转为待审核。' };
}

function wpcpush_management_lock(string $scope, int $ttl = 120): array
{
    global $wpdb;
    $key = 'wpcpush_' . $scope . '_lock';
    $lock = ['owner' => bin2hex(random_bytes(16)), 'expires_at' => time() + $ttl];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, maybe_serialize($lock), 'no'));
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($inserted === false) { throw new RuntimeException('无法创建机器人接收锁。'); }
        if ((int)$inserted === 1) { return $lock; }
        $current = get_option($key, []);
        if ($attempt !== 0 || !is_array($current) || (int)($current['expires_at'] ?? 0) > time()) { break; }
        wpcpush_management_unlock($scope, $current);
    }
    throw new RuntimeException('机器人消息正在处理中，请稍后重试。');
}

function wpcpush_management_unlock(string $scope, array $lock): void
{
    global $wpdb;
    $key = 'wpcpush_' . $scope . '_lock';
    $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => maybe_serialize($lock)]);
    wp_cache_delete($key, 'options');
    wp_cache_delete('notoptions', 'options');
}

function wpcpush_management_schedule(): void
{
    $config = wpcpush_config();
    if (!$config['weixin_manage_enabled'] || (defined('WPCPUSH_RECEIVER_MODE') && WPCPUSH_RECEIVER_MODE === 'worker')) {
        wp_clear_scheduled_hook('wpcpush_weixin_poll');
    } elseif (!wp_next_scheduled('wpcpush_weixin_poll')) {
        wp_schedule_event(time() + 60, 'wpcpush_minute', 'wpcpush_weixin_poll');
    }
}

function wpcpush_management_deactivate(): void
{
    wp_clear_scheduled_hook('wpcpush_weixin_poll');
}

function wpcpush_management_poll(string $mode = 'cron', ?callable $transport = null): void
{
    $previous = wpcpush_read('receiver_status');
    if ($mode === 'cron' && ($previous['mode'] ?? '') === 'worker' && (int)($previous['heartbeat'] ?? 0) > time() - 45) { return; }
    $status = array_merge(['last_poll' => 0], $previous, ['heartbeat' => time(), 'mode' => $mode, 'ok' => true, 'message' => '接收进程运行中。']);
    try {
        if (wpcpush_config()['weixin_manage_enabled']) {
            wpcpush_weixin_sync($transport);
            $status['last_poll'] = time();
            $status['message'] = '微信评论指令接收正常。';
        } else {
            $status['message'] = '接收进程就绪，微信评论管理尚未启用。';
        }
    } catch (Throwable) {
        $status['ok'] = false;
        $status['message'] = '微信接收未完成，请检查登录状态、接收人和服务器网络。';
    }
    $status['heartbeat'] = time();
    wpcpush_write('receiver_status', $status);
}

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['wpcpush_minute'] = ['interval' => 60, 'display' => '评论机器人每分钟接收'];
    return $schedules;
});
add_action('wpcpush_weixin_poll', 'wpcpush_management_poll');
