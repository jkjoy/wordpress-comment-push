<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wpcpush_admin_sections(): array
{
    return [
        'notifications' => ['label' => '通知设置', 'description' => '选择需要通知的评论状态与推送内容。'],
        'qq' => ['label' => 'QQ 机器人', 'description' => '配置 QQ 机器人凭证、接收人和官方消息回调。'],
        'weixin' => ['label' => '微信连接', 'description' => '扫码登录微信、绑定接收人并启用评论通知。'],
        'management' => ['label' => '评论管理', 'description' => '授权机器人管理评论，核对管理人并查看聊天指令。'],
        'status' => ['label' => '运行状态', 'description' => '查看消息接收状态、最近处理结果并发送测试通知。'],
    ];
}

function wpcpush_admin_section(): string
{
    $page = isset($_GET['page']) && is_string($_GET['page']) ? wp_unslash($_GET['page']) : '';
    foreach (wpcpush_admin_sections() as $section => $details) {
        if ($page === 'comment-push' . ($section === 'notifications' ? '' : '-' . $section)) { return $section; }
    }
    return 'notifications';
}

function wpcpush_register_admin_menu(): void
{
    global $wpcpush_admin_hooks;
    $wpcpush_admin_hooks = [add_menu_page('评论推送', '评论推送', 'manage_options', 'comment-push', 'wpcpush_admin_page', 'dashicons-format-chat', 26)];
    foreach (wpcpush_admin_sections() as $section => $details) {
        $slug = 'comment-push' . ($section === 'notifications' ? '' : '-' . $section);
        $wpcpush_admin_hooks[] = add_submenu_page('comment-push', $details['label'] . ' · 评论推送', $details['label'], 'manage_options', $slug, 'wpcpush_admin_page');
    }
}

function wpcpush_admin_legacy_redirect(): void
{
    global $pagenow;
    if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === 'comment-push' && current_user_can('manage_options')) {
        wp_safe_redirect(wpcpush_admin_url(), 302);
        exit;
    }
}

function wpcpush_admin_validate_settings(string $section, array $input, array $existing): array
{
    $fields = [
        'notifications' => ['notify_pending', 'notify_approved', 'notify_admin', 'include_link'],
        'qq' => ['qq_enabled', 'qq_app_id', 'qq_app_secret', 'clear_qq_app_secret', 'qq_target_type', 'qq_target_id'],
        'weixin' => ['weixin_enabled'],
        'management' => ['qq_manage_enabled', 'weixin_manage_enabled'],
    ];
    if (!isset($fields[$section])) { throw new InvalidArgumentException('无效的设置页面。'); }
    $merged = array_merge(wpcpush_defaults(), array_intersect_key($existing, wpcpush_defaults()));
    foreach ($merged as $key => $value) {
        if (is_bool($value)) { $merged[$key] = $value ? '1' : '0'; }
    }
    // Missing checkboxes only disable settings owned by the submitted page.
    foreach ($fields[$section] as $field) { $merged[$field] = $input[$field] ?? ''; }
    $validated = wpcpush_validate($merged, $existing, $section);
    if ($section === 'management' && ($validated['config']['qq_manage_enabled'] || $validated['config']['weixin_manage_enabled'])) {
        $validated['config']['manager_user_id'] = get_current_user_id();
    }
    return $validated;
}

function wpcpush_admin_plugin_links(array $links): array
{
    array_unshift($links, '<a href="' . esc_url(wpcpush_admin_url()) . '">设置</a>');
    return $links;
}

function wpcpush_admin_assets(string $hook): void
{
    global $wpcpush_admin_hooks;
    if (!in_array($hook, (array) $wpcpush_admin_hooks, true)) {
        return;
    }
    wp_enqueue_style('wpcpush-admin', plugins_url('assets/admin.css', __FILE__), [], WPCPUSH_VERSION);
    if (wpcpush_admin_section() !== 'weixin') { return; }
    $status = wpcpush_weixin_status();
    if (empty($status['qrcode_url'])) {
        return;
    }
    wp_enqueue_script('wpcpush-qrcode', plugins_url('assets/vendor/qrcode.js', __FILE__), [], '2.0.4', true);
    wp_enqueue_script('wpcpush-qrcode-utf8', plugins_url('assets/vendor/qrcode_UTF8.js', __FILE__), ['wpcpush-qrcode'], '2.0.4', true);
    wp_enqueue_script('wpcpush-admin', plugins_url('assets/admin.js', __FILE__), ['wpcpush-qrcode-utf8'], WPCPUSH_VERSION, true);
}

function wpcpush_admin_form_fields(string $operation): void
{
    echo '<input type="hidden" name="action" value="wpcpush_manage">';
    echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    echo '<input type="hidden" name="section" value="' . esc_attr(wpcpush_admin_section()) . '">';
    wp_nonce_field('wpcpush_manage');
}

