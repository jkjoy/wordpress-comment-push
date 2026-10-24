=== QQ / 微信评论推送 ===
Tags: comments, moderation, notifications, qq, weixin
Requires at least: 6.4
Tested up to: 7.1.3
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

通过 QQ 官方机器人和微信 iLink 通知新评论，并在授权会话中审核与回复评论。

== Description ==

参照 SBlog comment-push 插件移植，使用 WordPress 原生后台、HTTP API、评论事件和 REST 路由，兼容 SQLite 与 MySQL。

作者：老孙 https://www.imsun.org/
公开仓库：https://github.com/jkjoy/wordpress-comment-push
正式发行版：https://github.com/jkjoy/wordpress-comment-push/releases

* QQ 私聊、群聊和频道消息；官方 Webhook 验签和一次性接收人绑定。
* 微信扫码登录、验证码、同步接收人、会话绑定和退出登录。
* 通知待审核和直接通过审核的新评论，默认跳过管理员自己发表的评论。
* 通知包含文章、评论编号、状态、评论者和最多 400 字的摘要，可选文章链接；不发送邮箱、IP 或 User-Agent。
* 两个渠道可以独立启用，分别测试，查看各自最近一次结果。
* 默认关闭的 QQ / 微信评论管理，可查看、审核、标记垃圾、移入回收站、从回收站恢复和回复评论。
* QQ 私聊仅允许已保存接收人；QQ群仅允许目标群中通过一次性绑定登记的管理人，频道暂不支持管理。
* 微信只接受已扫码绑定接收人的命令，支持 WP-Cron 与独立 PHP 接收进程，后台显示心跳和最近处理结果。
* 从 GitHub Releases 检查最新正式发行版，支持 WordPress 原生升级及可选自动更新，无需 WordPress.org 上架。

通知和评论管理各自独立，所有开关默认关闭，升级不会自动授权已有接收人。管理命令按保存设置时的 WordPress 管理员当前权限执行。后台入口为顶级「评论推送」菜单。

== Installation ==

1. 从 https://github.com/jkjoy/wordpress-comment-push/releases/latest 下载发行附件 comment-push.zip，ZIP 顶层目录为 comment-push。
2. 在「插件 → 安装插件 → 上传插件」上传并启用；也可解压后将 comment-push 目录放入 wp-content/plugins/。
3. 打开「评论推送 → QQ机器人」或「评论推送 → 微信连接」，配置并保存相应通道；到「运行状态」发送测试。
4. 如需管理评论，先完成接收人绑定，再到「评论推送 → 评论管理」启用相应的管理开关。QQ群须由管理人重新发送一次性绑定命令。

详细配置见 README.md。QQ Webhook 自动绑定需要公网 HTTPS；本地测试站可以手动填写已有 OpenID 测试发送。

== Updates ==

1.2.0 起通过 GitHub 公开 API 检查本仓库最新正式发行版，使用固定附件 comment-push.zip 在线升级，不需要 GitHub 账号或令牌，也不依赖 WordPress.org 插件目录。

安装 1.2.0 后，后续版本可在「插件 → 已安装插件」或「仪表盘 → 更新」中原生升级；也可在插件列表选择「启用自动更新」，交由 WordPress 按自身计划更新。

1.1.1 及更早版本本身没有更新器，需先从 Releases 下载 comment-push.zip，在「上传插件」中手动替换至 1.2.0 一次，之后才能使用在线升级。替换安装会保留原有配置。

正常检查缓存约 6 小时，失败仅短暂缓存。在「仪表盘 → 更新」重新检查更新可刷新本插件的检查结果。暂时无法访问 GitHub 不影响通知和评论管理。

更新检查只读取公开发行信息，升级时下载安装包，不上传评论内容、QQ / 微信凭据或会话内容。

== Admin pages ==

后台顶级「评论推送」菜单提供五个子页：

