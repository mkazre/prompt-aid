<?php

namespace App\Services\Triage;

use App\Models\EmergencyContact;
use App\Models\TriageScenario;
use App\Models\TriageScenarioVersion;
use App\Models\User;
use App\Services\Doctors\DoctorMatchService;
use App\Support\DispatchSettings;
use App\Support\DoctorNowSettings;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Patient request scenarios — what happens, in which order, after a patient
 * chooses RED / ORANGE / YELLOW / GREEN (emergency scale popup, triage
 * questionnaire result, app and website alike).
 *
 * A scenario is an ordered list of steps from a fixed catalogue (types()),
 * each with its own settings. The ORDER is the behaviour: e.g. for orange
 * "collect_payment → broadcast_to_doctors → choose_channel" means the request
 * goes out to every available GP first and the patient picks video / voice /
 * WhatsApp only AFTER a doctor accepted; moving choose_channel above
 * broadcast_to_doctors restores the old "how should the doctor reach you?"
 * question up front.
 *
 * Storage: triage_scenarios (one row per level, versioned) +
 * triage_scenario_versions (immutable history, restorable). Cached forever
 * per level, busted on save. When a row is missing (fresh install before
 * the migration ran) the recommended default is used, so clients never
 * break.
 *
 * Consumers: TriageScenarioController (GET /api/triage/scenario/{level},
 * /emergency/scenario/{level}[.json]), DoctorMatchService (per-request
 * snapshot: specialty, dispatch mode, timeouts, channel timing),
 * ConsultHubService (post-accept channel choice), the Filament editor.
 */
class ScenarioEngine
{
    /**
     * Triage colours plus 'virtual' = the BLUE "Virtual / video consult" button of the
     * emergency popup (web) / EmergencyScale (app): the "speak to a doctor now" page where
     * the patient chooses video consult or call back, then the request goes to doctors.
     */
    public const LEVELS = ['red', 'orange', 'yellow', 'green', 'virtual'];

    /** The four triage colours (TriageSubmission levels) without the blue button. */
    public const TRIAGE_LEVELS = ['red', 'orange', 'yellow', 'green'];

    public const LEVEL_LABELS = [
        'red' => 'Red · immediate',
        'orange' => 'Orange · very urgent',
        'yellow' => 'Yellow · urgent',
        'green' => 'Green · routine',
        'virtual' => 'Blue button · virtual / video consult',
    ];

    /** Consult channels a patient can be offered. */
    public const CHANNELS = [
        'video' => 'Video call',
        'voice' => 'In-app voice call',
        'whatsapp' => 'WhatsApp call',
        'phone' => 'Phone call-back',
        'chat' => 'Chat',
    ];

    /**
     * Channels the patient can pick BEFORE a doctor is found: video / voice
     * become a video consult the doctor rings into; phone / WhatsApp become a
     * call-back to the number the patient enters. Chat needs a doctor first.
     */
    public const PRESELECT_CHANNELS = ['video', 'voice', 'whatsapp', 'phone'];

    public const DISPATCH_MODES = ['default' => 'Use Dispatch settings', 'broadcast' => 'Broadcast to all (first accept wins)', 'sequential' => 'One doctor at a time', 'manual' => 'Operations assigns manually'];

    public const FALLBACKS = [
        'retry' => 'Offer to try again',
        'widen_radius' => 'Try again with a wider search radius',
        'show_emergency' => 'Show the emergency number',
        'book_next_slot' => 'Offer to book the next available slot',
    ];

    public const CARE_CATEGORIES = [
        'all' => 'All care', 'gp' => 'General practitioners', 'dentists' => 'Dentists', 'specialists' => 'Specialists',
        'video' => 'Virtual consult', 'allied' => 'Allied health', 'radiology' => 'Radiology', 'laboratories' => 'Laboratories',
    ];

    public const BOOK_WHEN = [
        'today' => 'Today', 'next_available' => 'Next available slot', 'later' => 'Any doctor later today / tomorrow (request)',
    ];

    public const MESSAGE_STYLES = ['info' => 'Information', 'warning' => 'Warning', 'danger' => 'Danger (red)', 'success' => 'Reassurance (green)'];

    /** Steps that need a patient action and therefore stop an automatic doctor request in front of them. */
    public const ACTION_TYPES = ['call_emergency', 'offer_ambulance_or_shuttle', 'book_appointment', 'find_care', 'pharmacy', 'health_article'];

    /** Steps that belong to the "speak to a doctor now" consult flow. */
    public const CONSULT_TYPES = ['collect_payment', 'broadcast_to_doctors', 'choose_channel', 'auto_start_channel', 'practice_call', 'capture_booking'];

    /** Only one of each of these per scenario. */
    public const SINGLE_TYPES = ['call_emergency', 'offer_ambulance_or_shuttle', 'collect_payment', 'broadcast_to_doctors', 'choose_channel', 'auto_start_channel', 'practice_call', 'capture_booking', 'pharmacy', 'end'];

    /** Consult types the practice can book during a practice call. */
    public const BOOKING_MODES = ['in_person' => 'In-person consult', 'video' => 'Video consult'];

    public const MAX_STEPS = 15;

