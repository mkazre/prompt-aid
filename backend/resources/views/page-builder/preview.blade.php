<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seo_title ?: $page->title }} — Preview</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div style="background:var(--pa-beacon-wash);border-bottom:1px solid var(--pa-beacon-line);padding:10px 20px;font-size:13px;text-align:center">
        Preview — {{ $page->status === 'published' ? 'published' : 'draft, not live' }}. Header/footer are not shown here; this previews the block content only.
    </div>
    {!! $html !!}
</body>
</html>
