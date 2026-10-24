<?php

/**
 * Real WordPress integration checks. Run with `wp eval-file tests/integration.php`.
 * All external HTTP is intercepted before plugin settings or fixtures are changed.
 */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    http_response_code(403);
    exit;
}
if (!function_exists('wpcpush_config')) { WP_CLI::error('Activate comment-push before running these checks.'); }
if (!function_exists('sodium_crypto_sign_detached')) { WP_CLI::error('The sodium PHP extension is required.'); }

global $wpcpush_test_cases, $wpcpush_test_created_posts, $wpcpush_test_created_users, $wpcpush_test_created_comments, $wpcpush_test_http_calls, $wpcpush_test_http_router;
$wpcpush_test_cases = [];
$wpcpush_test_created_posts = [];
$wpcpush_test_created_users = [];
$wpcpush_test_created_comments = [];
$wpcpush_test_http_calls = [];
$wpcpush_test_http_router = null;
$wpcpush_test_original_user = get_current_user_id();
$wpcpush_test_results = [];

// There is deliberately one process-wide blocker, including unexpected requests.
// Individual tests supply fixtures to this callback; no request reaches a socket.
$wpcpush_test_http_guard = static function ($preempt, array $args, string $url) {
    $GLOBALS['wpcpush_test_http_calls'][] = ['url' => $url, 'args' => $args];
    $router = $GLOBALS['wpcpush_test_http_router'];
    if (is_callable($router)) { return $router($args, $url); }
    return new WP_Error('wpcpush_test_http_blocked', 'External HTTP is blocked by the integration harness.');
};
add_filter('pre_http_request', $wpcpush_test_http_guard, PHP_INT_MIN, 3);
$wpcpush_test_mail_guard = static fn() => true;
add_filter('pre_wp_mail', $wpcpush_test_mail_guard, PHP_INT_MIN);
$wpcpush_test_original_options = wpcpush_test_snapshot(true);

function wpcpush_it(string $name, callable $callback): void { $GLOBALS['wpcpush_test_cases'][$name] = $callback; }
function wpcpush_test_check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function wpcpush_test_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function wpcpush_test_raises(callable $callback, string $contains = ''): Throwable
{
    try { $callback(); } catch (Throwable $exception) {
        if ($contains !== '') { wpcpush_test_check(str_contains($exception->getMessage(), $contains), 'Unexpected error: ' . $exception->getMessage()); }
        return $exception;
    }
    throw new RuntimeException('Expected an exception');
}
function wpcpush_test_response(array $data = [], int $status = 200): array { return ['status' => $status, 'data' => $data]; }
function wpcpush_test_transport(array &$calls, array $responses): Closure
{
    return static function (string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6) use (&$calls, &$responses): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
        if ($responses === []) { throw new RuntimeException('Unexpected HTTP request'); }
        $next = array_shift($responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    };
}
function wpcpush_test_snapshot(bool $includeCron = false): array
{
    global $wpdb;
    $cronCondition = $includeCron ? " OR option_name = 'cron'" : '';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s{$cronCondition} ORDER BY option_name",
        $wpdb->esc_like('wpcpush_') . '%'
    ), ARRAY_A);
}
function wpcpush_test_clear_options(bool $includeCron = false): void
{
    global $wpdb;
    foreach (wpcpush_test_snapshot($includeCron) as $row) {
        $wpdb->delete($wpdb->options, ['option_name' => $row['option_name']]);
        wp_cache_delete($row['option_name'], 'options');
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}
function wpcpush_test_fresh(): void
{
    wpcpush_test_clear_options();
    $GLOBALS['wpcpush_test_http_router'] = null;
    $GLOBALS['wpcpush_test_http_calls'] = [];
    wp_set_current_user(0);
}
function wpcpush_test_qq_config(array $changes = []): array
{
    return array_replace(wpcpush_defaults(), ['qq_enabled' => true, 'qq_app_id' => '123456', 'qq_app_secret' => 'test-secret', 'qq_target_id' => 'USER_OPENID'], $changes);
}
function wpcpush_test_cached_qq(array $config, int $expires = 3600, string $token = 'cached-token'): void
{
    wpcpush_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => $token, 'expires_at' => time() + $expires]);
}
function wpcpush_test_weixin_account(string $owner = 'owner@im.wechat'): array
{
    $account = ['token' => 'weixin-test-secret', 'account_id' => 'bot@im.bot', 'owner_id' => $owner, 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false];
    wpcpush_write('weixin_account', $account);
    return $account;
}
function wpcpush_test_weixin_bound(bool $context = true): array
{
    wpcpush_test_weixin_account();
    wpcpush_write('weixin_sync', ['candidates' => ['owner@im.wechat'], 'context_tokens' => $context ? ['owner@im.wechat' => 'session-context'] : []]);
    $config = wpcpush_test_qq_config(['weixin_enabled' => true, 'weixin_user_id' => 'owner@im.wechat']);
    wpcpush_save_config($config);
    return $config;
}
function wpcpush_test_webhook_headers(string $raw, ?array $config = null, string $timestamp = '1725442341'): array
{
    $config ??= wpcpush_config();
    // Sign independently so a matching bug in the plugin signer cannot hide.
    $secret = $config['qq_app_secret'];
    $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
    $pair = sodium_crypto_sign_seed_keypair($seed);
    return [
        'x-bot-appid' => $config['qq_app_id'],
        'x-signature-timestamp' => $timestamp,
        'x-signature-ed25519' => bin2hex(sodium_crypto_sign_detached($timestamp . $raw, sodium_crypto_sign_secretkey($pair))),
    ];
}
function wpcpush_test_webhook_body(string $event, string $content, string $recipient = 'NEW_USER_OPENID'): array
{
    return ['op' => 0, 's' => 1, 'id' => 'event-1', 't' => $event, 'd' => [
        'id' => 'message-1', 'content' => $content,
        'author' => ['id' => 'LEGACY_MEMBER_ID', 'user_openid' => $recipient],
        'group_openid' => $recipient,
    ]];
}
function wpcpush_test_webhook_deliver(array $event, ?int $now = null): array
{
    $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return wpcpush_qq_webhook_handle('POST', $raw, wpcpush_test_webhook_headers($raw), $now);
}
function wpcpush_test_wp_response(array $data, int $status = 200): array
{
    return ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => $status, 'message' => 'Fixture'], 'cookies' => [], 'filename' => null];
}
function wpcpush_test_success_router(array $args, string $url): array
{
    if ($url === 'https://api.bot.qq.com/app/getAppAccessToken') { return wpcpush_test_wp_response(['access_token' => 'fixture-token', 'expires_in' => 7200]); }
    if (str_starts_with($url, 'https://api.bot.qq.com/') && str_ends_with($url, '/messages')) { return wpcpush_test_wp_response(['id' => 'fixture-message']); }
    if ($url === 'https://ilinkai.weixin.qq.com/ilink/bot/sendmessage') { return wpcpush_test_wp_response(['ret' => 0]); }
    throw new RuntimeException('Unexpected mocked endpoint: ' . $url);
}
function wpcpush_test_post(): int
{
    $id = wp_insert_post(['post_title' => 'Comment Push Integration Fixture', 'post_content' => 'Disposable fixture.', 'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open'], true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $GLOBALS['wpcpush_test_created_posts'][] = $id;
    return $id;
}
function wpcpush_test_user(string $role): int
{
    $id = wp_insert_user(['user_login' => 'cp_fixture_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $GLOBALS['wpcpush_test_created_users'][] = $id;
    return $id;
}
function wpcpush_test_comment(int $postId, string $status = '1', int $userId = 0, string $type = 'comment'): int
{
    $id = wp_insert_comment([
        'comment_post_ID' => $postId, 'comment_author' => '真实评论人', 'comment_author_email' => 'private@example.test',
        'comment_author_IP' => '192.0.2.17', 'comment_content' => '真实评论内容', 'comment_approved' => $status,
        'user_id' => $userId, 'comment_type' => $type,
    ]);
    wpcpush_test_check($id !== false && $id > 0, 'Failed to insert fixture comment');
    $GLOBALS['wpcpush_test_created_comments'][] = (int)$id;
    return (int)$id;
}

wpcpush_it('config validation handles non-scalar inputs without warnings', static function (): void {
    $result = wpcpush_validate(['qq_enabled' => '1', 'qq_app_id' => [], 'qq_app_secret' => ['bad'], 'qq_target_id' => ['https://evil.test'], 'qq_target_type' => ['c2c'], 'notify_pending' => '1'], wpcpush_defaults());
    wpcpush_test_check(count($result['errors']) >= 2);
    wpcpush_test_same('', $result['config']['qq_app_id']);
});

wpcpush_it('blank secrets preserve existing credentials and explicit clear removes them', static function (): void {
    $input = ['qq_app_id' => '123456', 'qq_target_type' => 'c2c', 'qq_target_id' => 'USER_OPENID', 'notify_pending' => '1', 'qq_app_secret' => ''];
    wpcpush_test_same('test-secret', wpcpush_validate($input, wpcpush_test_qq_config())['config']['qq_app_secret']);
    $input['clear_qq_app_secret'] = '1';
    wpcpush_test_same('', wpcpush_validate($input, wpcpush_test_qq_config())['config']['qq_app_secret']);
    $input['qq_app_secret'] = 'replacement-secret';
    wpcpush_test_same('replacement-secret', wpcpush_validate($input, wpcpush_test_qq_config())['config']['qq_app_secret']);
});

wpcpush_it('form fields cannot replace the synced Weixin recipient', static function (): void {
    $config = wpcpush_test_qq_config(['weixin_user_id' => 'owner@im.wechat']);
    $result = wpcpush_validate(['qq_target_type' => 'c2c', 'notify_pending' => '1', 'weixin_user_id' => 'attacker@im.wechat'], $config);
    wpcpush_test_same('owner@im.wechat', $result['config']['weixin_user_id']);
});

wpcpush_it('credential changes invalidate QQ token and unrelated changes preserve it', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    wpcpush_save_config(array_replace($config, ['include_link' => false]));
    wpcpush_test_same('cached-token', wpcpush_read('qq_token')['token']);
    wpcpush_save_config(array_replace($config, ['qq_app_secret' => 'new-secret']));
    wpcpush_test_same([], wpcpush_read('qq_token'));
});

wpcpush_it('QQ token endpoint, body, authentication and cache reuse match official protocol', static function (): void {
    $calls = [];
    $config = wpcpush_test_qq_config();
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['access_token' => 'issued-token', 'expires_in' => '7200']), wpcpush_test_response(['id' => 'message-1']), wpcpush_test_response(['id' => 'message-2'])]);
    wpcpush_qq_send($config, '你好', $mock);
    wpcpush_qq_send($config, '再次通知', $mock);
    wpcpush_test_same(3, count($calls));
    wpcpush_test_same('https://api.bot.qq.com/app/getAppAccessToken', $calls[0]['url']);
    wpcpush_test_same(['appId' => '123456', 'clientSecret' => 'test-secret'], $calls[0]['payload']);
    wpcpush_test_same('https://api.bot.qq.com/v2/users/USER_OPENID/messages', $calls[1]['url']);
    wpcpush_test_same(['Authorization: QQBot issued-token'], $calls[1]['headers']);
    wpcpush_test_same(['content' => '你好', 'msg_type' => 0], $calls[1]['payload']);
    wpcpush_test_check(!isset($calls[1]['payload']['msg_id'], $calls[1]['payload']['event_id']));
});

