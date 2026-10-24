<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

// Ported from Tencent/openclaw-weixin 2.4.10 (MIT); see THIRD_PARTY_NOTICES.md.
const WPCPUSH_WEIXIN_VERSION = '2.4.10';
const WPCPUSH_WEIXIN_BASE = 'https://ilinkai.weixin.qq.com';

function wpcpush_weixin_user_id(string $value): bool
{
    return (bool)preg_match('/\A[A-Za-z0-9_@.:-]{1,256}\z/', $value);
}

function wpcpush_weixin_base(string $url): string
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host)
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new RuntimeException('微信返回的接口地址不在受信任的官方域名范围内。');
    }
    return 'https://' . $host;
}

function wpcpush_weixin_qr_url(string $url): string
{
    if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) { return ''; }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) { return ''; }
    $host = strtolower((string)($parts['host'] ?? ''));
    return preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host) ? $url : '';
}

function wpcpush_weixin_headers(?string $token = null, bool $json = true): array
{
    $headers = ['iLink-App-Id: bot', 'iLink-App-ClientVersion: 132106'];
    if ($json) {
        $number = unpack('Nvalue', random_bytes(4));
        $headers[] = 'AuthorizationType: ilink_bot_token';
        $headers[] = 'X-WECHAT-UIN: ' . base64_encode((string)$number['value']);
    }
    if ($token !== null) {
        if (!preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)) { throw new RuntimeException('微信登录凭证格式无效，请重新扫码。'); }
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return $headers;
}

function wpcpush_weixin_base_info(): array
{
    return ['channel_version' => WPCPUSH_WEIXIN_VERSION, 'bot_agent' => 'WordPressCommentPush/1.0.0'];
}

function wpcpush_weixin_failed(array $response): bool
{
    if ((int)$response['status'] < 200 || (int)$response['status'] >= 300) { return true; }
    foreach (['ret', 'errcode'] as $key) {
        if (array_key_exists($key, $response['data']) && (!is_scalar($response['data'][$key]) || (string)$response['data'][$key] !== '0')) { return true; }
    }
    return false;
}

function wpcpush_weixin_error(array $response): RuntimeException
{
    $code = wpcpush_api_code($response['data']);
    foreach (['ret', 'errcode'] as $key) {
        if (isset($response['data'][$key]) && is_scalar($response['data'][$key]) && preg_match('/\A-?[0-9]{1,12}\z/', (string)$response['data'][$key]) && (string)$response['data'][$key] !== '0') {
            $code = (string)$response['data'][$key];
            break;
        }
    }
    if (in_array($response['data']['ret'] ?? null, [-14, '-14'], true) || in_array($response['data']['errcode'] ?? null, [-14, '-14'], true) || (int)$response['status'] === 401) {
        $account = wpcpush_read('weixin_account');
        if ($account !== []) { $account['expired'] = true; wpcpush_write('weixin_account', $account); }
        return new RuntimeException('微信登录凭证已失效，请重新扫码并同步接收人（错误码 -14 或 HTTP 401）。');
    }
    $message = '微信接口失败（HTTP ' . (int)$response['status'] . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
    $message .= $code === '-2'
        ? '请先向微信机器人发送一条消息，再在此同步接收人；该通道的主动发送受微信服务端限制。'
        : '请检查微信连接状态，稍后再试。';
    return new RuntimeException($message);
}

function wpcpush_weixin_status(): array
{
    $account = wpcpush_read('weixin_account');
    $login = wpcpush_read('weixin_login');
    $sync = wpcpush_read('weixin_sync');
    $userId = (string)(wpcpush_config()['weixin_user_id'] ?? '');
    $pending = isset($login['qrcode']) && (int)($login['started_at'] ?? 0) > time() - 300;
    $candidates = [];
    foreach (array_slice((array)($sync['candidates'] ?? []), 0, 20) as $candidate) {
        if (is_string($candidate) && wpcpush_weixin_user_id($candidate)) { $candidates[] = ['user_id' => $candidate]; }
    }
    return [
        'logged_in' => !empty($account['token']), 'expired' => !empty($account['expired']),
        'account_id' => (string)($account['account_id'] ?? ''), 'user_id' => $userId,
        'has_context' => $userId !== '' && !empty($sync['context_tokens'][$userId]),
        'login_pending' => $pending, 'login_status' => $pending ? (string)($login['status'] ?? 'wait') : '',
        'need_verifycode' => $pending && ($login['status'] ?? '') === 'need_verifycode',
        'qrcode_url' => $pending ? wpcpush_weixin_qr_url((string)($login['qrcode_url'] ?? '')) : '',
        'candidates' => $candidates,
    ];
}

