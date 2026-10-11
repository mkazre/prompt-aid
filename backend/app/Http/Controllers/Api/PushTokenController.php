<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;

/** The app registers its Expo push token after login and removes it on logout. */
class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^(Exponent|Expo)PushToken\\[.+\\]$/'],
            'platform' => ['nullable', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        // A device moves to whoever is signed in on it now.
        PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null, 'device_name' => $data['device_name'] ?? null, 'last_used_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        PushToken::query()->where('token', $data['token'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
