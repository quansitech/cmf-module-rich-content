<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Media\Concerns\HasMediaRichContent;
use Quansitech\Cmf\RichContent\Support\MediaReferences;
use Quansitech\Cmf\RichContent\Support\RichContentLocalizer;

/**
 * 配合 HasMedia / HasRichContent 接入正文处理与引用生命周期。
 *
 * trait 的 saving 早于宿主 booted 中的派生/校验监听：先确定最终 HTML，再让
 * 宿主计算 content_plain 等字段；saved 后只同步引用，不再静默补写第二次正文。
 * 单图下载失败由 importer 保留原图，清洗/数据库错误则正常抛出，不落未清洗内容。
 */
trait HasLocalizedRichContent
{
    use HasMediaRichContent;

    protected static function bootHasLocalizedRichContent(): void
    {
        static::saving(static function (Model $model): void {
            if (! (bool) config('cmf-rich-content.auto_localize', true)) {
                return;
            }

            /** @var static $model */
            foreach ($model->localizedRichContentFields() as $field) {
                $value = $model->getAttributes()[$field] ?? null;

                if (! $model->isDirty($field) || ! is_string($value)) {
                    continue;
                }

                $result = app(RichContentLocalizer::class)->prepareHtml($value, auth()->id());
                $model->setAttribute($field, $result->html);
            }
        });

        static::saved(static function (Model $model): void {
            if (! (bool) config('cmf-rich-content.auto_localize', true)) {
                return;
            }

            /** @var static $model */
            foreach ($model->localizedRichContentFields() as $field) {
                $html = $model->getAttributes()[$field] ?? '';
                MediaReferences::sync($model, $field, is_string($html) ? $html : '');
            }
        });
    }

    /** @return list<string> */
    protected function localizedRichContentFields(): array
    {
        return $this->mediaRichContentFields();
    }
}
