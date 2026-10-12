<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Features;

/**
 * GET /api/features → { data: { shop: true, shuttle: false, ... } }
 * Public: the app reads it before sign-in. Also included in /api/site-content.
 */
class FeaturesController extends Controller
{
    public function __invoke()
    {
        return response()->json(['data' => Features::all()]);
    }
}
