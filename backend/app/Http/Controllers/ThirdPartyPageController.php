<?php

namespace App\Http\Controllers;

use App\Models\ThirdPartyProfile;
use Illuminate\Http\Request;

/**
 * Public directories for ThirdPartyProfile groupings on the Find care tabs:
 * Radiology (imaging), Laboratories (lab) and Allied (everything else —
 * physio, optometry, dental, dietetics, audiology, home nursing).
 * Lab and imaging providers share the /labs/{lab} detail page; allied
 * providers use /allied/{provider}.
 */
class ThirdPartyPageController extends Controller
{
    protected const LAB_CATEGORIES = ['lab', 'imaging'];

    public function radiologyIndex(Request $request)
    {
        return $this->directory($request, ['imaging'], 'radiology', 'Search radiology and x-ray centres…', 'View centre');
    }

    public function laboratoriesIndex(Request $request)
    {
        return $this->directory($request, ['lab'], 'laboratories', 'Search laboratories…', 'View lab');
    }

    public function alliedIndex(Request $request)
    {
        $categories = array_values(array_diff(ThirdPartyProfile::CATEGORIES, self::LAB_CATEGORIES));

        return $this->directory($request, $categories, 'allied', 'Search allied practitioners…', 'View practice');
    }

    public function labsShow(ThirdPartyProfile $lab)
    {
        abort_unless(in_array($lab->category, self::LAB_CATEGORIES, true), 404);

        return view('pages.lab-single', ['provider' => $lab->load('user')]);
    }

    public function alliedShow(ThirdPartyProfile $provider)
    {
        abort_if(in_array($provider->category, self::LAB_CATEGORIES, true), 404);

        return view('pages.specialist-single', ['provider' => $provider->load('user')]);
    }

    protected function directory(Request $request, array $categories, string $tab, string $placeholder, string $cta)
    {
        return view('pages.provider-directory', [
            'providers' => $this->query($request, $categories),
            'tab' => $tab,
            'meta' => \App\Support\SiteContent::get("care.tabs.{$tab}"),
            'placeholder' => $placeholder,
            'cta' => $cta,
            'showRoute' => $tab === 'allied' ? 'allied.show' : 'labs.show',
        ]);
    }

    protected function query(Request $request, array $categories)
    {
        return ThirdPartyProfile::query()
            ->where('status', 'active')
            ->whereIn('category', $categories)
            ->with('user')
            ->when($request->filled('search'), fn ($q) => $q->where('company_name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderByDesc('rating_avg')
            ->paginate(9)
            ->withQueryString();
    }
}
