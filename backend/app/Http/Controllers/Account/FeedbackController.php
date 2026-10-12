<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\FeedbackSurvey;
use App\Services\Support\FeedbackSurveyService;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/** "How was your visit?" — the web survey page linked from the notification. */
class FeedbackController extends Controller
{
    public function show(Request $request, string $token)
    {
        $survey = $this->find($request, $token);

        return view('account.feedback', [
            'survey' => $survey,
            'title' => SupportSettings::str('feedback_title'),
            'body' => SupportSettings::str('feedback_body'),
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

        return redirect()->route('account.feedback.show', $survey->token)->with('success', 'Thank you — your feedback helps us improve.');
    }

    protected function find(Request $request, string $token): FeedbackSurvey
    {
        return FeedbackSurvey::query()
            ->where('token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
