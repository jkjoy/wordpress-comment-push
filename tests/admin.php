<?php

/** Admin section integration checks. Stop the independent receiver before running. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) { http_response_code(403); exit; }
if (!function_exists('wpcpush_admin_validate_settings')) { require_once WP_PLUGIN_DIR . '/comment-push/admin.php'; }
if (!function_exists('wpcpush_admin_validate_settings')) { WP_CLI::error('Install the version with separate administration pages first.'); }

global $wpcpush_atest_cases, $wpcpush_atest_users, $wpcpush_atest_http;
$wpcpush_atest_cases = [];
$wpcpush_atest_users = [];
$wpcpush_atest_http = [];
$wpcpush_atest_results = [];
$wpcpush_atest_user_before = get_current_user_id();
$wpcpush_atest_get_before = $_GET;
$wpcpush_atest_guard = static function ($preempt, array $args, string $url) {
    $GLOBALS['wpcpush_atest_http'][] = $url;
    return new WP_Error('wpcpush_admin_test_http_blocked', 'All external HTTP is blocked by admin integration checks.');
};
$wpcpush_atest_mail = static fn() => true;
add_filter('pre_http_request', $wpcpush_atest_guard, PHP_INT_MIN, 3);
add_filter('pre_wp_mail', $wpcpush_atest_mail, PHP_INT_MIN);
$wpcpush_atest_before = wpcpush_atest_snapshot();

function wpcpush_atest(string $name, callable $callback): void { $GLOBALS['wpcpush_atest_cases'][$name] = $callback; }
function wpcpush_atest_check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function wpcpush_atest_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) { throw new RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function wpcpush_atest_snapshot(): array
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = 'cron' ORDER BY option_name",
        $wpdb->esc_like('wpcpush_') . '%'
    ), ARRAY_A);
}
function wpcpush_atest_clear(bool $includeCron = false): void
{
    global $wpdb;
    foreach (wpcpush_atest_snapshot() as $row) {
        if (!$includeCron && $row['option_name'] === 'cron') { continue; }
        $wpdb->delete($wpdb->options, ['option_name' => $row['option_name']]);
        wp_cache_delete($row['option_name'], 'options');
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}
function wpcpush_atest_user(string $name): int
{
    global $wpcpush_atest_users;
    if (!isset($wpcpush_atest_users[$name])) {
        $id = wp_insert_user(['user_login' => 'cp_admin_fixture_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => 'administrator']);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $wpcpush_atest_users[$name] = (int)$id;
    }
    return $wpcpush_atest_users[$name];
}
function wpcpush_atest_fixture(array $changes = []): array
{
    wpcpush_write('weixin_account', ['token' => 'admin-fixture-weixin-token', 'account_id' => 'bot@im.bot', 'owner_id' => 'owner@im.wechat', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    wpcpush_write('weixin_sync', ['candidates' => ['owner@im.wechat'], 'context_tokens' => ['owner@im.wechat' => 'admin-fixture-context']]);
    wpcpush_save_config(array_replace(wpcpush_defaults(), [
        'qq_enabled' => true, 'qq_app_id' => '123456', 'qq_app_secret' => 'admin-fixture-qq-secret', 'qq_target_type' => 'c2c', 'qq_target_id' => 'ADMIN_OPENID',
        'weixin_enabled' => true, 'weixin_user_id' => 'owner@im.wechat', 'qq_manage_enabled' => true, 'weixin_manage_enabled' => true,
        'manager_user_id' => wpcpush_atest_user('original'), 'notify_pending' => true, 'notify_approved' => false, 'notify_admin' => true, 'include_link' => true,
    ], $changes));
    $config = wpcpush_config();
    wpcpush_write('qq_manager', ['identity' => wpcpush_qq_identity($config), 'target_type' => $config['qq_target_type'], 'target_id' => $config['qq_target_id'], 'actor_id' => 'BOUND_ACTOR']);
    wp_set_current_user(wpcpush_atest_user('editor'));
    return $config;
}
function wpcpush_atest_save(string $section, array $input): array
{
    $validated = wpcpush_admin_validate_settings($section, $input, wpcpush_config());
    wpcpush_atest_same([], $validated['errors'], 'Section validation failed: ' . implode(' ', $validated['errors']));
    wpcpush_save_config($validated['config']);
    return wpcpush_config();
}
function wpcpush_atest_preserved(array $before, array $after, array $allowed): void
{
    foreach ($before as $key => $value) {
        if (!in_array($key, $allowed, true)) { wpcpush_atest_same($value, $after[$key], 'Unrelated setting changed: ' . $key); }
    }
}
function wpcpush_atest_qq_input(array $changes = []): array
{
    $config = wpcpush_config();
    return array_replace(['qq_enabled' => $config['qq_enabled'] ? '1' : '', 'qq_app_id' => $config['qq_app_id'], 'qq_app_secret' => '', 'qq_target_type' => $config['qq_target_type'], 'qq_target_id' => $config['qq_target_id']], $changes);
}

wpcpush_atest('all five menu sections resolve to independent admin page URLs', static function (): void {
    $sections = ['notifications', 'qq', 'weixin', 'management', 'status'];
    wpcpush_atest_same($sections, array_keys(wpcpush_admin_sections()));
    foreach ($sections as $section) {
        $slug = 'comment-push' . ($section === 'notifications' ? '' : '-' . $section);
        $url = wpcpush_admin_url($section);
        parse_str((string)wp_parse_url($url, PHP_URL_QUERY), $query);
        wpcpush_atest_same($slug, $query['page']);
        wpcpush_atest_check(str_ends_with((string)wp_parse_url($url, PHP_URL_PATH), '/wp-admin/admin.php'));
        $_GET['page'] = $slug;
        wpcpush_atest_same($section, wpcpush_admin_section());
    }
    foreach (['comment-push-unknown', ['comment-push-qq']] as $unknown) {
        $_GET['page'] = $unknown;
        wpcpush_atest_same('notifications', wpcpush_admin_section());
    }
});

wpcpush_atest('notification save only changes its checkboxes and preserves the previously authorized manager', static function (): void {
    $before = wpcpush_atest_fixture();
    $after = wpcpush_atest_save('notifications', [
        'notify_approved' => '1', 'qq_enabled' => '', 'weixin_enabled' => '', 'qq_manage_enabled' => '', 'weixin_manage_enabled' => '',
        'qq_app_id' => '654321', 'qq_app_secret' => 'injected-secret', 'qq_target_id' => 'ATTACKER', 'clear_qq_app_secret' => '1',
        'manager_user_id' => wpcpush_atest_user('editor'), 'qq_manage_since' => '1',
    ]);
    wpcpush_atest_same(false, $after['notify_pending']);
    wpcpush_atest_same(true, $after['notify_approved']);
    wpcpush_atest_same(false, $after['notify_admin']);
    wpcpush_atest_same(false, $after['include_link']);
    wpcpush_atest_preserved($before, $after, ['notify_pending', 'notify_approved', 'notify_admin', 'include_link']);
});

wpcpush_atest('QQ save preserves blank secret management grants Weixin state and notification preferences', static function (): void {
    $before = wpcpush_atest_fixture();
    $after = wpcpush_atest_save('qq', wpcpush_atest_qq_input([
        'qq_enabled' => '', 'weixin_enabled' => '', 'qq_manage_enabled' => '', 'weixin_manage_enabled' => '',
        'notify_pending' => '', 'notify_approved' => '1', 'include_link' => '', 'manager_user_id' => wpcpush_atest_user('editor'),
    ]));
    wpcpush_atest_same(false, $after['qq_enabled']);
    wpcpush_atest_same('admin-fixture-qq-secret', $after['qq_app_secret']);
    wpcpush_atest_preserved($before, $after, ['qq_enabled']);
    wpcpush_atest_same('admin-fixture-weixin-token', wpcpush_read('weixin_account')['token']);
});

wpcpush_atest('Weixin save cannot overwrite QQ credentials other toggles or the authorized manager', static function (): void {
    $before = wpcpush_atest_fixture();
    $after = wpcpush_atest_save('weixin', [
        'qq_app_id' => '654321', 'qq_app_secret' => 'injected-secret', 'clear_qq_app_secret' => '1', 'qq_target_type' => 'group', 'qq_target_id' => 'ATTACKER',
        'qq_enabled' => '', 'qq_manage_enabled' => '', 'weixin_manage_enabled' => '', 'notify_admin' => '', 'manager_user_id' => wpcpush_atest_user('editor'),
        'weixin_user_id' => 'attacker@im.wechat',
    ]);
    wpcpush_atest_same(false, $after['weixin_enabled']);
    wpcpush_atest_preserved($before, $after, ['weixin_enabled']);
});

wpcpush_atest('only management settings explicitly authorize the current administrator and ignore forged identity fields', static function (): void {
    $before = wpcpush_atest_fixture();
    $after = wpcpush_atest_save('management', [
        'qq_manage_enabled' => '1', 'manager_user_id' => wpcpush_atest_user('original'), 'qq_manage_since' => '1', 'weixin_manage_since' => '1',
        'qq_app_id' => '654321', 'qq_app_secret' => 'injected-secret', 'qq_target_id' => 'ATTACKER', 'weixin_user_id' => 'attacker@im.wechat',
        'qq_enabled' => '', 'weixin_enabled' => '', 'notify_pending' => '',
    ]);
    wpcpush_atest_same(wpcpush_atest_user('editor'), $after['manager_user_id']);
    wpcpush_atest_same(true, $after['qq_manage_enabled']);
    wpcpush_atest_same(false, $after['weixin_manage_enabled']);
    wpcpush_atest_check($after['qq_manage_since'] >= time() - 5);
    wpcpush_atest_same(0, $after['weixin_manage_since']);
    wpcpush_atest_preserved($before, $after, ['qq_manage_enabled', 'weixin_manage_enabled', 'manager_user_id', 'qq_manage_since', 'weixin_manage_since']);
});

wpcpush_atest('notification save remains usable when unrelated legacy credentials and sessions are invalid', static function (): void {
    $before = wpcpush_atest_fixture(['qq_app_id' => 'legacy-invalid-id', 'qq_target_type' => 'group']);
    wpcpush_write('weixin_account', array_replace(wpcpush_read('weixin_account'), ['expired' => true]));
    wpcpush_write('qq_manager', []);
    $after = wpcpush_atest_save('notifications', ['notify_pending' => '1']);
    wpcpush_atest_preserved($before, $after, ['notify_pending', 'notify_approved', 'notify_admin', 'include_link']);
});

wpcpush_atest('QQ save is not blocked by an expired unrelated Weixin session', static function (): void {
    $before = wpcpush_atest_fixture();
    wpcpush_write('weixin_account', array_replace(wpcpush_read('weixin_account'), ['expired' => true]));
    $after = wpcpush_atest_save('qq', wpcpush_atest_qq_input());
    wpcpush_atest_same($before, $after);
});

wpcpush_atest('Weixin can be disabled even if unrelated QQ credentials or group management binding are invalid', static function (): void {
    $before = wpcpush_atest_fixture(['qq_app_id' => 'legacy-invalid-id', 'qq_target_type' => 'group']);
    wpcpush_write('qq_manager', []);
    wpcpush_write('weixin_account', array_replace(wpcpush_read('weixin_account'), ['expired' => true]));
    $after = wpcpush_atest_save('weixin', []);
    wpcpush_atest_same(false, $after['weixin_enabled']);
    wpcpush_atest_preserved($before, $after, ['weixin_enabled']);
});

wpcpush_atest('invalid section and the read-only status section cannot modify any plugin option', static function (): void {
    wpcpush_atest_fixture();
    foreach (['status', '', 'unknown', '../qq', 'QQ'] as $section) {
        $before = wpcpush_atest_snapshot();
        $thrown = false;
        try { wpcpush_admin_validate_settings($section, ['qq_app_secret' => 'injected-secret', 'qq_manage_enabled' => '1'], wpcpush_config()); }
        catch (InvalidArgumentException) { $thrown = true; }
        wpcpush_atest_check($thrown, 'Unknown section was accepted');
        wpcpush_atest_same($before, wpcpush_atest_snapshot());
    }
});

wpcpush_atest('section validation never writes to the database or sends HTTP requests', static function (): void {
    wpcpush_atest_fixture();
    foreach (['notifications' => ['notify_pending' => '1'], 'qq' => wpcpush_atest_qq_input(), 'weixin' => ['weixin_enabled' => '1'], 'management' => ['qq_manage_enabled' => '1']] as $section => $input) {
        $before = wpcpush_atest_snapshot();
        wpcpush_admin_validate_settings($section, $input, wpcpush_config());
        wpcpush_atest_same($before, wpcpush_atest_snapshot());
    }
    wpcpush_atest_same([], $GLOBALS['wpcpush_atest_http']);
});

wpcpush_atest('invalid settings report validation errors while leaving persisted configuration intact', static function (): void {
    wpcpush_atest_fixture();
    foreach ([['notifications', []], ['qq', wpcpush_atest_qq_input(['qq_app_id' => 'bad-id'])]] as [$section, $input]) {
        $before = wpcpush_atest_snapshot();
        $validated = wpcpush_admin_validate_settings($section, $input, wpcpush_config());
        wpcpush_atest_check($validated['errors'] !== [], 'Invalid settings were accepted');
        wpcpush_atest_same($before, wpcpush_atest_snapshot());
    }
});

wpcpush_atest('changing QQ credentials through its own page revokes QQ management but preserves other page settings', static function (): void {
    $before = wpcpush_atest_fixture();
    wpcpush_write('qq_token', ['token' => 'obsolete-token']);
    $after = wpcpush_atest_save('qq', wpcpush_atest_qq_input(['qq_app_secret' => 'replacement-secret']));
    wpcpush_atest_same('replacement-secret', $after['qq_app_secret']);
    wpcpush_atest_same(false, $after['qq_manage_enabled']);
    wpcpush_atest_same(0, $after['qq_manage_since']);
    wpcpush_atest_same([], wpcpush_read('qq_manager'));
    wpcpush_atest_same([], wpcpush_read('qq_token'));
    wpcpush_atest_preserved($before, $after, ['qq_app_secret', 'qq_manage_enabled', 'qq_manage_since']);
});

try {
    foreach ($wpcpush_atest_cases as $name => $callback) {
        wpcpush_atest_clear();
        $GLOBALS['wpcpush_atest_http'] = [];
        wp_set_current_user(0);
        $level = ob_get_level();
        ob_start();
        try { $callback(); $wpcpush_atest_results[] = 'PASS ' . $name; }
        catch (Throwable $error) { $wpcpush_atest_results[] = 'FAIL ' . $name . "\n     " . $error->getMessage(); }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
} finally {
    try {
        wp_set_current_user(0);
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        foreach ($wpcpush_atest_users as $id) { wp_delete_user($id); }
        wpcpush_atest_clear(true);
        global $wpdb;
        foreach ($wpcpush_atest_before as $row) {
            if ($wpdb->insert($wpdb->options, $row) === false) { throw new RuntimeException('Could not restore original option: ' . $row['option_name']); }
            wp_cache_delete($row['option_name'], 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wpcpush_atest_same($wpcpush_atest_before, wpcpush_atest_snapshot(), 'Original plugin options or cron were not restored');
    } finally {
        $_GET = $wpcpush_atest_get_before;
        wp_set_current_user($wpcpush_atest_user_before);
        remove_filter('pre_wp_mail', $wpcpush_atest_mail, PHP_INT_MIN);
        remove_filter('pre_http_request', $wpcpush_atest_guard, PHP_INT_MIN);
    }
}
$wpcpush_atest_failed = count(array_filter($wpcpush_atest_results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $wpcpush_atest_results) . "\n\n" . count($wpcpush_atest_results) . ' admin checks, ' . $wpcpush_atest_failed . " failures.\n";
WP_CLI::halt($wpcpush_atest_failed === 0 ? 0 : 1);
