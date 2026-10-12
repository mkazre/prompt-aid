<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Services\Health\PatientHealth;
use Illuminate\Http\Request;

class FavouriteDoctorsController extends Controller
{
    use ResolvesPatient;

    public function index(Request $request)
    {
        $this->requireFeature('favourites');
        $patient = $this->patient($request);

        return view('account.health.my-doctors', [
            'doctors' => PatientHealth::favouriteDoctors($patient),
        ]);
    }

    /** Heart button on the doctor profile: add or remove. */
    public function toggle(Request $request, int $doctor)
    {
        $this->requireFeature('favourites');
        $patient = $this->patient($request);
        $doctorProfile = DoctorProfile::query()->findOrFail($doctor);

        if (PatientHealth::isFavourite($patient, $doctorProfile->id)) {
            PatientHealth::removeFavourite($patient, $doctorProfile->id);
            $message = $doctorProfile->displayName().' removed from My doctors.';
        } else {
            PatientHealth::addFavourite($patient, $doctorProfile->id);
            $message = $doctorProfile->displayName().' saved to My doctors.';
        }

        if ($request->expectsJson()) {
            return response()->json(['favourite' => PatientHealth::isFavourite($patient, $doctorProfile->id), 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
