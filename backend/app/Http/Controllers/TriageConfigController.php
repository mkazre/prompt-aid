<?php

namespace App\Http\Controllers;

use App\Models\TriageConfig;

/**
 * Serves the admin-editable pre-triage config (symptoms, discriminators,
 * scoring weights, level metadata, facility capability) as JSON. Also
 * injected server-side into emergency.blade.php (see StaticPageController::
 * emergency()) so the triage page never has to wait on this endpoint — this
 * route exists for completeness / external consumers / cache-busted reloads.
 */
class TriageConfigController extends Controller
{
    public function show()
    {
        return response()->json(TriageConfig::current());
    }
}
