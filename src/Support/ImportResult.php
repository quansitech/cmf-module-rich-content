<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

/**
 * 一次 HTML 远程图片本地化的结果。
 */
final readonly class ImportResult
{
    /**
     * @param  list<array{source: string, media_id: int, url: string}>  $imported
     * @param  list<array{source: string, reason: string}>  $failed
     */
    public function __construct(
        public string $html,
        public array $imported = [],
        public array $failed = [],
    ) {}

    public function changed(): bool
    {
        return $this->imported !== [];
    }

    /**
     * 最终正文的全部媒体引用（含之前已有的图片），不是本次新增的列表。
     *
     * @return list<int>
     */
    public function mediaIds(): array
    {
        return MediaReferences::allIds($this->html);
    }
}
