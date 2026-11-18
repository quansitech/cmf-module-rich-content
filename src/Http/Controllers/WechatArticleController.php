<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\RichContent\Support\WechatArticleFetcher;
use RuntimeException;

/**
 * 后台「抓取公众号文章」接口：把正文（图片已落 cmf-media）返回给编辑器插入。
 *
 * 只有登录用户可用；链接校验、SSRF 防护与清洗都在 WechatArticleFetcher 里完成。
 */
final class WechatArticleController
{
    public function __invoke(Request $request, WechatArticleFetcher $fetcher): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        if (! (bool) config('cmf-rich-content.wechat_article.enabled', true)) {
            return response()->json(['message' => '公众号抓取未启用'], 404);
        }

        Gate::authorize('create', config('cmf-media.model', Media::class));

        try {
            $result = $fetcher->fetch((string) $validated['url'], $request->user()?->getAuthIdentifier());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'html' => $result->html,
            'imported' => count($result->imported),
            'failed' => count($result->failed),
        ]);
    }
}
