<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;

/**
 * For Providers carries a real lead-capture form, so — unlike About, How
 * It Works, FAQ, Privacy, Terms and the POPIA notice — it stays compiled
 * Blade+controller code rather than a page-builder page: builder pages
 * store raw HTML, which can't run Blade's route()/@csrf for a working POST.
 */
class StaticPageController extends Controller
{
    public function forProviders()
    {
        return view('pages.for-providers');
    }

    public function forProvidersSubmit(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'terms_accepted' => ['required', 'accepted'],
        ]);

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                "Provider application: {$data['business_name']}",
                "{$data['type']} · {$data['contact_name']} · {$data['email']} · {$data['phone']}".
                    (! empty($data['registration_no']) ? " · Reg. {$data['registration_no']}" : ''),
                icon: 'heroicon-o-building-office',
                color: 'info',
                url: 'mailto:'.$data['email'],
                actionLabel: 'Reply by email',
            );
        }

        return back()->with('success', "Thanks {$data['contact_name']}, we've received your application and will be in touch within two working days.");
    }
}
