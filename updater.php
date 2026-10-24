<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

const WPCPUSH_UPDATE_REPOSITORY = 'https://github.com/jkjoy/wordpress-comment-push';
const WPCPUSH_UPDATE_API = 'https://api.github.com/repos/jkjoy/wordpress-comment-push/releases/latest';

function wpcpush_updater_version(string $tag): ?string
{
    if (strlen($tag) > 128 || !preg_match('/\Av?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/', $tag, $matches)) {
        return null;
    }
    // Stable SemVer precedence ignores build metadata.
    return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
}

function wpcpush_updater_requirements(): array
{
    $headers = get_file_data(WPCPUSH_FILE, ['requires' => 'Requires at least', 'requires_php' => 'Requires PHP']);
    $requirements = ['requires' => '6.4', 'requires_php' => '8.0'];
    foreach ($requirements as $key => $fallback) {
        if (is_string($headers[$key] ?? null) && preg_match('/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $headers[$key])) {
            $requirements[$key] = $headers[$key];
        }
    }
    return $requirements;
}

function wpcpush_updater_release(bool $refresh = false): ?array
{
    if ($refresh) { wpcpush_updater_clear(); }
    $cached = get_site_transient('wpcpush_release');
    if (is_array($cached) && array_key_exists('release', $cached)) {
        return is_array($cached['release']) ? $cached['release'] : null;
    }

    $release = null;
    try {
        $response = wp_remote_get(WPCPUSH_UPDATE_API, [
            'timeout' => 4,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 524288,
            // The WordPress default user agent contains the site URL; this request needs no site data.
            'user-agent' => 'WordPressCommentPush-Updater',
            'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'],
        ]);
        if (!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true, 32, JSON_BIGINT_AS_STRING);
            if (is_array($data)) { $release = wpcpush_updater_normalize_release($data); }
        }
    } catch (Throwable) {
        // A failed update check must not expose a response, credentials, or interrupt WordPress.
        $release = null;
    }
    set_site_transient('wpcpush_release', ['release' => $release ?? false], $release === null ? 600 : 21600);
    return $release;
}