function wpcpush_admin_time($time): string
{
    return is_numeric($time) && (int) $time > 0 ? wp_date('Y-m-d H:i:s', (int) $time) : '未知时间';
}

function wpcpush_admin_set_notice(string $type, string $message, array $errors = []): void
{
    set_transient('wpcpush_admin_notice_' . get_current_user_id(), [
        'type' => $type === 'success' ? 'success' : 'error',
        'message' => $message,
        'errors' => array_values(array_filter($errors, 'is_string')),
    ], 120);
}

function wpcpush_admin_render_notice(): void
{
    $key = 'wpcpush_admin_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (!is_array($notice)) {
        return;
    }
    delete_transient($key);
    $type = ($notice['type'] ?? '') === 'success' ? 'success' : 'error';
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p>';
    if (!empty($notice['errors']) && is_array($notice['errors'])) {
        echo '<ul class="wpcpush-errors">';
        foreach ($notice['errors'] as $error) {
            if (is_string($error)) {
                echo '<li>' . esc_html($error) . '</li>';
            }
        }
        echo '</ul>';
    }
    echo '</div>';
}

function wpcpush_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('你没有权限管理评论推送。', '', ['response' => 403]);
    }
    nocache_headers();
    $section = wpcpush_admin_section();
    $sections = wpcpush_admin_sections();
    $config = wpcpush_config();
    $weixin = wpcpush_weixin_status();
    $qqWebhook = wpcpush_qq_webhook_status();
    $qqBinding = is_array($qqWebhook['binding'] ?? null) ? $qqWebhook['binding'] : [];
    $qqCanBind = !empty($qqWebhook['available']) && !empty($qqWebhook['configured']) && in_array($config['qq_target_type'], ['c2c', 'group'], true);
    $qrUrl = (string) ($weixin['qrcode_url'] ?? '');
    $candidates = is_array($weixin['candidates'] ?? null) ? $weixin['candidates'] : [];
    $results = ['qq' => wpcpush_read('result_qq'), 'weixin' => wpcpush_read('result_weixin')];
    $qqManager = wpcpush_read('qq_manager');
    $qqGroupManagerBound = $config['qq_target_type'] === 'group'
        && ($qqManager['target_type'] ?? '') === 'group'
        && ($qqManager['target_id'] ?? '') === $config['qq_target_id']
        && ($qqManager['identity'] ?? '') === hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret'])
        && !empty($qqManager['actor_id']);
    $managerUserId = (int) ($config['manager_user_id'] ?? 0);
    $managerUser = $managerUserId > 0 ? get_user_by('id', $managerUserId) : false;
    $receiver = wpcpush_read('receiver_status');
    $managementResult = wpcpush_read('management_result');
    $receiverMode = defined('WPCPUSH_RECEIVER_MODE') && WPCPUSH_RECEIVER_MODE === 'worker' ? 'worker' : 'cron';
    $receiverHeartbeat = (int) ($receiver['heartbeat'] ?? 0);
    $receiverRecent = ($receiver['mode'] ?? '') === $receiverMode
        && $receiverHeartbeat > time() - ($receiverMode === 'worker' ? 120 : 180)
        && $receiverHeartbeat <= time() + 60;
    if (empty($config['weixin_manage_enabled'])) {
        $receiverState = '微信评论管理未启用';
    } elseif (empty($weixin['logged_in']) || !empty($weixin['expired'])) {
        $receiverState = '微信连接未就绪，请重新扫码并绑定';
    } elseif ($receiverMode === 'cron' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        $receiverState = 'WordPress 自动定时任务已关闭，需要外部计划任务或独立接收进程';
    } elseif ($receiverRecent) {
        $receiverState = !empty($receiver['ok']) ? '接收器正在运行' : '接收器最近检查未成功，请查看下方结果';
    } else {
        $receiverState = $receiverMode === 'worker' ? '未检测到近期接收进程心跳' : '等待站点访问触发下一次定时检查';
    }
    $postUrl = admin_url('admin-post.php');
    ?>
    <div class="wrap wpcpush-wrap">
        <h1>评论推送 · <?php echo esc_html($sections[$section]['label']); ?></h1>
        <p class="wpcpush-intro"><?php echo esc_html($sections[$section]['description']); ?></p>
        <nav class="nav-tab-wrapper wpcpush-nav" aria-label="评论推送管理菜单">
            <?php foreach ($sections as $key => $details) : ?>
                <a class="nav-tab<?php echo $key === $section ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(wpcpush_admin_url($key)); ?>"<?php if ($key === $section) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html($details['label']); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php wpcpush_admin_render_notice(); ?>

        <?php if ($section === 'notifications') : ?>
        <section class="card wpcpush-card" aria-labelledby="wpcpush-settings-title">
            <h2 id="wpcpush-settings-title">新评论通知规则</h2>
            <form method="post" action="<?php echo esc_url($postUrl); ?>">
                <?php wpcpush_admin_form_fields('save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">推送内容</th><td><fieldset>
                        <legend class="screen-reader-text">推送内容</legend>
                        <?php foreach (['notify_pending' => '通知待审核的新评论', 'notify_approved' => '通知直接通过审核的新评论', 'notify_admin' => '同时通知管理员自己发表的评论', 'include_link' => '在通知中附上文章链接'] as $field => $label) : ?>
                            <label class="wpcpush-option"><input name="<?php echo esc_attr($field); ?>" type="checkbox" value="1" <?php checked($config[$field]); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                        <p class="description">QQ 通知中的文章域名可能需要在开放平台配置白名单；若平台拒绝链接，可取消附加链接后重试。</p>
                    </fieldset></td></tr>
                </table>
                <?php submit_button('保存通知设置'); ?>
            </form>
        </section>

        <p class="description">请在 <a href="<?php echo esc_url(wpcpush_admin_url('qq')); ?>">QQ 机器人</a> 或 <a href="<?php echo esc_url(wpcpush_admin_url('weixin')); ?>">微信连接</a> 中分别开启通知；需要通过聊天审核或回复评论时，前往 <a href="<?php echo esc_url(wpcpush_admin_url('management')); ?>">评论管理</a> 授权。</p>
        <?php elseif ($section === 'qq') : ?>
        <section class="card wpcpush-card" aria-labelledby="wpcpush-qq-settings-title">
            <h2 id="wpcpush-qq-settings-title">QQ 通知设置</h2>
            <form method="post" action="<?php echo esc_url($postUrl); ?>">
                <?php wpcpush_admin_form_fields('save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">官方 QQ 机器人</th><td>
                        <label><input name="qq_enabled" type="checkbox" value="1" <?php checked($config['qq_enabled']); ?>> 启用 QQ 评论通知</label>
                        <p class="description">在 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>创建机器人，填写正式环境凭证和接收 ID。</p>
                    </td></tr>
                    <tr><th scope="row"><label for="wpcpush-qq-app-id">QQ AppID</label></th><td><input class="regular-text" id="wpcpush-qq-app-id" name="qq_app_id" inputmode="numeric" maxlength="32" pattern="[0-9]{1,32}" value="<?php echo esc_attr($config['qq_app_id']); ?>" autocomplete="off"></td></tr>
                    <tr><th scope="row"><label for="wpcpush-qq-secret">QQ AppSecret</label></th><td>
                        <input class="regular-text" id="wpcpush-qq-secret" name="qq_app_secret" type="password" maxlength="256" autocomplete="new-password" value="" placeholder="<?php echo esc_attr($config['qq_app_secret'] !== '' ? '已保存；留空保留现有密钥' : '填写机器人 AppSecret'); ?>">
                        <?php if ($config['qq_app_secret'] !== '') : ?><p><label><input name="clear_qq_app_secret" type="checkbox" value="1"> 清除已保存的 AppSecret</label></p><?php endif; ?>
                    </td></tr>
                    <tr><th scope="row"><label for="wpcpush-qq-target-type">QQ 接收类型</label></th><td>
                        <select id="wpcpush-qq-target-type" name="qq_target_type">
                            <?php foreach (['c2c' => '私聊（用户 OpenID）', 'group' => 'QQ群（群 OpenID）', 'channel' => 'QQ频道（子频道 ID）'] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($config['qq_target_type'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th scope="row"><label for="wpcpush-qq-target-id">QQ 接收 ID</label></th><td>
                        <input class="regular-text" id="wpcpush-qq-target-id" name="qq_target_id" maxlength="128" pattern="[A-Za-z0-9_-]{1,128}" value="<?php echo esc_attr($config['qq_target_id']); ?>" autocomplete="off" aria-describedby="wpcpush-qq-target-hint">
                        <p class="description" id="wpcpush-qq-target-hint">私聊填写用户 OpenID，群聊填写群 OpenID，可在下方通过官方 Webhook 自动绑定；不能直接填写 QQ 号或群号。频道填写接口返回的子频道 ID。</p>
                        <p class="description">接收人须先添加机器人好友，或将机器人加入目标群，并允许主动消息。平台限制主动消息权限和频率；频道推送还需其他程序维持机器人 WebSocket 在线。</p>
                    </td></tr>
                </table>
                <?php submit_button('保存 QQ 设置'); ?>
            </form>
        </section>

        <section class="card wpcpush-card" aria-labelledby="wpcpush-webhook-title">
            <h2 id="wpcpush-webhook-title">QQ Webhook 与接收人绑定</h2>
            <p>接入官方消息事件后，通过一次性绑定码自动填写用户或群 OpenID。</p>
            <p><label for="wpcpush-webhook-url"><strong>官方事件回调地址</strong></label></p>
            <input class="large-text code" id="wpcpush-webhook-url" type="text" value="<?php echo esc_attr((string) ($qqWebhook['url'] ?? '')); ?>" readonly spellcheck="false" autocomplete="off" aria-describedby="wpcpush-webhook-hint">
            <p class="description" id="wpcpush-webhook-hint">复制到 QQ 开放平台。地址须为公网可访问的 HTTPS，允许平台请求直接到达，不受登录页或防火墙验证拦截，并保持本插件启用。</p>
            <ol class="wpcpush-steps">
                <li>填写 AppID、AppSecret，选择「私聊」或「QQ群」并保存；尚无接收 ID 时先不启用 QQ 评论通知。</li>
                <li>进入 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>的机器人管理，在事件订阅中填写回调地址并完成验证。私聊订阅 <code>C2C_MESSAGE_CREATE</code>，群聊订阅 <code>GROUP_AT_MESSAGE_CREATE</code>。</li>
                <li>生成绑定码，在需要接收通知的 QQ 私聊中发送完整命令；群聊须将机器人加入目标群并 @机器人发送命令。</li>
                <li>刷新本页，确认上方接收 ID 已填写，再启用 QQ 评论通知、保存并发送测试。群聊同时登记发送绑定命令的账号为唯一管理人，需要管理评论时，前往「评论管理」勾选对应管理开关并保存。测试通知在「运行状态」中发送。</li>
            </ol>
            <?php if (empty($qqWebhook['available'])) : ?>
                <p class="description">当前 PHP 未启用 sodium 扩展，无法验证官方 Webhook 签名。请启用 sodium 后接入回调；手动填写接收 ID 的发送功能不依赖此扩展。</p>
            <?php elseif (empty($qqWebhook['configured'])) : ?>
                <p class="description">请先保存 AppID 和 AppSecret，再验证回调、生成绑定码。</p>
            <?php elseif ($config['qq_target_type'] === 'channel') : ?>
                <p class="description">当前已保存的类型为 QQ 频道，请手动填写子频道 ID。自动绑定支持私聊和 QQ 群，修改类型后须先保存。</p>
            <?php endif; ?>
            <?php if ($qqBinding) : ?>
                <p><label for="wpcpush-binding-command"><strong>一次性绑定命令</strong></label></p>
                <input class="large-text code" id="wpcpush-binding-command" type="text" value="<?php echo esc_attr('绑定通知 ' . (string) ($qqBinding['code'] ?? '')); ?>" readonly spellcheck="false" autocomplete="off" aria-describedby="wpcpush-binding-hint">
                <p class="description" id="wpcpush-binding-hint">绑定<?php echo ($qqBinding['target_type'] ?? '') === 'group' ? '目标 QQ 群并登记发送命令的账号为管理人（须 @机器人）' : '当前 QQ 私聊用户'; ?>；有效期至 <?php echo esc_html(wpcpush_admin_time($qqBinding['expires_at'] ?? 0)); ?>。请仅在目标会话中发送，匹配成功后立即失效。</p>
            <?php endif; ?>
            <div class="wpcpush-actions">
                <form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('qq_binding_start'); ?><button class="button" type="submit" <?php disabled(!$qqCanBind); ?>><?php echo $qqBinding ? '重新生成绑定码' : '生成绑定码'; ?></button></form>
                <?php if ($qqBinding) : ?><form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('qq_binding_cancel'); ?><button class="button" type="submit">取消本次绑定</button></form><?php endif; ?>
                <a class="button" href="<?php echo esc_url(wpcpush_admin_url('qq')); ?>">刷新绑定状态</a>
            </div>
            <p class="description">回调验证：<?php echo !empty($qqWebhook['last_challenge_at']) ? '最近成功于 ' . esc_html(wpcpush_admin_time($qqWebhook['last_challenge_at'])) : '尚未收到成功验证'; ?>；已验证消息事件：<?php echo !empty($qqWebhook['last_event_at']) ? '最近收到于 ' . esc_html(wpcpush_admin_time($qqWebhook['last_event_at'])) : '尚未收到'; ?>。</p>
            <p class="description">绑定码有效期 10 分钟，仅按已保存的接收类型匹配；绑定不会自动启用通知或评论管理。<a href="https://bot.q.qq.com/wiki/develop/api-v2/dev-prepare/event-emit/webhook.html" target="_blank" rel="noopener noreferrer">查看官方 Webhook 文档</a>。</p>
        </section>

        <?php elseif ($section === 'weixin') : ?>
        <section class="card wpcpush-card" aria-labelledby="wpcpush-weixin-settings-title">
            <h2 id="wpcpush-weixin-settings-title">微信通知设置</h2>
            <form method="post" action="<?php echo esc_url($postUrl); ?>">
                <?php wpcpush_admin_form_fields('save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">微信</th><td>
                        <label><input name="weixin_enabled" type="checkbox" value="1" <?php checked($config['weixin_enabled']); ?>> 启用微信评论通知</label>
                        <p class="description">使用从 OpenClaw 微信通道移植的 iLink 接口。请先在本页扫码登录，再向微信中的机器人发送一条消息并同步接收人。</p>
                    </td></tr>
                    <tr><th scope="row"><label for="wpcpush-weixin-user">微信接收人</label></th><td>
                        <input class="regular-text" id="wpcpush-weixin-user" type="text" value="<?php echo esc_attr($config['weixin_user_id']); ?>" readonly placeholder="尚未绑定" aria-describedby="wpcpush-weixin-user-hint">
                        <p class="description" id="wpcpush-weixin-user-hint">接收人从微信消息同步并绑定。会话上下文过期时，请重新发消息并同步。</p>
                    </td></tr>
                </table>
                <?php submit_button('保存微信设置'); ?>
            </form>
        </section>

        <section class="card wpcpush-card" aria-labelledby="wpcpush-weixin-title">
            <h2 id="wpcpush-weixin-title">微信连接</h2>
            <p>扫码登录、检查登录状态与绑定接收人由你手动操作。启用微信评论管理后，接收器会自动检查新命令。</p>
            <p>登录状态：<strong><?php echo !empty($weixin['expired']) ? '登录已失效，请重新扫码' : (!empty($weixin['logged_in']) ? '已登录' : '尚未登录'); ?></strong><?php echo !empty($weixin['logged_in']) && empty($weixin['expired']) ? '；接收会话' . (!empty($weixin['has_context']) ? '已就绪' : '尚未同步') : ''; ?>。</p>
            <?php if (!empty($weixin['logged_in']) && empty($weixin['expired']) && empty($weixin['has_context'])) : ?><p class="description">尚未取得会话上下文时仍可尝试推送，微信可能拒绝发送。若通知失败，请先给机器人发送一条消息，再同步接收人。</p><?php endif; ?>
            <?php if ($qrUrl !== '') : ?>
                <div class="wpcpush-qr" data-push-qr="<?php echo esc_attr($qrUrl); ?>" aria-live="polite">正在生成登录二维码……</div>
                <p class="description">使用微信扫描二维码，并在手机上确认登录。完成后点击「检查登录状态」。二维码仅在本机生成。</p>
                <p><a href="<?php echo esc_url($qrUrl); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">打开微信官方登录链接</a></p>
                <noscript><p class="description">启用 JavaScript 后可显示二维码，或使用上方官方登录链接。</p></noscript>
            <?php endif; ?>
            <div class="wpcpush-actions">
                <form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('weixin_start'); ?><button class="button" type="submit"><?php echo $qrUrl !== '' ? '重新获取二维码' : '获取登录二维码'; ?></button></form>
                <?php if (!empty($weixin['login_pending'])) : ?>
                    <form class="wpcpush-login-check" method="post" action="<?php echo esc_url($postUrl); ?>">
                        <?php wpcpush_admin_form_fields('weixin_check'); ?>
                        <?php if (!empty($weixin['need_verifycode'])) : ?><div><label for="wpcpush-weixin-code">手机验证码</label> <input id="wpcpush-weixin-code" name="verify_code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" pattern="[0-9]{1,12}" required></div><?php endif; ?>
                        <button class="button" type="submit">检查登录状态</button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($weixin['logged_in'])) : ?>
                    <form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('weixin_sync'); ?><button class="button" type="submit" <?php disabled(!empty($weixin['expired'])); ?>>同步微信接收人</button></form>
                    <form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('weixin_disconnect'); ?><button class="button wpcpush-disconnect" type="submit">退出微信登录</button></form>
                <?php endif; ?>
            </div>
            <?php if ($candidates) : ?>
                <form class="wpcpush-candidates" method="post" action="<?php echo esc_url($postUrl); ?>">
                    <?php wpcpush_admin_form_fields('weixin_bind'); ?>
                    <p><label for="wpcpush-weixin-candidate"><strong>选择刚刚发送消息的微信接收人</strong></label></p>
                    <select id="wpcpush-weixin-candidate" name="weixin_user_id" required>
                        <option value="">请选择接收人</option>
                        <?php foreach ($candidates as $candidate) : ?>
                            <option value="<?php echo esc_attr((string) ($candidate['user_id'] ?? '')); ?>"><?php echo esc_html((string) ($candidate['user_id'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">仅可绑定本次登录实际收到过消息的用户。确认是自己的接收 ID 后再绑定。</p>
                    <p><button class="button" type="submit">绑定选中的接收人</button></p>
                </form>
            <?php endif; ?>
            <p class="description">首次连接顺序：获取二维码 → 微信扫码并确认 → 检查登录状态 → 给机器人发消息 → 同步接收人 → 在本页开启通知，或前往「评论管理」授权。退出登录会清除本地凭证和接收人，并停用微信通知与管理。</p>
        </section>

        <?php elseif ($section === 'management') : ?>
        <section class="card wpcpush-card" aria-labelledby="wpcpush-management-settings-title">
            <h2 id="wpcpush-management-settings-title">管理授权</h2>
            <form method="post" action="<?php echo esc_url($postUrl); ?>">
                <?php wpcpush_admin_form_fields('save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">机器人管理评论</th><td><fieldset>
                        <legend class="screen-reader-text">机器人管理评论</legend>
                        <label class="wpcpush-option"><input name="qq_manage_enabled" type="checkbox" value="1" <?php checked(!empty($config['qq_manage_enabled'])); ?>> 允许已绑定的 QQ 会话管理评论</label>
                        <label class="wpcpush-option"><input name="weixin_manage_enabled" type="checkbox" value="1" <?php checked(!empty($config['weixin_manage_enabled'])); ?>> 允许已绑定的微信接收人管理评论</label>
                        <p class="description">两个管理开关默认关闭，与评论通知独立。启用并保存后，以当前 WordPress 管理员的权限执行命令；评论编号见通知，命令列表和授权状态见下方。每次保存管理授权均使用当前管理员账号。</p>
                        <p class="description">QQ 私聊只允许已保存的接收人。QQ群只允许通过一次性绑定码登记的管理人，其他群成员无权操作；旧版绑定请重新生成绑定码。QQ 频道暂不支持管理。</p>
                        <p class="description">微信只允许本次扫码账号已绑定的接收人。退出或重新登录微信、更换 QQ 凭证或接收目标后，原管理授权会撤销，须重新核对并启用。</p>
                    </fieldset></td></tr>
                </table>
                <?php submit_button('保存管理授权'); ?>
            </form>
        </section>

        <section class="card wpcpush-card" aria-labelledby="wpcpush-management-title">
            <h2 id="wpcpush-management-title">授权身份与指令</h2>
            <p>在已授权的会话发送以下命令。QQ群中须 @机器人；所有操作均检查授权 WordPress 用户的当前权限。</p>
            <dl class="wpcpush-status-list">
                <dt>WordPress 授权用户</dt>
                <dd><?php if ($managerUser) : ?><?php echo esc_html($managerUser->display_name . '（' . $managerUser->user_login . '，ID ' . $managerUserId . '）'); ?><?php elseif ($managerUserId > 0) : ?>原授权账户已不存在，请重新保存管理授权。<?php else : ?>尚未授权；启用管理并保存时使用当前管理员账号。<?php endif; ?></dd>
                <dt>QQ 评论管理</dt>
                <dd><?php echo !empty($config['qq_manage_enabled']) ? '已启用' : '未启用'; ?><?php if (!empty($config['qq_manage_since'])) : ?> · 本次授权于 <?php echo esc_html(wpcpush_admin_time($config['qq_manage_since'])); ?><?php endif; ?></dd>
                <dt>QQ 授权会话</dt>
                <dd><?php if ($config['qq_target_type'] === 'channel') : ?>QQ 频道暂不支持评论管理。<?php elseif ($config['qq_target_id'] === '') : ?>尚未绑定接收人。<?php else : ?><?php echo $config['qq_target_type'] === 'group' ? 'QQ群 OpenID：' : '私聊用户 OpenID：'; ?><code><?php echo esc_html($config['qq_target_id']); ?></code><?php endif; ?></dd>
                <?php if ($config['qq_target_type'] === 'group') : ?>
                    <dt>QQ 群管理人</dt>
                    <dd><?php if ($qqGroupManagerBound) : ?><code><?php echo esc_html((string) $qqManager['actor_id']); ?></code><?php else : ?>尚未登记。请由需要管理评论的 QQ 账号在目标群重新发送一次性绑定命令。<?php endif; ?></dd>
                <?php endif; ?>
                <dt>微信评论管理</dt>
                <dd><?php echo !empty($config['weixin_manage_enabled']) ? '已启用' : '未启用'; ?><?php if (!empty($config['weixin_manage_since'])) : ?> · 本次授权于 <?php echo esc_html(wpcpush_admin_time($config['weixin_manage_since'])); ?><?php endif; ?></dd>
                <dt>微信授权接收人</dt>
                <dd><?php if ($config['weixin_user_id'] !== '') : ?><code><?php echo esc_html($config['weixin_user_id']); ?></code><?php else : ?>尚未扫码绑定。<?php endif; ?></dd>
            </dl>
            <table class="widefat striped wpcpush-commands">
                <caption class="screen-reader-text">评论管理命令</caption>
                <thead><tr><th scope="col">命令</th><th scope="col">用途</th></tr></thead>
                <tbody>
                    <tr><td><code>评论帮助</code></td><td>显示管理命令说明。</td></tr>
                    <tr><td><code>待审评论 [页码]</code></td><td>查看待审核评论；页码可省略，例如 <code>待审评论 2</code>。</td></tr>
                    <tr><td><code>查看评论 ID</code></td><td>查看指定评论，例如 <code>查看评论 123</code>。</td></tr>
                    <tr><td><code>通过评论 ID</code></td><td>将评论设为已通过审核。</td></tr>
                    <tr><td><code>驳回评论 ID</code></td><td>将评论设为待审核。</td></tr>
                    <tr><td><code>垃圾评论 ID</code></td><td>将评论标记为垃圾评论。</td></tr>
                    <tr><td><code>删除评论 ID</code></td><td>将评论移入回收站。</td></tr>
                    <tr><td><code>恢复评论 ID</code></td><td>恢复回收站中的评论。</td></tr>
                    <tr><td><code>回复评论 ID 内容</code></td><td>以授权 WordPress 用户发表回复，例如 <code>回复评论 123 谢谢你的反馈。</code></td></tr>
                </tbody>
            </table>
            <p class="description">ID 替换为评论编号。回复内容会作为真实评论发布；命令结果会回复到当前授权会话。关闭管理开关后，该渠道不再执行评论命令。</p>
            <p class="description">仅处理具有可信平台时间、在本次启用管理后发送且不超过 5 分钟的新命令。启用前的旧消息不执行，同一条平台消息重复投递不会重复操作。</p>

        </section>

        <?php elseif ($section === 'status') : ?>
        <section class="card wpcpush-card" aria-labelledby="wpcpush-receiver-title">
            <h2 id="wpcpush-receiver-title">接收与管理状态</h2>
            <h3>微信命令接收状态</h3>
            <dl class="wpcpush-status-list">
                <dt>接收方式</dt><dd><?php echo $receiverMode === 'worker' ? '独立 PHP 接收进程' : 'WordPress 定时任务（约每分钟，依赖站点访问）'; ?></dd>
                <dt>当前状态</dt><dd><?php echo esc_html($receiverState); ?></dd>
                <dt>最近心跳</dt><dd><?php echo $receiverHeartbeat > 0 ? esc_html(wpcpush_admin_time($receiverHeartbeat)) : '尚无记录'; ?></dd>
                <dt>最近检查消息</dt><dd><?php echo !empty($receiver['last_poll']) ? esc_html(wpcpush_admin_time($receiver['last_poll'])) : '尚无记录'; ?></dd>
                <?php if (!empty($receiver['message'])) : ?><dt>接收结果</dt><dd><?php echo esc_html((string) $receiver['message']); ?></dd><?php endif; ?>
            </dl>
            <?php if ($receiverMode === 'worker') : ?>
                <p class="description">本机启动脚本会同时启动独立接收进程。启用微信管理后即可接收命令，无需保持本页面打开；若无近期心跳，请重新运行站点启动脚本。</p>
            <?php else : ?>
                <p class="description">默认通过 WP-Cron 检查微信消息。没有站点访问时，处理可能延后；需要持续接收时，可在服务器独立运行 <code>php wp-content/plugins/comment-push/worker.php</code>，并配置 <code>WPCPUSH_RECEIVER_MODE</code> 为 <code>worker</code>。QQ 命令由官方 Webhook 接收。</p>
            <?php endif; ?>
            <h3>最近管理结果</h3>
            <?php if ($managementResult) : ?>
                <p class="description"><?php echo ($managementResult['channel'] ?? '') === 'qq' ? 'QQ' : (($managementResult['channel'] ?? '') === 'weixin' ? '微信' : '机器人'); ?> · <strong><?php echo !empty($managementResult['ok']) ? '已处理' : '未完成'; ?></strong> · <?php echo esc_html(wpcpush_admin_time($managementResult['time'] ?? 0)); ?><?php echo !empty($managementResult['message']) ? ' · ' . esc_html((string) $managementResult['message']) : ''; ?></p>
            <?php else : ?><p class="description">尚无评论管理记录。</p><?php endif; ?>
            <p class="description">仅保存一条简要管理结果，不在插件状态中保存评论正文或回复原文。</p>
        </section>

        <section class="card wpcpush-card" aria-labelledby="wpcpush-tests-title">
            <h2 id="wpcpush-tests-title">测试与最近结果</h2>
            <p>测试会立即发送一条消息，使用已保存的设置；修改配置后请先保存。</p>
            <p class="description">发送成功表示平台已受理，请在 QQ / 微信中核对实际送达。</p>
            <?php foreach (['qq' => 'QQ', 'weixin' => '微信'] as $channel => $label) : $result = $results[$channel]; ?>
                <div class="wpcpush-result">
                    <form method="post" action="<?php echo esc_url($postUrl); ?>"><?php wpcpush_admin_form_fields('test_' . $channel); ?><button class="button" type="submit">发送<?php echo esc_html($label); ?>测试通知</button></form>
                    <?php if ($result) : ?>
                        <p class="description"><?php echo esc_html($label); ?>最近结果：<strong><?php echo !empty($result['ok']) ? '已受理' : '失败'; ?></strong> · <?php echo esc_html(wpcpush_admin_time($result['time'] ?? 0)); ?><?php echo !empty($result['message']) ? ' · ' . esc_html((string) $result['message']) : ''; ?></p>
                    <?php else : ?><p class="description"><?php echo esc_html($label); ?>尚无发送记录。</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </div>
    <?php
}

function wpcpush_admin_manage(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('你没有权限管理评论推送。', '', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('请使用设置页提交操作。', '', ['response' => 405]);
    }
    check_admin_referer('wpcpush_manage');
    $input = wp_unslash($_POST);
    $operation = wpcpush_input($input, 'operation');
    $section = wpcpush_input($input, 'section');
    $redirectSection = match ($operation) {
        'qq_binding_start', 'qq_binding_cancel' => 'qq',
        'weixin_start', 'weixin_check', 'weixin_sync', 'weixin_bind', 'weixin_disconnect' => 'weixin',
        'test_qq', 'test_weixin' => 'status',
        default => $section,
    };
    try {
        if ($operation === 'save') {
            $validated = wpcpush_admin_validate_settings($section, $input, wpcpush_config());
            if (!empty($validated['errors'])) {
                wpcpush_admin_set_notice('error', '设置未保存，请检查以下项目。', $validated['errors']);
            } else {
                wpcpush_save_config($validated['config']);
                wpcpush_admin_set_notice('success', wpcpush_admin_sections()[$section]['label'] . '已保存。');
            }
        } elseif ($operation === 'test_qq' || $operation === 'test_weixin') {
            $result = wpcpush_test(substr($operation, 5));
            wpcpush_admin_set_notice(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? '请查看下方最近结果。'));
        } elseif ($operation === 'qq_binding_start') {
            wpcpush_qq_binding_start();
            wpcpush_admin_set_notice('success', 'QQ 一次性绑定码已生成，请在 10 分钟内向目标会话发送绑定命令。');
        } elseif ($operation === 'qq_binding_cancel') {
            wpcpush_qq_binding_cancel();
            wpcpush_admin_set_notice('success', '本次 QQ 绑定已取消，现有接收 ID 保持不变。');
        } elseif ($operation === 'weixin_start') {
            $result = wpcpush_weixin_start();
            wpcpush_admin_set_notice('success', (string) ($result['message'] ?? '微信登录二维码已生成。'));
        } elseif ($operation === 'weixin_check') {
            $result = wpcpush_weixin_check(wpcpush_input($input, 'verify_code'));
            wpcpush_admin_set_notice(in_array($result['status'] ?? '', ['expired', 'verify_code_blocked'], true) ? 'error' : 'success', (string) ($result['message'] ?? '微信登录状态已更新。'));
        } elseif ($operation === 'weixin_sync') {
            $result = wpcpush_weixin_sync();
            wpcpush_admin_set_notice('success', (string) ($result['message'] ?? '微信接收人已同步。'));
        } elseif ($operation === 'weixin_bind') {
            wpcpush_weixin_bind(wpcpush_input($input, 'weixin_user_id'));
            wpcpush_admin_set_notice('success', '微信接收人已绑定。可在本页开启通知，或前往「评论管理」授权。');
        } elseif ($operation === 'weixin_disconnect') {
            wpcpush_weixin_disconnect();
            wpcpush_admin_set_notice('success', '微信已退出登录，微信通知与评论管理已停用。');
        } else {
            wpcpush_admin_set_notice('error', '无效的操作，请从评论推送设置页重试。');
        }
    } catch (Throwable $exception) {
        $messages = [
            'save' => '无法保存推送设置，请检查 WordPress 数据库是否可写。',
            'test_qq' => 'QQ 测试未完成，请检查已保存的凭证、接收 ID 和服务器网络。',
            'test_weixin' => '微信测试未完成，请检查登录状态、接收人和服务器网络。',
            'qq_binding_start' => '无法生成 QQ 绑定码，请先保存有效凭证和私聊或群聊类型，并检查 sodium 扩展。',
            'qq_binding_cancel' => '无法取消 QQ 绑定，请检查数据库是否可写后重试。',
            'weixin_start' => '无法获取微信登录二维码，请检查服务器网络后重试。',
            'weixin_check' => '无法检查微信登录，请检查验证码、二维码有效期和服务器网络后重试。',
            'weixin_sync' => '无法同步微信接收人，请确认登录有效，给机器人发送消息后重试。',
            'weixin_bind' => '无法绑定该微信接收人，请重新同步并从实际收到消息的候选人中选择。',
            'weixin_disconnect' => '无法退出微信登录，请检查数据库是否可写后重试。',
        ];
        wpcpush_admin_set_notice('error', $messages[$operation] ?? '操作未完成，请检查服务器配置后重试。');
    }
    wp_safe_redirect(wpcpush_admin_url($redirectSection), 303);
    exit;
}

add_action('admin_menu', 'wpcpush_register_admin_menu');
add_action('admin_init', 'wpcpush_admin_legacy_redirect');
add_action('admin_enqueue_scripts', 'wpcpush_admin_assets');
add_action('admin_post_wpcpush_manage', 'wpcpush_admin_manage');
add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/comment-push.php'), 'wpcpush_admin_plugin_links');