wpcpush_it('QQ near-expiry and other-identity tokens are never reused', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_test_cached_qq($config, 30);
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['access_token' => 'renewed-token', 'expires_in' => 7200]), wpcpush_test_response(['id' => 'ok'])]);
    wpcpush_qq_send($config, '通知', $mock);
    wpcpush_test_same('https://api.bot.qq.com/app/getAppAccessToken', $calls[0]['url']);
    $calls = [];
    $changed = array_replace($config, ['qq_app_id' => '654321']);
    wpcpush_qq_send($changed, '通知', wpcpush_test_transport($calls, [wpcpush_test_response(['access_token' => 'different-bot', 'expires_in' => 7200]), wpcpush_test_response(['id' => 'ok'])]));
    wpcpush_test_same('654321', $calls[0]['payload']['appId']);
});

wpcpush_it('QQ HTTP 401 refreshes once and retries with the new token', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_test_cached_qq($config);
    $calls = [];
    wpcpush_qq_send($config, '通知', wpcpush_test_transport($calls, [wpcpush_test_response(['code' => 11243], 401), wpcpush_test_response(['access_token' => 'new-token', 'expires_in' => 7200]), wpcpush_test_response(['id' => 'ok'])]));
    wpcpush_test_same(3, count($calls));
    wpcpush_test_same(['Authorization: QQBot cached-token'], $calls[0]['headers']);
    wpcpush_test_same(['Authorization: QQBot new-token'], $calls[2]['headers']);
});

wpcpush_it('QQ a second HTTP 401 terminates without a retry loop', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_test_cached_qq($config);
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response([], 401), wpcpush_test_response(['access_token' => 'new-token', 'expires_in' => 7200]), wpcpush_test_response([], 401)]);
    wpcpush_test_raises(static fn() => wpcpush_qq_send($config, '通知', $mock), 'HTTP 401');
    wpcpush_test_same(3, count($calls));
});

wpcpush_it('QQ HTTP 200 business errors in both code formats are failures', static function (): void {
    foreach (['code', 'err_code'] as $field) {
        $config = wpcpush_test_qq_config();
        wpcpush_test_cached_qq($config);
        $calls = [];
        $mock = wpcpush_test_transport($calls, [wpcpush_test_response([$field => 40034105, 'message' => 'do not render this remote payload'])]);
        $error = wpcpush_test_raises(static fn() => wpcpush_qq_send($config, '通知', $mock), '40034105');
        wpcpush_test_check(!str_contains($error->getMessage(), 'do not render'));
        wpcpush_test_same(1, count($calls));
    }
});

