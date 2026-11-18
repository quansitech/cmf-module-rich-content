<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * 后台「上传视频」接口：文件直接进 cmf-media，返回可直接插入正文的地址。
 *
 * 与富文本图片共用同一条落盘链路（MediaUploader：服务端探测 MIME、内容指纹去重、
 * 规则校验、跟随 cmf-media.default 驱动），只是规则换成 video。
 */
final class VideoUploadController
{
    public function __invoke(Request $request, MediaUploader $uploader): JsonResponse
    {
        if (! (bool) config('cmf-rich-content.video.enabled', true)) {
            return response()->json(['message' => '视频插入未启用'], 404);
        }

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        Gate::authorize('create', config('cmf-media.model', Media::class));

        $rule = UploadRule::resolve((string) config('cmf-rich-content.video.upload_rule', 'video'));

        try {
            $media = $uploader->store(
                $request->file('file'),
                uploaderId: $request->user()?->getAuthIdentifier(),
                rule: $rule,
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => (string) collect($exception->errors())->flatten()->first(),
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => $media->id,
            'url' => $media->url(),
            'name' => $media->original_name,
            'width' => $media->width,
            'height' => $media->height,
            'size' => $media->size,
        ]);
    }
}