function wpcpush_weixin_start(?callable $transport = null): array
{
    $transport ??= 'wpcpush_http';
    $account = wpcpush_read('weixin_account');
    $tokens = [];
    if (is_string($account['token'] ?? null) && $account['token'] !== '') { $tokens[] = $account['token']; }
    $response = $transport('POST', WPCPUSH_WEIXIN_BASE . '/ilink/bot/get_bot_qrcode?bot_type=3', wpcpush_weixin_headers(), ['local_token_list' => $tokens], 12);
    if (wpcpush_weixin_failed($response)) { throw wpcpush_weixin_error($response); }
    $data = $response['data'];
    $qrcode = $data['qrcode'] ?? null;
    $url = is_string($data['qrcode_img_content'] ?? null) ? wpcpush_weixin_qr_url($data['qrcode_img_content']) : '';
    if (!is_string($qrcode) || $qrcode === '' || strlen($qrcode) > 8192 || $url === '') { throw new RuntimeException('微信未返回有效的官方扫码链接。'); }
    wpcpush_write('weixin_login', ['qrcode' => $qrcode, 'qrcode_url' => $url, 'base_url' => WPCPUSH_WEIXIN_BASE, 'started_at' => time(), 'status' => 'wait']);
    return ['status' => 'wait', 'qrcode_url' => $url, 'message' => '请打开官方扫码链接，使用微信扫码并确认连接。'];
}

function wpcpush_weixin_check(string $verifyCode = '', ?callable $transport = null): array
{
    $transport ??= 'wpcpush_http';
    $login = wpcpush_read('weixin_login');
    if (empty($login['qrcode']) || (int)($login['started_at'] ?? 0) <= time() - 300) {
        wpcpush_write('weixin_login', []);
        return ['status' => 'expired', 'message' => '扫码链接已过期，请重新生成。'];
    }
    if ($verifyCode !== '' && !preg_match('/\A[0-9]{1,12}\z/', $verifyCode)) { throw new RuntimeException('请输入手机微信显示的数字验证码。'); }
    $base = wpcpush_weixin_base((string)($login['base_url'] ?? WPCPUSH_WEIXIN_BASE));
    $path = '/ilink/bot/get_qrcode_status?qrcode=' . rawurlencode((string)$login['qrcode']);
    if ($verifyCode !== '') { $path .= '&verify_code=' . rawurlencode($verifyCode); }
    try {
        $response = $transport('GET', $base . $path, wpcpush_weixin_headers(null, false), null, 12);
    } catch (RuntimeException $exception) {
        if ($exception->getCode() !== 28) { throw $exception; }
        return ['status' => 'wait', 'message' => '微信尚未更新连接状态，请稍后再次检查。'];
    }
    if (wpcpush_weixin_failed($response)) { throw wpcpush_weixin_error($response); }
    $data = $response['data'];
    $status = is_string($data['status'] ?? null) ? $data['status'] : '';
    $messages = [
        'wait' => '等待微信扫码。', 'scaned' => '已扫码，请在手机微信中完成确认。',
        'need_verifycode' => '请输入手机微信显示的数字验证码，然后检查连接状态。',
        'verify_code_blocked' => '验证码多次错误，请稍后重新生成扫码链接。',
        'expired' => '扫码链接已过期，请重新生成。',
        'binded_redirect' => '微信提示已绑定此连接；如本机没有有效凭证，请在微信中解除旧连接后重新扫码。',
        'scaned_but_redirect' => '已扫码，微信正在切换服务区域，请再次检查状态。',
        'confirmed' => '微信已连接。请向微信机器人发送“绑定通知”，然后点击同步接收人。',
    ];
    if (!isset($messages[$status])) { throw new RuntimeException('微信返回了无法识别的登录状态，请稍后重试。'); }
    $login['status'] = $status;
    if ($status === 'scaned_but_redirect') {
        if (!is_string($data['redirect_host'] ?? null)) { throw new RuntimeException('微信未返回有效的区域地址。'); }
        $login['base_url'] = wpcpush_weixin_base('https://' . $data['redirect_host']);
    }
    if ($status === 'confirmed') {
        $token = $data['bot_token'] ?? null;
        $accountId = $data['ilink_bot_id'] ?? null;
        $owner = $data['ilink_user_id'] ?? '';
        if (!is_string($token) || !preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)
            || !is_string($accountId) || !wpcpush_weixin_user_id($accountId)
            || !is_string($owner) || ($owner !== '' && !wpcpush_weixin_user_id($owner))) { throw new RuntimeException('微信返回的登录凭证不完整，请重新扫码。'); }
        $accountBase = wpcpush_weixin_base(is_string($data['baseurl'] ?? null) && $data['baseurl'] !== '' ? $data['baseurl'] : $base);
        wpcpush_write('weixin_account', ['token' => $token, 'account_id' => $accountId, 'owner_id' => $owner, 'base_url' => $accountBase, 'connected_at' => time(), 'expired' => false]);
        wpcpush_write('weixin_sync', []);
        wpcpush_write('weixin_login', []);
        $config = wpcpush_config();
        $config['weixin_enabled'] = false;
        $config['weixin_user_id'] = '';
        $config['weixin_manage_enabled'] = false;
        $config['weixin_manage_since'] = 0;
        wpcpush_save_config($config);
    } else {
        wpcpush_write('weixin_login', in_array($status, ['expired', 'verify_code_blocked', 'binded_redirect'], true) ? [] : $login);
    }
    return ['status' => $status, 'message' => $messages[$status]];
}

