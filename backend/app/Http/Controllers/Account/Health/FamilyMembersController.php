<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Models\FamilyMember;
use App\Services\Health\PatientHealth;
use Illuminate\Http\Request;

class FamilyMembersController extends Controller
{
    use ResolvesPatient;

    public function index(Request $request)
    {
        $this->requireFeature('family');
        $patient = $this->patient($request);

        return view('account.health.family', [
            'members' => $patient->familyMembers()->orderBy('name')->get(),
            'editing' => $request->integer('edit') ? $patient->familyMembers()->find($request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->requireFeature('family');
        $patient = $this->patient($request);
        $data = $request->validate(PatientHealth::familyRules());

        abort_if($patient->familyMembers()->count() >= 20, 422, 'You can add up to 20 family members.');
        $patient->familyMembers()->create($data);

        return redirect()->route('account.health.family')->with('success', $data['name'].' added.');
    }

    public function update(Request $request, int $member)
    {
        $this->requireFeature('family');
        $record = $this->owned($request, $member);
        $record->update($request->validate(PatientHealth::familyRules()));

        return redirect()->route('account.health.family')->with('success', $record->name.' updated.');
    }

    public function destroy(Request $request, int $member)
    {
        $this->requireFeature('family');
        $record = $this->owned($request, $member);
        $record->delete();

        return redirect()->route('account.health.family')->with('success', $record->name.' removed.');
    }

    protected function owned(Request $request, int $id): FamilyMember
    {
        return $this->patient($request)->familyMembers()->findOrFail($id);
    }
}
