<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Quansitech\Cmf\RichContent\Http\Controllers\ImageUploadController;
use Quansitech\Cmf\RichContent\Http\Controllers\VideoUploadController;
use Quansitech\Cmf\RichContent\Http\Controllers\WechatArticleController;

/*
|--------------------------------------------------------------------------
| 富文本扩展包路由
|--------------------------------------------------------------------------
|
| 只服务后台编辑器：登录用户才能调用，前缀固定为 cmf-rich-content，避免与
| 宿主项目路由冲突。抓取接口本身还会做域名白名单与 SSRF 校验。
|
*/

Route::middleware(['web', 'auth'])
    ->prefix('cmf-rich-content')
    ->name('cmf-rich-content.')
    ->group(function (): void {
        Route::post('wechat-article', WechatArticleController::class)
            ->middleware('throttle:cmf-rich-content-wechat-article')
            ->name('wechat-article');

        // 本地上传视频：文件进 cmf-media，返回可直接插入的地址。
        Route::post('video', VideoUploadController::class)
            ->middleware('throttle:cmf-rich-content-video')
            ->name('video');

        // 图片直传被 CORS 拦时的中转兜底（常规链路是浏览器直传，不经过这里）。
        Route::post('image', ImageUploadController::class)
            ->middleware('throttle:cmf-rich-content-image')
            ->name('image');
    });
