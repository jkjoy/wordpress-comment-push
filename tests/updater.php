<?php

/** Offline GitHub updater integration checks. Never installs or replaces a plugin. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) { http_response_code(403); exit; }
if (!function_exists('wpcpush_updater_release')) { WP_CLI::error('Activate the version with GitHub updates first.'); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

global $wpcpush_utest_cases, $wpcpush_utest_http, $wpcpush_utest_responses, $wpcpush_utest_wporg_response, $wpcpush_utest_fixtures;
$wpcpush_utest_cases = [];
$wpcpush_utest_http = [];
$wpcpush_utest_responses = [];
$wpcpush_utest_wporg_response = null;
$wpcpush_utest_fixtures = [];
$wpcpush_utest_results = [];
$wpcpush_utest_user_before = get_current_user_id();
$wpcpush_utest_get_before = $_GET;
$wpcpush_utest_had_filesystem = array_key_exists('wp_filesystem', $GLOBALS);
$wpcpush_utest_filesystem_before = $GLOBALS['wp_filesystem'] ?? null;
$wpcpush_utest_guard = static function ($preempt, array $args, string $url) {
    $GLOBALS['wpcpush_utest_http'][] = ['url' => $url, 'args' => $args];
    if (str_starts_with($url, 'https://api.github.com/')) {
        return $GLOBALS['wpcpush_utest_responses'] ? array_shift($GLOBALS['wpcpush_utest_responses']) : new WP_Error('updater_test_unexpected_request', 'No offline GitHub fixture is queued.');
    }
    if (str_contains($url, 'api.wordpress.org/plugins/update-check/')) {
        if ($GLOBALS['wpcpush_utest_wporg_response'] !== null) { return $GLOBALS['wpcpush_utest_wporg_response']; }
        return wpcpush_utest_response(['plugins' => [], 'translations' => [], 'no_update' => []]);
    }
    return new WP_Error('updater_test_external_http_blocked', 'All external HTTP is blocked by updater checks.');
};
$wpcpush_utest_mail = static fn() => true;
add_filter('pre_http_request', $wpcpush_utest_guard, PHP_INT_MIN, 3);
add_filter('pre_wp_mail', $wpcpush_utest_mail, PHP_INT_MIN);
$wpcpush_utest_before = wpcpush_utest_snapshot();

function wpcpush_utest(string $name, callable $callback): void { $GLOBALS['wpcpush_utest_cases'][$name] = $callback; }
function wpcpush_utest_check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function wpcpush_utest_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) { throw new RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function wpcpush_utest_snapshot(): array
{
    global $wpdb;
    return $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN (
        '_site_transient_wpcpush_release', '_site_transient_timeout_wpcpush_release',
        '_site_transient_update_plugins', '_site_transient_timeout_update_plugins'
    ) ORDER BY option_name", ARRAY_A);
}
function wpcpush_utest_clear(): void
{
    delete_site_transient('update_plugins');
    delete_site_transient('wpcpush_release');
    $GLOBALS['wpcpush_utest_http'] = [];
    $GLOBALS['wpcpush_utest_responses'] = [];
    $GLOBALS['wpcpush_utest_wporg_response'] = null;
}
function wpcpush_utest_response(array $data, int $status = 200): array
{
    return ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => $status, 'message' => 'Offline fixture'], 'cookies' => [], 'filename' => null];
}
function wpcpush_utest_headers(): array { return get_plugin_data(WPCPUSH_FILE, false, false); }
function wpcpush_utest_repo(): string { return rtrim(wpcpush_utest_headers()['UpdateURI'], '/'); }
function wpcpush_utest_asset(string $tag = 'v1.2.0'): string { return wpcpush_utest_repo() . '/releases/download/' . $tag . '/comment-push.zip'; }
function wpcpush_utest_release(string $tag = 'v1.2.0', array $changes = []): array
{
    return array_replace([
        'id' => 1234, 'tag_name' => $tag, 'name' => 'QQ / 微信评论推送 ' . $tag, 'draft' => false, 'prerelease' => false,
        'html_url' => wpcpush_utest_repo() . '/releases/tag/' . $tag, 'published_at' => '2026-10-24T06:00:00Z',
        'body' => "## 更新\n\n新增 GitHub 更新能力。", 'zipball_url' => wpcpush_utest_repo() . '/zipball/' . $tag,
        'assets' => [['name' => 'comment-push.zip', 'state' => 'uploaded', 'content_type' => 'application/zip', 'size' => 1024, 'browser_download_url' => wpcpush_utest_asset($tag)]],
    ], $changes);
}
function wpcpush_utest_queue(array $data, int $status = 200): void { $GLOBALS['wpcpush_utest_responses'][] = wpcpush_utest_response($data, $status); }
function wpcpush_utest_github_calls(): array
{
    return array_values(array_filter($GLOBALS['wpcpush_utest_http'], static fn(array $call): bool => str_starts_with($call['url'], 'https://api.github.com/')));
}
function wpcpush_utest_ttl(): int { return (int)get_site_option('_site_transient_timeout_wpcpush_release') - time(); }
function wpcpush_utest_package(string $php = '8.0', string $wordpress = '6.4'): string
{
    $directory = wp_normalize_path(WP_CONTENT_DIR) . '/wpcpush-updater-fixture-' . bin2hex(random_bytes(8));
    if (!mkdir($directory)) { throw new RuntimeException('Could not create the temporary package fixture'); }
    $GLOBALS['wpcpush_utest_fixtures'][] = $directory;
    $headers = "<?php\n/**\n * Plugin Name: Offline Comment Push Package Fixture\n * Version: 99.0.0\n * Requires PHP: {$php}\n * Requires at least: {$wordpress}\n */\n";
    if (file_put_contents($directory . '/comment-push.php', $headers) === false) { throw new RuntimeException('Could not write the temporary package fixture'); }
    return trailingslashit($directory);
}
function wpcpush_utest_package_checker(): Plugin_Upgrader
{
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
    $GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct(false);
    $upgrader = new Plugin_Upgrader();
    // init() would schedule backup cleanup; the package reader only needs strings.
    $upgrader->generic_strings();
    return $upgrader;
}

