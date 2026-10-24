<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

function wpcpush_http(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6): array
{
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('通知接口必须使用有效的 HTTPS 地址。');
    }
    $requestHeaders = ['Accept' => 'application/json', 'Content-Type' => 'application/json; charset=utf-8'];
    foreach ($headers as $header) {
        if (!is_string($header) || strpos($header, ':') === false || preg_match('/[\r\n]/', $header)) {
            throw new RuntimeException('通知接口请求头格式无效。');
        }
        [$name, $value] = explode(':', $header, 2);
        $requestHeaders[trim($name)] = trim($value);
    }
    $args = [
        'method' => $method,
        'headers' => $requestHeaders,
        'timeout' => max(1, min(30, $timeout)),
        'redirection' => 0,
        'sslverify' => true,
        'reject_unsafe_urls' => true,
        'limit_response_size' => 1048576,
        'httpversion' => '1.1',
    ];
    if ($payload !== null) {
        $args['body'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $args['data_format'] = 'body';
    }
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        // Only inspect the error internally; URLs and provider errors may contain credentials.
        $timeoutError = preg_match('/(?:cURL error 28|timed? out|timeout)/i', $response->get_error_message()) === 1;
        throw new RuntimeException('通知接口连接失败或超时，请检查服务器网络和 HTTPS 证书。', $timeoutError ? 28 : 0);
    }
    $status = (int)wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    // iLink message identifiers are uint64 and must never be rounded to floating point.
    $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data)) {
        throw new RuntimeException('通知接口返回了无效的 JSON 响应（HTTP ' . $status . '）。');
    }
    return ['status' => $status, 'data' => $data];
}

function wpcpush_api_code(array $data): string
{
    $value = $data['errcode'] ?? $data['err_code'] ?? $data['code'] ?? $data['ret'] ?? '';
    return is_scalar($value) && preg_match('/\A-?[0-9]{1,12}\z/', (string)$value) ? (string)$value : '';
}

function wpcpush_api_error(string $provider, array $response): RuntimeException
{
    $code = wpcpush_api_code($response['data']);
    $message = $provider . '接口失败（HTTP ' . (int)$response['status'] . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
    if ($provider === 'QQ') {
        $message .= match ($code) {
            '40034105' => '请在 QQ 中开启机器人的主动消息权限。',
            '40054004', '40034101', '40054003' => '请添加机器人好友或将机器人加入目标群，并检查 OpenID。',
            '40054013' => '接收人已关闭机器人主动消息。',
            '40054010', '304003' => '文章域名未获允许，可关闭通知中的文章链接后重试。',
            '40054016', '304018' => '机器人离线；频道推送需要其他程序保持 WebSocket 在线。',
            '304023', '304024' => '消息正在平台审核，请勿重复发送。',
            default => (int)$response['status'] === 429 || $code === '40034100' ? '已达到平台频率限制，请稍后再试。' : '请检查机器人凭证、接收 ID 和平台权限。',
        };
    }
    return new RuntimeException($message);
}
