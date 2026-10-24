<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_qq_token(array $config, callable $transport, bool $refresh = false): string
{
    $identity = hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']);
    $cached = wpcpush_read('qq_token');
    if (!$refresh && ($cached['identity'] ?? '') === $identity && (int)($cached['expires_at'] ?? 0) > time() + 60 && is_string($cached['token'] ?? null)) {
        return $cached['token'];
    }
    $response = $transport('POST', 'https://api.bot.qq.com/app/getAppAccessToken', [], ['appId' => $config['qq_app_id'], 'clientSecret' => $config['qq_app_secret']]);
    $data = $response['data'];
    if ($response['status'] !== 200 || !is_string($data['access_token'] ?? null) || $data['access_token'] === '' || (int)($data['expires_in'] ?? 0) <= 0 || !in_array(wpcpush_api_code($data), ['', '0'], true)) {
        throw wpcpush_api_error('QQ', $response);
    }
    $token = $data['access_token'];
    if (preg_match('/[\x00-\x20\x7f]/', $token)) { throw new RuntimeException('QQ 返回的访问凭证格式无效。'); }
    wpcpush_write('qq_token', ['identity' => $identity, 'token' => $token, 'expires_at' => time() + min(86400, (int)$data['expires_in'])]);
    return $token;
}

function wpcpush_qq_send(array $config, string $text, ?callable $transport = null): void
{
    wpcpush_qq_post_message($config, ['content' => $text], $transport);
}

function wpcpush_qq_reply(array $config, array $incoming, string $text, ?callable $transport = null): void
{
    $type = $incoming['target_type'] ?? '';
    $target = $incoming['target_id'] ?? '';
    $messageId = $incoming['reply_msg_id'] ?? $incoming['id'] ?? '';
    if (!in_array($type, ['c2c', 'group'], true) || $type !== $config['qq_target_type']
        || !is_string($target) || !hash_equals($config['qq_target_id'], $target)
        || !is_string($messageId) || $messageId === '' || strlen($messageId) > 1024
        || preg_match('/[\x00-\x20\x7f]/', $messageId)) {
        throw new RuntimeException('QQ 命令回执缺少有效的会话或消息 ID。');
    }
    wpcpush_qq_post_message($config, ['content' => $text, 'msg_id' => $messageId, 'msg_seq' => 1], $transport);
}

function wpcpush_qq_post_message(array $config, array $payload, ?callable $transport = null): void
{
    $transport ??= 'wpcpush_http';
    if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $config['qq_target_id'])) {
        throw new RuntimeException('QQ 凭证或接收 ID 尚未配置完整。');
    }
    $path = match ($config['qq_target_type']) {
        'c2c' => '/v2/users/', 'group' => '/v2/groups/', 'channel' => '/channels/',
        default => throw new RuntimeException('QQ 接收类型无效。'),
    };
    if ($config['qq_target_type'] !== 'channel') { $payload['msg_type'] = 0; }
    $url = 'https://api.bot.qq.com' . $path . rawurlencode($config['qq_target_id']) . '/messages';
    $token = wpcpush_qq_token($config, $transport);
    $response = $transport('POST', $url, ['Authorization: QQBot ' . $token], $payload);
    if ($response['status'] === 401) {
        wpcpush_write('qq_token', []);
        $token = wpcpush_qq_token($config, $transport, true);
        $response = $transport('POST', $url, ['Authorization: QQBot ' . $token], $payload);
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !in_array(wpcpush_api_code($response['data']), ['', '0'], true)
        || !is_string($response['data']['id'] ?? null) || $response['data']['id'] === '') {
        throw wpcpush_api_error('QQ', $response);
    }
}
