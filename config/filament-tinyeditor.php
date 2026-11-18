<?php

/**
 * 本包的 TinyMCE 默认配置（来自 filament-tinyeditor，附加公众号抓取插件）。
 *
 * 未发布时以本文件为准，宿主已有同名键会覆盖本文件（array_replace_recursive）。
 * 需要覆盖时才发布配置；宿主未声明的键继续使用包默认值。
 */
return [
    'version' => [
        'tiny' => '8.0.2',
        'language' => [
            // https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/
            'version' => '25.8.4',
            'package' => 'langs8',
        ],
        'licence_key' => env('TINY_LICENSE_KEY', 'no-api-key'),
    ],
    'provider' => env('TINY_PROVIDER', 'vendor'), // cloud|vendor
    // 'direction' => 'rtl',

    /**
     * change darkMode: 'auto'|'force'|'class'|'media'|false|'custom'
     */
    'darkMode' => 'auto',

    /** cutsom */
    'skins' => [
        // oxide, oxide-dark, tinymce-5, tinymce-5-dark
        'ui' => 'oxide',

        // dark, default, document, tinymce-5, tinymce-5-dark, writer
        'content' => 'default',
    ],

    'profiles' => [
        'default' => [
            'plugins' => 'accordion autoresize codesample directionality advlist link image lists preview pagebreak searchreplace wordcount code fullscreen insertdatetime media table emoticons wechatarticle cmfvideo',
            'toolbar' => 'undo redo removeformat | fontfamily fontsize fontsizeinput font_size_formats styles | bold italic underline | rtl ltr | alignjustify alignleft aligncenter alignright | numlist bullist outdent indent | forecolor backcolor | blockquote table toc hr | image link media codesample emoticons | wordcount fullscreen | wxarticle',
            'upload_directory' => null,
            // 公众号文章抓取插件：包内 resources/js/tinymce-wechat-article.js，
            // 由 cmf-rich-content-assets 发布到 public/vendor/cmf-rich-content/js 下。
            // cmfvideo：媒体对话框提供「上传 / 嵌入」两页签
            // （resources/js/tinymce-video.js）。不占工具栏按钮。
            'external_plugins' => [
                'wechatarticle' => '/vendor/cmf-rich-content/js/tinymce-wechat-article.js',
                'cmfvideo' => '/vendor/cmf-rich-content/js/tinymce-video.js',
            ],
        ],

        'simple' => [
            'plugins' => 'autoresize directionality emoticons link wordcount',
            'toolbar' => 'removeformat | bold italic | rtl ltr | numlist bullist | link emoticons',
            'upload_directory' => null,
        ],

        'minimal' => [
            'plugins' => 'link wordcount',
            'toolbar' => 'bold italic link numlist bullist',
            'upload_directory' => null,
        ],

        'full' => [
            'plugins' => 'accordion autoresize codesample directionality advlist autolink link image lists charmap preview anchor pagebreak searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media table emoticons help',
            'toolbar' => 'undo redo removeformat | fontfamily fontsize fontsizeinput font_size_formats styles | bold italic underline | rtl ltr | alignjustify alignright aligncenter alignleft | numlist bullist outdent indent accordion | forecolor backcolor | blockquote table toc hr | image link anchor media codesample emoticons | visualblocks print preview wordcount fullscreen help',
            'upload_directory' => null,
        ],
    ],

    /**
     * this option will load optional language file based on you app locale
     * example:
     * languages => [
     *      'fa' => 'https://cdn.jsdelivr.net/npm/tinymce-i18n@25.8.4/langs7/fa.min.js',
     *      'es' => 'https://cdn.jsdelivr.net/npm/tinymce-i18n@25.8.4/langs7/es.min.js',
     *      'ja' => asset('assets/ja.min.js')
     * ]
     */
    'languages' => [],

    'extra' => [
        'toolbar' => [
            // 'fontsize' => '10px 12px 13px 14px 16px 18px 20px',
            // 'fontfamily' => 'Tahoma=tahoma,arial,helvetica,sans-serif;',
            // 'content_style' => 'body { font-family: "Tahoma", sans-serif; }',
        ],

        // 视频按原始比例显示：width/height 属性只是插入时的兜底尺寸，历史内容里可能是 16:9 定高，
        // 这里用 CSS 覆盖，避免更宽的视频在编辑器里出现上下白边；display:block 顺带去掉行内基线空隙。
        // 图片与 SVG 卡片同理：插入时带的是原始像素宽，超出正文区域就按容器宽度等比缩放（前台由
        // .rich-body 的隔离样式兜底，编辑器里靠这段 content_style，两边口径一致）。
        'content_style' => 'video{display:block;max-width:100%;height:auto}svg{display:block;max-width:100%;height:auto}img{max-width:100%;height:auto}',
    ],
];
