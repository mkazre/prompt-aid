<?php

namespace App\Http\Controllers;

use App\Support\Tick;
use Illuminate\Http\Request;

/** GET /tick?key=... — for an external uptime pinger when the host has no cron. Disabled while the key setting is empty. */
class TickController extends Controller
{
    public function __invoke(Request $request)
    {
        $key = Tick::secretKey();
        abort_if($key === '' || ! hash_equals($key, (string) $request->query('key', '')), 404);

        return response()->json(['ran' => Tick::run(), 'at' => now()->toIso8601String()]);
    }
}