    protected const CACHE_PREFIX = 'triage_scenario.v1.';

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * Every step type with its label, help text and settings (key => [kind, default]).
     * Kinds: text, textarea, bool, int (nullable when default null), select, channels.
     *
     * @return array<string, array{label: string, help: string, settings: array<string, array{0: string, 1: mixed}>}>
     */
    public static function types(): array
    {
        return [
            'show_message' => [
                'label' => 'Show a message',
                'help' => 'A coloured note at this point of the flow (does not stop the flow).',
                'settings' => ['title' => ['text', 'Important'], 'body' => ['textarea', ''], 'style' => ['select', 'info']],
            ],
            'call_emergency' => [
                'label' => 'Call emergency services',
                'help' => 'Big call button for the primary emergency contact (Staff → System → Emergency Contacts). Auto-dial with a 0 s countdown dials the moment the patient taps the colour.',
                'settings' => ['label' => ['text', 'Call an ambulance now'], 'auto_dial' => ['bool', false], 'countdown_seconds' => ['int', 5], 'show_list' => ['bool', true]],
            ],
            'offer_ambulance_or_shuttle' => [
                'label' => 'Offer ambulance or shuttle',
                'help' => 'Buttons to call an ambulance and/or book a Prompt Aid shuttle to care.',
                'settings' => ['ambulance' => ['bool', true], 'shuttle' => ['bool', true], 'note' => ['text', 'Need transport to care?']],
            ],
            'collect_payment' => [
                'label' => 'Collect payment upfront',
                'help' => 'Pay-before-we-connect (Payments settings). Only used while "Patients pay consultation fees through Prompt Aid" is ON (default OFF: practices charge outside the system, so this step is skipped). Must come before "Offer to doctors". The hold is only captured once the consult starts and is released if no doctor accepts.',
                'settings' => ['allow_medical_aid' => ['bool', true], 'note' => ['text', 'Pay securely to connect — refunded in full if no doctor accepts.']],
            ],
            'broadcast_to_doctors' => [
                'label' => 'Offer the request to available doctors',
                'help' => 'Uber-style request: alerts the available doctors (default every online GP at once); the first to accept gets the consult.',
                'settings' => [
                    'specialty' => ['text', ''],
                    'optional' => ['bool', false],
                    'button_label' => ['text', 'Request a doctor now'],
                    'dispatch_mode' => ['select', 'default'],
                    'offer_timeout_seconds' => ['int', null],
                    'max_wait_minutes' => ['int', null],
                    'radius_km' => ['int', null],
                    'fallback' => ['select', 'retry'],
                ],
            ],
            'choose_channel' => [
                'label' => 'Let the patient choose the channel',
                'help' => 'Video, in-app voice, WhatsApp, phone call-back or chat. AFTER "Offer to doctors" = asked once a doctor accepted; BEFORE = asked up front (old behaviour; chat not allowed).',
                'settings' => [
                    'channels' => ['channels', ['video', 'voice', 'whatsapp']],
                    'default_channel' => ['select', 'video'],
                    'title' => ['text', 'How would you like to talk to your doctor?'],
                    'auto_pick_after_seconds' => ['int', 0],
                ],
            ],
            'auto_start_channel' => [
                'label' => 'Start a channel automatically',
                'help' => 'No question: as soon as a doctor accepts, this channel starts. Must come after "Offer to doctors".',
                'settings' => ['channel' => ['select', 'video']],
            ],
            'practice_call' => [
                'label' => 'In-app call between the practice and the patient',
                'help' => 'After "Offer to doctors": the request goes to every available GP PRACTICE (their reception team; the GP when no receptionist is online). The first practice to accept gets the patient and an in-app voice call immediately rings BOTH sides — the practice and the patient each accept. Both are told the call is recorded.',
                'settings' => [
                    'record' => ['bool', true],
                    'title' => ['text', 'A practice accepted — answer their call'],
                ],
            ],
            'capture_booking' => [
                'label' => 'Practice captures the appointment during the call',
                'help' => 'After "In-app call": the practice books the appointment while on the call (GP, date/time, in-person or video) and can book a shuttle the patient chooses and pays for immediately. The booking shows on the doctor\'s and the patient\'s calendars.',
                'settings' => [
                    'in_person' => ['bool', true],
                    'video' => ['bool', true],
                    'default_mode' => ['select', 'in_person'],
                    'shuttle' => ['bool', true],
                ],
            ],
            'book_appointment' => [
                'label' => 'Book an appointment',
                'help' => 'Button to the booking list (today / next slot) or the "any doctor later today / tomorrow" request.',
                'settings' => ['when' => ['select', 'today'], 'specialty' => ['text', ''], 'label' => ['text', 'Book a doctor for today']],
            ],
            'find_care' => [
                'label' => 'Find care (listing)',
                'help' => 'Button to the Find care listing, filtered.',
                'settings' => ['category' => ['select', 'gp'], 'label' => ['text', 'Find care near me']],
            ],
            'self_care_advice' => [
                'label' => 'Self-care advice',
                'help' => 'A card of advice lines (one per line).',
                'settings' => ['title' => ['text', 'What to do right now'], 'lines' => ['textarea', '']],
            ],
            'health_article' => [
                'label' => 'Health article',
                'help' => 'Link to a health article (slug) or the article list (leave empty).',
                'settings' => ['slug' => ['text', ''], 'label' => ['text', 'Read our health articles']],
            ],
            'pharmacy' => [
                'label' => 'Pharmacy',
                'help' => 'Button to pharmacies / order medicine.',
                'settings' => ['label' => ['text', 'Ask a pharmacist or order medicine']],
            ],
            'end' => [
                'label' => 'End',
                'help' => 'Closing line. Must be the last step.',
                'settings' => ['message' => ['text', 'Come back to this tool if anything changes.']],
            ],
        ];
    }

    /** @return array<string, string> type => label */
    public static function typeOptions(): array
    {
        return array_map(fn ($t) => $t['label'], self::types());
    }

