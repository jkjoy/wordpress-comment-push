<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_qq_webhook_url(): string
{
    return rest_url('comment-push/v1/qq/webhook');
}

function wpcpush_qq_identity(array $config): string
{
    return hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']);
}

function wpcpush_qq_keypair(string $secret): string
{
    if (!function_exists('sodium_crypto_sign_seed_keypair')) { throw new RuntimeException('QQ Webhook 需要启用 PHP sodium 扩展。'); }
    if ($secret === '') { throw new RuntimeException('请先保存 QQ AppID 和 AppSecret。'); }
    $seed = $secret;
    while (strlen($seed) < 32) { $seed .= $secret; }
    return sodium_crypto_sign_seed_keypair(substr($seed, 0, 32));
}

function wpcpush_qq_webhook_verify(string $raw, string $timestamp, string $signature, string $secret): bool
{
    if ($secret === '' || !function_exists('sodium_crypto_sign_verify_detached')
        || !preg_match('/\A[0-9]{1,20}\z/', $timestamp) || !preg_match('/\A[a-fA-F0-9]{128}\z/', $signature)) { return false; }
    $keypair = wpcpush_qq_keypair($secret);
    return sodium_crypto_sign_verify_detached((string)hex2bin($signature), $timestamp . $raw, sodium_crypto_sign_publickey($keypair));
}

function wpcpush_qq_webhook_status(): array
{
    $config = wpcpush_config();
    $identity = wpcpush_qq_identity($config);
    $state = wpcpush_read('qq_webhook');
    $binding = wpcpush_read('qq_binding');
    if (($state['identity'] ?? '') !== $identity) { $state = []; }
    if (($binding['identity'] ?? '') !== $identity || (int)($binding['expires_at'] ?? 0) <= time()
        || !is_string($binding['code'] ?? null) || !preg_match('/\A[A-F0-9]{16}\z/', $binding['code'])
        || !in_array($binding['target_type'] ?? '', ['c2c', 'group'], true)) { $binding = []; }
    return [
        'available' => function_exists('sodium_crypto_sign_verify_detached'),
        'configured' => $config['qq_app_id'] !== '' && $config['qq_app_secret'] !== '',
        'url' => wpcpush_qq_webhook_url(),
        'last_challenge_at' => (int)($state['last_challenge_at'] ?? 0),
        'last_event_at' => (int)($state['last_event_at'] ?? 0),
        'binding' => $binding === [] ? [] : array_intersect_key($binding, array_flip(['code', 'expires_at', 'target_type'])),
    ];
}

function wpcpush_qq_binding_start(): array
{
    $config = wpcpush_config();
    wpcpush_qq_keypair($config['qq_app_secret']);
    if ($config['qq_app_id'] === '') { throw new RuntimeException('请先保存 QQ AppID 和 AppSecret。'); }
    if (!in_array($config['qq_target_type'], ['c2c', 'group'], true)) { throw new RuntimeException('自动绑定支持 QQ 私聊和群聊；频道请手动填写子频道 ID。'); }
    $binding = ['code' => strtoupper(bin2hex(random_bytes(8))), 'expires_at' => time() + 600,
        'target_type' => $config['qq_target_type'], 'identity' => wpcpush_qq_identity($config)];
    wpcpush_write('qq_binding', $binding);
    return array_intersect_key($binding, array_flip(['code', 'expires_at', 'target_type']));
}

function wpcpush_qq_binding_cancel(): void
{
    wpcpush_write('qq_binding', []);
}

