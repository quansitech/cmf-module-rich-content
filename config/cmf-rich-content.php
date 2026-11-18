<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 自动本地化
    |--------------------------------------------------------------------------
    |
    | 使用 HasLocalizedRichContent trait 的模型，在 saving 时处理富文本字段，saved 后同步引用；把
    | 远程图片下载到所配置的媒体库。关闭后仍可手动调用 RichContentLocalizer。
    |
    | 落盘一律走 cmf-media 的 MediaUploader（按 hash 去重、规则校验、引用计数），
    | 驱动跟随 cmf-media.default：配成 local 落 public/cmf-media，配成
    | cos/tos/oss 则直接传到对应云存储，本包不自行拼磁盘路径。
    |
    */
    'auto_localize' => env('CMF_RICH_CONTENT_AUTO_LOCALIZE', true),

    /*
    |--------------------------------------------------------------------------
    | 保存前处理
    |--------------------------------------------------------------------------
    |
    | clean_paste：清理 Word / 公众号等来源的冗余标记（mso-*、条件注释、
    |              空段落、align 属性等）。
    | sanitize：按白名单过滤标签、属性、样式与 URL 协议，防 XSS。
    |
    */
    'clean_paste' => env('CMF_RICH_CONTENT_CLEAN_PASTE', true),

    'sanitize' => env('CMF_RICH_CONTENT_SANITIZE', true),

    /*
    |--------------------------------------------------------------------------
    | 远程图片本地化
    |--------------------------------------------------------------------------
    |
    | enabled：总开关。
    | upload_rule：落库时使用的 cmf-media 上传规则名（cmf-media.rules.*）。
    | allowed_hosts：主机白名单（fnmatch 通配，如 "*.qpic.cn"）；空数组表示
    |                只做内网地址拦截，不限制公网主机。
    | max_bytes：单图体积上限（字节），需 <= upload_rule 的 max_size。
    | max_images_per_save：单次保存最多处理的图片张数。
    | total_budget_seconds：单次保存用于下载的总时长预算（秒）。
    | timeout / connect_timeout：单请求读/连接超时（秒）。
    | max_redirects：最多跟随的重定向跳数（每一跳都会重新做 SSRF 校验）。
    | user_agent：下载时使用的 UA（部分站点拒绝空 UA 或命令行 UA）。
    |
    */
    'remote_images' => [
        'enabled' => env('CMF_RICH_CONTENT_REMOTE_IMAGES', true),
        'upload_rule' => 'cmf-rich-image',
        'allowed_hosts' => [],
        'max_bytes' => 5 * 1024 * 1024,
        'max_images_per_save' => 10,
        'total_budget_seconds' => 20,
        'timeout' => 5,
        'connect_timeout' => 3,
        'max_redirects' => 3,
        'user_agent' => 'Mozilla/5.0 (compatible; cmf-rich-content/1.0)',
    ],

    /*
    |--------------------------------------------------------------------------
    | 公众号文章抓取
    |--------------------------------------------------------------------------
    |
    | 后台编辑器里粘贴公众号链接即可把正文（含图片）抓进来；图片在抓取时
    | 就通过 MediaUploader 落到 cmf-media（跟随 cmf-media.default 的驱动）。
    |
    | enabled：总开关，关闭后接口返回 404。
    | allowed_hosts：允许抓取的域名（fnmatch 通配），默认只放行公众号。
    | requests_per_minute：每个登录用户每分钟最多抓取次数，超出返回 429。
    | max_bytes：响应体体积上限（字节），超出直接中断。
    | timeout：单请求总超时（秒）。
    | max_images：单篇最多抓取的图片张数，超出部分保留外链。
    | budget_seconds：整篇图片下载的总时长预算（秒），超时部分保留外链。
    | user_agent：请求公众号页面时使用的 UA（默认伪装桌面浏览器）。
    |
    */
    /*
    |--------------------------------------------------------------------------
    | 内嵌视频（iframe）白名单
    |--------------------------------------------------------------------------
    |
    | 富文本里允许保留的 iframe 来源。只有 https + host 命中下面清单的 iframe
    | 才会通过 HtmlSanitizer；其余 iframe 仍按危险标签整段删除。
    | 同一份清单会同步给 TinyMCE 的 sandbox_iframes_exclusions，避免编辑器里
    | 播放器被自动沙箱挡住（缺 allow-scripts 时控制台会报 Blocked script execution）。
    |
    | 支持 fnmatch 通配，例如 '*.bilibili.com'。新增站点只需加在这里。
    |
    */
    'embeds' => [
        'allowed_hosts' => [
            'player.bilibili.com',
            'player.youku.com',
            'player.iqiyi.com',
            'open.iqiyi.com',
            'v.qq.com',
            'channels.weixin.qq.com',
            'mp.weixin.qq.com',
            'open.douyin.com',
            'www.douyin.com',
            'www.youtube.com',
            'youtube.com',
            'm.youtube.com',
            'www.youtube-nocookie.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 插入视频
    |--------------------------------------------------------------------------
    |
    | 编辑器「插入/编辑媒体」对话框的「上传」页签走这里：选本地文件 → cmf-media 直传。
    | 地址嗅探（页面地址 → 官方播放器）已下线，嵌入一律由「嵌入」页签粘 iframe 代码完成，
    | 可嵌入的站点仍由上方 embeds.allowed_hosts 把关。
    |
    | upload_rule：本地上传视频使用的 cmf-media 规则（cmf-media.rules.*），
    |              默认 video（video/*，体积上限沿用全局）。
    | requests_per_minute：每个登录用户每分钟最多上传次数。
    |
    */
    'video' => [
        'enabled' => env('CMF_RICH_CONTENT_VIDEO', true),
        'upload_rule' => 'cmf-rich-video',
        'requests_per_minute' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | 图片上传
    |--------------------------------------------------------------------------
    |
    | enabled：设为 false 时 /cmf-rich-content/image 兜底端点直接 404（浏览器直传不受影响）。
    | upload_rule：沿用 cmf-media 的 editor 规则（image/* 与 PDF，体积上限 10MB）。
    |
    */
    'image' => [
        'enabled' => env('CMF_RICH_CONTENT_IMAGE', true),
        'upload_rule' => 'cmf-rich-image',
        'requests_per_minute' => 20,
    ],

    'wechat_article' => [
        'enabled' => env('CMF_RICH_CONTENT_WECHAT_ARTICLE', true),
        'allowed_hosts' => ['mp.weixin.qq.com', 'weixin.qq.com'],
        'requests_per_minute' => 6,
        'max_bytes' => 5 * 1024 * 1024,
        'timeout' => 15,
        'max_images' => 30,
        'budget_seconds' => 60,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    ],
];
