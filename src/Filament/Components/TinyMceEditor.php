<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Filament\Components;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;

/**
 * 使用包内本地语言包的 TinyMCE 字段。
 *
 * 上游 TinyEditor 的语言映射把 zh-CN 映射成 zh-Hans，且语言资源 key 与 tinymce-i18n
 * 的目录结构对不上（TinyMCE 8 用 langs8/zh-CN.js），结果是中文后台仍显示英文。
 * 这里统一按 TinyMCE 8 的语言码（如 zh-CN）返回，配合
 * TinyMceServiceProvider 登记的本地语言资源使用。
 */
class TinyMceEditor extends TinyEditor
{
    /**
     * TinyMCE 语言码（TinyMCE 8 使用带连字符的 ISO 形式，如 zh-CN）。
     */
    public function getInterfaceLanguage(): string
    {
        return match (strtolower(str_replace('_', '-', (string) $this->evaluate($this->language)))) {
            'zh-cn', 'zh' => 'zh-CN',
            default => 'en',
        };
    }

    /**
     * 供 blade 里 x-load-js 取用的 Filament 资源 id。
     */
    public function getLanguageId(): string
    {
        $language = $this->getInterfaceLanguage();

        return $language === 'en' ? 'cmf-rich-content-lang-en' : 'tinymce-lang-'.$language;
    }

    /**
     * 语言包地址：中文走包内发布的本地文件，其余回落到上游的 CDN 拼装。
     */
    public function getLanguageURL($lang): string
    {
        if ($lang === 'en') {
            return '';
        }

        $local = 'vendor/cmf-rich-content/langs/'.$lang.'.js';

        return file_exists(public_path($local)) ? asset($local) : parent::getLanguageURL($lang);
    }
}
