<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppWidget;
use App\Support\Widgets\WidgetCatalog;
use App\Support\Widgets\WidgetDataService;
use Illuminate\Http\Request;

/**
 * Config-driven widgets for the mobile app. Everything is resolved against
 * the authenticated user's own role — there's no way to ask for another
 * role's widget list or data.
 */
class WidgetController extends Controller
{
    public function __construct(protected WidgetDataService $data) {}

    /**
     * GET /api/app-widgets?screen=home[&include=data][&lat=&lng=]
     * → { data: [{key, title, subtitle, settings, data?}] }
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'screen' => ['nullable', 'string', 'in:'.implode(',', array_keys(WidgetCatalog::SCREENS))],
            'include' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();
        $widgets = AppWidget::for($user->role, $validated['screen'] ?? 'home');

        $payloads = ($validated['include'] ?? null) === 'data'
            ? $this->data->payloads($user, $widgets, $this->coord($validated, 'lat'), $this->coord($validated, 'lng'))
            : null;

        return response()->json([
            'data' => $widgets->map(function (AppWidget $w) use ($payloads) {
                $row = $w->toClientArray();
                if ($payloads !== null) {
                    $row['data'] = $payloads[$w->key] ?? null;
                }

                return $row;
            })->values(),
        ]);
    }

    /**
     * GET /api/widgets/data?keys=a,b,c[&lat=&lng=]
     * → { data: { key: payload|null } } — only keys enabled for the user's own role are computed.
     */
    public function data(Request $request)
    {
        $validated = $request->validate([
            'keys' => ['required', 'string', 'max:2000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $keys = array_filter(array_map('trim', explode(',', $validated['keys'])));
        $user = $request->user();
        $widgets = AppWidget::forRole($user->role)->filter(fn (AppWidget $w) => in_array($w->key, $keys, true));

        return response()->json([
            'data' => (object) $this->data->payloads($user, $widgets, $this->coord($validated, 'lat'), $this->coord($validated, 'lng')),
        ]);
    }

    protected function coord(array $validated, string $key): ?float
    {
        return isset($validated['lat'], $validated['lng']) ? (float) $validated[$key] : null;
    }
}
