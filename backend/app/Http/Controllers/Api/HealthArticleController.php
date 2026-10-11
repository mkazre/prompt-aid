<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthArticle;
use Illuminate\Http\Request;

/** Public read endpoints for published health articles. */
class HealthArticleController extends Controller
{
    public function index(Request $request)
    {
        $articles = HealthArticle::query()->published()
            ->when($request->string('category')->isNotEmpty(), fn ($q) => $q->where('category', $request->string('category')))
            ->paginate(20);

        return response()->json([
            'data' => collect($articles->items())->map(fn (HealthArticle $a) => $a->toSummaryArray())->values(),
            'meta' => ['current_page' => $articles->currentPage(), 'last_page' => $articles->lastPage(), 'total' => $articles->total()],
        ]);
    }

    public function show(string $slug)
    {
        $article = HealthArticle::query()->published()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $article->toSummaryArray() + ['body' => $article->body]]);
    }
}
