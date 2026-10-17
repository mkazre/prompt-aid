<?php

namespace App\Http\Controllers;

use App\Models\AppWidget;
use App\Models\Ride;
use App\Models\User;
use App\Support\Widgets\WidgetCatalog;
use App\Support\Widgets\WidgetDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $patient = $user->patientProfile;

        // Defense in depth: staff/vendor/partner accounts have no patient
        // profile. WebAuthController already redirects them to their own
        // panel on login, but guard here too in case this route is hit any
        // other way, so it degrades to a redirect instead of a crash.
        if (! $patient) {
            $panelPath = match ($user->role) {
                User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN, User::ROLE_DOCTOR => '/staff',
                User::ROLE_PHARMACY_ADMIN => '/vendor',
                User::ROLE_THIRD_PARTY => '/partner',
                User::ROLE_DRIVER => '/driver',
                default => '/',
            };

            return redirect($panelPath);
        }

        return view('dashboard.index', [
            'widgetBlocks' => $this->widgetBlocks($user),
            'appointments' => $patient->appointments()->with(['doctor.user', 'clinic'])->orderByDesc('date')->limit(5)->get(),
            'invoices' => $patient->invoices()->latest()->limit(5)->get(),
            'rides' => $patient->rides()->latest('requested_at')->limit(5)->get(),
            'activeRide' => $patient->rides()->with('driver.user')->whereNotIn('status', Ride::TERMINAL)->latest('requested_at')->limit(20)->get()->first(fn (Ride $r) => $r->isActive()),
            'labRequests' => $patient->labRequests()->with(['doctor.user', 'thirdParty', 'items', 'results'])->latest()->limit(5)->get(),
            'orders' => $patient->orders()->with('pharmacy')->latest()->limit(5)->get(),
        ]);
    }

    /** Widgets that read best at full width on the web dashboard grid. */
    protected const WIDE_WIDGETS = [
        'patient_search_shortcuts', 'patient_visit_stats', 'patient_clinic_services',
        'patient_top_doctors', 'patient_health_articles', 'patient_emergency_quickdial',
    ];

    /**
     * The same admin-configured "patient · home" widgets the app shows,
     * rendered server-side. Each widget renders in isolation so one broken
     * partial or query never takes the dashboard down.
     *
     * @return array<int, array{key: string, span: string, html: string}>
     */
    protected function widgetBlocks(User $user): array
    {
        try {
            $widgets = AppWidget::for(User::ROLE_PATIENT, 'home');
            $data = app(WidgetDataService::class)->payloads($user, $widgets);
        } catch (Throwable $e) {
            Log::warning('Dashboard widgets unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $blocks = [];

        foreach ($widgets as $widget) {
            $view = WidgetCatalog::isBanner($widget->key)
                ? 'dashboard.widgets.banner'
                : 'dashboard.widgets.'.str_replace('_', '-', $widget->key);

            if (! view()->exists($view)) {
                continue;
            }

            try {
                $html = view($view, [
                    'w' => $widget,
                    's' => $widget->publicSettings(),
                    'd' => $data[$widget->key] ?? null,
                ])->render();
            } catch (Throwable $e) {
                Log::warning('Dashboard widget failed to render', ['key' => $widget->key, 'error' => $e->getMessage()]);

                continue;
            }

            if (trim($html) === '') {
                continue; // widget chose to hide itself (nothing to show)
            }

            $blocks[] = [
                'key' => $widget->key,
                'span' => in_array($widget->key, self::WIDE_WIDGETS, true) || WidgetCatalog::isBanner($widget->key) ? 'wide' : 'half',
                'html' => $html,
            ];
        }

        return $blocks;
    }
}
