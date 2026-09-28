<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalScheme;

/**
 * Public read endpoint so the mobile registration wizard's scheme step can
 * populate its picker — mirrors Account\ProfileController's web-side query.
 */
class MedicalSchemeController extends Controller
{
    public function index()
    {
        return MedicalScheme::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
