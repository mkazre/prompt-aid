<?php

namespace App\Http\Controllers;

use App\Models\ThirdPartyProfile;
use Illuminate\Http\Request;

/**
 * Public directories for the two ThirdPartyProfile groupings shown in the
 * new-ui nav: "Labs & imaging" (lab/imaging) and "Physio & specialists"
 * (everything else — physio, optometry, dental, dietetics, audiology,
 * home nursing).
 */
class ThirdPartyPageController extends Controller
{
    protected const LAB_CATEGORIES = ['lab', 'imaging'];

    public function labsIndex(Request $request)
    {
        return view('pages.labs', ['providers' => $this->query($request, self::LAB_CATEGORIES)]);
    }

    public function labsShow(ThirdPartyProfile $lab)
    {
        abort_unless(in_array($lab->category, self::LAB_CATEGORIES, true), 404);

        return view('pages.lab-single', ['provider' => $lab->load('user')]);
    }

    public function specialistsIndex(Request $request)
    {
        $categories = array_diff(ThirdPartyProfile::CATEGORIES, self::LAB_CATEGORIES);

        return view('pages.specialists', ['providers' => $this->query($request, $categories)]);
    }

    public function specialistsShow(ThirdPartyProfile $specialist)
    {
        abort_if(in_array($specialist->category, self::LAB_CATEGORIES, true), 404);

        return view('pages.specialist-single', ['provider' => $specialist->load('user')]);
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