wpcpush_it('QQ token errors returned with HTTP 200 never reach send endpoint', static function (): void {
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['code' => 100016, 'access_token' => 'invalid', 'expires_in' => 7200])]);
    wpcpush_test_raises(static fn() => wpcpush_qq_send(wpcpush_test_qq_config(), '通知', $mock), '100016');
    wpcpush_test_same(1, count($calls));
    wpcpush_test_same([], wpcpush_read('qq_token'));
});

wpcpush_it('QQ malformed success and audit-pending responses do not claim delivery', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_test_cached_qq($config);
    foreach ([wpcpush_test_response([]), wpcpush_test_response(['code' => 304023, 'data' => ['message_audit' => ['audit_id' => 'audit']]], 202)] as $fixture) {
        $calls = [];
        $mock = wpcpush_test_transport($calls, [$fixture]);
        wpcpush_test_raises(static fn() => wpcpush_qq_send($config, '通知', $mock));
        wpcpush_test_same(1, count($calls));
    }
});

wpcpush_it('QQ network ambiguity and rate limits never trigger automatic resends', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_test_cached_qq($config);
    foreach ([new RuntimeException('simulated timeout'), wpcpush_test_response(['err_code' => 40034100], 429), wpcpush_test_response([], 503)] as $fixture) {
        $calls = [];
        $mock = wpcpush_test_transport($calls, [$fixture]);
        wpcpush_test_raises(static fn() => wpcpush_qq_send($config, '通知', $mock));
        wpcpush_test_same(1, count($calls));
    }
});

wpcpush_it('QQ group and channel use correct endpoints and channel omits msg_type', static function (): void {
    foreach (['group' => '/v2/groups/', 'channel' => '/channels/'] as $kind => $path) {
        $config = wpcpush_test_qq_config(['qq_target_type' => $kind]);
        wpcpush_test_cached_qq($config);
        $calls = [];
        wpcpush_qq_send($config, '通知', wpcpush_test_transport($calls, [wpcpush_test_response(['id' => 'ok'])]));
        wpcpush_test_same('https://api.bot.qq.com' . $path . 'USER_OPENID/messages', $calls[0]['url']);
        wpcpush_test_same($kind !== 'channel', isset($calls[0]['payload']['msg_type']));
    }
});

wpcpush_it('QQ target cannot inject paths or arbitrary hosts', static function (): void {
    foreach (['https://evil.test', '../messages', 'target?token=x', "target\r\nX-Test:1"] as $target) {
        $calls = [];
        $mock = wpcpush_test_transport($calls, []);
        wpcpush_test_raises(static fn() => wpcpush_qq_send(wpcpush_test_qq_config(['qq_target_id' => $target]), '通知', $mock));
        wpcpush_test_same([], $calls);
    }
});

wpcpush_it('QQ Webhook signing matches the official Ed25519 challenge vector', static function (): void {
    $pair = wpcpush_qq_keypair('DG5g3B4j9X2KOErG');
    $signature = sodium_crypto_sign_detached('1725442341Arq0D5A61EgUu4OxUvOp', sodium_crypto_sign_secretkey($pair));
    wpcpush_test_same('87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706', bin2hex($signature));
});

wpcpush_it('QQ Webhook verification uses the published public key and independently verified exact raw bytes', static function (): void {
    $raw = '{ "op": 0,"d": {}, "t": "GATEWAY_EVENT_NAME"}';
    $pair = wpcpush_qq_keypair('naOC0ocQE3shWLAfffVLB1rhYPG7');
    wpcpush_test_same([215, 195, 98, 254, 120, 174, 248, 31, 242, 50, 135, 180, 147, 98, 139, 93, 176, 42, 60, 79, 227, 11, 33, 94, 77, 25, 96, 155, 93, 118, 103, 58], array_values(unpack('C*', sodium_crypto_sign_publickey($pair))));
    // The documentation's 865ad...ed002 signature does not verify against its literal
    // body. This fixed signature was cross-checked with PHP Sodium and Node crypto.
    $signature = '2eb9983ebb8bb209e78fd095942f58e442656656e7975d01e64f9023a84b7c964290fdd40e5500c33867ccfe9563b7e0b6bac0e1d42c13e787b304fd51f71102';
    wpcpush_test_check(wpcpush_qq_webhook_verify($raw, '1725442341', $signature, 'naOC0ocQE3shWLAfffVLB1rhYPG7'));
    wpcpush_test_check(!wpcpush_qq_webhook_verify($raw, '1725442341', '865ad13a61752ca65e26bde6676459cd36cf1be609375b37bd62af366e1dc25a8dc789ba7f14e017ada3d554c671a911bfdf075ba54835b23391d509579ed002', 'naOC0ocQE3shWLAfffVLB1rhYPG7'));
    wpcpush_test_check(!wpcpush_qq_webhook_verify(str_replace(' ', '', $raw), '1725442341', $signature, 'naOC0ocQE3shWLAfffVLB1rhYPG7'));
    wpcpush_test_check(!wpcpush_qq_webhook_verify($raw, '1725442342', $signature, 'naOC0ocQE3shWLAfffVLB1rhYPG7'));
    wpcpush_test_check(!wpcpush_qq_webhook_verify($raw, '1725442341', $signature, 'wrong-secret'));
    foreach (['', 'bad', str_repeat('z', 128), str_repeat('0', 126), str_repeat('0', 130)] as $invalid) {
        wpcpush_test_check(!wpcpush_qq_webhook_verify($raw, '1725442341', $invalid, 'naOC0ocQE3shWLAfffVLB1rhYPG7'));
    }
});

wpcpush_it('QQ Webhook keys use exactly the first 32 repeated secret bytes for short and long credentials', static function (): void {
    foreach (['a', '0123456789abcdef', '0123456789abcdef0123456789abcdef', '0123456789abcdef0123456789abcdefEXTRA', str_repeat('long-secret', 20)] as $secret) {
        $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
        $expected = sodium_crypto_sign_seed_keypair($seed);
        wpcpush_test_same(sodium_crypto_sign_publickey($expected), sodium_crypto_sign_publickey(wpcpush_qq_keypair($secret)));
    }
});

wpcpush_it('QQ Webhook signed challenge returns the official plain token and signature', static function (): void {
    $config = wpcpush_test_qq_config(['qq_enabled' => false, 'qq_app_secret' => 'DG5g3B4j9X2KOErG']);
    wpcpush_save_config($config);
    $raw = '{"op":13,"d":{"plain_token":"Arq0D5A61EgUu4OxUvOp","event_ts":"1725442341"}}';
    $result = wpcpush_qq_webhook_handle('POST', $raw, wpcpush_test_webhook_headers($raw), 1800000000);
    wpcpush_test_same(200, $result['status']);
    wpcpush_test_same('Arq0D5A61EgUu4OxUvOp', $result['body']['plain_token']);
    wpcpush_test_same('87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706', $result['body']['signature']);
    wpcpush_test_same(1800000000, wpcpush_qq_webhook_status()['last_challenge_at']);
    wpcpush_test_same($config, wpcpush_config());
});

