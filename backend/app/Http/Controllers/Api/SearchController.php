<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Clinic;
use App\Models\Condition;
use App\Models\DoctorProfile;
use App\Models\Product;
use App\Models\ServiceCategory;
use App\Models\TriageConfig;
use App\Support\Widgets\WidgetDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Search-as-you-type for the patient app and public site: one query,
 * grouped results (specialities, conditions & symptoms, doctors, clinics,
 * pharmacy products). Public, throttled, cached briefly per query.
 */
class SearchController extends Controller
{
    protected const PER_GROUP = 5;

    /**
     * Triage symptom "system" → the specialization that usually handles it.
     * Only used for triage-config symptoms; admin-managed Conditions carry
     * their own specialization.
     */
    protected const SYSTEM_SPECIALTY = [
        'derm' => 'Dermatologist',
        'cardiac' => 'Cardiologist',
        'obstetric' => 'Gynaecologist',
        'paediatric' => 'Pediatrician',
        'ophthal' => 'Ophthalmologist',
        'dental' => 'Dentist',
    ];

    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['query' => $q, 'groups' => $this->emptyGroups()]);
        }

        $q = mb_substr($q, 0, 60);
        $cacheKey = 'search.v1.'.md5(mb_strtolower($q));

        $groups = Cache::remember($cacheKey, now()->addMinutes(2), fn () => [
            'specialities' => $this->specialities($q),
            'conditions' => $this->conditions($q),
            'doctors' => $this->doctors($q),
            'clinics' => $this->clinics($q),
            'products' => $this->products($q),
        ]);

        return response()->json(['query' => $q, 'groups' => $groups]);
    }

    protected function emptyGroups(): array
    {
        return ['specialities' => [], 'conditions' => [], 'doctors' => [], 'clinics' => [], 'products' => []];
    }

    protected function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }

    protected function specialities(string $q): array
    {
        $fromDoctors = DoctorProfile::query()
            ->where('status', 'active')
            ->whereNotNull('specialization')
            ->where('specialization', 'like', $this->like($q))
            ->select('specialization')
            ->selectRaw('COUNT(*) as doctors_count')
            ->groupBy('specialization')
            ->orderByDesc('doctors_count')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn ($row) => [
                'type' => 'speciality',
                'name' => $row->specialization,
                'specialization' => $row->specialization,
                'subtitle' => $row->doctors_count.' '.Str::plural('doctor', $row->doctors_count),
            ]);

        $fromCategories = ServiceCategory::query()
            ->where('name', 'like', $this->like($q))
            ->orderBy('sort')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (ServiceCategory $c) => [
                'type' => 'service_category',
                'id' => $c->id,
                'name' => $c->name,
                'specialization' => null,
                'subtitle' => 'Service category',
            ]);

        return $fromDoctors->concat($fromCategories)
            ->unique(fn ($r) => mb_strtolower($r['name']))
            ->take(self::PER_GROUP)
            ->values()
            ->all();
    }

    protected function conditions(string $q): array
    {
        $needle = mb_strtolower($q);

        /** @var Collection<int, Condition> $all */
        $all = Cache::remember('search.conditions', now()->addMinutes(10), fn () => Condition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'synonyms', 'specialization']));

        $results = $all
            ->map(function (Condition $c) use ($needle) {
                if (str_contains(mb_strtolower($c->name), $needle)) {
                    $rank = str_starts_with(mb_strtolower($c->name), $needle) ? 0 : 1;
                    $matched = null;
                } else {
                    $matched = collect($c->synonymList())->first(fn ($s) => str_contains(mb_strtolower($s), $needle));
                    if (! $matched) {
                        return null;
                    }
                    $rank = 2;
                }

                return [
                    'rank' => $rank,
                    'type' => 'condition',
                    'id' => $c->id,
                    'name' => $c->name,
                    'specialization' => $c->specialization,
                    'subtitle' => $matched ? "Also: {$matched}" : ($c->specialization ? "See a {$c->specialization}" : null),
                    'urgent' => false,
                ];
            })
            ->filter();

        // Symptoms from the admin-editable triage config.
        $config = TriageConfig::current();
        $symptoms = collect($config['symptoms'] ?? [])
            ->filter(fn ($s) => is_array($s) && isset($s['label']) && str_contains(mb_strtolower($s['label']), $needle))
            ->map(fn ($s) => [
                'rank' => 3,
                'type' => 'symptom',
                'id' => $s['id'] ?? null,
                'name' => $s['label'],
                'specialization' => self::SYSTEM_SPECIALTY[$s['system'] ?? ''] ?? 'General Practitioner',
                'subtitle' => 'Symptom',
                'urgent' => false,
            ]);

        // Red-flag discriminators route to emergency triage, not a booking.
        $redFlags = collect($config['discriminators'] ?? [])
            ->filter(fn ($d) => is_array($d) && isset($d['label']) && str_contains(mb_strtolower($d['label']), $needle))
            ->map(fn ($d) => [
                'rank' => 4,
                'type' => 'symptom',
                'id' => $d['id'] ?? null,
                'name' => $d['label'],
                'specialization' => null,
                'subtitle' => 'Emergency sign: start triage',
                'urgent' => in_array($d['level'] ?? null, ['red', 'orange'], true),
            ]);

        return $results->concat($symptoms)->concat($redFlags)
            ->unique(fn ($r) => mb_strtolower($r['name']))
            ->sortBy('rank')
            ->take(self::PER_GROUP)
            ->map(fn ($r) => collect($r)->except('rank')->all())
            ->values()
            ->all();
    }

    protected function doctors(string $q): array
    {
        return DoctorProfile::query()
            ->where('status', 'active')
            ->where(fn ($w) => $w
                ->whereHas('user', fn ($u) => $u->where('name', 'like', $this->like($q)))
                ->orWhere('specialization', 'like', $this->like($q)))
            ->with('user:id,name,avatar')
            ->orderByDesc('rating_avg')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (DoctorProfile $d) => [
                'type' => 'doctor',
                'id' => $d->id,
                'name' => $d->user?->name,
                'specialization' => $d->specialization,
                'subtitle' => $d->specialization,
                'avatar_url' => WidgetDataService::mediaUrl($d->user?->avatar),
                'rating_avg' => round((float) $d->rating_avg, 1),
                'rating_count' => (int) $d->rating_count,
                'is_accepting_appointments' => (bool) $d->is_accepting_appointments,
                'url' => route('doctors.show', $d),
            ])
            ->all();
    }

    protected function clinics(string $q): array
    {
        return Clinic::query()
            ->where('status', 'active')
            ->where(fn ($w) => $w
                ->where('name', 'like', $this->like($q))
                ->orWhere('city', 'like', $this->like($q)))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Clinic $c) => [
                'type' => 'clinic',
                'id' => $c->id,
                'name' => $c->name,
                'subtitle' => collect([$c->address, $c->city])->filter()->implode(', '),
                'logo_url' => WidgetDataService::mediaUrl($c->logo),
                'url' => route('clinics.show', $c),
            ])
            ->all();
    }

    protected function products(string $q): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('name', 'like', $this->like($q))
            ->with('pharmacy')
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get();

        return $products->map(fn (Product $p) => [
            'type' => 'product',
            'id' => $p->id,
            'name' => $p->name,
            'subtitle' => trim(($p->pharmacy?->name ?? '').' · R '.number_format((float) $p->price, 2), ' ·'),
            'url' => route('shop.show', $p),
            'product' => (new ProductResource($p))->resolve(),
        ])->all();
    }
}
