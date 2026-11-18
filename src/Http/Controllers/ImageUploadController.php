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
 * 图片上传的服务器中转兜底：只在云存储桶未配 CORS、浏览器直传被拦时才用得上。
 *
 * 常规链路是浏览器直传（check / sign / callback），文件不经过 PHP；这条兜底是为了
 * 不让「桶没配 CORS」的环境从「能用」退化成「完全传不了图」。
 */
final class ImageUploadController
{
    public function __invoke(Request $request, MediaUploader $uploader): JsonResponse
    {
        if (! (bool) config('cmf-rich-content.image.enabled', true)) {
            return response()->json(['message' => '图片上传未启用'], 404);
        }

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        Gate::authorize('create', config('cmf-media.model', Media::class));

        $rule = UploadRule::resolve((string) config('cmf-rich-content.image.upload_rule', 'editor'));

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