wpcpush_utest('plugin headers register the fixed GitHub updater and local author identity', static function (): void {
    $headers = wpcpush_utest_headers();
    wpcpush_utest_same('1.2.0', $headers['Version']);
    wpcpush_utest_same('https://github.com/jkjoy/wordpress-comment-push', $headers['UpdateURI']);
    wpcpush_utest_same('老孙', $headers['Author']);
    wpcpush_utest_same('https://www.imsun.org/', $headers['AuthorURI']);
    wpcpush_utest_check(has_filter('update_plugins_github.com', 'wpcpush_updater_update') !== false);
    wpcpush_utest_check(has_filter('plugins_api', 'wpcpush_updater_information') !== false);
});

wpcpush_utest('stable release metadata uses the specified uploaded ZIP and local requirements', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release());
    $release = wpcpush_updater_release();
    wpcpush_utest_check(is_array($release));
    wpcpush_utest_same('1.2.0', $release['version']);
    wpcpush_utest_same('v1.2.0', $release['tag']);
    wpcpush_utest_same(wpcpush_utest_asset(), $release['package']);
    wpcpush_utest_same(wpcpush_utest_repo() . '/releases/tag/v1.2.0', $release['url']);
    wpcpush_utest_same('2026-10-24 06:00:00', $release['last_updated']);
    wpcpush_utest_same('6.4', $release['requires']);
    wpcpush_utest_same('8.0', $release['requires_php']);
    $calls = wpcpush_utest_github_calls();
    wpcpush_utest_same(1, count($calls));
    wpcpush_utest_same('https://api.github.com/repos/jkjoy/wordpress-comment-push/releases/latest', $calls[0]['url']);
    wpcpush_utest_same('GET', $calls[0]['args']['method']);
    wpcpush_utest_check(empty($calls[0]['args']['headers']['Authorization']), 'Updater must not reuse QQ or Weixin credentials');
});

