@props(['html' => ''])

@php
    $clean = app(\Quansitech\Cmf\RichContent\Support\HtmlSanitizer::class)->sanitize((string) $html);
    $body = app(\Quansitech\Cmf\RichContent\Support\MediaUrlResolver::class)->resolve($clean);
@endphp

<div {{ $attributes->class('cmf-rich-content-body') }}>
    {!! $body !!}
</div>

@once
    <link rel="stylesheet" href="{{ asset('vendor/cmf-rich-content/css/body.css') }}" data-navigate-track>
    <script src="{{ asset('vendor/cmf-rich-content/js/rich-content-svg.js') }}" defer data-navigate-once></script>
@endonce
