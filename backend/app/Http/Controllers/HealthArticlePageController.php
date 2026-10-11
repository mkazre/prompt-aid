<?php

namespace App\Http\Controllers;

use App\Models\HealthArticle;

/** Public web pages for published health articles (linked from the dashboard widget). */
class HealthArticlePageController extends Controller
{
    public function index()
    {
        return view('health-articles.index', [
            'articles' => HealthArticle::query()->published()->paginate(12),
        ]);
    }

    public function show(string $slug)
    {
        $article = HealthArticle::query()->published()->where('slug', $slug)->firstOrFail();

        return view('health-articles.show', [
            'article' => $article,
            'paragraphs' => preg_split('/\n\s*\n/', trim((string) $article->body)) ?: [],
            'more' => HealthArticle::query()->published()->where('id', '!=', $article->id)->limit(3)->get(),
        ]);
    }
}
