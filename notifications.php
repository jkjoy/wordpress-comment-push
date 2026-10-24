<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_text(string $value, int $limit): string
{
    $text = wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text));
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) { return ''; }
    return count($characters) > $limit ? implode('', array_slice($characters, 0, $limit)) . '…' : $text;
}

function wpcpush_comment_text(WP_Post $post, WP_Comment $comment, array $config): string
{
    $approved = (string)$comment->comment_approved === '1';
    $text = '新评论 · ' . wpcpush_text(get_bloginfo('name'), 60)
        . "\n文章：" . wpcpush_text($post->post_title, 100)
        . "\n评论编号：#" . $comment->comment_ID
        . "\n状态：" . ($approved ? '已发布' : '待审核')
        . "\n评论人：" . wpcpush_text($comment->comment_author, 50)
        . "\n\n" . wpcpush_text($comment->comment_content, 400);
    if ($config['include_link']) {
        $url = $approved ? get_comment_link($comment) : get_permalink($post) . '#comments';
        $text .= "\n\n" . esc_url_raw($url);
    }
    return $text;
}

function wpcpush_deliver(string $channel, array $config, string $text, ?callable $transport = null): array
{
    try {
        match ($channel) {
            'qq' => wpcpush_qq_send($config, $text, $transport),
            'weixin' => wpcpush_weixin_send($config, $text, $transport),
            default => throw new RuntimeException('无效的通知渠道。'),
        };
        $result = ['ok' => true, 'message' => ($channel === 'qq' ? 'QQ' : '微信') . '接口已接受通知。', 'time' => time()];
    } catch (Throwable $exception) {
        $message = get_class($exception) === RuntimeException::class
            ? $exception->getMessage() : '通知发送失败，请检查数据库和服务器环境。';
        $result = ['ok' => false, 'message' => $message, 'time' => time()];
    }
    try { wpcpush_write('result_' . $channel, $result); }
    catch (Throwable) { error_log('Comment push: unable to save notification result.'); }
    return $result;
}

function wpcpush_test(string $channel, ?callable $transport = null): array
{
    $config = wpcpush_config();
    $text = '评论通知连接测试' . "\n站点：" . wpcpush_text(get_bloginfo('name'), 60)
        . "\n时间：" . wp_date('Y-m-d H:i:s');
    if ($config['include_link']) { $text .= "\n" . home_url('/'); }
    return wpcpush_deliver($channel, $config, $text, $transport);
}

function wpcpush_comment_created(int $comment_id, $comment = null, ?callable $transport = null): void
{
    try {
        $config = wpcpush_config();
        if (!$config['qq_enabled'] && !$config['weixin_enabled']) { return; }
        $comment = $comment instanceof WP_Comment ? $comment : get_comment($comment_id);
        if (!$comment instanceof WP_Comment || !in_array($comment->comment_type, ['', 'comment'], true)) { return; }
        $status = (string)$comment->comment_approved;
        if (!in_array($status, ['0', '1'], true)) { return; }
        if (!$config[$status === '1' ? 'notify_approved' : 'notify_pending']) { return; }
        if (!$config['notify_admin'] && $comment->user_id > 0 && user_can((int)$comment->user_id, 'manage_options')) { return; }
        $post = get_post((int)$comment->comment_post_ID);
        if (!$post instanceof WP_Post) { return; }
        $text = wpcpush_comment_text($post, $comment, $config);
        foreach (['qq', 'weixin'] as $channel) {
            if ($config[$channel . '_enabled']) { wpcpush_deliver($channel, $config, $text, $transport); }
        }
    } catch (Throwable) {
        // The comment already exists; a notification failure must never invalidate it.
        error_log('Comment push: unable to prepare notification.');
    }
}
