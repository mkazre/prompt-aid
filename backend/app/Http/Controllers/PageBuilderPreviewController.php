<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\PageBuilder\Rendering\PageRenderer;

class PageBuilderPreviewController extends Controller
{
    public function show(Page $page, PageRenderer $renderer)
    {
        return view('page-builder.preview', [
            'page' => $page,
            'html' => $renderer->render($page),
        ]);
    }
}