wpcpush_utest('draft prerelease and malformed stable version tags never become update metadata', static function (): void {
    foreach ([['draft' => true], ['prerelease' => true], ['draft' => 'false'], ['tag_name' => 'v1.2'], ['tag_name' => 'v1.2.0-rc.1'], ['tag_name' => '../v1.2.0'], ['tag_name' => ['v1.2.0']]] as $changes) {
        delete_site_transient('wpcpush_release');
        wpcpush_utest_queue(wpcpush_utest_release('v1.2.0', $changes));
        wpcpush_utest_same(null, wpcpush_updater_release(), 'Accepted unsafe release metadata');
    }
});

wpcpush_utest('source archives missing uploads and differently named ZIPs cannot replace the release asset', static function (): void {
    foreach ([[], [['name' => 'source.zip', 'state' => 'uploaded', 'browser_download_url' => wpcpush_utest_asset()]], [['name' => 'comment-push.zip', 'state' => 'new', 'browser_download_url' => wpcpush_utest_asset()]]] as $assets) {
        delete_site_transient('wpcpush_release');
        wpcpush_utest_queue(wpcpush_utest_release('v1.2.0', ['assets' => $assets]));
        wpcpush_utest_same(null, wpcpush_updater_release(), 'Source archive or incomplete asset was accepted');
    }
});

wpcpush_utest('asset trust is limited to the exact repository tag and package path', static function (): void {
    $repoPath = 'jkjoy/wordpress-comment-push';
    $paths = [
        'https://evil.example/' . $repoPath . '/releases/download/v1.2.0/comment-push.zip',
        'http://github.com/' . $repoPath . '/releases/download/v1.2.0/comment-push.zip',
        'https://github.com.evil.example/' . $repoPath . '/releases/download/v1.2.0/comment-push.zip',
        'https://github.com/other-owner/wordpress-comment-push/releases/download/v1.2.0/comment-push.zip',
        'https://github.com/jkjoy/other-repository/releases/download/v1.2.0/comment-push.zip',
        wpcpush_utest_repo() . '/releases/download/v1.2.1/comment-push.zip',
        wpcpush_utest_repo() . '/releases/download/v1.2.0/another.zip',
        wpcpush_utest_asset() . '?redirect=https://evil.example',
        wpcpush_utest_repo() . '/archive/refs/tags/v1.2.0.zip',
    ];
    foreach ($paths as $url) {
        delete_site_transient('wpcpush_release');
        wpcpush_utest_queue(wpcpush_utest_release('v1.2.0', ['assets' => [['name' => 'comment-push.zip', 'state' => 'uploaded', 'browser_download_url' => $url]]]));
        wpcpush_utest_same(null, wpcpush_updater_release(), 'Accepted untrusted package path: ' . $url);
    }
});

wpcpush_utest('release details URLs cannot redirect plugin information to another repository', static function (): void {
    foreach (['https://evil.example/releases/tag/v1.2.0', 'https://github.com/other/repository/releases/tag/v1.2.0', wpcpush_utest_repo() . '/releases/tag/v1.2.1'] as $url) {
        delete_site_transient('wpcpush_release');
        wpcpush_utest_queue(wpcpush_utest_release('v1.2.0', ['html_url' => $url]));
        wpcpush_utest_same(null, wpcpush_updater_release());
    }
});

wpcpush_utest('successful metadata is cached for six hours and explicit refresh fetches new data', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release());
    $first = wpcpush_updater_release();
    wpcpush_utest_same($first, wpcpush_updater_release());
    wpcpush_utest_same(1, count(wpcpush_utest_github_calls()));
    wpcpush_utest_check(wpcpush_utest_ttl() >= 21590 && wpcpush_utest_ttl() <= 21600, 'Successful release cache TTL is not six hours');
    wpcpush_utest_queue(wpcpush_utest_release('v1.2.1'));
    wpcpush_utest_same('1.2.1', wpcpush_updater_release(true)['version']);
    wpcpush_utest_same(2, count(wpcpush_utest_github_calls()));
});

wpcpush_utest('GitHub errors are cached briefly and never expose remote error payloads as release data', static function (): void {
    wpcpush_utest_queue(['message' => 'PRIVATE_GITHUB_ERROR_MUST_NOT_RENDER'], 403);
    wpcpush_utest_same(null, wpcpush_updater_release());
    wpcpush_utest_same(null, wpcpush_updater_release());
    wpcpush_utest_same(1, count(wpcpush_utest_github_calls()));
    wpcpush_utest_check(wpcpush_utest_ttl() >= 590 && wpcpush_utest_ttl() <= 600, 'Failure cache TTL is not ten minutes');
    wpcpush_utest_check(!str_contains(wp_json_encode(get_site_transient('wpcpush_release')), 'PRIVATE_GITHUB_ERROR'));
});

