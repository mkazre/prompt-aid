<?php

namespace App\Services\Finance;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorMatchRequest;
use App\Models\Invoice;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Who earns a captured payment, and under which service type.
 *
 *   consult (DoctorMatchRequest)          → doctor, or the clinic when the clinic's
 *                                           "payouts go to" setting is "clinic"
 *   invoice → appointment                 → in person = "appointment", video / call-back = "consult";
 *                                           doctor / clinic as above
 *   order                                 → pharmacy (commission = orders.commission_amount when set)
 *   ride, ride_charge                     → the ride's driver (shuttle)
 *   lab_request                           → partner (third-party profile)
 *   subscription / anything unattributed  → platform only
 */
class Attribution
{
    /**
     * @return array{service: string, payee_type: ?string, payee_id: ?int, ids: array<string, ?int>, commission_cents: ?int, meta: array<string, mixed>}
     */
    public function for(?Model $payable): array
    {
        $out = ['service' => 'other', 'payee_type' => null, 'payee_id' => null, 'ids' => [], 'commission_cents' => null, 'meta' => []];
        if (! $payable) {
            return $out;
        }

        if ($payable instanceof DoctorMatchRequest) {
            return $this->clinical('consult', $payable->doctor_profile_id, $payable->clinic_id ?: $payable->appointment?->clinic_id, ['consult_id' => $payable->id, 'mode' => $payable->mode]);
        }

        if ($payable instanceof Invoice) {
            $appt = $payable->appointment_id ? Appointment::query()->find($payable->appointment_id) : null;
            $consult = DoctorMatchRequest::query()->where('invoice_id', $payable->id)->first();
            $remote = $consult || ($appt && ($appt->isVideo() || $appt->mode === Appointment::MODE_CALLBACK || $appt->visit_type === Appointment::VISIT_PHONE));
            $service = $appt || $consult ? ($remote ? 'consult' : 'appointment') : 'appointment';
            $doctor = $appt?->doctor_profile_id ?: $consult?->doctor_profile_id;
            $clinic = $payable->clinic_id ?: $appt?->clinic_id ?: $consult?->clinic_id;

            return $this->clinical($service, $doctor, $clinic, ['invoice_id' => $payable->id, 'appointment_id' => $appt?->id]);
        }

        if ($payable instanceof Order) {
            $commission = $payable->commission_amount !== null && Money::cents((string) $payable->commission_amount) > 0
                ? Money::cents((string) $payable->commission_amount) : null;

            return ['service' => 'pharmacy', 'payee_type' => $payable->pharmacy_id ? 'pharmacy' : null, 'payee_id' => $payable->pharmacy_id ? (int) $payable->pharmacy_id : null,
                'ids' => ['pharmacy' => $payable->pharmacy_id ? (int) $payable->pharmacy_id : null], 'commission_cents' => $commission,
                'meta' => ['order_id' => $payable->id, 'order_no' => $payable->order_no]];
        }

        if ($payable instanceof RideCharge || $payable instanceof Ride) {
            $ride = $payable instanceof Ride ? $payable : $payable->ride;
            $driver = $ride?->driver_profile_id ? (int) $ride->driver_profile_id : null;

            return ['service' => 'shuttle', 'payee_type' => $driver ? 'driver' : null, 'payee_id' => $driver, 'ids' => ['driver' => $driver],
                'commission_cents' => null, 'meta' => array_filter(['ride_id' => $ride?->id, 'ride_ref' => $ride?->ride_ref, 'charge_type' => $payable instanceof RideCharge ? $payable->type : 'fare'])];
        }

        if ($payable instanceof LabRequest) {
            $partner = $payable->third_party_profile_id ? (int) $payable->third_party_profile_id : null;

            return ['service' => 'lab', 'payee_type' => $partner ? 'partner' : null, 'payee_id' => $partner, 'ids' => ['partner' => $partner],
                'commission_cents' => null, 'meta' => ['lab_request_id' => $payable->id]];
        }

        if ($payable instanceof Subscription) {
            $out['service'] = 'subscription';
            $out['meta'] = ['subscription_id' => $payable->id];
        }

        return $out;
    }

    protected function clinical(string $service, $doctorId, $clinicId, array $meta): array
    {
        $doctorId = $doctorId ? (int) $doctorId : null;
        $clinicId = $clinicId ? (int) $clinicId : null;
        $toClinic = $clinicId && Clinic::query()->whereKey($clinicId)->value('payout_to') === 'clinic';
        [$type, $id] = match (true) {
            $toClinic => ['clinic', $clinicId],
            (bool) $doctorId => ['doctor', $doctorId],
            (bool) $clinicId => ['clinic', $clinicId],
            default => [null, null],
        };

        return ['service' => $service, 'payee_type' => $type, 'payee_id' => $id, 'ids' => ['doctor' => $doctorId, 'clinic' => $clinicId],
            'commission_cents' => null, 'meta' => array_filter($meta + ['doctor_profile_id' => $doctorId, 'clinic_id' => $clinicId])];
    }
}
