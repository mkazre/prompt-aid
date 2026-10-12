<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Faq;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/**
 * Public read endpoints for the app: Help centre FAQs and announcements.
 * Both accept an optional Sanctum token so results follow the viewer's
 * role (patient / doctor / driver).
 */
class HelpController extends Controller
{
    public function faqs(Request $request)
    {
        $user = $request->user('sanctum');
        $faqs = Faq::forViewer($user?->role, (string) $request->query('q', ''));

        return response()->json([
            'data' => $faqs->map(fn (Faq $f) => $f->toApiArray())->values(),
            'meta' => [
                'heading' => SupportSettings::str('help_heading'),
                'intro' => SupportSettings::str('help_intro'),
                'web_url' => route('help'),
            ],
        ]);
    }

    public function announcements(Request $request)
    {
        $user = $request->user('sanctum');

        // "Guests" announcements are website-only: the app is always signed in.
        $items = Announcement::liveFor($user)->reject(fn (Announcement $a) => $user && $a->audience === 'guests');

        return response()->json(['data' => $items->map(fn (Announcement $a) => $a->toApiArray())->values()]);
    }
}
