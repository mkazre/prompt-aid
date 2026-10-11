<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Services\Rides\RideStatusPresenter;
use Illuminate\Http\Request;

/**
 * Public, read-only "follow my trip" link (/trip/{token}). The token is a
 * random 40-char value generated per ride; the link stops showing live data
 * once the ride ends. Rate-limited in routes. Shows map, driver first
 * name/vehicle/plate and ETA only — no addresses, medical or contact data.
 */
class ShareTripController extends Controller
{
    public function show(string $token, RideStatusPresenter $presenter)
    {
        $ride = $this->find($token);

        return response()
            ->view('rides.share', [
                'token' => $token,
                'initial' => $ride && $ride->isActive() ? $presenter->forShare($ride) : null,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function status(Request $request, string $token, RideStatusPresenter $presenter)
    {
        $ride = $this->find($token);
        if (! $ride || ! $ride->isActive()) {
            return response()->json(['ended' => true, 'poll_ms' => 0]);
        }

        return response()->json($presenter->forShare($ride, $request->query('rk'), $request->query('tk')))
            ->header('X-Robots-Tag', 'noindex');
    }

    protected function find(string $token): ?Ride
    {
        if (strlen($token) < 32 || strlen($token) > 64 || ! ctype_alnum($token)) {
            return null;
        }

        return Ride::query()->where('share_token', $token)->first();
    }
}
