<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\PromoCodeController;
use App\Services\Commerce\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /promo-codes/validate (session auth) — used by the pharmacy checkout page. */
class PromoCodeWebController extends Controller
{
    public function validateCode(Request $request, PromoCodeService $promos): JsonResponse
    {
        return PromoCodeController::respond($request, $promos);
    }
}