function wpcpush_qq_bind_event(array $event, int $now): void
{
    $type = match ($event['t'] ?? null) { 'C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group', default => '' };
    $data = $event['d'] ?? null;
    if ($type === '' || !is_array($data) || !is_string($data['content'] ?? null)) { return; }
    $target = $type === 'group' ? ($data['group_openid'] ?? null) : ($data['author']['user_openid'] ?? null);
    if (!is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $target)) { return; }
    $content = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content']);
    if (!is_string($content) || !preg_match('/\A绑定通知\s+([A-Fa-f0-9]{16})\s*\z/u', $content, $matches)) { return; }
    $code = strtoupper($matches[1]);
    $pending = wpcpush_read('qq_binding');
    if (!is_string($pending['code'] ?? null) || !hash_equals($pending['code'], $code)) { return; }

    // Only one signed event may consume a pending binding at a time.
    $lock = wpcpush_qq_binding_lock();
    try {
        wp_cache_delete('wpcpush_config', 'options');
        wp_cache_delete('wpcpush_qq_binding', 'options');
        $config = wpcpush_config();
        $pending = wpcpush_read('qq_binding');
        if (is_string($pending['code'] ?? null) && hash_equals($pending['code'], $code)
            && (int)($pending['expires_at'] ?? 0) > $now && ($pending['target_type'] ?? '') === $type
            && ($pending['identity'] ?? '') === wpcpush_qq_identity($config) && $config['qq_target_type'] === $type) {
            $config['qq_target_id'] = $target;
            $config['qq_manage_enabled'] = false;
            $config['qq_manage_since'] = 0;
            wpcpush_save_config($config);
            $actor = $type === 'group' ? ($data['author']['member_openid'] ?? '') : $target;
            wpcpush_write('qq_manager', is_string($actor) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $actor)
                ? ['identity' => wpcpush_qq_identity($config), 'target_type' => $type, 'target_id' => $target, 'actor_id' => $actor]
                : []);
            wpcpush_write('qq_binding', []);
        }
    } finally {
        wpcpush_qq_binding_unlock($lock);
    }
}

function wpcpush_qq_binding_lock(): array
{
    global $wpdb;
    $key = 'wpcpush_qq_binding_lock';
    $lock = ['owner' => bin2hex(random_bytes(16)), 'expires_at' => time() + 30];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        // add_option() can upsert a competing value; a unique-key insert must never replace an owner.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key,
            maybe_serialize($lock),
            'no'
        ));
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($inserted === false) { throw new RuntimeException('无法创建 QQ 绑定锁，请检查数据库连接。'); }
        if ((int)$inserted === 1) { return $lock; }
        if ($attempt !== 0) { break; }
        $current = get_option($key, []);
        if (!is_array($current) || (int)($current['expires_at'] ?? 0) > time()) { break; }
        wpcpush_qq_binding_unlock($current);
    }
    throw new RuntimeException('QQ 绑定正在处理中，请稍后刷新状态。');
}

function wpcpush_qq_binding_unlock(array $lock): void
{
    global $wpdb;
    $key = 'wpcpush_qq_binding_lock';
    // Compare the complete value so an expired owner never removes a newer lock.
    $deleted = $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => maybe_serialize($lock)]);
    if ($deleted === false) { throw new RuntimeException('QQ 绑定锁释放失败，请稍后重试。'); }
    wp_cache_delete($key, 'options');
    wp_cache_delete('notoptions', 'options');
}

function wpcpush_qq_manage_event(array $event, string $signatureTimestamp, ?callable $transport = null): void
{
    if (!preg_match('/\A[0-9]{1,20}\z/', $signatureTimestamp) || abs(time() - (int)$signatureTimestamp) > 300) { return; }
    $type = match ($event['t'] ?? null) { 'C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group', default => '' };
    $data = $event['d'] ?? null;
    if ($type === '' || !is_array($data) || !is_string($data['content'] ?? null) || !empty($data['author']['bot'])) { return; }
    $messageId = $data['id'] ?? null;
    $sender = $type === 'group' ? ($data['author']['member_openid'] ?? null) : ($data['author']['user_openid'] ?? null);
    $target = $type === 'group' ? ($data['group_openid'] ?? null) : $sender;
    $timestamp = $data['timestamp'] ?? null;
    if (!is_string($messageId) || $messageId === '' || strlen($messageId) > 1024 || preg_match('/[\x00-\x20\x7f]/', $messageId)
        || !is_string($sender) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sender)
        || !is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $target)
        || !is_string($timestamp) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $timestamp)) { return; }
    try {
        $created = new DateTimeImmutable($timestamp);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) { return; }
    } catch (Exception) { return; }
    $text = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content']);
    if (!is_string($text)) { return; }
    $incoming = ['id' => $messageId, 'reply_msg_id' => $messageId, 'sender_id' => $sender,
        'target_id' => $target, 'target_type' => $type, 'text' => $text, 'created_at' => $created->getTimestamp()];
    try {
        $reply = wpcpush_management_receive('qq', $incoming);
        if ($reply === null) { return; }
        wpcpush_qq_reply(wpcpush_config(), $incoming, $reply, $transport);
        $result = ['ok' => true, 'channel' => 'qq', 'time' => time(), 'message' => 'QQ 已接受管理命令回执。'];
    } catch (Throwable) {
        $result = ['ok' => false, 'channel' => 'qq', 'time' => time(), 'message' => 'QQ 管理命令或回执未完成，请在后台检查评论状态和连接。'];
    }
    try { wpcpush_write('management_result', $result); }
    catch (Throwable) { error_log('Comment push: unable to save QQ management result.'); }
}