wpcpush_utest('network errors and malformed JSON cannot masquerade as an available update', static function (): void {
    $malformed = wpcpush_utest_response([]);
    $malformed['body'] = '{invalid-json';
    foreach ([new WP_Error('http_request_failed', 'simulated timeout'), $malformed] as $fixture) {
        delete_site_transient('wpcpush_release');
        $GLOBALS['wpcpush_utest_responses'][] = $fixture;
        wpcpush_utest_same(null, wpcpush_updater_release());
    }
});

wpcpush_utest('the native hostname filter leaves every other GitHub plugin untouched', static function (): void {
    $sentinel = ['version' => '42.0.0', 'package' => 'https://example.invalid/existing-provider.zip'];
    $headers = wpcpush_utest_headers();
    wpcpush_utest_same($sentinel, apply_filters('update_plugins_github.com', $sentinel, $headers, 'another-plugin/another-plugin.php', ['zh_CN']));
    wpcpush_utest_same(false, apply_filters('update_plugins_github.com', false, $headers, 'another-plugin/comment-push.php', []));
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

wpcpush_utest('the native hostname filter supplies a WordPress-compatible update payload only for this plugin', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release('v99.0.0'));
    $update = apply_filters('update_plugins_github.com', false, wpcpush_utest_headers(), plugin_basename(WPCPUSH_FILE), ['zh_CN']);
    wpcpush_utest_check(is_array($update));
    wpcpush_utest_same('comment-push', $update['slug']);
    wpcpush_utest_same('99.0.0', $update['version']);
    wpcpush_utest_same(wpcpush_utest_asset('v99.0.0'), $update['package']);
    wpcpush_utest_same('6.4', $update['requires']);
    wpcpush_utest_same('8.0', $update['requires_php']);
});

wpcpush_utest('WordPress core places newer GitHub releases in the native update response', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release('v99.0.0'));
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    $file = plugin_basename(WPCPUSH_FILE);
    wpcpush_utest_check(isset($updates->response[$file]), 'WordPress did not expose the newer GitHub release');
    wpcpush_utest_same('99.0.0', $updates->response[$file]->new_version);
    wpcpush_utest_same(wpcpush_utest_asset('v99.0.0'), $updates->response[$file]->package);
    wpcpush_utest_check(!isset($updates->no_update[$file]));
});

wpcpush_utest('WordPress core never offers a downgrade or same-version reinstall as an upgrade', static function (): void {
    foreach (['v1.0.0', 'v1.2.0'] as $tag) {
        delete_site_transient('update_plugins');
        wpcpush_utest_queue(wpcpush_utest_release($tag));
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $file = plugin_basename(WPCPUSH_FILE);
        wpcpush_utest_check(!isset($updates->response[$file]), 'WordPress offered a downgrade or same-version update');
    }
});

