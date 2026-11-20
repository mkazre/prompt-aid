<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\ProviderSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provider self sign-up from the app (doctors, practices, pharmacies, labs, radiology, allied). */
class ProviderSignupController extends Controller
{
    public function __construct(protected ProviderSignup $signup) {}

    /** GET /api/auth/signup-options — what "Join as…" shows (admin-switchable). */
    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->signup->catalogue()])->header('Cache-Control', 'no-cache, private');
    }

    /** POST /api/auth/register-provider (multipart: fields + documents[]). No token: the login works once approved. */
    public function store(Request $request): JsonResponse
    {
        ['application' => $app] = $this->signup->register($request);

        return response()->json([
            'message' => trim((string) \App\Support\SiteContent::get('signup.review_note', '')) ?: 'Thanks — we received your application. Our team checks your registration and documents, usually within two working days. You can sign in with the email and password you chose as soon as you are approved.',
            'data' => ['application_id' => $app->id, 'reference' => 'PA-'.str_pad((string) $app->id, 5, '0', STR_PAD_LEFT), 'status' => $app->status],
        ], 201);
    }
}
