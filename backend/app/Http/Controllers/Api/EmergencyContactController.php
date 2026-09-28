<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmergencyContactResource;
use App\Models\EmergencyContact;

/**
 * Public read endpoint for the admin-configurable numbers that used to be
 * hardcoded across the mobile triage flow (TriageStartScreen, TriageFlagsScreen,
 * TriageResultScreen) — mirrors EmergencyContact::activeOrdered() already used
 * by the web triage pages.
 */
class EmergencyContactController extends Controller
{
    public function index()
    {
        return EmergencyContactResource::collection(EmergencyContact::activeOrdered());
    }
}