wpcpush_it('QQ Webhook forged or unsigned challenge never changes plugin state', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    wpcpush_qq_binding_start();
    $raw = '{"op":13,"d":{"plain_token":"attacker","event_ts":"1725442341"}}';
    $signed = wpcpush_test_webhook_headers($raw);
    $invalidHeaders = [
        [],
        ['x-bot-appid' => '123456'],
        array_replace($signed, ['x-signature-ed25519' => str_repeat('0', 128)]),
        array_replace($signed, ['x-bot-appid' => '654321']),
        array_replace($signed, ['x-bot-appid' => '']),
        array_replace($signed, ['x-signature-timestamp' => '1725442342']),
        array_replace($signed, ['x-signature-ed25519' => []]),
        array_replace($signed, ['x-bot-appid' => ['123456']]),
    ];
    foreach ($invalidHeaders as $headers) {
        $before = wpcpush_test_snapshot();
        $result = wpcpush_qq_webhook_handle('POST', $raw, $headers);
        wpcpush_test_check($result['status'] >= 400 && $result['status'] < 500);
        wpcpush_test_check(!isset($result['body']['signature']), 'Rejected challenge returned a signature');
        wpcpush_test_same($before, wpcpush_test_snapshot());
    }
});

wpcpush_it('QQ Webhook a forged event cannot consume a real active binding code', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    $binding = wpcpush_qq_binding_start();
    $event = wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code'], 'ATTACKER_OPENID');
    $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signed = wpcpush_test_webhook_headers($raw);
    foreach ([[], array_replace($signed, ['x-bot-appid' => '654321']), array_replace($signed, ['x-signature-ed25519' => str_repeat('0', 128)])] as $headers) {
        $before = wpcpush_test_snapshot();
        wpcpush_test_check(wpcpush_qq_webhook_handle('POST', $raw, $headers)['status'] >= 400);
        wpcpush_test_same($before, wpcpush_test_snapshot());
    }
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code'], 'REAL_USER_OPENID'));
    wpcpush_test_same('REAL_USER_OPENID', wpcpush_config()['qq_target_id']);
});

wpcpush_it('QQ Webhook rejects malformed signed payloads, unsupported methods and excessive bodies', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    $cases = [
        ['POST', '{'], ['POST', 'null'], ['POST', '[]'], ['POST', '"text"'],
        ['POST', '{"op":"13","d":{"plain_token":"token","event_ts":"1725442341"}}'],
        ['POST', '{"op":13,"d":[]}'],
        ['POST', '{"op":13,"d":{"plain_token":[],"event_ts":"1725442341"}}'],
        ['POST', '{"op":13,"d":{"plain_token":"token","event_ts":[]}}'],
        ['POST', '{"op":0,"t":"C2C_MESSAGE_CREATE","d":"invalid"}'],
        ['GET', '{"op":0,"t":"GATEWAY_EVENT_NAME","d":{}}'],
        ['PUT', '{"op":0,"t":"GATEWAY_EVENT_NAME","d":{}}'],
        ['POST', str_repeat(' ', 65537)],
    ];
    foreach ($cases as [$method, $raw]) {
        $before = wpcpush_test_snapshot();
        $result = wpcpush_qq_webhook_handle($method, $raw, wpcpush_test_webhook_headers($raw));
        wpcpush_test_check($result['status'] >= 400 && $result['status'] < 500, 'Accepted malformed payload: ' . substr($raw, 0, 100));
        wpcpush_test_same($before, wpcpush_test_snapshot());
    }
});

wpcpush_it('QQ Webhook acknowledges valid events without requiring recent signature timestamps', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    $binding = wpcpush_qq_binding_start();
    $before = wpcpush_qq_webhook_status()['binding'];
    $result = wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '普通消息'), 1800000000);
    wpcpush_test_same(200, $result['status']);
    wpcpush_test_same(['op' => 12, 'd' => 0], $result['body']);
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    wpcpush_test_same($before, wpcpush_qq_webhook_status()['binding']);
    wpcpush_test_same(1800000000, wpcpush_qq_webhook_status()['last_event_at']);
    wpcpush_test_check(preg_match('/\A[A-F0-9]{16}\z/', $binding['code']) === 1);
});

wpcpush_it('QQ private binding requires the active code and binds user_openid without enabling notifications', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config(['qq_enabled' => false]));
    $binding = wpcpush_qq_binding_start();
    $result = wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code']));
    wpcpush_test_same(200, $result['status']);
    wpcpush_test_same('NEW_USER_OPENID', wpcpush_config()['qq_target_id']);
    wpcpush_test_same(false, wpcpush_config()['qq_enabled']);
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code'], 'REPLAY_USER'));
    wpcpush_test_same('NEW_USER_OPENID', wpcpush_config()['qq_target_id']);
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 AAAAAAAAAAAAAAAA', 'UNEXPECTED_USER'));
    wpcpush_test_same('NEW_USER_OPENID', wpcpush_config()['qq_target_id']);
});

wpcpush_it('QQ group binding handles an initial mention and chooses group_openid rather than member identity', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config(['qq_enabled' => false, 'qq_target_type' => 'group']));
    $binding = wpcpush_qq_binding_start();
    $event = wpcpush_test_webhook_body('GROUP_AT_MESSAGE_CREATE', '<@123456> 绑定通知 ' . $binding['code'], 'GROUP_OPENID');
    $event['d']['author']['user_openid'] = 'MEMBER_OPENID';
    wpcpush_test_same(200, wpcpush_test_webhook_deliver($event)['status']);
    wpcpush_test_same('GROUP_OPENID', wpcpush_config()['qq_target_id']);
    wpcpush_test_same('group', wpcpush_config()['qq_target_type']);
    wpcpush_test_same(false, wpcpush_config()['qq_enabled']);
});

wpcpush_it('QQ wrong event type, incorrect codes and malformed recipients cannot consume pending binding', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    $binding = wpcpush_qq_binding_start();
    $events = [
        wpcpush_test_webhook_body('GROUP_AT_MESSAGE_CREATE', '绑定通知 ' . $binding['code']),
        wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 0000000000000000'),
        wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '前缀 绑定通知 ' . $binding['code']),
        wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code'] . ' 尾缀'),
        wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code'], '../bad-target'),
    ];
    $missingRecipient = wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code']);
    unset($missingRecipient['d']['author']['user_openid']);
    $events[] = $missingRecipient;
    $malformedContent = wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '');
    $malformedContent['d']['content'] = [];
    $events[] = $malformedContent;
    foreach ($events as $event) {
        wpcpush_test_same(200, wpcpush_test_webhook_deliver($event)['status']);
        wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    }
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code']));
    wpcpush_test_same('NEW_USER_OPENID', wpcpush_config()['qq_target_id']);
});

