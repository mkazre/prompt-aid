<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedbackSurvey;
use App\Services\Support\FeedbackSurveyService;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/** "How was your visit?" surveys for the app (Home card + notification tap). */
class FeedbackController extends Controller
{
    public function pending(Request $request)
    {
        $survey = FeedbackSurvey::query()
            ->where('user_id', $request->user()->id)
            ->pending()
            ->where('prompted_at', '>=', now()->subDays(30))
            ->latest('prompted_at')
            ->first();

        return response()->json([
            'data' => $survey?->toApiArray(),
            'meta' => $this->meta(),
        ]);
    }

    public function show(Request $request, string $token)
    {
        return response()->json([
            'data' => $this->find($request, $token)->toApiArray(),
            'meta' => $this->meta(),
        ]);
    }

    public function submit(Request $request, string $token, FeedbackSurveyService $service)
    {
        $survey = $this->find($request, $token);

        $data = $request->validate([
            'score' => ['required', 'integer', 'between:0,10'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->submit($survey, (int) $data['score'], $data['comment'] ?? null);

        return response()->json(['data' => $survey->fresh()->toApiArray(), 'message' => 'Thank you for your feedback.']);
    }

    public function dismiss(Request $request, string $token, FeedbackSurveyService $service)
    {
        $service->dismiss($this->find($request, $token));

        return response()->json(['ok' => true]);
    }

    protected function find(Request $request, string $token): FeedbackSurvey
    {
        return FeedbackSurvey::query()->where('token', $token)->where('user_id', $request->user()->id)->firstOrFail();
    }

    protected function meta(): array
    {
        return [
            'title' => SupportSettings::str('feedback_title'),
            'body' => SupportSettings::str('feedback_body'),
        ];
    }
}