wpcpush_utest('WordPress core still exposes GitHub updates when WordPress.org fails and preserves unrelated plugin data', static function (): void {
    foreach ([new WP_Error('http_request_failed', 'simulated WordPress.org outage'), wpcpush_utest_response(['error' => 'Unavailable'], 503)] as $failure) {
        wpcpush_utest_clear();
        $GLOBALS['wpcpush_utest_wporg_response'] = $failure;
        wpcpush_utest_queue(wpcpush_utest_release('v99.0.0'));
        $otherUpdate = (object)['plugin' => 'other-updated/plugin.php', 'new_version' => '7.0.0', 'package' => 'https://example.invalid/unrelated-update.zip'];
        $otherCurrent = (object)['plugin' => 'other-current/plugin.php', 'new_version' => '2.0.0'];
        set_site_transient('update_plugins', (object)[
            'last_checked' => 0, 'checked' => ['other-updated/plugin.php' => '6.0.0'],
            'response' => ['other-updated/plugin.php' => $otherUpdate], 'no_update' => ['other-current/plugin.php' => $otherCurrent],
            'translations' => [['slug' => 'unrelated-translation', 'language' => 'zh_CN']],
        ]);
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $file = plugin_basename(WPCPUSH_FILE);
        wpcpush_utest_check(isset($updates->response[$file]), 'GitHub update disappeared when WordPress.org was unavailable');
        wpcpush_utest_same('99.0.0', $updates->response[$file]->new_version);
        wpcpush_utest_same((array)$otherUpdate, (array)$updates->response['other-updated/plugin.php']);
        wpcpush_utest_same((array)$otherCurrent, (array)$updates->no_update['other-current/plugin.php']);
        wpcpush_utest_same('6.0.0', $updates->checked['other-updated/plugin.php']);
        wpcpush_utest_same([['slug' => 'unrelated-translation', 'language' => 'zh_CN']], $updates->translations);
        wpcpush_utest_same(1, count(wpcpush_utest_github_calls()), 'Multiple transient writes caused duplicate GitHub HTTP calls');
        wpcpush_utest_check(count(array_filter($GLOBALS['wpcpush_utest_http'], static fn(array $call): bool => str_contains($call['url'], 'api.wordpress.org/plugins/update-check/'))) >= 1, 'The core WordPress.org failure branch was not exercised');
    }
});

wpcpush_utest('WordPress.org failure still records equal and lower GitHub versions as no update', static function (): void {
    foreach (['v1.0.0', 'v1.2.0'] as $tag) {
        wpcpush_utest_clear();
        $GLOBALS['wpcpush_utest_wporg_response'] = wpcpush_utest_response([], 503);
        wpcpush_utest_queue(wpcpush_utest_release($tag));
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $file = plugin_basename(WPCPUSH_FILE);
        wpcpush_utest_check(!isset($updates->response[$file]), 'Outage fallback offered an equal or older version as an upgrade');
        wpcpush_utest_check(isset($updates->no_update[$file]), 'Outage fallback did not record the installed plugin as checked');
        wpcpush_utest_same(substr($tag, 1), $updates->no_update[$file]->new_version);
        wpcpush_utest_same(wpcpush_utest_headers()['Version'], $updates->checked[$file]);
        wpcpush_utest_same(1, count(wpcpush_utest_github_calls()));
    }
});

wpcpush_utest('GitHub fallback transient merging does not recurse or discard other providers when GitHub is unavailable', static function (): void {
    $maxDepth = 0;
    $observe = static function ($value) use (&$maxDepth) {
        global $wp_current_filter;
        $depth = count(array_filter($wp_current_filter, static fn(string $hook): bool => $hook === 'pre_set_site_transient_update_plugins'));
        $maxDepth = max($maxDepth, $depth);
        if ($depth > 2) { throw new RuntimeException('Update transient filter recursively rewrote its own transient'); }
        return $value;
    };
    add_filter('pre_set_site_transient_update_plugins', $observe, 1);
    try {
        wpcpush_utest_queue(['message' => 'Not Found'], 404);
        $other = (object)['last_checked' => 0, 'response' => ['unrelated/plugin.php' => (object)['new_version' => '4.0.0']], 'no_update' => [], 'checked' => ['unrelated/plugin.php' => '3.0.0']];
        set_site_transient('update_plugins', $other);
        set_site_transient('update_plugins', $other);
        wpcpush_utest_same(serialize($other), serialize(get_site_transient('update_plugins')));
        wpcpush_utest_same(1, $maxDepth, 'Transient filter entered recursively');
        wpcpush_utest_same(1, count(wpcpush_utest_github_calls()), 'Failure cache was not shared between transient writes');
    } finally { remove_filter('pre_set_site_transient_update_plugins', $observe, 1); }
});