wpcpush_it('QQ binding expires after ten minutes and cancelled or replaced codes cannot bind', static function (): void {
    wpcpush_save_config(wpcpush_test_qq_config());
    $started = time();
    $binding = wpcpush_qq_binding_start();
    wpcpush_test_check($binding['expires_at'] >= $started + 600 && $binding['expires_at'] <= time() + 600);
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code']), $binding['expires_at'] + 1);
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    $cancelled = wpcpush_qq_binding_start();
    wpcpush_qq_binding_cancel();
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $cancelled['code']));
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    $old = wpcpush_qq_binding_start();
    $current = wpcpush_qq_binding_start();
    wpcpush_test_check($old['code'] !== $current['code']);
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $old['code']));
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $current['code']));
    wpcpush_test_same('NEW_USER_OPENID', wpcpush_config()['qq_target_id']);
});

wpcpush_it('QQ binding mutex refuses a second owner without replacing the live lock', static function (): void {
    $first = wpcpush_qq_binding_lock();
    wpcpush_test_same($first, get_option('wpcpush_qq_binding_lock'));
    wpcpush_test_raises(static fn() => wpcpush_qq_binding_lock());
    wpcpush_test_same($first, get_option('wpcpush_qq_binding_lock'));
    wpcpush_qq_binding_unlock($first);
    wpcpush_test_same(false, get_option('wpcpush_qq_binding_lock', false));
});

wpcpush_it('QQ binding mutex recovers an expired lease and stale unlock cannot delete its successor', static function (): void {
    $expired = ['owner' => 'expired-fixture-owner', 'expires_at' => time() - 1];
    update_option('wpcpush_qq_binding_lock', $expired, false);
    $successor = wpcpush_qq_binding_lock();
    wpcpush_test_check($successor['owner'] !== $expired['owner']);
    wpcpush_test_check($successor['expires_at'] > time());
    wpcpush_test_same($successor, get_option('wpcpush_qq_binding_lock'));
    wpcpush_qq_binding_unlock($expired);
    wpcpush_test_same($successor, get_option('wpcpush_qq_binding_lock'));
    wpcpush_qq_binding_unlock($successor);
    wpcpush_test_same(false, get_option('wpcpush_qq_binding_lock', false));
});

wpcpush_it('QQ binding mutex remains exclusive when stale WordPress cache reports no lock', static function (): void {
    global $wpdb;
    $key = 'wpcpush_qq_binding_lock';
    $first = wpcpush_qq_binding_lock();
    $notoptions = wp_cache_get('notoptions', 'options');
    // Reproduce another worker's old negative cache after the first SQL insert.
    // add_option() skips its existence check here and its upsert can steal a lock.
    wp_cache_set('notoptions', array_replace(is_array($notoptions) ? $notoptions : [], [$key => true]), 'options');
    try {
        wpcpush_test_raises(static fn() => wpcpush_qq_binding_lock());
        $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        wpcpush_test_same($first, maybe_unserialize($stored), 'An applicant replaced the existing database lock owner');
    } finally {
        if ($notoptions === false) { wp_cache_delete('notoptions', 'options'); }
        else { wp_cache_set('notoptions', $notoptions, 'options'); }
        wp_cache_delete($key, 'options');
        wpcpush_qq_binding_unlock($first);
    }
});

wpcpush_it('QQ credential changes clear callback status and pending binding; type changes cannot reuse it', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_save_config($config);
    $binding = wpcpush_qq_binding_start();
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '普通消息'));
    wpcpush_save_config(array_replace($config, ['qq_app_secret' => 'changed-secret']));
    $status = wpcpush_qq_webhook_status();
    wpcpush_test_check(empty($status['last_event_at']));
    wpcpush_test_check(empty($status['last_challenge_at']));
    wpcpush_test_check(empty($status['binding']));
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('C2C_MESSAGE_CREATE', '绑定通知 ' . $binding['code']));
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
    $binding = wpcpush_qq_binding_start();
    wpcpush_save_config(array_replace(wpcpush_config(), ['qq_target_type' => 'group']));
    wpcpush_test_webhook_deliver(wpcpush_test_webhook_body('GROUP_AT_MESSAGE_CREATE', '绑定通知 ' . $binding['code'], 'WRONG_GROUP'));
    wpcpush_test_same('USER_OPENID', wpcpush_config()['qq_target_id']);
});

wpcpush_it('QQ binding requires saved credentials and a supported private or group target', static function (): void {
    foreach ([['qq_app_id' => ''], ['qq_app_secret' => ''], ['qq_target_type' => 'channel']] as $changes) {
        wpcpush_save_config(wpcpush_test_qq_config($changes));
        $before = wpcpush_test_snapshot();
        wpcpush_test_raises(static fn() => wpcpush_qq_binding_start());
        wpcpush_test_same($before, wpcpush_test_snapshot());
    }
});

wpcpush_it('message snippets keep UTF-8 intact and neutralize encoded QQ mentions', static function (): void {
    wpcpush_test_same('你好🙂…', wpcpush_text('<b>你好🙂再见</b>', 3));
    $text = wpcpush_text('&lt;@everyone&gt;&lt;@!1234&gt;正文<script>bad</script>', 400);
    wpcpush_test_check(!str_contains($text, '<@'), 'Encoded mention survived notification sanitation');
    wpcpush_test_check(str_contains($text, '正文'));
    wpcpush_test_same('', wpcpush_text("\xff\xfe", 20));
});

wpcpush_it('Weixin rejects non-official and malformed API bases', static function (): void {
    wpcpush_test_same('https://ilinkai.weixin.qq.com', wpcpush_weixin_base('https://ilinkai.weixin.qq.com/'));
    foreach (['http://ilinkai.weixin.qq.com', 'https://weixin.qq.com.evil.test', 'https://evil.test', 'https://127.0.0.1', 'https://user@ilinkai.weixin.qq.com', 'https://ilinkai.weixin.qq.com:443', 'https://ilinkai.weixin.qq.com/api', 'https://ilinkai.weixin.qq.com?x=1'] as $base) {
        wpcpush_test_raises(static fn() => wpcpush_weixin_base($base));
    }
});

wpcpush_it('Weixin QR initiation stores only verified official login links', static function (): void {
    $calls = [];
    $result = wpcpush_weixin_start(wpcpush_test_transport($calls, [wpcpush_test_response(['qrcode' => 'qr-code', 'qrcode_img_content' => 'https://liteapp.weixin.qq.com/q/test'])]));
    wpcpush_test_same('wait', $result['status']);
    wpcpush_test_same('qr-code', wpcpush_read('weixin_login')['qrcode']);
    wpcpush_test_same('https://ilinkai.weixin.qq.com/ilink/bot/get_bot_qrcode?bot_type=3', $calls[0]['url']);
    wpcpush_test_check(in_array('iLink-App-Id: bot', $calls[0]['headers'], true));
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['qrcode' => 'bad', 'qrcode_img_content' => 'https://evil.test/q'])]);
    wpcpush_test_raises(static fn() => wpcpush_weixin_start($mock));
    wpcpush_test_same('qr-code', wpcpush_read('weixin_login')['qrcode']);
});

