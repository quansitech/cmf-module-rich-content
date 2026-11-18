<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;
use Quansitech\Cmf\RichContent\Filament\Components\TinyMceEditor;

/**
 * 后台富文本编辑器工厂（TinyMCE）。
 *
 * 编辑器自带的图片上传走 Livewire 临时上传，此处把两个扩展点接到 cmf-media：
 * 保存返回 media id，取 URL 走 Media::url()——正文里的 <img data-id> 由此与
 * 媒体库同源，后续清理/复用都走同一套引用计数。
 *
 * 只有需要图片能力的字段才用本工厂；纯文字富文本（如轮播标语）仍用
 * Filament 内置 RichEditor，避免 TinyMCE 包一层 <p> 破坏纯文本口径。
 */
final class RichText
{
    public static function editor(string $field): TinyMceEditor
    {
        return TinyMceEditor::make($field)
            ->profile('default')
            ->language(app()->getLocale())
            ->toolbarSticky(true)
            ->saveUploadedFileAttachmentUsing(static function (TemporaryUploadedFile $file): int|string|null {
                Gate::authorize('create', config('cmf-media.model', Media::class));

                return app(MediaUploader::class)
                    ->store($file, uploaderId: auth()->id(), rule: UploadRule::resolve((string) config('cmf-rich-content.remote_images.upload_rule', 'editor')))
                    ->getKey();
            })
            ->getFileAttachmentUrlUsing(static function (mixed $file): ?string {
                // 编辑器直传图片在保存前 data-id 还是 Livewire 的临时文件 key（UUID），
                // 它不是媒体 id，交给远程图落地流程去搬字节，这里不能拿去查库。
                $id = MediaReferences::id($file);

                if ($id === null) {
                    return null;
                }

                return Media::withTrashed()->find($id)?->url();
            });
    }
}