wpcpush_utest('plugin information uses local author requirements and safely displays hostile release notes', static function (): void {
    $body = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">危险链接</a>中文更新';
    wpcpush_utest_queue(wpcpush_utest_release('v1.2.0', ['body' => $body]));
    $info = plugins_api('plugin_information', ['slug' => 'comment-push']);
    wpcpush_utest_check(is_object($info) && !is_wp_error($info));
    wpcpush_utest_same('comment-push', $info->slug);
    wpcpush_utest_same('1.2.0', $info->version);
    wpcpush_utest_same('6.4', $info->requires);
    wpcpush_utest_same('8.0', $info->requires_php);
    wpcpush_utest_same(wpcpush_utest_asset(), $info->download_link);
    wpcpush_utest_check(str_contains($info->author, '老孙') && str_contains($info->author, 'https://www.imsun.org/'));
    $html = implode("\n", $info->sections);
    wpcpush_utest_check(str_contains($html, '中文更新'));
    wpcpush_utest_check(!preg_match('/<(?:script|iframe|object|embed)\b/i', $html), 'Unsafe executable tag in plugin information');
    wpcpush_utest_check(!preg_match('/<[^>]+\bon(?:error|load|click)\s*=/i', $html), 'Unsafe event attribute in plugin information');
    wpcpush_utest_check(!preg_match('/<[^>]+\bhref\s*=\s*[\'"]?javascript:/i', $html), 'Unsafe URL in plugin information');
});

wpcpush_utest('plugin information filter isolates unrelated slugs actions and earlier providers', static function (): void {
    $sentinel = (object)['name' => 'Existing unrelated provider'];
    foreach ([['plugin_information', (object)['slug' => 'another-plugin']], ['query_plugins', (object)['slug' => 'comment-push']], ['plugin_information', (object)['slug' => ['comment-push']]]] as [$action, $args]) {
        wpcpush_utest_same($sentinel, apply_filters('plugins_api', $sentinel, $action, $args));
    }
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

wpcpush_utest('an unavailable own release returns an error instead of consulting an unrelated WordPress.org slug', static function (): void {
    wpcpush_utest_queue(['message' => 'Not Found'], 404);
    $info = plugins_api('plugin_information', ['slug' => 'comment-push']);
    wpcpush_utest_check(is_wp_error($info));
    wpcpush_utest_same(1, count($GLOBALS['wpcpush_utest_http']));
    wpcpush_utest_check(str_starts_with($GLOBALS['wpcpush_utest_http'][0]['url'], 'https://api.github.com/'));
});

wpcpush_utest('clearing native plugin updates also invalidates the separate GitHub release cache', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release());
    wpcpush_updater_release();
    set_site_transient('update_plugins', (object)['last_checked' => time()]);
    delete_site_transient('update_plugins');
    wpcpush_utest_same(false, get_site_transient('wpcpush_release'));
    wpcpush_utest_queue(wpcpush_utest_release('v1.2.1'));
    wpcpush_utest_same('1.2.1', wpcpush_updater_release()['version']);
});

wpcpush_utest('manual check-again clears caches only for a user allowed to update plugins', static function (): void {
    wpcpush_utest_queue(wpcpush_utest_release());
    wpcpush_updater_release();
    set_site_transient('update_plugins', (object)['last_checked' => time()]);
    $_GET['force-check'] = '1';
    wp_set_current_user(0);
    wpcpush_updater_maybe_refresh();
    wpcpush_utest_check(get_site_transient('wpcpush_release') !== false, 'Anonymous caller invalidated the release cache');
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wpcpush_utest_check($admins !== [], 'No local administrator is available for the manual update check');
    wp_set_current_user((int)$admins[0]);
    wpcpush_updater_maybe_refresh();
    wpcpush_utest_same(false, get_site_transient('wpcpush_release'));
    wpcpush_utest_same(false, get_site_transient('update_plugins'));
    wpcpush_utest_same(1, count(wpcpush_utest_github_calls()), 'Clearing caches should not itself send a request');
});