function wpcpush_weixin_account(): array
{
    $account = wpcpush_read('weixin_account');
    if (empty($account['token']) || !is_string($account['token'])) { throw new RuntimeException('请先连接微信。'); }
    if (!empty($account['expired'])) { throw new RuntimeException('微信登录凭证已失效，请重新扫码。'); }
    $account['base_url'] = wpcpush_weixin_base((string)($account['base_url'] ?? ''));
    return $account;
}

function wpcpush_weixin_sync(?callable $transport = null): array
{
    $transport ??= 'wpcpush_http';
    $lock = wpcpush_management_lock('weixin_poll', 120);
    try {
        // Manual recipient sync and scheduled command polling share this single consumer.
        wp_cache_delete('wpcpush_weixin_sync', 'options');
        wp_cache_delete('notoptions', 'options');
        $account = wpcpush_weixin_account();
        $sync = wpcpush_read('weixin_sync');
        try {
            $response = $transport('POST', $account['base_url'] . '/ilink/bot/getupdates', wpcpush_weixin_headers($account['token']), [
                'get_updates_buf' => (string)($sync['get_updates_buf'] ?? ''), 'base_info' => wpcpush_weixin_base_info(),
            ], 12);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 28) { throw $exception; }
            return ['status' => 'wait', 'message' => '尚未收到新消息。请先向微信机器人发送“绑定通知”，再同步一次。', 'candidates' => []];
        }
        if (wpcpush_weixin_failed($response)) { throw wpcpush_weixin_error($response); }
        wp_cache_delete('wpcpush_weixin_account', 'options');
        wp_cache_delete('wpcpush_config', 'options');
        wp_cache_delete('notoptions', 'options');
        $currentAccount = wpcpush_weixin_account();
        if ($currentAccount['account_id'] !== $account['account_id'] || !hash_equals($account['token'], $currentAccount['token'])) {
            throw new RuntimeException('微信连接已更换，请使用当前账号重新同步。');
        }
        $data = $response['data'];
        $messages = is_array($data['msgs'] ?? null) ? array_slice($data['msgs'], 0, 100) : [];
        $context = is_array($sync['context_tokens'] ?? null) ? $sync['context_tokens'] : [];
        $candidates = is_array($sync['candidates'] ?? null) ? $sync['candidates'] : [];
        foreach ($messages as $message) {
            if (!is_array($message) || (int)($message['message_type'] ?? 0) !== 1 || !empty($message['group_id']) || (float)($message['delete_time_ms'] ?? 0) > 0) { continue; }
            $from = $message['from_user_id'] ?? '';
            $token = $message['context_token'] ?? '';
            if (!is_string($from) || !wpcpush_weixin_user_id($from) || $from === $account['account_id']) { continue; }
            // A scanner's account may only bind itself; unrelated inbound users cannot replace the owner.
            if (!empty($account['owner_id']) && $account['owner_id'] !== $from) { continue; }
            if (is_string($token) && $token !== '' && strlen($token) <= 16384) { $context[$from] = $token; }
            if (!in_array($from, $candidates, true)) { $candidates[] = $from; }
        }
        $sync['context_tokens'] = array_slice($context, -20, null, true);
        $sync['candidates'] = array_slice($candidates, -20);
        $sync['synced_at'] = time();
        // Save current contexts before replying, while retaining the last committed cursor.
        wpcpush_write('weixin_sync', $sync);
        $config = wpcpush_config();
        if ($config['weixin_user_id'] !== '') {
            $result = ['status' => 'bound', 'message' => '接收人保持绑定，会话信息已同步。', 'candidates' => []];
        } elseif (!empty($account['owner_id']) && in_array($account['owner_id'], $sync['candidates'], true)) {
            wpcpush_weixin_bind($account['owner_id']);
            $result = ['status' => 'bound', 'message' => '已绑定扫码微信账号为评论通知接收人，现在可启用微信通知。', 'candidates' => []];
        } else {
            $candidatesView = array_map(static fn(string $userId): array => ['user_id' => $userId], $sync['candidates']);
            $result = ['status' => $candidatesView === [] ? 'wait' : 'select', 'message' => $candidatesView === [] ? '尚未收到绑定消息。请先向微信机器人发送“绑定通知”，再同步一次。' : '请选择刚发送绑定消息的接收人。', 'candidates' => $candidatesView];
        }
        foreach ($messages as $message) {
            if (is_array($message)) { wpcpush_weixin_manage_message($message, $transport); }
        }
        // Command claims survive reply failures, so advancing the cursor cannot repeat an action.
        if (is_string($data['get_updates_buf'] ?? null) && $data['get_updates_buf'] !== '') {
            $sync['get_updates_buf'] = $data['get_updates_buf'];
            wpcpush_write('weixin_sync', $sync);
        }
        return $result;
    } finally {
        wpcpush_management_unlock('weixin_poll', $lock);
    }
}

