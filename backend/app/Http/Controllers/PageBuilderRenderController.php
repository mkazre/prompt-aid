<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Page;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Service;
use App\Models\ThirdPartyProfile;
use App\PageBuilder\Rendering\PageRenderer;
use App\PageBuilder\Templates\TemplateResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page-builder catch-all — registered last in routes/web.php, after
 * every named route. Resolution order: published `Page` by slug (single
 * segment) → archive `Page` + single entity by slug/id (two segments) →
 * 404. Never reached for paths already claimed by a named route (auth,
 * dashboard, checkout, rides, /staff, /vendor, /partner, /api — see the
 * catch-all's own `where()` exclusion below).
 */
class PageBuilderRenderController extends Controller
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected const ENTITY_MODELS = [
        'doctor' => DoctorProfile::class,
        'clinic' => Clinic::class,
        'pharmacy' => Pharmacy::class,
        'product' => Product::class,
        'service' => Service::class,
        'third_party' => ThirdPartyProfile::class,
    ];

    public function render(Request $request, string $path, PageRenderer $renderer, TemplateResolver $templates): Response
    {
        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) === 1) {
            $page = Page::query()
                ->where('slug', $segments[0])
                ->when(! $this->canPreviewDrafts($request), fn ($q) => $q->where('status', 'published'))
                ->first();

            if ($page && $page->kind === 'page') {
                return response(view('page-builder.render', ['page' => $page, 'html' => $renderer->render($page)]));
            }

            if ($page && $page->kind === 'archive' && $page->entity_type) {
                return response(view('page-builder.render', ['page' => $page, 'html' => $renderer->render($page)]));
            }

            abort(404);
        }

        if (count($segments) === 2) {
            [$archiveSlug, $entitySlug] = $segments;

            $archivePage = Page::query()->where('slug', $archiveSlug)->where('kind', 'archive')->first();

            if (! $archivePage || ! $archivePage->entity_type) {
                abort(404);
            }

            $entity = $this->resolveEntity($archivePage->entity_type, $entitySlug);

            if (! $entity) {
                abort(404);
            }

            $template = $templates->forSingle($archivePage->entity_type, $entity);

            if (! $template || ! $template->page) {
                abort(404);
            }

            return response(view('page-builder.render', [
                'page' => $template->page,
                'html' => $renderer->render($template->page, $entity),
            ]));
        }

        abort(404);
    }

    protected function resolveEntity(string $entityType, string $slug): ?\Illuminate\Database\Eloquent\Model
    {
        $modelClass = self::ENTITY_MODELS[$entityType] ?? null;

        if (! $modelClass) {
            return null;
        }

        $hasSlugColumn = Cache::rememberForever("model_has_slug:{$modelClass}", fn () => \Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), 'slug'));

        if ($hasSlugColumn) {
            $entity = $modelClass::query()->where('slug', $slug)->first();
            if ($entity) {
                return $entity;
            }
        }

        return is_numeric($slug) ? $modelClass::query()->find((int) $slug) : null;
    }

    protected function canPreviewDrafts(Request $request): bool
    {
        return $request->user()?->role === \App\Models\User::ROLE_SUPER_ADMIN;
    }
}