wpcpush_it('Weixin confirmed QR login saves account and clears stale binding', static function (): void {
    wpcpush_test_weixin_bound();
    wpcpush_write('weixin_login', ['qrcode' => 'qr value', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com']);
    $calls = [];
    $result = wpcpush_weixin_check('', wpcpush_test_transport($calls, [wpcpush_test_response(['status' => 'confirmed', 'bot_token' => 'new-weixin-secret', 'ilink_bot_id' => 'bot@im.bot', 'ilink_user_id' => 'owner@im.wechat', 'baseurl' => 'https://ilinkai.weixin.qq.com'])]));
    wpcpush_test_same('confirmed', $result['status']);
    wpcpush_test_same('new-weixin-secret', wpcpush_read('weixin_account')['token']);
    wpcpush_test_same([], wpcpush_read('weixin_login'));
    wpcpush_test_same([], wpcpush_read('weixin_sync'));
    wpcpush_test_same('', wpcpush_config()['weixin_user_id']);
    wpcpush_test_same(false, wpcpush_config()['weixin_enabled']);
    wpcpush_test_check(str_contains($calls[0]['url'], 'qrcode=qr%20value'));
});

wpcpush_it('Weixin login redirects cannot move credentials to an arbitrary host', static function (): void {
    wpcpush_write('weixin_login', ['qrcode' => 'qr', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com']);
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['status' => 'scaned_but_redirect', 'redirect_host' => 'evil.test'])]);
    wpcpush_test_raises(static fn() => wpcpush_weixin_check('', $mock));
    wpcpush_test_same('https://ilinkai.weixin.qq.com', wpcpush_read('weixin_login')['base_url']);
    wpcpush_test_same(1, count($calls));
});

wpcpush_it('Weixin sync binds the scanning owner and ignores other users or group messages', static function (): void {
    wpcpush_test_weixin_account();
    $calls = [];
    $result = wpcpush_weixin_sync(wpcpush_test_transport($calls, [wpcpush_test_response(['ret' => 0, 'get_updates_buf' => 'next-cursor', 'msgs' => [
        ['message_type' => 1, 'from_user_id' => 'attacker@im.wechat', 'context_token' => 'attacker-context'],
        ['message_type' => 1, 'from_user_id' => 'owner@im.wechat', 'group_id' => 'group', 'context_token' => 'group-context'],
        ['message_type' => 2, 'from_user_id' => 'owner@im.wechat', 'context_token' => 'outbound-context'],
        ['message_type' => 1, 'from_user_id' => 'owner@im.wechat', 'context_token' => 'owner-context'],
    ]])]));
    wpcpush_test_same('bound', $result['status']);
    wpcpush_test_same('owner@im.wechat', wpcpush_config()['weixin_user_id']);
    wpcpush_test_same(['owner@im.wechat'], wpcpush_read('weixin_sync')['candidates']);
    wpcpush_test_same('owner-context', wpcpush_read('weixin_sync')['context_tokens']['owner@im.wechat']);
    wpcpush_test_same('next-cursor', wpcpush_read('weixin_sync')['get_updates_buf']);
    wpcpush_test_check(in_array('Authorization: Bearer weixin-test-secret', $calls[0]['headers'], true));
});

wpcpush_it('Weixin unsynced recipients cannot bind or receive notifications', static function (): void {
    wpcpush_test_weixin_account();
    wpcpush_test_raises(static fn() => wpcpush_weixin_bind('attacker@im.wechat'));
    $calls = [];
    $mock = wpcpush_test_transport($calls, []);
    wpcpush_test_raises(static fn() => wpcpush_weixin_send(wpcpush_test_qq_config(['weixin_user_id' => 'attacker@im.wechat']), '通知', $mock));
    wpcpush_test_same([], $calls);
});

wpcpush_it('Weixin send includes context, protocol metadata and unique client IDs', static function (): void {
    $config = wpcpush_test_weixin_bound();
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['ret' => 0]), wpcpush_test_response(['ret' => 0])]);
    wpcpush_weixin_send($config, '你好', $mock);
    wpcpush_weixin_send($config, '你好', $mock);
    wpcpush_test_same('https://ilinkai.weixin.qq.com/ilink/bot/sendmessage', $calls[0]['url']);
    wpcpush_test_same('owner@im.wechat', $calls[0]['payload']['msg']['to_user_id']);
    wpcpush_test_same('session-context', $calls[0]['payload']['msg']['context_token']);
    wpcpush_test_same([['type' => 1, 'text_item' => ['text' => '你好']]], $calls[0]['payload']['msg']['item_list']);
    wpcpush_test_same(2, $calls[0]['payload']['msg']['message_type']);
    wpcpush_test_check($calls[0]['payload']['msg']['client_id'] !== $calls[1]['payload']['msg']['client_id']);
    wpcpush_test_check(isset($calls[0]['payload']['base_info']['channel_version']));
});

wpcpush_it('Weixin a synced owner can attempt sending without context as upstream permits', static function (): void {
    $config = wpcpush_test_weixin_bound(false);
    $calls = [];
    wpcpush_weixin_send($config, '通知', wpcpush_test_transport($calls, [wpcpush_test_response(['ret' => 0])]));
    wpcpush_test_same(1, count($calls));
    wpcpush_test_check(!isset($calls[0]['payload']['msg']['context_token']));
});

wpcpush_it('Weixin polling read timeout returns wait and preserves login and sync state', static function (): void {
    wpcpush_test_weixin_bound();
    $login = ['qrcode' => 'qr', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com'];
    wpcpush_write('weixin_login', $login);
    $sync = wpcpush_read('weixin_sync');
    $calls = [];
    wpcpush_test_same('wait', wpcpush_weixin_check('', wpcpush_test_transport($calls, [new RuntimeException('safe read timeout', 28)]))['status']);
    wpcpush_test_same($login, wpcpush_read('weixin_login'));
    wpcpush_test_same('wait', wpcpush_weixin_sync(wpcpush_test_transport($calls, [new RuntimeException('safe read timeout', 28)]))['status']);
    wpcpush_test_same($sync, wpcpush_read('weixin_sync'));
    wpcpush_test_same(2, count($calls));
    $mock = wpcpush_test_transport($calls, [new RuntimeException('connection failure')]);
    wpcpush_test_raises(static fn() => wpcpush_weixin_sync($mock), 'connection failure');
    wpcpush_test_same(3, count($calls));
});

wpcpush_it('Weixin either negative ret or errcode defeats an apparent successful acknowledgement', static function (): void {
    $config = wpcpush_test_weixin_bound();
    foreach ([['ret' => 0, 'errcode' => -2], ['ret' => -2, 'errcode' => 0], ['message_id' => 42, 'ret' => -2]] as $data) {
        $calls = [];
        $mock = wpcpush_test_transport($calls, [wpcpush_test_response($data)]);
        wpcpush_test_raises(static fn() => wpcpush_weixin_send($config, '通知', $mock), '-2');
        wpcpush_test_same(1, count($calls));
    }
});

wpcpush_it('expired Weixin account cannot be enabled through settings', static function (): void {
    $config = wpcpush_test_weixin_bound();
    wpcpush_write('weixin_account', array_replace(wpcpush_read('weixin_account'), ['expired' => true]));
    $result = wpcpush_validate(['weixin_enabled' => '1', 'notify_pending' => '1', 'qq_target_type' => 'c2c'], $config);
    wpcpush_test_check($result['errors'] !== []);
});

wpcpush_it('Weixin session-expiry errors disable cached account and never retry', static function (): void {
    $config = wpcpush_test_weixin_bound();
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response(['ret' => -14])]);
    wpcpush_test_raises(static fn() => wpcpush_weixin_send($config, '通知', $mock), '-14');
    wpcpush_test_same(1, count($calls));
    wpcpush_test_same(true, wpcpush_read('weixin_account')['expired']);
    $calls = [];
    $mock = wpcpush_test_transport($calls, []);
    wpcpush_test_raises(static fn() => wpcpush_weixin_send($config, '通知', $mock));
    wpcpush_test_same([], $calls);
});

