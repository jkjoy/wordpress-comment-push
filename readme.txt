=== QQ / 微信评论推送 ===
Tags: comments, moderation, notifications, qq, weixin
Requires at least: 6.4
Tested up to: 7.1.3
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

通过 QQ 官方机器人和微信 iLink 接收新评论通知，并在授权会话中审核与回复评论。

== Description ==

作者：老孙 https://www.imsun.org/
详细使用说明：https://github.com/jkjoy/wordpress-comment-push#readme

* QQ 私聊、群聊、频道及微信通知，各渠道独立开启。
* 可选择通知待审核、已通过的新评论，默认不通知管理员自己的评论。
* 通知包含文章、评论编号、状态、评论者和内容摘要，可附文章链接。
* 授权会话可查看、审核、回复评论，管理垃圾评论及回收站；QQ 频道仅支持通知。
* 后台提供测试通知、运行状态及在线更新。

== Installation ==

1. 从 https://github.com/jkjoy/wordpress-comment-push/releases/latest 下载 comment-push.zip，在 WordPress「插件 → 安装插件 → 上传插件」上传并启用。
2. 在「评论推送 → 通知设置」选择需要通知的评论及内容。
3. 在「QQ机器人」填写 AppID、AppSecret 和接收目标；私聊使用用户 OpenID，群聊使用群 OpenID，频道使用子频道 ID，不能填写普通 QQ 号或群号。
4. 微信在「微信连接」扫码登录，检查登录状态，给机器人发消息后同步并绑定接收人。
5. 分别开启所需渠道的通知开关并保存，到「运行状态」发送测试通知。

要求 WordPress 6.4+、PHP 8.0+。所用渠道需能访问官方 HTTPS 接口，在线更新需能访问 GitHub；QQ 自动绑定和管理还需 PHP sodium 扩展及公网 HTTPS 回调。

QQ 回调地址以后台显示为准，私聊订阅 C2C_MESSAGE_CREATE，群聊订阅 GROUP_AT_MESSAGE_CREATE。生成绑定码后发送「绑定通知 CODE」，群聊需 @机器人；绑定码 10 分钟内有效且只能使用一次。群内发送绑定命令的账号是唯一管理人。频道通知需其他程序维持机器人 WebSocket 在线。

AppSecret 留空保存会保留已有密钥。更换 QQ 凭证或目标、退出或重新登录微信后需重新授权管理；微信需重新绑定接收人。

== 评论管理 ==

接收人绑定后，在「评论管理」开启对应渠道的管理开关。命令按保存时的 WordPress 管理员权限执行，回复使用该管理员身份。通知和管理默认关闭，可独立开启。

命令与编号之间有空格，示例中的 123 替换为实际评论编号：

* 评论帮助
* 待审评论（可加页码，如：待审评论 2）
* 查看评论 123
* 通过评论 123
* 驳回评论 123
* 垃圾评论 123
* 删除评论 123
* 恢复评论 123
* 回复评论 123 谢谢你的反馈。

删除仅移入回收站，恢复仅用于回收站中的评论，回复前需先通过评论。QQ 私聊仅允许绑定接收人，群聊仅允许绑定管理人且需 @机器人。仅处理启用管理后、5 分钟内发送的新命令。

微信默认通过 WP-Cron 接收命令，依赖站点访问。需要持续接收时，在 wp-config.php 载入 wp-settings.php 之前加入：

define('WPCPUSH_RECEIVER_MODE', 'worker');

然后在 WordPress 安装目录使用与站点相同的 PHP 配置运行并保持进程：

php wp-content/plugins/comment-push/worker.php

需先绑定并开启微信评论管理，无需保持后台页面打开。「运行状态」可查看接收器心跳及最近结果。

== 更新与卸载 ==

1.2.0 起可在「插件 → 已安装插件」或「仪表盘 → 更新」中在线升级，也可启用自动更新。1.1.1 及更早版本需先上传最新 comment-push.zip 并替换已安装版本一次，配置会保留。

停用插件保留配置；删除插件会清除设置、凭证和状态。