* 通知设置：通知的评论状态与内容规则。
* QQ机器人：机器人凭证、接收目标、Webhook 绑定与 QQ 通知开关。
* 微信连接：扫码登录、接收人同步和绑定、退出登录与微信通知开关。
* 评论管理：QQ / 微信管理开关、授权身份与命令说明。
* 运行状态：接收器状态、测试通知、最近发送与管理结果。

每个设置页单独保存，仅更新本页设置并保留其他页面配置。更换账号或绑定目标时仍会撤销相应管理授权。旧的「设置 → QQ / 微信评论推送」地址自动跳转至新入口。

== Comment management ==

支持：评论帮助、待审评论 [页码]、查看评论 ID、通过评论 ID、驳回评论 ID、垃圾评论 ID、删除评论 ID、恢复评论 ID、回复评论 ID 内容。

ID 为通知中的评论编号。删除表示移入回收站，恢复仅用于回收站中的评论，回复会创建真实评论。QQ群发送命令须 @机器人，其他群成员不能管理评论。更换 QQ 凭证或接收目标、退出或重新登录微信后须重新授权。

仅处理具有可信平台时间、在本次启用管理后发送且不超过 5 分钟的新命令。启用前的旧消息不执行，同一条平台消息重复投递不会重复操作。

普通主机默认约每分钟通过 WP-Cron 接收微信命令，依赖站点访问。需要持续接收时，在 wp-config.php 设 WPCPUSH_RECEIVER_MODE 为 worker，并从 WordPress 安装目录运行：

php wp-content/plugins/comment-push/worker.php

仅本测试站：开发目录中的 start.ps1 / stop.ps1 同时启停独立接收器，不包含在发布的插件 ZIP 中。其他站点使用自身主机环境维持接收进程。无需保持后台页面打开，未启用微信管理时不会自动拉取管理消息。QQ 命令由官方 Webhook 接收。「评论推送 → 运行状态」显示接收模式、最近心跳、检查时间与简要管理结果。

== External services ==

启用并配置通道后，插件通过 HTTPS 将通知发送至对应平台。扫码、手动同步及测试由管理员操作发起；启用微信评论管理后，接收器会自动查询官方新消息并回复命令结果。查看设置页本身不直接发起平台请求。未配置的通道不会发送通知。

QQ：api.bot.qq.com（访问凭证与通知/命令回复发送）；QQ 官方 Webhook 向本站投递绑定与管理消息。QQ 开放平台：https://q.qq.com/ 。
微信：ilinkai.weixin.qq.com 及官方返回的 weixin.qq.com 子域名（扫码登录、会话同步、接收管理命令与消息发送）。协议与服务说明：https://github.com/Tencent/openclaw-weixin 。
GitHub：api.github.com（公开正式发行版检查），github.com 及其发行附件下载域名（插件升级包）。仓库：https://github.com/jkjoy/wordpress-comment-push 。不向 GitHub 上传评论或 QQ / 微信凭据。

凭证、会话及管理授权保存在 WordPress 数据库的非自动加载 options 中，不注册为公开 REST 设置。二维码在管理员浏览器本地生成。插件状态仅保留简要处理结果，不保存评论正文或原回复；回复本身作为评论存入 WordPress。

== Changelog ==

= 1.2.0 =
新增 GitHub Releases 在线更新、固定发行附件 comment-push.zip、WordPress 原生升级及可选自动更新、缓存和手动重新检查支持；更新作者及公开仓库信息。

= 1.1.1 =
后台改为顶级「评论推送」菜单及通知设置、QQ机器人、微信连接、评论管理、运行状态五个子页；各页独立保存并保留其他页配置，旧设置地址兼容跳转。

= 1.1.0 =
新增独立 QQ / 微信评论管理开关、授权身份及群管理人绑定、评论管理命令、微信自动接收与运行状态；新评论通知增加评论编号。

= 1.0.0 =
首次发布 WordPress 版本，包含 QQ、微信及评论通知流程。