function wpcpush_weixin_manage_message(array $message, ?callable $transport = null): void
{
    if ((int)($message['message_type'] ?? 0) !== 1 || !empty($message['group_id'])
        || (float)($message['delete_time_ms'] ?? 0) > 0) { return; }
    $id = $message['message_id'] ?? null;
    $milliseconds = $message['create_time_ms'] ?? null;
    $sender = $message['from_user_id'] ?? null;
    if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[0-9]{1,24}\z/', (string)$id)
        || (!is_int($milliseconds) && !is_string($milliseconds)) || !preg_match('/\A[0-9]{1,16}\z/', (string)$milliseconds)
        || (int)$milliseconds <= 0 || !is_string($sender) || !wpcpush_weixin_user_id($sender)) { return; }
    $parts = [];
    foreach (is_array($message['item_list'] ?? null) ? $message['item_list'] : [] as $item) {
        if (is_array($item) && ($item['type'] ?? null) === 1 && is_string($item['text_item']['text'] ?? null)) {
            $parts[] = $item['text_item']['text'];
        }
    }
    if ($parts === []) { return; }
    $incoming = ['id' => (string)$id, 'sender_id' => $sender, 'target_id' => $sender,
        'target_type' => 'private', 'text' => implode("\n", $parts), 'created_at' => intdiv((int)$milliseconds, 1000)];
    try {
        $reply = wpcpush_management_receive('weixin', $incoming);
        if ($reply === null) { return; }
        $context = $message['context_token'] ?? '';
        if (!is_string($context) || strlen($context) > 16384) { $context = ''; }
        wpcpush_weixin_send(wpcpush_config(), $reply, $transport, $context);
        $result = ['ok' => true, 'channel' => 'weixin', 'time' => time(), 'message' => '微信已接受管理命令回执。'];
    } catch (Throwable) {
        $result = ['ok' => false, 'channel' => 'weixin', 'time' => time(), 'message' => '微信管理命令或回执未完成，请在后台检查评论状态和连接。'];
    }
    try { wpcpush_write('management_result', $result); }
    catch (Throwable) { error_log('Comment push: unable to save Weixin management result.'); }
}