function wpcpush_qq_webhook_handle(string $method, string $raw, array $headers, ?int $now = null, ?callable $transport = null): array
{
    $now ??= time();
    $failure = static fn(int $status): array => ['status' => $status, 'body' => ['op' => 12, 'd' => 1]];
    if ($method !== 'POST') { return $failure(405); }
    if (strlen($raw) > 65536) { return $failure(413); }
    $config = wpcpush_config();
    if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || !function_exists('sodium_crypto_sign_verify_detached')) { return $failure(503); }
    $appId = $headers['x-bot-appid'] ?? null;
    $timestamp = $headers['x-signature-timestamp'] ?? null;
    $signature = $headers['x-signature-ed25519'] ?? null;
    if (!is_string($appId) || !hash_equals($config['qq_app_id'], $appId)
        || !is_string($timestamp) || !is_string($signature)
        || !wpcpush_qq_webhook_verify($raw, $timestamp, $signature, $config['qq_app_secret'])) { return $failure(403); }
    try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return $failure(400); }
    if (!is_array($event) || !is_int($event['op'] ?? null) || !is_array($event['d'] ?? null)) { return $failure(400); }
    $state = wpcpush_read('qq_webhook');
    $identity = wpcpush_qq_identity($config);
    if (($state['identity'] ?? '') !== $identity) { $state = ['identity' => $identity]; }
    if ($event['op'] === 13) {
        $token = $event['d']['plain_token'] ?? null;
        $eventTs = $event['d']['event_ts'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096 || !is_string($eventTs)
            || !preg_match('/\A[0-9]{1,20}\z/', $eventTs)) { return $failure(400); }
        $key = sodium_crypto_sign_secretkey(wpcpush_qq_keypair($config['qq_app_secret']));
        $signature = bin2hex(sodium_crypto_sign_detached($eventTs . $token, $key));
        $state['last_challenge_at'] = $now;
        wpcpush_write('qq_webhook', $state);
        return ['status' => 200, 'body' => ['plain_token' => $token, 'signature' => $signature]];
    }
    if ($event['op'] !== 0 || !is_string($event['t'] ?? null)) { return $failure(400); }
    wpcpush_qq_bind_event($event, $now);
    wpcpush_qq_manage_event($event, $timestamp, $transport);
    $state['last_event_at'] = $now;
    wpcpush_write('qq_webhook', $state);
    return ['status' => 200, 'body' => ['op' => 12, 'd' => 0]];
}

function wpcpush_qq_webhook_request(WP_REST_Request $request): WP_REST_Response
{
    try {
        $response = wpcpush_qq_webhook_handle($request->get_method(), $request->get_body(), [
            'x-bot-appid' => $request->get_header('x-bot-appid'),
            'x-signature-timestamp' => $request->get_header('x-signature-timestamp'),
            'x-signature-ed25519' => $request->get_header('x-signature-ed25519'),
        ]);
    } catch (Throwable) {
        error_log('Comment push: QQ webhook processing failed.');
        $response = ['status' => 500, 'body' => ['op' => 12, 'd' => 1]];
    }
    return new WP_REST_Response($response['body'], $response['status'], ['Cache-Control' => 'no-store']);
}

add_action('rest_api_init', static function (): void {
    register_rest_route('comment-push/v1', '/qq/webhook', [
        'methods' => 'POST',
        'callback' => 'wpcpush_qq_webhook_request',
        'permission_callback' => '__return_true',
    ]);
});