wpcpush_utest('source selection validates compatible downloaded plugin headers with the native package checker', static function (): void {
    $source = wpcpush_utest_package();
    $checker = wpcpush_utest_package_checker();
    $result = apply_filters('upgrader_source_selection', $source, $source, $checker, ['plugin' => plugin_basename(WPCPUSH_FILE), 'type' => 'plugin', 'action' => 'update']);
    wpcpush_utest_same($source, $result);
    wpcpush_utest_same('Offline Comment Push Package Fixture', $checker->new_plugin_data['Name']);
    wpcpush_utest_same('8.0', $checker->new_plugin_data['RequiresPHP']);
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

wpcpush_utest('a downloaded future release requiring PHP 99 is rejected before installation', static function (): void {
    $source = wpcpush_utest_package('99.0');
    $checker = wpcpush_utest_package_checker();
    $result = apply_filters('upgrader_source_selection', $source, $source, $checker, ['plugin' => plugin_basename(WPCPUSH_FILE), 'action' => 'update']);
    wpcpush_utest_check(is_wp_error($result));
    wpcpush_utest_same('incompatible_php_required_version', $result->get_error_code());
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

wpcpush_utest('a downloaded future release requiring WordPress 99 is rejected before installation', static function (): void {
    $source = wpcpush_utest_package('8.0', '99.0');
    $checker = wpcpush_utest_package_checker();
    $result = apply_filters('upgrader_source_selection', $source, $source, $checker, ['plugin' => plugin_basename(WPCPUSH_FILE), 'action' => 'update']);
    wpcpush_utest_check(is_wp_error($result));
    wpcpush_utest_same('incompatible_wp_required_version', $result->get_error_code());
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

wpcpush_utest('source selection preserves existing errors and leaves unrelated installation targets untouched', static function (): void {
    $source = wpcpush_utest_package('99.0', '99.0');
    $checker = wpcpush_utest_package_checker();
    $own = plugin_basename(WPCPUSH_FILE);
    wpcpush_utest_same($source, apply_filters('upgrader_source_selection', $source, $source, $checker, ['plugin' => 'another/plugin.php', 'action' => 'update']));
    wpcpush_utest_same($source, apply_filters('upgrader_source_selection', $source, $source, $checker, ['plugin' => $own, 'action' => 'install']));
    wpcpush_utest_same($source, apply_filters('upgrader_source_selection', $source, $source, new stdClass(), ['plugin' => $own, 'action' => 'update']));
    $error = new WP_Error('prior_package_error', 'Existing package error must survive unchanged.');
    wpcpush_utest_same($error, apply_filters('upgrader_source_selection', $error, $source, $checker, ['plugin' => $own, 'action' => 'update']));
    wpcpush_utest_same([], $checker->new_plugin_data);
    wpcpush_utest_same([], $GLOBALS['wpcpush_utest_http']);
});

try {
    foreach ($wpcpush_utest_cases as $name => $callback) {
        wpcpush_utest_clear();
        $level = ob_get_level();
        ob_start();
        try { $callback(); $wpcpush_utest_results[] = 'PASS ' . $name; }
        catch (Throwable $error) { $wpcpush_utest_results[] = 'FAIL ' . $name . "\n     " . $error->getMessage(); }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
} finally {
    try {
        foreach ($wpcpush_utest_fixtures as $directory) {
            $file = $directory . '/comment-push.php';
            if (is_file($file) && !unlink($file)) { throw new RuntimeException('Could not remove temporary package fixture file'); }
            if (is_dir($directory) && !rmdir($directory)) { throw new RuntimeException('Could not remove temporary package fixture directory'); }
        }
        wpcpush_utest_clear();
        global $wpdb;
        foreach ($wpcpush_utest_before as $row) {
            if ($wpdb->insert($wpdb->options, $row) === false) { throw new RuntimeException('Could not restore original transient: ' . $row['option_name']); }
            wp_cache_delete($row['option_name'], 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wpcpush_utest_same($wpcpush_utest_before, wpcpush_utest_snapshot(), 'Updater transient restoration did not match the original snapshot');
    } finally {
        $_GET = $wpcpush_utest_get_before;
        wp_set_current_user($wpcpush_utest_user_before);
        if ($wpcpush_utest_had_filesystem) { $GLOBALS['wp_filesystem'] = $wpcpush_utest_filesystem_before; }
        else { unset($GLOBALS['wp_filesystem']); }
        remove_filter('pre_wp_mail', $wpcpush_utest_mail, PHP_INT_MIN);
        remove_filter('pre_http_request', $wpcpush_utest_guard, PHP_INT_MIN);
    }
}
$wpcpush_utest_failed = count(array_filter($wpcpush_utest_results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $wpcpush_utest_results) . "\n\n" . count($wpcpush_utest_results) . ' updater checks, ' . $wpcpush_utest_failed . " failures.\n";
WP_CLI::halt($wpcpush_utest_failed === 0 ? 0 : 1);