    /**
     * Recommended defaults (also what "Reset to recommended default" restores).
     *
     * @return array<string, array<int, array{id: string, type: string, settings: array<string, mixed>}>>
     */
    public static function defaults(): array
    {
        $s = fn (string $type, array $settings = [], ?string $id = null) => self::normalizeStep(['id' => $id, 'type' => $type, 'settings' => $settings]);

        return [
            'red' => [
                $s('show_message', ['title' => 'This is an emergency', 'body' => 'Not breathing, chest pain, heavy bleeding, unconscious or fitting: call for an ambulance now. Do not drive yourself.', 'style' => 'danger'], 'red-msg'),
                $s('call_emergency', ['label' => 'Call an ambulance now', 'auto_dial' => true, 'countdown_seconds' => 0, 'show_list' => true], 'red-call'),
                $s('collect_payment', [], 'red-pay'),
                $s('broadcast_to_doctors', ['optional' => true, 'button_label' => 'Also request a doctor now'], 'red-doc'),
                $s('choose_channel', ['channels' => ['video', 'voice', 'whatsapp'], 'default_channel' => 'video'], 'red-channel'),
            ],
            // Nov 2026: the first GP practice to accept calls the patient in-app and books the
            // appointment (in person / video, optional shuttle) during the call. No consult payment.
            'orange' => [
                $s('broadcast_to_doctors', ['button_label' => 'Get connected to a practice now', 'fallback' => 'retry'], 'orange-doc'),
                $s('practice_call', [], 'orange-call'),
                $s('capture_booking', [], 'orange-book'),
                $s('find_care', ['category' => 'gp', 'label' => 'Find a practice near me instead'], 'orange-find'),
                $s('end', ['message' => 'Your booking shows under Appointments and in your calendar.'], 'orange-end'),
            ],
            'yellow' => [
                $s('show_message', ['title' => 'You need to be seen today', 'body' => 'A GP or clinic can handle this — an emergency department will keep you waiting behind the red and orange cases.', 'style' => 'warning'], 'yellow-msg'),
                $s('book_appointment', ['when' => 'today', 'label' => 'Book a doctor for today'], 'yellow-book'),
                $s('book_appointment', ['when' => 'later', 'label' => 'Any doctor later today / tomorrow'], 'yellow-later'),
                $s('self_care_advice', ['title' => 'Until you are seen', 'lines' => "Write down when the symptoms started and anything that makes them worse.\nKeep drinking fluids.\nIf it gets worse, use the emergency scale again."], 'yellow-advice'),
            ],
            'green' => [
                $s('self_care_advice', ['title' => 'Look after yourself', 'lines' => "This can be booked normally, today or tomorrow.\nA pharmacist can advise on minor complaints without an appointment.\nCome back to this tool if anything changes — it only takes a minute."], 'green-advice'),
                $s('find_care', ['category' => 'all', 'label' => 'Book a non-urgent service or consult'], 'green-find'),
                $s('pharmacy', [], 'green-pharmacy'),
                $s('health_article', [], 'green-articles'),
            ],
            // Blue "Virtual / video consult" button: the former orange page — choose video consult
            // or call back first, then the request goes to every available doctor. No payment.
            'virtual' => [
                $s('choose_channel', ['channels' => ['video', 'phone', 'whatsapp'], 'default_channel' => 'video', 'title' => 'How should the doctor reach you?'], 'virtual-channel'),
                $s('broadcast_to_doctors', ['button_label' => 'Speak to a doctor now', 'fallback' => 'retry'], 'virtual-doc'),
                $s('book_appointment', ['when' => 'next_available', 'label' => 'Book the next available slot instead'], 'virtual-book'),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Normalising + validation
    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $step */
    public static function normalizeStep(array $step): array
    {
        $type = (string) ($step['type'] ?? '');
        $id = trim((string) ($step['id'] ?? ''));
        if ($id === '' || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) {
            $id = 'st_'.substr(bin2hex(random_bytes(6)), 0, 10);
        }
        $def = self::types()[$type] ?? null;
        if (! $def) {
            return ['id' => $id, 'type' => $type, 'settings' => []];
        }
        $in = is_array($step['settings'] ?? null) ? $step['settings'] : [];
        $out = [];
        foreach ($def['settings'] as $key => [$kind, $default]) {
            $v = array_key_exists($key, $in) ? $in[$key] : $default;
            $out[$key] = match ($kind) {
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default,
                'int' => ($v === null || $v === '') ? ($default === null ? null : (int) $default) : (is_numeric($v) ? (int) $v : $v),
                'channels' => array_values(array_map('strval', array_filter((array) $v, fn ($c) => is_string($c) && $c !== ''))),
                'textarea' => mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, 4000),
                default => mb_substr(trim((string) $v), 0, 500),
            };
        }

        return ['id' => $id, 'type' => $type, 'settings' => $out];
    }

    /** @param  array<int, array<string, mixed>>  $steps */
    public static function normalizeSteps(array $steps): array
    {
        $seen = [];
        $out = [];
        foreach (array_values($steps) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $n = self::normalizeStep($step);
            while (isset($seen[$n['id']])) {
                $n['id'] = 'st_'.substr(bin2hex(random_bytes(6)), 0, 10);
            }
            $seen[$n['id']] = true;
            $out[] = $n;
        }

        return $out;
    }

    /**
     * Ordering and settings rules. Errors block saving; warnings are shown.
     *
     * @param  array<int, array<string, mixed>>  $steps  normalised steps
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(array $steps): array
    {
        $errors = [];
        $warnings = [];
        $types = self::types();
        $n = count($steps);

        if ($n === 0) {
            return ['errors' => ['Add at least one step.'], 'warnings' => []];
        }
        if ($n > self::MAX_STEPS) {
            $errors[] = 'Keep it to '.self::MAX_STEPS.' steps or fewer.';
        }

        $pos = [];
        foreach ($steps as $i => $step) {
            $type = $step['type'] ?? '';
            if (! isset($types[$type])) {
                $errors[] = 'Step '.($i + 1).': choose a step type.';

                continue;
            }
            $pos[$type][] = $i;
        }
        foreach (self::SINGLE_TYPES as $t) {
            if (count($pos[$t] ?? []) > 1) {
                $errors[] = '"'.$types[$t]['label'].'" can only be used once.';
            }
        }
        $first = fn (string $t) => $pos[$t][0] ?? null;
        $label = fn (string $t) => '"'.$types[$t]['label'].'"';

        if (($e = $first('end')) !== null && $e !== $n - 1) {
            $errors[] = $label('end').' must be the last step.';
        }

        $b = $first('broadcast_to_doctors');
        $pay = $first('collect_payment');
        $choose = $first('choose_channel');
        $auto = $first('auto_start_channel');
        $pc = $first('practice_call');
        $cap = $first('capture_booking');

        if ($pay !== null && ($b === null || $pay > $b)) {
            $errors[] = $label('collect_payment').' must come before '.$label('broadcast_to_doctors').' (there is nothing to pay for otherwise).';
        }
        if ($b !== null && $pay === null && $this->paymentsOn()) {
            $errors[] = 'Upfront payment is switched on (Staff → Payment settings), so add '.$label('collect_payment').' before '.$label('broadcast_to_doctors').'.';
        }
        if ($auto !== null && ($b === null || $auto < $b)) {
            $errors[] = $label('auto_start_channel').' needs a doctor: put it after '.$label('broadcast_to_doctors').'.';
        }
        if ($choose !== null && $auto !== null) {
            $errors[] = 'Use either '.$label('choose_channel').' or '.$label('auto_start_channel').', not both.';
        }
        if ($choose !== null && $b === null) {
            $errors[] = $label('choose_channel').' only makes sense with '.$label('broadcast_to_doctors').'.';
        }
        if ($pc !== null && ($b === null || $pc < $b)) {
            $errors[] = $label('practice_call').' needs a practice: put it after '.$label('broadcast_to_doctors').'.';
        }
        if ($pc !== null && ($choose !== null || $auto !== null)) {
            $errors[] = 'With '.$label('practice_call').' the practice talks to the patient in the app — remove '.$label($choose !== null ? 'choose_channel' : 'auto_start_channel').'.';
        }
        if ($cap !== null && ($pc === null || $cap < $pc)) {
            $errors[] = $label('capture_booking').' happens during the call: put it after '.$label('practice_call').'.';
        }
        if ($pay !== null && ! $this->paymentsOn()) {
            $warnings[] = 'Consultation fees through Prompt Aid are switched OFF (Staff → Payment settings), so '.$label('collect_payment').' is skipped — patients are not asked to pay.';
        }

        foreach ($steps as $i => $step) {
            $type = $step['type'] ?? '';
            if (! isset($types[$type])) {
                continue;
            }
            $st = $step['settings'] ?? [];
            $where = 'Step '.($i + 1).' ('.$types[$type]['label'].'): ';
            switch ($type) {
                case 'show_message':
                    if (trim((string) $st['title']) === '' && trim((string) $st['body']) === '') {
                        $errors[] = $where.'enter a title or a message.';
                    }
                    if (! isset(self::MESSAGE_STYLES[$st['style']])) {
                        $errors[] = $where.'choose a style.';
                    }
                    break;
                case 'call_emergency':
                    if (! is_int($st['countdown_seconds']) || $st['countdown_seconds'] < 0 || $st['countdown_seconds'] > 60) {
                        $errors[] = $where.'the auto-dial countdown must be 0–60 seconds.';
                    }
                    if (! $this->primaryContact()) {
                        $warnings[] = $where.'there is no active primary emergency contact (Staff → System → Emergency Contacts) — the call button falls back to the dispatch number in Site content.';
                    }
                    if ($i > 0 && $st['auto_dial'] && $st['countdown_seconds'] === 0) {
                        $warnings[] = $where.'auto-dial at 0 s only dials instantly when it is the first action; here it dials as soon as the patient reaches it.';
                    }
                    break;
                case 'offer_ambulance_or_shuttle':
                    if (! $st['ambulance'] && ! $st['shuttle']) {
                        $errors[] = $where.'offer at least the ambulance or the shuttle.';
                    }
                    break;
                case 'broadcast_to_doctors':
                    if (! isset(self::DISPATCH_MODES[$st['dispatch_mode']])) {
                        $errors[] = $where.'choose a dispatch mode.';
                    }
                    if (! isset(self::FALLBACKS[$st['fallback']])) {
                        $errors[] = $where.'choose what happens when nobody accepts.';
                    }
                    if ($st['offer_timeout_seconds'] !== null && (! is_int($st['offer_timeout_seconds']) || $st['offer_timeout_seconds'] < 10 || $st['offer_timeout_seconds'] > 600)) {
                        $errors[] = $where.'each doctor\'s time to accept must be 10–600 seconds (empty = On-demand doctors setting).';
                    }
                    if ($st['max_wait_minutes'] !== null && (! is_int($st['max_wait_minutes']) || $st['max_wait_minutes'] < 1 || $st['max_wait_minutes'] > 120)) {
                        $errors[] = $where.'the maximum wait must be 1–120 minutes (empty = On-demand doctors setting).';
                    }
                    if ($st['radius_km'] !== null && (! is_int($st['radius_km']) || $st['radius_km'] < 1 || $st['radius_km'] > 500)) {
                        $errors[] = $where.'the radius must be 1–500 km.';
                    }
                    if ($st['specialty'] !== '' && ! in_array(mb_strtolower($st['specialty']), array_map('mb_strtolower', $this->specialties()), true)) {
                        $warnings[] = $where.'no active doctor has the specialty "'.$st['specialty'].'" yet — requests will fail until one registers.';
                    }
                    if (! DoctorNowSettings::bool('doctor_now_enabled')) {
                        $warnings[] = $where.'on-demand consults are switched off (Staff → System → On-demand doctors), so patients will see "switched off".';
                    }
                    if ($st['optional'] && $b === $i && $i === 0) {
                        $warnings[] = $where.'an optional request as the only action shows just a button.';
                    }
                    break;
                case 'choose_channel':
                    $chs = $st['channels'];
                    if ($chs === []) {
                        $errors[] = $where.'offer at least one channel.';
                    }
                    foreach ($chs as $c) {
                        if (! isset(self::CHANNELS[$c])) {
                            $errors[] = $where.'unknown channel "'.$c.'".';
                        }
                    }
                    if (count($chs) !== count(array_unique($chs))) {
                        $errors[] = $where.'each channel can only be listed once.';
                    }
                    if (! in_array($st['default_channel'], $chs, true)) {
                        $errors[] = $where.'the default channel must be one of the offered channels.';
                    }
                    if ($b !== null && $i < $b) {
                        $bad = array_values(array_diff($chs, self::PRESELECT_CHANNELS));
                        if ($bad) {
                            $errors[] = $where.'before a doctor accepts the patient can only pre-select video, voice, WhatsApp or phone call-back ('.implode(', ', array_map(fn ($c) => self::CHANNELS[$c] ?? $c, $bad)).' needs a doctor first — move this step after "Offer to doctors").';
                        }
                    }
                    if (! is_int($st['auto_pick_after_seconds']) || $st['auto_pick_after_seconds'] < 0 || $st['auto_pick_after_seconds'] > 600) {
                        $errors[] = $where.'"pick the default automatically after" must be 0–600 seconds.';
                    }
                    if (array_intersect($chs, ['video', 'voice']) && ! DoctorNowSettings::bool('doctor_now_video_enabled') && $b !== null && $i < $b) {
                        $warnings[] = $where.'video consults are switched off in On-demand doctors, so video / voice will be refused up front.';
                    }
                    break;
                case 'auto_start_channel':
                    if (! isset(self::CHANNELS[$st['channel']])) {
                        $errors[] = $where.'choose the channel to start.';
                    }
                    break;
                case 'practice_call':
                    if ($b !== null) {
                        $bs = $steps[$b]['settings'] ?? [];
                        if (! in_array($bs['dispatch_mode'] ?? 'default', ['default', 'broadcast'], true)) {
                            $errors[] = $where.'a practice call goes to every available practice at once — set the dispatch mode of '.$label('broadcast_to_doctors').' to "Use Dispatch settings" or "Broadcast".';
                        }
                    }
                    if (! $st['record']) {
                        $warnings[] = $where.'calls are not recorded — call logs are still kept.';
                    }
                    break;
                case 'capture_booking':
                    if (! $st['in_person'] && ! $st['video']) {
                        $errors[] = $where.'allow at least in-person or video consults.';
                    }
                    if (! isset(self::BOOKING_MODES[$st['default_mode']]) || empty($st[$st['default_mode']])) {
                        $errors[] = $where.'the default consult type must be one of the allowed types.';
                    }
                    break;
                case 'book_appointment':
                    if (! isset(self::BOOK_WHEN[$st['when']])) {
                        $errors[] = $where.'choose when.';
                    }
                    break;
                case 'find_care':
                    if (! isset(self::CARE_CATEGORIES[$st['category']])) {
                        $errors[] = $where.'choose a category.';
                    }
                    break;
                case 'self_care_advice':
                    if (trim((string) $st['lines']) === '') {
                        $errors[] = $where.'enter at least one advice line.';
                    }
                    break;
                case 'health_article':
                    if ($st['slug'] !== '' && ! preg_match('/^[a-z0-9-]{1,190}$/', $st['slug'])) {
                        $errors[] = $where.'the article slug may only contain lowercase letters, numbers and dashes.';
                    }
                    break;
            }
        }

        if ($b !== null && $choose === null && $auto === null && $pc === null) {
            $warnings[] = 'No channel step: the consult defaults to a video call that the doctor starts from the consult hub.';
        }
        if ($pc !== null && $cap === null) {
            $warnings[] = 'No '.$label('capture_booking').' step: the practice can still book from the call screen.';
        }
        $consultEnd = max(array_filter([$b, $choose, $auto, $pc, $cap], fn ($v) => $v !== null) ?: [-1]);
        if ($consultEnd >= 0) {
            foreach ($steps as $i => $step) {
                if ($i > $consultEnd && in_array($step['type'] ?? '', self::ACTION_TYPES, true)) {
                    $warnings[] = 'Step '.($i + 1).' comes after the doctor request: it is shown as an alternative when no doctor accepts (and on the scenario page).';
                    break;
                }
            }
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    // ------------------------------------------------------------------
    // Read / write
    // ------------------------------------------------------------------

    public static function isLevel(?string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    /**
     * The live scenario for a level (cached).
     *
     * @return array{level: string, version: int, steps: array<int, array<string, mixed>>, updated_at: ?string, updated_by: ?string, is_default: bool}
     */
    public function scenario(string $level): array
    {
        if (! self::isLevel($level)) {
            throw new \InvalidArgumentException('Unknown triage level.');
        }

        return Cache::rememberForever(self::CACHE_PREFIX.$level, function () use ($level) {
            $row = null;
            try {
                $row = TriageScenario::query()->with('editor:id,name')->where('level', $level)->where('is_active', true)->first();
            } catch (Throwable $e) {
                Log::warning('[ScenarioEngine] read failed, using defaults: '.$e->getMessage());
            }
            if (! $row) {
                return ['level' => $level, 'version' => 0, 'steps' => self::defaults()[$level], 'updated_at' => null, 'updated_by' => null, 'is_default' => true];
            }

            return [
                'level' => $level,
                'version' => (int) $row->version,
                'steps' => self::normalizeSteps((array) $row->steps),
                'updated_at' => $row->updated_at?->toIso8601String(),
                'updated_by' => $row->editor?->name,
                'is_default' => false,
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function steps(string $level): array
    {
        return $this->scenario($level)['steps'];
    }

    public static function forget(?string $level = null): void
    {
        foreach ($level ? [$level] : self::LEVELS as $l) {
            Cache::forget(self::CACHE_PREFIX.$l);
        }
    }

    /**
     * Validate and store a new version. Same steps as the live version → no new version.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function save(string $level, array $steps, ?User $by = null, ?string $note = null): TriageScenario
    {
        if (! self::isLevel($level)) {
            throw new ScenarioValidationException(['Unknown triage level.']);
        }
        $steps = self::normalizeSteps($steps);
        $check = $this->validate($steps);
        if ($check['errors']) {
            throw new ScenarioValidationException($check['errors']);
        }

        $row = DB::transaction(function () use ($level, $steps, $by, $note) {
            $row = TriageScenario::query()->where('level', $level)->lockForUpdate()->first();
            if ($row && self::sameSteps((array) $row->steps, $steps)) {
                return $row;
            }
            if (! $row) {
                $row = TriageScenario::query()->create(['level' => $level, 'steps' => $steps, 'version' => 1, 'is_active' => true, 'updated_by' => $by?->id]);
            } else {
                $row->forceFill(['steps' => $steps, 'version' => (int) $row->version + 1, 'is_active' => true, 'updated_by' => $by?->id])->save();
            }
            TriageScenarioVersion::query()->create([
                'triage_scenario_id' => $row->id,
                'level' => $level,
                'version' => $row->version,
                'steps' => $steps,
                'note' => $note !== null ? mb_substr($note, 0, 255) : null,
                'created_by' => $by?->id,
            ]);

            return $row;
        });
        self::forget($level);

        return $row->fresh();
    }

    public function restore(string $level, int $version, ?User $by = null): TriageScenario
    {
        $v = TriageScenarioVersion::query()->where('level', $level)->where('version', $version)->first();
        if (! $v) {
            throw new ScenarioValidationException(['That version does not exist.']);
        }

        return $this->save($level, (array) $v->steps, $by, 'Restored version '.$version);
    }

    public function reset(string $level, ?User $by = null): TriageScenario
    {
        return $this->save($level, self::defaults()[$level], $by, 'Reset to recommended default');
    }

    /** @return array<int, array{version: int, note: ?string, by: ?string, at: ?string, steps: array<int, mixed>}> */
    public function history(string $level, int $limit = 15): array
    {
        try {
            return TriageScenarioVersion::query()->with('author:id,name')->where('level', $level)->orderByDesc('version')->limit($limit)->get()
                ->map(fn (TriageScenarioVersion $v) => [
                    'version' => (int) $v->version,
                    'note' => $v->note,
                    'by' => $v->author?->name,
                    'at' => $v->created_at?->toIso8601String(),
                    'steps' => (array) $v->steps,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    protected static function sameSteps(array $a, array $b): bool
    {
        $strip = fn (array $steps) => array_map(fn ($s) => ['type' => $s['type'] ?? null, 'settings' => $s['settings'] ?? []], self::normalizeSteps($steps));

        return json_encode($strip($a)) === json_encode($strip($b));
    }

    // ------------------------------------------------------------------
    // Derived plan (what the consult flow does)
    // ------------------------------------------------------------------

    /**
     * The "speak to a doctor" part of a scenario, as the request engine and
     * the clients need it. has_consult=false when there is no
     * broadcast_to_doctors step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    public static function planFor(array $steps): array
    {
        $idx = fn (string $t) => collect($steps)->search(fn ($s) => ($s['type'] ?? null) === $t);
        $b = $idx('broadcast_to_doctors');
        if ($b === false) {
            return ['has_consult' => false, 'auto_request' => false, 'channel_timing' => 'none', 'channels' => [], 'payment' => false, 'flow' => 'none', 'practice_call' => false, 'booking' => null];
        }
        $bs = $steps[$b]['settings'];
        $c = $idx('choose_channel');
        $a = $idx('auto_start_channel');
        $p = $idx('collect_payment');
        $pcIdx = $idx('practice_call');
        $capIdx = $idx('capture_booking');
        $practice = $pcIdx !== false && $pcIdx > $b;

        $timing = 'none';
        $channels = [];
        $default = 'video';
        $autoChannel = null;
        $title = null;
        $autoPick = 0;
        if ($c !== false) {
            $cs = $steps[$c]['settings'];
            $timing = $c < $b ? 'before' : 'after';
            $channels = array_values(array_filter($cs['channels'], fn ($x) => isset(self::CHANNELS[$x])));
            $default = in_array($cs['default_channel'], $channels, true) ? $cs['default_channel'] : ($channels[0] ?? 'video');
            $title = $cs['title'] ?: null;
            $autoPick = max(0, (int) $cs['auto_pick_after_seconds']);
        } elseif ($a !== false) {
            $timing = 'auto';
            $autoChannel = $steps[$a]['settings']['channel'];
            $channels = [$autoChannel];
            $default = $autoChannel;
        }
        $booking = null;
        if ($practice) {
            // The practice calls the patient in the app (voice) once it accepted.
            $timing = 'practice';
            $channels = ['voice'];
            $default = 'voice';
            $autoChannel = null;
            $title = $steps[$pcIdx]['settings']['title'] ?: null;
            $cs = $capIdx !== false ? $steps[$capIdx]['settings'] : self::normalizeStep(['type' => 'capture_booking'])['settings'];
            $modes = array_values(array_filter(array_keys(self::BOOKING_MODES), fn ($m) => ! empty($cs[$m])));
            $booking = [
                'modes' => $modes ?: ['in_person'],
                'default_mode' => in_array($cs['default_mode'], $modes, true) ? $cs['default_mode'] : ($modes[0] ?? 'in_person'),
                'shuttle' => (bool) $cs['shuttle'],
            ];
        }

        // Automatic request: no patient action is needed in front of the doctor request.
        $blocked = false;
        foreach ($steps as $i => $s) {
            if ($i >= $b) {
                break;
            }
            if (in_array($s['type'] ?? '', self::ACTION_TYPES, true)) {
                $blocked = true;
            }
        }
        $optional = (bool) $bs['optional'];

        return [
            'has_consult' => true,
            'optional' => $optional,
            'auto_request' => ! $optional && ! $blocked && $timing !== 'before',
            'payment' => $p !== false && $p < $b && self::consultPaymentsOn(),
            'allow_medical_aid' => $p !== false ? (bool) $steps[$p]['settings']['allow_medical_aid'] : true,
            'payment_note' => $p !== false ? ($steps[$p]['settings']['note'] ?: null) : null,
            'specialty' => $bs['specialty'] !== '' ? $bs['specialty'] : null,
            'button_label' => $bs['button_label'] ?: 'Request a doctor now',
            'dispatch_mode' => $bs['dispatch_mode'] !== 'default' ? $bs['dispatch_mode'] : null,
            'offer_timeout_seconds' => $bs['offer_timeout_seconds'],
            'max_wait_minutes' => $bs['max_wait_minutes'],
            'radius_km' => $bs['radius_km'],
            'fallback' => $bs['fallback'],
            'channel_timing' => $timing,
            'channels' => $channels,
            'default_channel' => $default,
            'auto_channel' => $autoChannel,
            'choice_title' => $title,
            'auto_pick_after_seconds' => $autoPick,
            'flow' => $practice ? 'practice' : 'doctor',
            'practice_call' => $practice,
            'record_calls' => $practice ? (bool) $steps[$pcIdx]['settings']['record'] : false,
            'booking' => $booking,
        ];
    }

    /** @return array<string, mixed> */
    public function plan(string $level): array
    {
        return self::planFor($this->steps($level)) + ['level' => $level, 'version' => $this->scenario($level)['version']];
    }

    /**
     * Snapshot stored on a DoctorMatchRequest (so a later edit never changes
     * a request that is already running).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $level): array
    {
        $plan = $this->plan($level);

        return array_intersect_key($plan, array_flip([
            'level', 'version', 'specialty', 'dispatch_mode', 'offer_timeout_seconds', 'max_wait_minutes', 'radius_km', 'fallback',
            'channel_timing', 'channels', 'default_channel', 'auto_channel', 'choice_title', 'auto_pick_after_seconds', 'allow_medical_aid',
            'flow', 'practice_call', 'record_calls', 'booking',
        ]));
    }

    /**
     * Action steps placed AFTER the doctor request: shown as alternatives
     * when nobody accepted / the request was cancelled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alternatives(string $level, ?string $ref = null): array
    {
        $all = $this->resolve($level, $ref)['steps'];
        $consultEnd = -1;
        foreach ($all as $i => $s) {
            if (in_array($s['type'], self::CONSULT_TYPES, true)) {
                $consultEnd = $i;
            }
        }

        return array_values(array_filter($all, fn ($s, $i) => $i > $consultEnd && in_array($s['type'], self::ACTION_TYPES, true), ARRAY_FILTER_USE_BOTH));
    }

    // ------------------------------------------------------------------
    // Plain-English preview
    // ------------------------------------------------------------------

    /**
     * "What the patient will experience", one line per step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, string>
     */
    public function describe(array $steps): array
    {
        $steps = self::normalizeSteps($steps);
        $plan = self::planFor($steps);
        $lines = [];
        $phone = $this->primaryContact()?->phone ?? 'the emergency number';
        $defaultMode = DispatchSettings::mode('doctor_now');
        $offerSecs = max(10, DoctorNowSettings::int('doctor_now_offer_timeout_seconds'));
        $waitMins = max(1, DoctorNowSettings::int('doctor_now_request_timeout_minutes'));
        $chList = fn (array $chs) => self::humanList(array_map(fn ($c) => self::CHANNELS[$c] ?? $c, $chs), 'or');
        $firstAction = true;

        foreach ($steps as $i => $s) {
            $st = $s['settings'];
            $n = ($i + 1).'. ';
            switch ($s['type']) {
                case 'show_message':
                    $lines[] = $n.'Shows a '.(self::MESSAGE_STYLES[$st['style']] ?? 'note').' note: “'.trim($st['title'].($st['body'] !== '' ? ' — '.mb_strimwidth($st['body'], 0, 90, '…') : '')).'”.';
                    break;
                case 'call_emergency':
                    $how = $st['auto_dial']
                        ? ($st['countdown_seconds'] === 0 ? ($firstAction ? 'Dials '.$phone.' immediately when the patient taps this colour' : 'Dials '.$phone.' immediately') : 'Shows “'.$st['label'].'” and dials '.$phone.' automatically after '.$st['countdown_seconds'].' s (the patient can cancel)')
                        : 'Shows a “'.$st['label'].'” button for '.$phone;
                    $lines[] = $n.$how.($st['show_list'] ? ', with the other emergency numbers listed.' : '.');
                    $firstAction = false;
                    break;
                case 'offer_ambulance_or_shuttle':
                    $lines[] = $n.'Offers '.self::humanList(array_filter([$st['ambulance'] ? 'an ambulance ('.$phone.')' : null, $st['shuttle'] ? 'a Prompt Aid shuttle to care' : null]), 'or').'.';
                    $firstAction = false;
                    break;
                case 'collect_payment':
                    $lines[] = $n.'Takes the consult fee upfront'.($st['allow_medical_aid'] ? ' (or the medical-aid co-payment)' : '').' — no doctor is alerted until it is paid; refunded in full if nobody accepts.'.($this->paymentsOn() ? '' : ' (Consultation fees through Prompt Aid / upfront payment are OFF in Payment settings, so this is SKIPPED — the patient pays nothing.)');
                    break;
                case 'practice_call':
                    $lines[] = $n.'As soon as a practice accepts, an in-app VOICE call rings both the practice\'s reception and the patient (ringtone + push). Each side taps Accept; once both have, they are connected.'.($st['record'] ? ' Both are told the call is recorded; the recording and call log are kept for the practice and Prompt Aid.' : ' The call is not recorded; the call log is kept.');
                    break;
                case 'capture_booking':
                    $modes = array_values(array_filter(array_keys(self::BOOKING_MODES), fn ($m) => ! empty($st[$m])));
                    $lines[] = $n.'During the call the practice books the appointment — GP, date and time, '.self::humanList(array_map(fn ($m) => mb_strtolower(self::BOOKING_MODES[$m]), $modes), 'or').'. It appears on the doctor\'s and the patient\'s calendars at once.'
                        .($st['shuttle'] ? ' The practice can also book a shuttle: the patient sees the vehicle options with fares, chooses one and pays immediately.' : '');
                    break;
                case 'broadcast_to_doctors':
                    $spec = $st['specialty'] !== '' ? $st['specialty'] : 'General Practitioner';
                    $mode = $st['dispatch_mode'] !== 'default' ? $st['dispatch_mode'] : $defaultMode;
                    $reach = match ($mode) {
                        'sequential' => 'offers the request to one available '.$spec.' at a time',
                        'manual' => 'puts the request on the operations Live requests screen for manual assignment to a '.$spec,
                        default => 'sends the request to ALL available '.$spec.'s at once — the first doctor to accept gets it, like Uber',
                    };
                    $timing = ' (each doctor has '.($st['offer_timeout_seconds'] ?? $offerSecs).' s to accept; the search stops after '.($st['max_wait_minutes'] ?? $waitMins).' min).';
                    $fallback = ' If nobody accepts: '.mb_strtolower(self::FALLBACKS[$st['fallback']] ?? 'offer to try again').'.';
                    $lines[] = $n.($st['optional']
                        ? 'Shows a “'.$st['button_label'].'” button. If tapped, it '.$reach.$timing.$fallback
                        : ($plan['auto_request'] && $firstAction ? 'Immediately — without asking anything else — '.$reach.$timing.$fallback : 'Then '.$reach.$timing.$fallback));
                    $lines[] = ! empty($plan['practice_call'])
                        ? '   Each practice\'s online reception team (or its GP when nobody is at reception) gets the full-screen alert with sound; the others see “taken” as soon as one practice accepts. No payment is asked.'
                        : '   Doctors get the full-screen alert with sound; the others see “accepted by another doctor” as soon as one accepts.';
                    $firstAction = false;
                    break;
                case 'choose_channel':
                    $after = $plan['channel_timing'] === 'after';
                    $lines[] = $n.($after
                        ? 'After a doctor accepts, the patient sees “Dr X accepted” and chooses: '.$chList($st['channels']).' ('.(self::CHANNELS[$st['default_channel']] ?? $st['default_channel']).' highlighted). The consult starts in that channel; the doctor waits in the consult hub until the patient chooses.'
                        : 'Asks the patient up front how the doctor should reach them: '.$chList($st['channels']).'.')
                        .($after && $st['auto_pick_after_seconds'] > 0 ? ' If the patient has not chosen after '.$st['auto_pick_after_seconds'].' s, '.(self::CHANNELS[$st['default_channel']] ?? '').' starts automatically.' : '');
                    break;
                case 'auto_start_channel':
                    $lines[] = $n.'As soon as a doctor accepts, a '.mb_strtolower(self::CHANNELS[$st['channel']] ?? 'video call').' starts automatically — no question asked.';
                    break;
                case 'book_appointment':
                    $lines[] = $n.'Shows “'.$st['label'].'” → '.match ($st['when']) {
                        'later' => 'the “any doctor later today / tomorrow” request',
                        'next_available' => 'the booking list (next available slot)',
                        default => 'the booking list for today',
                    }.($st['specialty'] !== '' ? ' ('.$st['specialty'].')' : '').'.';
                    $firstAction = false;
                    break;
                case 'find_care':
                    $lines[] = $n.'Shows “'.$st['label'].'” → Find care: '.(self::CARE_CATEGORIES[$st['category']] ?? 'all').'.';
                    $firstAction = false;
                    break;
                case 'self_care_advice':
                    $count = count(array_filter(array_map('trim', explode("\n", (string) $st['lines']))));
                    $lines[] = $n.'Shows advice “'.$st['title'].'” ('.$count.' line'.($count === 1 ? '' : 's').').';
                    break;
                case 'health_article':
                    $lines[] = $n.'Shows “'.$st['label'].'” → '.($st['slug'] !== '' ? 'the article “'.$st['slug'].'”' : 'the health articles list').'.';
                    $firstAction = false;
                    break;
                case 'pharmacy':
                    $lines[] = $n.'Shows “'.$st['label'].'” → pharmacies / order medicine.';
                    $firstAction = false;
                    break;
                case 'end':
                    $lines[] = $n.'Ends with: “'.$st['message'].'”.';
                    break;
                default:
                    $lines[] = $n.'(Unknown step — choose a type.)';
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------
    // Runtime payload for clients
    // ------------------------------------------------------------------

    /**
     * GET /api/triage/scenario/{level} (and the web JSON / page). Steps with
     * the links / numbers each client needs already resolved.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $level, ?string $triageRef = null): array
    {
        $sc = $this->scenario($level);
        $plan = self::planFor($sc['steps']);
        $contacts = $this->contacts();
        $primary = $contacts[0] ?? null;
        foreach ($contacts as $c) {
            if ($c['primary']) {
                $primary = $c;
                break;
            }
        }
        $ref = $triageRef !== null && $triageRef !== '' ? mb_substr($triageRef, 0, 40) : null;

        $steps = [];
        foreach ($sc['steps'] as $s) {
            $type = $s['type'];
            $st = $s['settings'];
            $row = ['id' => $s['id'], 'type' => $type, 'label' => self::types()[$type]['label'] ?? $type, 'settings' => $st];
            switch ($type) {
                case 'call_emergency':
                    $row['phone'] = $primary['phone'] ?? null;
                    $row['tel'] = $primary['tel'] ?? null;
                    $row['contacts'] = $st['show_list'] ? $contacts : [];
                    break;
                case 'offer_ambulance_or_shuttle':
                    $row['ambulance'] = $st['ambulance'] && $primary ? ['phone' => $primary['phone'], 'tel' => $primary['tel']] : null;
                    $row['shuttle_url'] = $st['shuttle'] && Route::has('shuttle') ? route('shuttle').'#request' : null;
                    $row['app'] = $st['shuttle'] ? ['tab' => 'Rides', 'screen' => 'ShuttleRequest', 'params' => (object) []] : null;
                    break;
                case 'broadcast_to_doctors':
                    $row['url'] = $this->consultUrl($level, $ref, true);
                    $row['app'] = ['tab' => 'Home', 'screen' => 'InstantConsult', 'params' => ['level' => $level, 'auto' => true] + ($ref ? ['triageReference' => $ref] : [])];
                    break;
                case 'book_appointment':
                    $row['url'] = $st['when'] === 'later' && Route::has('dispatch.request-doctor')
                        ? route('dispatch.request-doctor')
                        : (Route::has('doctors.index') ? route('doctors.index', array_filter(['group' => $st['specialty'] === '' ? 'gp' : null, 'specialization' => $st['specialty'] ?: null])) : null);
                    $row['app'] = ['tab' => 'Care', 'screen' => 'FindCare', 'params' => ['group' => 'gp']];
                    break;
                case 'find_care':
                    $row['url'] = $this->careUrl($st['category']);
                    $row['app'] = ['tab' => 'Care', 'screen' => 'FindCare', 'params' => $st['category'] === 'all' ? (object) [] : ['group' => $st['category']]];
                    break;
                case 'self_care_advice':
                    $row['lines'] = array_values(array_filter(array_map('trim', explode("\n", (string) $st['lines'])), fn ($l) => $l !== ''));
                    break;
                case 'health_article':
                    $row['url'] = $st['slug'] !== '' && Route::has('health-articles.show') ? route('health-articles.show', $st['slug']) : (Route::has('health-articles.index') ? route('health-articles.index') : null);
                    $row['app'] = $st['slug'] !== '' ? ['tab' => 'Home', 'screen' => 'HealthArticleDetail', 'params' => ['slug' => $st['slug']]] : ['tab' => 'Home', 'screen' => 'HealthArticles', 'params' => (object) []];
                    break;
                case 'pharmacy':
                    $row['url'] = Route::has('pharmacies.index') ? route('pharmacies.index') : null;
                    $row['app'] = ['tab' => 'Pharmacy', 'screen' => 'Shop', 'params' => (object) []];
                    break;
            }
            $steps[] = $row;
        }

        return [
            'level' => $level,
            'level_label' => self::LEVEL_LABELS[$level],
            'version' => $sc['version'],
            'updated_at' => $sc['updated_at'],
            'steps' => $steps,
            'plan' => $plan,
            'entry' => $this->entry($level, $sc['steps'], $plan, $primary, $ref),
            'summary' => $this->describe($sc['steps']),
            'emergency' => $primary,
        ];
    }

    /**
     * What tapping the colour does: dial straight away, start the doctor
     * request, or open the scenario page / screen.
     *
     * @return array{kind: string, tel: ?string, url: string, app: array<string, mixed>}
     */
    protected function entry(string $level, array $steps, array $plan, ?array $primary, ?string $ref): array
    {
        $pageUrl = Route::has('emergency.scenario') ? route('emergency.scenario', array_filter(['level' => $level, 'triage' => $ref])) : url('/emergency');
        $screen = ['tab' => 'Home', 'screen' => 'TriageScenario', 'params' => ['level' => $level] + ($ref ? ['triageReference' => $ref] : [])];

        foreach ($steps as $s) {
            $type = $s['type'];
            if ($type === 'call_emergency') {
                if ($s['settings']['auto_dial'] && (int) $s['settings']['countdown_seconds'] === 0 && ! empty($primary['tel'])) {
                    return ['kind' => 'dial', 'tel' => $primary['tel'], 'url' => $pageUrl, 'app' => $screen];
                }
                break;
            }
            if (in_array($type, self::ACTION_TYPES, true) || $type === 'broadcast_to_doctors') {
                break;
            }
        }
        if ($plan['auto_request']) {
            return ['kind' => 'consult', 'tel' => null, 'url' => $this->consultUrl($level, $ref, true), 'app' => ['tab' => 'Home', 'screen' => 'InstantConsult', 'params' => ['level' => $level, 'auto' => true] + ($ref ? ['triageReference' => $ref] : [])]];
        }
        if (($plan['has_consult'] ?? false) && ($plan['channel_timing'] ?? '') === 'before' && ! ($plan['optional'] ?? false) && $this->onlyConsultFirst($steps)) {
            // "Choose video or call back, then we find a doctor" (blue button): open that page directly.
            return ['kind' => 'consult', 'tel' => null, 'url' => $this->consultUrl($level, $ref, false), 'app' => ['tab' => 'Home', 'screen' => 'InstantConsult', 'params' => ['level' => $level, 'auto' => false] + ($ref ? ['triageReference' => $ref] : [])]];
        }

        return ['kind' => 'steps', 'tel' => null, 'url' => $pageUrl, 'app' => $screen];
    }

    /** True when nothing but messages / the channel choice comes before the doctor request. */
    protected function onlyConsultFirst(array $steps): bool
    {
        foreach ($steps as $s) {
            $t = $s['type'] ?? '';
            if ($t === 'broadcast_to_doctors') {
                return true;
            }
            if (! in_array($t, ['show_message', 'choose_channel', 'collect_payment', 'self_care_advice'], true)) {
                return false;
            }
        }

        return false;
    }

    /** Website link each colour of the emergency popup opens (tel: for an instant dial). @return array<string, string> */
    public function entryUrls(): array
    {
        $out = [];
        foreach (self::LEVELS as $level) {
            try {
                $e = $this->resolve($level)['entry'];
                $out[$level] = $e['kind'] === 'dial' && $e['tel'] ? $e['tel'] : $e['url'];
            } catch (Throwable) {
                // Popup keeps its built-in link.
            }
        }

        return $out;
    }

    public function consultUrl(string $level, ?string $ref, bool $auto): string
    {
        $base = Route::has('account.doctor-now') ? route('account.doctor-now') : url('/account/see-a-doctor');

        return $base.'?'.http_build_query(array_filter(['level' => $level, 'auto' => $auto ? 1 : null, 'triage' => $ref]));
    }

    protected function careUrl(string $category): ?string
    {
        return match ($category) {
            'gp' => Route::has('care.gp') ? route('care.gp') : url('/general-practitioners'),
            'dentists' => Route::has('care.dentists') ? route('care.dentists') : url('/dentists'),
            'video' => Route::has('virtual-consult') ? route('virtual-consult') : url('/virtual-consult'),
            'specialists' => url('/specialists'),
            'allied' => url('/allied'),
            'radiology' => url('/radiology'),
            'laboratories' => url('/laboratories'),
            default => Route::has('doctors.index') ? route('doctors.index') : url('/doctors'),
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<int, array{label: string, phone: string, tel: string, primary: bool}> */
    protected function contacts(): array
    {
        try {
            $rows = EmergencyContact::activeOrdered()->map(fn (EmergencyContact $c) => [
                'label' => (string) $c->label, 'phone' => (string) $c->phone, 'tel' => (string) ($c->tel_url ?: 'tel:'.preg_replace('/[^0-9+]/', '', (string) $c->phone)), 'primary' => (bool) $c->is_primary,
            ])->values()->all();
        } catch (Throwable) {
            $rows = [];
        }
        if (! $rows) {
            $phone = \App\Support\SiteLinks::dispatchPhone();
            $rows = [['label' => 'Prompt Aid dispatch', 'phone' => $phone, 'tel' => \App\Support\SiteLinks::dispatchTel(), 'primary' => true]];
        }

        return $rows;
    }

    protected function primaryContact(): ?EmergencyContact
    {
        try {
            return EmergencyContact::primary();
        } catch (Throwable) {
            return null;
        }
    }

    protected function paymentsOn(): bool
    {
        return self::consultPaymentsOn();
    }

    /** Consultation fees through the platform (Payment settings master switch, default off) AND upfront payment on. */
    public static function consultPaymentsOn(): bool
    {
        try {
            return PaymentSettings::consultPaymentsEnabled() && PaymentSettings::bool('consult_upfront_payment');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    protected function specialties(): array
    {
        try {
            return DoctorMatchService::specialties();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<int, string>  $items */
    public static function humanList(array $items, string $last = 'and'): string
    {
        $items = array_values(array_filter($items));
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' '.$last.' '.end($items);
    }
}
