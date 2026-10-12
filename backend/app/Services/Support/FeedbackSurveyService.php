<?php

namespace App\Services\Support;

use App\Models\Appointment;
use App\Models\FeedbackSurvey;
use App\Models\Ride;
use App\Services\Notifications\InAppNotifier;
use App\Support\SupportSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "How was your visit?" NPS surveys.
 *
 * A survey row is created — and the patient prompted with an in-app
 * notification (push + web bell) — once per completed appointment or
 * shuttle ride. Two triggers, both idempotent thanks to the unique
 * (subject_type, subject_id) key:
 *   1. model `updated` hooks the moment the status flips to completed
 *      (registered in AppServiceProvider);
 *   2. a Tick sweep for anything completed recently that the hook missed
 *      (status changed by a raw query, a failed push, etc.).
 */
class FeedbackSurveyService
{
    public function enabledFor(string $type): bool
    {
        if (! SupportSettings::bool('feedback_enabled')) {
            return false;
        }

        return $type === 'ride'
            ? SupportSettings::bool('feedback_rides')
            : SupportSettings::bool('feedback_appointments');
    }

    public function promptForAppointment(Appointment $appointment): ?FeedbackSurvey
    {
        if ($appointment->status !== Appointment::STATUS_COMPLETED || ! $this->enabledFor('appointment')) {
            return null;
        }

        $appointment->loadMissing(['patient', 'doctor.user', 'clinic']);
        $userId = $appointment->patient?->user_id;
        if (! $userId) {
            return null;
        }

        $who = $appointment->doctor?->user?->name ? 'Dr. '.preg_replace('/^Dr\.?\s*/i', '', $appointment->doctor->user->name) : ($appointment->clinic?->name ?? 'your practitioner');
        $label = 'Visit with '.$who.' on '.$appointment->date?->format('j M');

        return $this->create('appointment', $appointment->id, $userId, $label);
    }

    public function promptForRide(Ride $ride): ?FeedbackSurvey
    {
        if ($ride->status !== Ride::STATUS_COMPLETED || ! $this->enabledFor('ride')) {
            return null;
        }

        $ride->loadMissing('patient');
        $userId = $ride->patient?->user_id;
        if (! $userId) {
            return null;
        }

        $label = 'Shuttle trip on '.($ride->completed_at ?? $ride->updated_at ?? now())->format('j M').($ride->dropoff_address ? ' to '.Str::limit((string) $ride->dropoff_address, 40) : '');

        return $this->create('ride', $ride->id, $userId, $label);
    }

    protected function create(string $type, int $subjectId, int $userId, string $label): ?FeedbackSurvey
    {
        if (FeedbackSurvey::query()->where('subject_type', $type)->where('subject_id', $subjectId)->exists()) {
            return null;
        }

        try {
            $survey = FeedbackSurvey::query()->create([
                'token' => Str::random(40),
                'user_id' => $userId,
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'subject_label' => mb_substr($label, 0, 255),
                'prompted_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Lost a race with the other trigger — the unique key did its job.
            return null;
        }

        app(InAppNotifier::class)->send(
            $userId,
            'general',
            SupportSettings::str('feedback_title'),
            $label.' — '.SupportSettings::str('feedback_body'),
            [
                'url' => route('account.feedback.show', $survey->token, false),
                'route' => 'feedback',
                'params' => ['token' => $survey->token],
            ],
        );

        return $survey;
    }

    /** Tick job: catch completions the model hooks missed. Bounded per run. */
    public function sweep(): int
    {
        if (! SupportSettings::bool('feedback_enabled')) {
            return 0;
        }

        $days = max(1, SupportSettings::int('feedback_lookback_days'));
        $since = now()->subDays($days);
        $count = 0;

        if ($this->enabledFor('appointment')) {
            Appointment::query()
                ->where('status', Appointment::STATUS_COMPLETED)
                ->where('updated_at', '>=', $since)
                ->whereDate('date', '>=', $since->toDateString())
                ->whereDate('date', '<=', now()->toDateString())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('feedback_surveys')
                    ->where('feedback_surveys.subject_type', 'appointment')
                    ->whereColumn('feedback_surveys.subject_id', 'appointments.id'))
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->each(function (Appointment $a) use (&$count) {
                    if ($this->safely(fn () => $this->promptForAppointment($a))) {
                        $count++;
                    }
                });
        }

        if ($this->enabledFor('ride')) {
            Ride::query()
                ->where('status', Ride::STATUS_COMPLETED)
                ->where('completed_at', '>=', $since)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('feedback_surveys')
                    ->where('feedback_surveys.subject_type', 'ride')
                    ->whereColumn('feedback_surveys.subject_id', 'rides.id'))
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->each(function (Ride $r) use (&$count) {
                    if ($this->safely(fn () => $this->promptForRide($r))) {
                        $count++;
                    }
                });
        }

        return $count;
    }

    public function submit(FeedbackSurvey $survey, int $score, ?string $comment): FeedbackSurvey
    {
        $survey->update([
            'score' => max(0, min(10, $score)),
            'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000) : null,
            'responded_at' => now(),
            'dismissed_at' => null,
        ]);

        return $survey;
    }

    public function dismiss(FeedbackSurvey $survey): void
    {
        if (! $survey->isAnswered()) {
            $survey->update(['dismissed_at' => now()]);
        }
    }

    /**
     * @return array{responses: int, promoters: int, passives: int, detractors: int, nps: int|null, average: float|null}
     */
    public static function summary(?string $type = null, ?\DateTimeInterface $since = null): array
    {
        $q = FeedbackSurvey::query()->responded()->whereNotNull('score');
        if ($type) {
            $q->where('subject_type', $type);
        }
        if ($since) {
            $q->where('responded_at', '>=', $since);
        }

        $row = $q->selectRaw('COUNT(*) as total, SUM(CASE WHEN score >= 9 THEN 1 ELSE 0 END) as promoters, SUM(CASE WHEN score BETWEEN 7 AND 8 THEN 1 ELSE 0 END) as passives, SUM(CASE WHEN score <= 6 THEN 1 ELSE 0 END) as detractors, AVG(score) as average')->first();

        $total = (int) ($row->total ?? 0);
        $promoters = (int) ($row->promoters ?? 0);
        $detractors = (int) ($row->detractors ?? 0);

        return [
            'responses' => $total,
            'promoters' => $promoters,
            'passives' => (int) ($row->passives ?? 0),
            'detractors' => $detractors,
            'nps' => $total > 0 ? (int) round(($promoters - $detractors) / $total * 100) : null,
            'average' => $total > 0 ? round((float) $row->average, 1) : null,
        ];
    }

    protected function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('[Feedback] prompt failed: '.$e->getMessage());

            return null;
        }
    }
}