wpcpush_it('Weixin rejects malformed acknowledgements and avoids ambiguous resend', static function (): void {
    $config = wpcpush_test_weixin_bound();
    foreach ([wpcpush_test_response([]), wpcpush_test_response(['ret' => -2]), new RuntimeException('simulated timeout')] as $fixture) {
        $calls = [];
        $mock = wpcpush_test_transport($calls, [$fixture]);
        wpcpush_test_raises(static fn() => wpcpush_weixin_send($config, '通知', $mock));
        wpcpush_test_same(1, count($calls));
    }
});

wpcpush_it('Weixin disconnect clears all account, login, sync and recipient state', static function (): void {
    wpcpush_test_weixin_bound();
    wpcpush_write('weixin_login', ['qrcode' => 'old-qr', 'started_at' => time()]);
    wpcpush_weixin_disconnect();
    foreach (['weixin_account', 'weixin_login', 'weixin_sync'] as $key) { wpcpush_test_same([], wpcpush_read($key)); }
    wpcpush_test_same('', wpcpush_config()['weixin_user_id']);
    wpcpush_test_same(false, wpcpush_config()['weixin_enabled']);
});

wpcpush_it('WordPress storage uses non-autoload options and deletes empty records', static function (): void {
    global $wpdb;
    wpcpush_write('test_record', ['value' => 'fixture']);
    wpcpush_test_same(['value' => 'fixture'], wpcpush_read('test_record'));
    $autoload = $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'wpcpush_test_record'");
    wpcpush_test_check(in_array($autoload, ['no', 'off', 'auto-off'], true), 'Plugin option must not autoload');
    wpcpush_write('test_record', []);
    wpcpush_test_same(false, get_option('wpcpush_test_record', false));
});

wpcpush_it('malformed WordPress options fall back to safe defaults', static function (): void {
    wpcpush_write('config', ['qq_enabled' => '1', 'qq_app_id' => [], 'qq_target_type' => false, 'include_link' => [], 'unknown' => 'https://evil.test']);
    wpcpush_test_same(wpcpush_defaults(), wpcpush_config());
    update_option('wpcpush_config', 'not-an-array', false);
    wpcpush_test_same(wpcpush_defaults(), wpcpush_config());
});

wpcpush_it('unexpected external HTTP is blocked by the harness', static function (): void {
    $result = wp_remote_get('https://example.invalid/integration-must-never-connect');
    wpcpush_test_check(is_wp_error($result));
    wpcpush_test_same('wpcpush_test_http_blocked', $result->get_error_code());
});

wpcpush_it('plugin hooks only persisted new comments and leaves moderation unhooked', static function (): void {
    wpcpush_test_same(30, has_action('wp_insert_comment', 'wpcpush_comment_created'));
    wpcpush_test_same(false, has_action('comment_post', 'wpcpush_comment_created'));
    wpcpush_test_same(false, has_action('transition_comment_status', 'wpcpush_comment_created'));
});

wpcpush_it('disabled plugin inserts real comments without HTTP or plugin storage', static function (): void {
    $id = wpcpush_test_comment(wpcpush_test_post());
    wpcpush_test_check(get_comment($id) instanceof WP_Comment);
    wpcpush_test_same([], $GLOBALS['wpcpush_test_http_calls']);
    wpcpush_test_same([], wpcpush_test_snapshot());
});

wpcpush_it('real approved comment sends once with trusted content and no private identifiers', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
    $postId = wpcpush_test_post();
    $id = wpcpush_test_comment($postId);
    wpcpush_test_same(1, count($GLOBALS['wpcpush_test_http_calls']));
    $payload = json_decode($GLOBALS['wpcpush_test_http_calls'][0]['args']['body'], true);
    $text = $payload['content'];
    wpcpush_test_check(str_contains($text, 'Comment Push Integration Fixture'));
    wpcpush_test_check(str_contains($text, '真实评论人') && str_contains($text, '真实评论内容'));
    wpcpush_test_check(str_contains($text, '#comment-' . $id));
    wpcpush_test_check(!str_contains($text, 'private@example.test') && !str_contains($text, '192.0.2.17'));
    wpcpush_test_same(true, wpcpush_read('result_qq')['ok']);
    wpcpush_test_check(get_comment($id) instanceof WP_Comment);
});

wpcpush_it('pending and approved flags filter the persisted WordPress status', static function (): void {
    $postId = wpcpush_test_post();
    foreach ([['0', 'notify_pending'], ['1', 'notify_approved']] as [$status, $flag]) {
        wpcpush_test_fresh();
        $config = wpcpush_test_qq_config([$flag => false]);
        wpcpush_save_config($config);
        wpcpush_test_cached_qq($config);
        $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
        wpcpush_test_comment($postId, $status);
        wpcpush_test_same([], $GLOBALS['wpcpush_test_http_calls'], 'Disabled status notification was sent');
    }
});

wpcpush_it('pending WordPress comments notify when enabled', static function (): void {
    $config = wpcpush_test_qq_config(['notify_pending' => true]);
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
    $id = wpcpush_test_comment(wpcpush_test_post(), '0');
    wpcpush_test_same('0', get_comment($id)->comment_approved);
    wpcpush_test_same(1, count($GLOBALS['wpcpush_test_http_calls']));
});

wpcpush_it('spam trash trackback and pingback comments never notify', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
    $postId = wpcpush_test_post();
    foreach ([['spam', 'comment'], ['trash', 'comment'], ['1', 'trackback'], ['1', 'pingback']] as [$status, $type]) {
        wpcpush_test_comment($postId, $status, 0, $type);
    }
    wpcpush_test_same([], $GLOBALS['wpcpush_test_http_calls']);
});

