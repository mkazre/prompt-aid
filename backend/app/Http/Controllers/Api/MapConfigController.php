<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MapSettings;
use Illuminate\Http\JsonResponse;

class MapConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['data' => MapSettings::clientConfig()])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