function wpcpush_updater_normalize_release(array $data): ?array
{
    $tag = $data['tag_name'] ?? null;
    if (($data['draft'] ?? null) !== false || ($data['prerelease'] ?? null) !== false || !is_string($tag)) { return null; }
    $version = wpcpush_updater_version($tag);
    if ($version === null) { return null; }
    $tagPaths = array_unique([$tag, rawurlencode($tag)]);
    $releaseUrls = array_map(static fn(string $value): string => WPCPUSH_UPDATE_REPOSITORY . '/releases/tag/' . $value, $tagPaths);
    $packageUrls = array_map(static fn(string $value): string => WPCPUSH_UPDATE_REPOSITORY . '/releases/download/' . $value . '/comment-push.zip', $tagPaths);
    if (!is_string($data['html_url'] ?? null) || !in_array($data['html_url'], $releaseUrls, true)) { return null; }
    $package = '';
    foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
        if (is_array($asset) && ($asset['name'] ?? '') === 'comment-push.zip' && ($asset['state'] ?? '') === 'uploaded'
            && is_string($asset['browser_download_url'] ?? null) && in_array($asset['browser_download_url'], $packageUrls, true)) {
            $package = $asset['browser_download_url'];
            break;
        }
    }
    if ($package === '') { return null; }
    $body = is_string($data['body'] ?? null) ? sanitize_textarea_field(substr($data['body'], 0, 32768)) : '';
    $lastUpdated = '';
    if (is_string($data['published_at'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $data['published_at'])) {
        try {
            $date = new DateTimeImmutable($data['published_at']);
            $lastUpdated = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {}
    }
    return array_merge([
        'version' => $version, 'tag' => $tag, 'url' => WPCPUSH_UPDATE_REPOSITORY . '/releases/tag/' . rawurlencode($tag),
        'package' => $package, 'body' => $body, 'last_updated' => $lastUpdated,
    ], wpcpush_updater_requirements());
}

function wpcpush_updater_update($update, array $pluginData, string $pluginFile, array $locales)
{
    if ($pluginFile !== plugin_basename(WPCPUSH_FILE) || ($pluginData['UpdateURI'] ?? '') !== WPCPUSH_UPDATE_REPOSITORY) { return $update; }
    $release = wpcpush_updater_release();
    if ($release === null) { return $update; }
    // Downloaded package requirements are checked separately before replacing the installed plugin.
    return [
        'id' => WPCPUSH_UPDATE_REPOSITORY,
        'slug' => 'comment-push',
        'version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['package'],
        'requires' => $release['requires'],
        'requires_php' => $release['requires_php'],
    ];
}

function wpcpush_updater_transient($transient)
{
    if (!is_object($transient)) { return $transient; }
    $pluginFile = plugin_basename(WPCPUSH_FILE);
    $headers = get_file_data(WPCPUSH_FILE, ['Version' => 'Version', 'UpdateURI' => 'Update URI']);
    $installedVersion = $headers['Version'] ?? '';
    if (!is_string($installedVersion) || $installedVersion === '') { return $transient; }
    $offer = wpcpush_updater_update(false, $headers, $pluginFile, []);
    if (!is_array($offer)) { return $transient; }

    // Core saves this transient before contacting WordPress.org, whose failure skips the native hostname hook.
    // Reuse the cached GitHub release without saving this transient again or touching other plugins' entries.
    foreach (['response', 'no_update', 'checked'] as $key) {
        if (!isset($transient->$key)) {
            $transient->$key = [];
        } elseif (is_object($transient->$key)) {
            $transient->$key = get_object_vars($transient->$key);
        } elseif (!is_array($transient->$key)) {
            $transient->$key = [];
        }
    }
    $transient->checked[$pluginFile] = $installedVersion;
    $update = (object)$offer;
    $update->plugin = $pluginFile;
    $update->new_version = $offer['version'];
    unset($transient->response[$pluginFile], $transient->no_update[$pluginFile]);
    if (version_compare($update->new_version, $installedVersion, '>')) {
        $transient->response[$pluginFile] = $update;
    } else {
        $transient->no_update[$pluginFile] = $update;
    }
    return $transient;
}

function wpcpush_updater_information($result, string $action, $args)
{
    if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== 'comment-push') { return $result; }
    $release = wpcpush_updater_release();
    if ($release === null) {
        // Never fall back to a different WordPress.org plugin with the same slug.
        return new WP_Error('wpcpush_release_unavailable', '暂时无法取得 GitHub 发布信息，请稍后重新检查。');
    }
    $information = (object)[
        'name' => 'QQ / 微信评论推送',
        'slug' => 'comment-push',
        'version' => $release['version'],
        'author' => '<a href="https://www.imsun.org/">老孙</a>',
        'author_profile' => 'https://www.imsun.org/',
        'homepage' => WPCPUSH_UPDATE_REPOSITORY,
        'requires' => $release['requires'],
        'requires_php' => $release['requires_php'],
        'download_link' => $release['package'],
        'external' => true,
        'sections' => [
            'description' => '<p>通过 QQ 官方机器人或微信 iLink 接收新评论通知，并管理评论审核、回复和回收站。</p>',
            'changelog' => $release['body'] !== '' ? '<p>' . nl2br(esc_html($release['body']), false) . '</p>' : '<p>此版本未提供更新说明。</p>',
        ],
    ];
    if ($release['last_updated'] !== '') { $information->last_updated = $release['last_updated']; }
    return $information;
}

function wpcpush_updater_check_package($source, $remoteSource, $upgrader, array $hookExtra)
{
    if (is_wp_error($source) || !($upgrader instanceof Plugin_Upgrader)
        || ($hookExtra['plugin'] ?? '') !== plugin_basename(WPCPUSH_FILE)
        || ($hookExtra['action'] ?? '') !== 'update') {
        return $source;
    }
    // Single-plugin upgrades do not attach core's package validation themselves.
    return $upgrader->check_package($source);
}

function wpcpush_updater_clear(): void
{
    delete_site_transient('wpcpush_release');
}

function wpcpush_updater_maybe_refresh(): void
{
    if (!empty($_GET['force-check']) && current_user_can('update_plugins')) {
        // The native "Check again" action must bypass both WordPress and this plugin's cache.
        delete_site_transient('update_plugins');
    }
}

add_filter('update_plugins_github.com', 'wpcpush_updater_update', 10, 4);
add_filter('pre_set_site_transient_update_plugins', 'wpcpush_updater_transient');
add_filter('plugins_api', 'wpcpush_updater_information', 10, 3);
add_filter('upgrader_source_selection', 'wpcpush_updater_check_package', 10, 4);
add_action('delete_site_transient_update_plugins', 'wpcpush_updater_clear');
add_action('load-update-core.php', 'wpcpush_updater_maybe_refresh', 1);