function wpcpush_weixin_bind(string $userId): void
{
    $account = wpcpush_weixin_account();
    $sync = wpcpush_read('weixin_sync');
    if (!wpcpush_weixin_user_id($userId) || !in_array($userId, (array)($sync['candidates'] ?? []), true)
        || (!empty($account['owner_id']) && $account['owner_id'] !== $userId)) { throw new RuntimeException('只能绑定本次微信连接中已同步的接收人。'); }
    $config = wpcpush_config();
    if ($config['weixin_user_id'] !== '' && $config['weixin_user_id'] !== $userId) { throw new RuntimeException('请先断开微信连接，再更换接收人。'); }
    $config['weixin_user_id'] = $userId;
    wpcpush_save_config($config);
}

function wpcpush_weixin_send(array $config, string $text, ?callable $transport = null, ?string $contextOverride = null): void
{
    $transport ??= 'wpcpush_http';
    $account = wpcpush_weixin_account();
    $to = (string)($config['weixin_user_id'] ?? '');
    $sync = wpcpush_read('weixin_sync');
    if (!wpcpush_weixin_user_id($to) || !in_array($to, (array)($sync['candidates'] ?? []), true)
        || (!empty($account['owner_id']) && $account['owner_id'] !== $to)) { throw new RuntimeException('微信接收人尚未完成绑定，请先发送绑定消息并同步。'); }
    $msg = ['from_user_id' => '', 'to_user_id' => $to,
        'client_id' => 'wpcpush:' . (string)(int)floor(microtime(true) * 1000) . '-' . bin2hex(random_bytes(8)),
        'message_type' => 2, 'message_state' => 2, 'item_list' => [['type' => 1, 'text_item' => ['text' => $text]]],
    ];
    $context = $contextOverride ?? $sync['context_tokens'][$to] ?? '';
    if (is_string($context) && $context !== '') { $msg['context_token'] = $context; }
    // No automatic resend: network timeouts do not reveal whether the server accepted a message.
    $response = $transport('POST', $account['base_url'] . '/ilink/bot/sendmessage', wpcpush_weixin_headers($account['token']), ['msg' => $msg, 'base_info' => wpcpush_weixin_base_info()]);
    if (wpcpush_weixin_failed($response)) { throw wpcpush_weixin_error($response); }
    $data = $response['data'];
    $messageId = $data['message_id'] ?? '';
    if (!array_key_exists('ret', $data) && !array_key_exists('errcode', $data) && (!is_scalar($messageId) || !preg_match('/\A[0-9]{1,24}\z/', (string)$messageId))) {
        throw new RuntimeException('微信接口未返回发送受理状态，请检查连接；为避免重复通知，不会自动重发。');
    }
}

function wpcpush_weixin_disconnect(): void
{
    foreach (['weixin_account', 'weixin_login', 'weixin_sync'] as $key) { wpcpush_write($key, []); }
    $config = wpcpush_config();
    $config['weixin_enabled'] = false;
    $config['weixin_user_id'] = '';
    $config['weixin_manage_enabled'] = false;
    $config['weixin_manage_since'] = 0;
    wpcpush_save_config($config);
}
