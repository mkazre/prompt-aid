<?php

namespace App\Http\Controllers;

use App\Services\Triage\ScenarioEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient request scenarios at runtime — one resolver for the app and the
 * website (App\Services\Triage\ScenarioEngine::resolve):
 *   GET /api/triage/scenario/{level}          (app; public, like /triage/submit)
 *   GET /emergency/scenario/{level}.json      (website JS)
 *   GET /emergency/scenario/{level}           (website page that runs the steps)
 * Scenario content is not personal data; ?triage=<reference> is only echoed
 * into the links so the doctor request is linked to the triage result.
 */
class TriageScenarioController extends Controller
{
    public function __construct(protected ScenarioEngine $engine) {}

    public function show(Request $request, string $level): JsonResponse
    {
        abort_unless(ScenarioEngine::isLevel($level), 404);

        return response()->json(['data' => $this->engine->resolve($level, $this->ref($request))])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function page(Request $request, string $level)
    {
        abort_unless(ScenarioEngine::isLevel($level), 404);
        $scenario = $this->engine->resolve($level, $this->ref($request));
        // Nothing to do before the doctor request: go straight to it.
        if ($scenario['entry']['kind'] === 'consult' && ! $request->boolean('steps')) {
            return redirect()->to($scenario['entry']['url']);
        }

        return view('triage.scenario', ['scenario' => $scenario, 'triageRef' => $this->ref($request)]);
    }

    protected function ref(Request $request): ?string
    {
        $ref = trim((string) $request->query('triage', ''));

        return $ref !== '' && preg_match('/^[A-Za-z0-9-]{1,40}$/', $ref) ? $ref : null;
    }
}