wpcpush_it('administrator opt-in is independent of ordinary logged-in commenters', static function (): void {
    $adminId = wpcpush_test_user('administrator');
    $subscriberId = wpcpush_test_user('subscriber');
    $postId = wpcpush_test_post();
    $config = wpcpush_test_qq_config(['notify_admin' => false]);
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
    wp_set_current_user($adminId);
    wpcpush_test_comment($postId, '1', $adminId);
    wpcpush_test_same([], $GLOBALS['wpcpush_test_http_calls']);
    wp_set_current_user($subscriberId);
    wpcpush_test_comment($postId, '1', $subscriberId);
    wpcpush_test_same(1, count($GLOBALS['wpcpush_test_http_calls']));
    wpcpush_save_config(array_replace($config, ['notify_admin' => true]));
    wpcpush_test_comment($postId, '1', $adminId);
    wpcpush_test_same(2, count($GLOBALS['wpcpush_test_http_calls']));
});

wpcpush_it('approving editing and re-moderating an existing comment never duplicate the new-comment push', static function (): void {
    $config = wpcpush_test_qq_config();
    wpcpush_save_config($config);
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = 'wpcpush_test_success_router';
    $id = wpcpush_test_comment(wpcpush_test_post(), '0');
    wpcpush_test_same(1, count($GLOBALS['wpcpush_test_http_calls']));
    wpcpush_test_check(wp_set_comment_status($id, 'approve'));
    wpcpush_test_check((bool)wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Edited fixture comment.']));
    wpcpush_test_check(wp_set_comment_status($id, 'hold'));
    wpcpush_test_check(wp_set_comment_status($id, 'approve'));
    wpcpush_test_same(1, count($GLOBALS['wpcpush_test_http_calls']));
});

wpcpush_it('QQ failure still delivers Weixin and never rolls back the real comment', static function (): void {
    $config = wpcpush_test_weixin_bound();
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = static function (array $args, string $url): array {
        if (str_starts_with($url, 'https://api.bot.qq.com/')) { return wpcpush_test_wp_response(['err_code' => 40034105]); }
        return wpcpush_test_success_router($args, $url);
    };
    $id = wpcpush_test_comment(wpcpush_test_post());
    wpcpush_test_same(2, count($GLOBALS['wpcpush_test_http_calls']));
    wpcpush_test_same(false, wpcpush_read('result_qq')['ok']);
    wpcpush_test_same(true, wpcpush_read('result_weixin')['ok']);
    wpcpush_test_check(get_comment($id) instanceof WP_Comment);
});

wpcpush_it('Weixin timeout preserves successful QQ result and the real comment', static function (): void {
    $config = wpcpush_test_weixin_bound();
    wpcpush_test_cached_qq($config);
    $GLOBALS['wpcpush_test_http_router'] = static function (array $args, string $url) {
        if (str_contains($url, 'weixin.qq.com')) { return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'); }
        return wpcpush_test_success_router($args, $url);
    };
    $id = wpcpush_test_comment(wpcpush_test_post());
    wpcpush_test_same(2, count($GLOBALS['wpcpush_test_http_calls']));
    wpcpush_test_same(true, wpcpush_read('result_qq')['ok']);
    wpcpush_test_same(false, wpcpush_read('result_weixin')['ok']);
    wpcpush_test_check(get_comment($id) instanceof WP_Comment);
});

wpcpush_it('Weixin HTTP 401 invalidates login and does not resend', static function (): void {
    $config = wpcpush_test_weixin_bound();
    $calls = [];
    $mock = wpcpush_test_transport($calls, [wpcpush_test_response([], 401)]);
    wpcpush_test_raises(static fn() => wpcpush_weixin_send($config, '通知', $mock), '401');
    wpcpush_test_same(1, count($calls));
    wpcpush_test_same(true, wpcpush_read('weixin_account')['expired']);
});

wpcpush_it('administrator settings escape identities and never refill saved secrets', static function (): void {
    if (!function_exists('wpcpush_admin_page')) { require_once WP_PLUGIN_DIR . '/comment-push/admin.php'; }
    wp_set_current_user(wpcpush_test_user('administrator'));
    $config = wpcpush_test_weixin_bound();
    wpcpush_write('weixin_account', array_replace(wpcpush_read('weixin_account'), ['account_id' => '<script>alert(1)</script>']));
    wpcpush_test_cached_qq($config);
    $originalGet = $_GET;
    $html = '';
    ob_start();
    try {
        foreach (array_keys(wpcpush_admin_sections()) as $section) {
            $_GET['page'] = 'comment-push' . ($section === 'notifications' ? '' : '-' . $section);
            wpcpush_admin_page();
        }
        $html = (string)ob_get_contents();
    } finally { ob_end_clean(); $_GET = $originalGet; }
    foreach (['test-secret', 'weixin-test-secret', 'cached-token', 'session-context'] as $secret) {
        wpcpush_test_check(!str_contains($html, $secret), 'Saved secret was rendered');
    }
    wpcpush_test_check(!str_contains($html, '<script>alert(1)</script>'));
    wpcpush_test_check(str_contains($html, '_wpnonce'));
    wpcpush_test_check((bool)preg_match('/name="qq_app_secret"[^>]*value=""|name="qq_app_secret"(?![^>]*value=)/', $html));
});

try {
    foreach ($wpcpush_test_cases as $name => $callback) {
        wpcpush_test_fresh();
        $level = ob_get_level();
        ob_start();
        try {
            $callback();
            $wpcpush_test_results[] = 'PASS ' . $name;
        } catch (Throwable $exception) {
            $wpcpush_test_results[] = 'FAIL ' . $name . "\n     " . $exception->getMessage();
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
    }
} finally {
    // Keep the HTTP guard installed throughout cleanup and exact option restore.
    try {
        $GLOBALS['wpcpush_test_http_router'] = null;
        wp_set_current_user(0);
        foreach ($wpcpush_test_created_comments as $id) { wp_delete_comment($id, true); }
        foreach ($wpcpush_test_created_posts as $id) { wp_delete_post($id, true); }
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        foreach ($wpcpush_test_created_users as $id) { wp_delete_user($id); }
        wpcpush_test_clear_options(true);
        global $wpdb;
        foreach ($wpcpush_test_original_options as $row) {
            $inserted = $wpdb->insert($wpdb->options, $row);
            if ($inserted === false) { throw new RuntimeException('Could not restore original plugin option: ' . $row['option_name']); }
            wp_cache_delete($row['option_name'], 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        if (wpcpush_test_snapshot(true) !== $wpcpush_test_original_options) { throw new RuntimeException('Plugin and cron option restoration did not match the original snapshot.'); }
    } finally {
        wp_set_current_user($wpcpush_test_original_user);
        remove_filter('pre_wp_mail', $wpcpush_test_mail_guard, PHP_INT_MIN);
        remove_filter('pre_http_request', $wpcpush_test_http_guard, PHP_INT_MIN);
    }
}
$wpcpush_test_failed = count(array_filter($wpcpush_test_results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $wpcpush_test_results) . "\n\n" . count($wpcpush_test_results) . ' checks, ' . $wpcpush_test_failed . " failures.\n";
WP_CLI::halt($wpcpush_test_failed === 0 ? 0 : 1);
