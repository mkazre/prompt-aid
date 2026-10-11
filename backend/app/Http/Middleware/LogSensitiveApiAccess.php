<?php

namespace App\Http\Middleware;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\LabRequest;
use App\Models\PatientDocument;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\TriageSubmission;
use App\Services\Privacy\AccessLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `api` group. After a SUCCESSFUL response to one of the
 * API "show" endpoints that return special personal (health) information,
 * writes a data_access_logs row. Lists are not logged. Matching is by
 * route URI so route files don't need touching; new sensitive show
 * endpoints just need a line in MAP.
 *
 * Fails open: logging problems never affect the response.
 */
class LogSensitiveApiAccess
{
    /**
     * route URI => [route parameter, resolver]
     *
     * @var array<string, array{0:string,1:string}>
     */
    protected const MAP = [
        'api/lab-requests/{labRequest}' => ['labRequest', LabRequest::class],
        'api/doctor/appointments/{id}/encounter' => ['id', 'appointment-encounter'],
        'api/patient-profiles/{patientProfile}' => ['patientProfile', PatientProfile::class],
        'api/encounters/{encounter}' => ['encounter', Encounter::class],
        'api/prescriptions/{prescription}' => ['prescription', Prescription::class],
        'api/patient-documents/{patientDocument}' => ['patientDocument', PatientDocument::class],
        'api/triage/{triageSubmission}' => ['triageSubmission', TriageSubmission::class],
    ];

    public function __construct(protected AccessLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($request->isMethod('GET') && $response->getStatusCode() < 300 && $request->user()) {
                $uri = $request->route()?->uri();
                if ($uri && isset(self::MAP[$uri])) {
                    [$param, $type] = self::MAP[$uri];
                    $subject = $this->resolve($request->route($param), $type);
                    $this->logger->log($subject, 'view', 'api:'.$uri);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    protected function resolve(mixed $value, string $type): ?Model
    {
        if ($value instanceof Model) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }

        if ($type === 'appointment-encounter') {
            return Encounter::query()->where('appointment_id', (int) $value)->first()
                ?? Appointment::query()->find((int) $value);
        }

        return $type::query()->find($value);
    }
}
