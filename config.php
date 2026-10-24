<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_defaults(): array
{
    return [
        'qq_enabled' => false,
        'qq_app_id' => '',
        'qq_app_secret' => '',
        'qq_target_type' => 'c2c',
        'qq_target_id' => '',
        'weixin_enabled' => false,
        'weixin_user_id' => '',
        'qq_manage_enabled' => false,
        'weixin_manage_enabled' => false,
        'manager_user_id' => 0,
        'qq_manage_since' => 0,
        'weixin_manage_since' => 0,
        'notify_pending' => true,
        'notify_approved' => true,
        'notify_admin' => false,
        'include_link' => true,
    ];
}

function wpcpush_read(string $key): array
{
    $value = get_option('wpcpush_' . $key, []);
    return is_array($value) ? $value : [];
}

function wpcpush_write(string $key, array $record): void
{
    $name = 'wpcpush_' . $key;
    if ($record === []) {
        delete_option($name);
        return;
    }
    // Credentials and session state are not autoloaded or registered with the REST API.
    update_option($name, $record, false);
    if (get_option($name) !== $record) {
        throw new RuntimeException('无法保存评论推送设置，请检查数据库连接。');
    }
}

function wpcpush_config(): array
{
    $config = wpcpush_defaults();
    foreach (wpcpush_read('config') as $key => $value) {
        if (array_key_exists($key, $config) && gettype($config[$key]) === gettype($value)) {
            $config[$key] = $value;
        }
    }
    return $config;
}

function wpcpush_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function wpcpush_validate(array $input, array $existing, string $scope = 'all'): array
{
    $config = array_merge(wpcpush_defaults(), array_intersect_key($existing, wpcpush_defaults()));
    $errors = [];
    foreach (['qq_enabled', 'weixin_enabled', 'qq_manage_enabled', 'weixin_manage_enabled', 'notify_pending', 'notify_approved', 'notify_admin', 'include_link'] as $key) {
        $config[$key] = wpcpush_input($input, $key) === '1';
    }
    foreach (['qq_app_id', 'qq_target_type', 'qq_target_id'] as $key) {
        $config[$key] = wpcpush_input($input, $key);
    }
    if (wpcpush_input($input, 'clear_qq_app_secret') === '1') { $config['qq_app_secret'] = ''; }
    $secret = wpcpush_input($input, 'qq_app_secret');
    if ($secret !== '') { $config['qq_app_secret'] = $secret; }

    if (in_array($scope, ['all', 'qq'], true)) {
        if ($config['qq_app_id'] !== '' && !preg_match('/\A[0-9]{1,32}\z/', $config['qq_app_id'])) {
            $errors[] = 'QQ AppID 只能填写数字。';
        }
        if ($config['qq_app_secret'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $config['qq_app_secret'])) {
            $errors[] = 'QQ AppSecret 格式无效。';
        }
        if (!in_array($config['qq_target_type'], ['c2c', 'group', 'channel'], true)) {
            $errors[] = '请选择有效的 QQ 接收类型。';
        }
        if ($config['qq_target_id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $config['qq_target_id'])) {
            $errors[] = 'QQ 接收 ID 格式无效。';
        }
        if ($config['qq_enabled'] && ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '')) {
            $errors[] = '启用 QQ 通知前，请填写 AppID、AppSecret 和接收 ID。';
        }
    }
    if (in_array($scope, ['all', 'management'], true) && $config['qq_manage_enabled']) {
        if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '') {
            $errors[] = '启用 QQ 评论管理前，请先保存机器人凭证并绑定接收人。';
        }
        if (!in_array($config['qq_target_type'], ['c2c', 'group'], true)) {
            $errors[] = '机器人评论管理支持 QQ 私聊和群聊，暂不支持频道。';
        }
        if ($config['qq_target_type'] === 'group') {
            $manager = wpcpush_read('qq_manager');
            if (($manager['identity'] ?? '') !== hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret'])
                || ($manager['target_id'] ?? '') !== $config['qq_target_id'] || ($manager['target_type'] ?? '') !== 'group'
                || empty($manager['actor_id'])) {
                $errors[] = '请重新生成 QQ 绑定码，由管理人在目标群内绑定后再启用群评论管理。';
            }
        }
    }
    // Only the authenticated Weixin sync/bind flow may set weixin_user_id.
    $validateWeixin = ($scope === 'all' && ($config['weixin_enabled'] || $config['weixin_manage_enabled']))
        || ($scope === 'weixin' && $config['weixin_enabled']) || ($scope === 'management' && $config['weixin_manage_enabled']);
    if ($validateWeixin) {
        $account = wpcpush_read('weixin_account');
        if (empty($account['token']) || !empty($account['expired']) || $config['weixin_user_id'] === '') {
            $errors[] = '启用微信通知或管理前，请先扫码登录，并向微信机器人发送消息、同步接收人。';
        }
    }
    if (in_array($scope, ['all', 'notifications'], true) && !$config['notify_pending'] && !$config['notify_approved']) {
        $errors[] = '请至少选择一种评论状态。';
    }
    return ['config' => $config, 'errors' => $errors];
}

function wpcpush_save_config(array $config): void
{
    $config = array_merge(wpcpush_defaults(), array_intersect_key($config, wpcpush_defaults()));
    $existing = wpcpush_config();
    $qqIdentityChanged = $existing['qq_app_id'] !== $config['qq_app_id'] || $existing['qq_app_secret'] !== $config['qq_app_secret'];
    $qqTargetChanged = $existing['qq_target_type'] !== $config['qq_target_type'] || $existing['qq_target_id'] !== $config['qq_target_id'];
    if ($qqIdentityChanged) {
        foreach (['qq_token', 'qq_binding', 'qq_webhook'] as $key) { wpcpush_write($key, []); }
    } elseif ($qqTargetChanged) {
        wpcpush_write('qq_binding', []);
    }
    if ($qqIdentityChanged || $qqTargetChanged) {
        wpcpush_write('qq_manager', []);
        if ($existing['qq_manage_enabled']) { $config['qq_manage_enabled'] = false; }
    }
    if ($existing['weixin_user_id'] !== $config['weixin_user_id'] && $existing['weixin_manage_enabled']) {
        $config['weixin_manage_enabled'] = false;
    }
    foreach (['qq', 'weixin'] as $channel) {
        $enabled = $channel . '_manage_enabled';
        $since = $channel . '_manage_since';
        $config[$since] = !$config[$enabled] ? 0 : (
            !$existing[$enabled] || $existing[$since] <= 0 || $existing['manager_user_id'] !== $config['manager_user_id']
                ? time() : $existing[$since]
        );
    }
    wpcpush_write('config', array_intersect_key($config, wpcpush_defaults()));
    if (function_exists('wpcpush_management_schedule')) { wpcpush_management_schedule(); }
}

function wpcpush_admin_url(string $section = 'notifications'): string
{
    $suffix = in_array($section, ['qq', 'weixin', 'management', 'status'], true) ? '-' . $section : '';
    return admin_url('admin.php?page=comment-push' . $suffix);
}
