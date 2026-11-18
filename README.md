# cmf-module-rich-content

QS CMF 的 TinyMCE 富文本模块，支持格式化粘贴、cmf-media 图片/视频上传、公众号可编辑正文导入、内联 SVG 动画与图片/卡片尺寸调整。

## 安装

在已初始化的 Filament 5 / QS CMF 项目中（PHP 8.3+、Livewire 4）：

```bash
composer require quansitech/cmf-module-rich-content
php artisan cmf:rich-content:install
```

Composer 自动发现 Provider；安装命令同时发布 TinyMCE、语言包、富文本资源和 Filament（含媒体指纹脚本）资源。升级部署时复跑同一条命令。不需要 npm 构建、发布配置、手工注册 Provider 或设置 `dont-discover`；从之前项目内版本升级时，请移除旧的 TinyEditor `dont-discover` 条目。

依赖的 cmf-media 需完成标准安装和迁移，编辑用户需有媒体创建权限。默认使用媒体模块配置的存储驱动；云存储直传需给存储桶配置后台来源的 CORS。CORS/网络阻断时会尝试同源中转，受 PHP 和反向代理上传上限约束。

## 在表单和模型中接入

```php
use Quansitech\Cmf\RichContent\Support\RichText;

RichText::editor('content')->label('正文')->columnSpanFull();
```

让正文宿主模型 `implements Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent`，并同时使用 `Quansitech\Cmf\Media\Concerns\HasMedia` 和 `Quansitech\Cmf\RichContent\Concerns\HasLocalizedRichContent`。默认处理 `content` 字段；配置模型的 `$mediaRichContentAttributes` 可指定其他字段。保存时清洗 HTML；仅当前操作者有媒体创建权限时尝试转存远程或内联图片，无权限时仍可正常保存清洗后的正文。保存后同步媒体引用，避免正文图片和视频被当作孤立媒体清理。

前台可直接使用 Blade 组件，它会清洗正文、刷新媒体 URL 并加载画面限宽及 SVG 动画所需资源：

```blade
<x-cmf-rich-content::body :html="$article->content" />
```

已有自己的渲染层，也可以调用 `MediaUrlResolver::resolve()` 刷新受时效限制的媒体地址。

## 默认行为和按需定制

- 图片上传成功直接插入正文；视频对话框提供「上传 / 嵌入」，默认选择上传。已有图文格式与编辑器空行保留。
- 公众号导入只提取可编辑正文，不嵌入整篇网页；白名单图片转存 cmf-media。常见公众号 SVG 动画卡片支持导入、播放和缩放，不保证兼容所有第三方动画。
- 自动注册 `cmf-rich-image`（默认上限 10 MiB）和 `cmf-rich-video`（默认上限 500 MiB）上传规则，不覆盖同名规则，不放宽媒体库全局 MIME 或体积限制。
- 登录、媒体创建权限、限流、远程图片域名与公网地址校验、HTML/iframe/SVG 白名单继续生效。配置 `cmf-rich-content` 可按需收紧；仅定制时执行 `php artisan vendor:publish --tag=cmf-rich-content-config`。

## 包内测试

```bash
cd rich-content
composer install
vendor/bin/phpunit
```
