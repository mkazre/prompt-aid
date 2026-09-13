<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PatientProfile;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRateCard;
use App\Models\RideReview;
use App\Models\RideStatusEvent;
use App\Models\Service;
use App\Models\Setting;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();

        // --- Super admin ---
        User::query()->updateOrCreate(
            ['email' => 'admin@promptaid.health'],
            [
                'name' => 'Prompt Aid Super Admin',
                'phone' => '+27710000001',
                'password' => bcrypt('password'),
                'role' => User::ROLE_SUPER_ADMIN,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // --- Clinics + clinic admins ---
        $clinicDefs = [
            ['name' => 'Prompt Aid Sandton Clinic', 'city' => 'Sandton', 'lat' => -26.1076, 'lng' => 28.0567],
            ['name' => 'Prompt Aid Rosebank Clinic', 'city' => 'Rosebank', 'lat' => -26.1467, 'lng' => 28.0436],
            ['name' => 'Prompt Aid Pretoria Clinic', 'city' => 'Pretoria', 'lat' => -25.7479, 'lng' => 28.2293],
        ];

        $clinics = collect();
        foreach ($clinicDefs as $i => $def) {
            $admin = User::factory()->clinicAdmin()->create([
                'name' => $def['name'].' Admin',
                'email' => 'clinicadmin'.($i + 1).'@promptaid.health',
            ]);

            $clinics->push(Clinic::query()->create([
                'clinic_admin_id' => $admin->id,
                'name' => $def['name'],
                'slug' => Str::slug($def['name']),
                'description' => 'A modern, patient-first clinic offering general practice, specialist referrals, and free patient shuttle service.',
                'phone' => '+2711'.random_int(1000000, 9999999),
                'email' => Str::slug($def['name']).'@promptaid.health',
                'address' => fake()->streetAddress(),
                'city' => $def['city'],
                'state' => 'Gauteng',
                'country' => 'South Africa',
                'postal_code' => fake()->postcode(),
                'lat' => $def['lat'],
                'lng' => $def['lng'],
                'specialties' => ['General Practice', 'Pediatrics', 'Dermatology'],
                'working_hours' => ['mon-fri' => '08:00-18:00', 'sat' => '08:00-13:00', 'sun' => 'closed'],
                'status' => 'active',
            ]));
        }

        // --- Doctors ---
        $specializations = ['General Practitioner', 'Pediatrician', 'Dermatologist', 'Cardiologist', 'Gynaecologist', 'ENT Specialist'];
        $doctors = collect();
        foreach ($specializations as $i => $spec) {
            $user = User::factory()->doctor()->create([
                'name' => 'Dr. '.fake()->name(),
                'email' => 'doctor'.($i + 1).'@promptaid.health',
            ]);

            $doctor = DoctorProfile::query()->create([
                'user_id' => $user->id,
                'specialization' => $spec,
                'qualification' => 'MBChB, '.fake()->randomElement(['FCP(SA)', 'MMed', 'MRCGP']),
                'experience_years' => random_int(3, 20),
                'bio' => fake()->paragraph(),
                'consultation_fee' => random_int(300, 900),
                'registration_no' => 'HPCSA-'.random_int(100000, 999999),
                'rating_avg' => round(random_int(38, 50) / 10, 1),
                'rating_count' => random_int(5, 120),
                'status' => 'active',
            ]);

            // attach to 1-2 clinics
            $clinics->random(random_int(1, 2))->each(fn (Clinic $clinic) => $doctor->clinics()->syncWithoutDetaching([$clinic->id]));

            foreach ($doctor->clinics as $clinic) {
                foreach (range(1, 5) as $weekday) { // Mon-Fri
                    DoctorAvailability::query()->create([
                        'doctor_profile_id' => $doctor->id,
                        'clinic_id' => $clinic->id,
                        'weekday' => $weekday,
                        'start_time' => '08:00',
                        'end_time' => '16:00',
                        'slot_minutes' => 30,
                        'is_active' => true,
                    ]);
                }

                Service::query()->create([
                    'clinic_id' => $clinic->id,
                    'doctor_profile_id' => $doctor->id,
                    'name' => $spec.' Consultation',
                    'category' => 'Consultation',
                    'description' => 'Standard consultation with '.$user->name,
                    'price' => $doctor->consultation_fee,
                    'duration_minutes' => 30,
                    'is_active' => true,
                ]);
            }

            $doctors->push($doctor);
        }

        // --- Patients ---
        $patients = collect();
        foreach (range(1, 12) as $i) {
            $user = User::factory()->patient()->create(['email' => 'patient'.$i.'@promptaid.health']);
            $patients->push(PatientProfile::query()->create([
                'user_id' => $user->id,
                'dob' => fake()->dateTimeBetween('-70 years', '-5 years')->format('Y-m-d'),
                'gender' => fake()->randomElement(['male', 'female']),
                'blood_group' => fake()->randomElement(['O+', 'A+', 'B+', 'AB+', 'O-']),
                'address' => fake()->streetAddress(),
                'lat' => -26.1 + (random_int(-500, 500) / 10000),
                'lng' => 28.05 + (random_int(-500, 500) / 10000),
                'emergency_contact_name' => fake()->name(),
                'emergency_contact_phone' => '+2772'.random_int(1000000, 9999999),
            ]));
        }

        // --- Drivers ---
        $drivers = collect();
        foreach (range(1, 6) as $i) {
            $user = User::factory()->driver()->create(['email' => 'driver'.$i.'@promptaid.health']);
            $drivers->push(DriverProfile::query()->create([
                'user_id' => $user->id,
                'license_no' => 'DL-'.random_int(100000, 999999),
                'license_expiry' => now()->addYears(2),
                'vehicle_make' => fake()->randomElement(['Toyota', 'Hyundai', 'VW', 'Nissan']),
                'vehicle_model' => fake()->randomElement(['Corolla Quest', 'Grand i10', 'Polo Vivo', 'Almera']),
                'vehicle_color' => fake()->safeColorName(),
                'vehicle_plate_no' => strtoupper(fake()->bothify('??##??GP')),
                'vehicle_type' => 'sedan',
                'availability' => fake()->randomElement([DriverProfile::AVAILABLE, DriverProfile::OFFLINE]),
                'current_lat' => -26.1 + (random_int(-500, 500) / 10000),
                'current_lng' => 28.05 + (random_int(-500, 500) / 10000),
                'location_updated_at' => now(),
                'rating_avg' => round(random_int(40, 50) / 10, 1),
                'rating_count' => random_int(5, 80),
                'status' => 'active',
            ]));
        }

        // --- Appointments, encounters, prescriptions, invoices, payments, reviews ---
        foreach (range(1, 25) as $i) {
            $patient = $patients->random();
            $doctor = $doctors->random();
            $clinic = $doctor->clinics->isNotEmpty() ? $doctor->clinics->random() : $clinics->random();
            $status = fake()->randomElement([
                Appointment::STATUS_COMPLETED, Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING, Appointment::STATUS_CANCELLED,
            ]);
            $date = fake()->dateTimeBetween('-30 days', '+14 days');

            $appointment = Appointment::query()->create([
                'patient_profile_id' => $patient->id,
                'doctor_profile_id' => $doctor->id,
                'clinic_id' => $clinic->id,
                'service_id' => $clinic->services()->first()?->id,
                'date' => $date->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '09:30',
                'visit_type' => fake()->randomElement(['clinic', 'clinic', 'telemed']),
                'status' => $status,
                'reason' => fake()->sentence(),
            ]);

            if ($status === Appointment::STATUS_COMPLETED) {
                $encounter = Encounter::query()->create([
                    'appointment_id' => $appointment->id,
                    'vitals' => ['bp' => '120/80', 'temp' => '36.7', 'pulse' => '72', 'weight' => '70kg'],
                    'chief_complaint' => fake()->sentence(),
                    'diagnosis' => fake()->sentence(),
                    'notes' => fake()->paragraph(),
                ]);

                $prescription = Prescription::query()->create([
                    'encounter_id' => $encounter->id,
                    'notes' => 'Take with food.',
                ]);

                PrescriptionItem::query()->create([
                    'prescription_id' => $prescription->id,
                    'medicine_name' => fake()->randomElement(['Amoxicillin 500mg', 'Paracetamol 500mg', 'Ibuprofen 400mg']),
                    'dosage' => '1 tablet',
                    'frequency' => 'Twice daily',
                    'duration_days' => 7,
                    'instructions' => 'After meals',
                ]);

                $fee = (float) $doctor->consultation_fee;
                $invoice = Invoice::query()->create([
                    'appointment_id' => $appointment->id,
                    'patient_profile_id' => $patient->id,
                    'clinic_id' => $clinic->id,
                    'subtotal' => $fee,
                    'tax' => round($fee * 0.15, 2),
                    'discount' => 0,
                    'total' => round($fee * 1.15, 2),
                    'status' => Invoice::STATUS_PAID,
                    'due_date' => now(),
                ]);

                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $doctor->specialization.' Consultation',
                    'qty' => 1,
                    'unit_price' => $fee,
                    'amount' => $fee,
                ]);

                Payment::query()->create([
                    'invoice_id' => $invoice->id,
                    'method' => fake()->randomElement(['card', 'cash', 'mobile_money']),
                    'amount' => $invoice->total,
                    'status' => 'success',
                    'gateway' => 'mock',
                    'gateway_ref' => 'MOCK-'.Str::upper(Str::random(10)),
                ]);

                if (fake()->boolean(70)) {
                    Review::query()->create([
                        'patient_profile_id' => $patient->id,
                        'doctor_profile_id' => $doctor->id,
                        'clinic_id' => $clinic->id,
                        'appointment_id' => $appointment->id,
                        'rating' => random_int(3, 5),
                        'comment' => fake()->sentence(),
                        'status' => 'approved',
                    ]);
                }

                // ~40% of completed appointments booked a shuttle ride
                if (fake()->boolean(40)) {
                    $driver = $drivers->random();
                    $ride = Ride::query()->create([
                        'patient_profile_id' => $patient->id,
                        'driver_profile_id' => $driver->id,
                        'appointment_id' => $appointment->id,
                        'pickup_address' => $patient->address,
                        'pickup_lat' => $patient->lat,
                        'pickup_lng' => $patient->lng,
                        'dropoff_address' => $clinic->address,
                        'dropoff_lat' => $clinic->lat,
                        'dropoff_lng' => $clinic->lng,
                        'status' => Ride::STATUS_COMPLETED,
                        'distance_km' => round(random_int(20, 200) / 10, 1),
                        'fare_estimate' => random_int(60, 180),
                        'fare_final' => random_int(60, 180),
                        'requested_at' => $date,
                        'accepted_at' => $date,
                        'started_at' => $date,
                        'completed_at' => $date,
                    ]);

                    foreach ([Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED, Ride::STATUS_IN_PROGRESS, Ride::STATUS_COMPLETED] as $status) {
                        RideStatusEvent::query()->create([
                            'ride_id' => $ride->id,
                            'status' => $status,
                            'lat' => $ride->pickup_lat,
                            'lng' => $ride->pickup_lng,
                        ]);
                    }

                    RideReview::query()->create([
                        'ride_id' => $ride->id,
                        'rating' => random_int(3, 5),
                        'comment' => fake()->sentence(),
                    ]);
                }
            }
        }

        // --- A couple of live/in-flight rides not tied to appointments, for demo ---
        foreach (range(1, 3) as $i) {
            $patient = $patients->random();
            $driver = $drivers->firstWhere('availability', DriverProfile::AVAILABLE) ?? $drivers->first();

            Ride::query()->create([
                'patient_profile_id' => $patient->id,
                'driver_profile_id' => $i === 1 ? null : $driver->id,
                'pickup_address' => $patient->address,
                'pickup_lat' => $patient->lat,
                'pickup_lng' => $patient->lng,
                'dropoff_address' => $clinics->random()->address,
                'dropoff_lat' => $clinics->random()->lat,
                'dropoff_lng' => $clinics->random()->lng,
                'status' => $i === 1 ? Ride::STATUS_REQUESTED : Ride::STATUS_DRIVER_ENROUTE,
                'distance_km' => 5.4,
                'fare_estimate' => 85,
            ]);
        }

        $this->seedRateCards();
        $thirdParties = $this->seedThirdParties();
        $this->seedLabRequests($doctors, $patients, $thirdParties);
        $pharmacies = $this->seedPharmacies();
        $this->seedOrders($patients, $pharmacies);

        $this->command?->info('Prompt Aid demo data seeded. Login as admin@promptaid.health / password');
    }

    protected function seedRateCards(): void
    {
        $cards = [
            ['vehicle_type' => 'sedan', 'base_fare' => 30, 'per_km_rate' => 12, 'per_minute_rate' => 0.5, 'minimum_fare' => 35],
            ['vehicle_type' => 'suv', 'base_fare' => 45, 'per_km_rate' => 16, 'per_minute_rate' => 0.75, 'minimum_fare' => 50],
            ['vehicle_type' => 'van', 'base_fare' => 55, 'per_km_rate' => 18, 'per_minute_rate' => 0.75, 'minimum_fare' => 60],
            ['vehicle_type' => 'wheelchair_accessible', 'base_fare' => 40, 'per_km_rate' => 14, 'per_minute_rate' => 0.5, 'minimum_fare' => 45],
        ];

        foreach ($cards as $card) {
            RideRateCard::query()->updateOrCreate(['vehicle_type' => $card['vehicle_type']], [...$card, 'is_active' => true]);
        }
    }

    protected function seedThirdParties()
    {
        $defs = [
            ['name' => 'MediLab Diagnostics', 'type' => 'lab'],
            ['name' => 'ClearScan Imaging', 'type' => 'imaging'],
        ];

        return collect($defs)->map(function ($def, $i) {
            $user = User::factory()->create([
                'name' => $def['name'].' Admin',
                'email' => 'thirdparty'.($i + 1).'@promptaid.health',
                'role' => User::ROLE_THIRD_PARTY,
            ]);

            return ThirdPartyProfile::query()->create([
                'user_id' => $user->id,
                'company_name' => $def['name'],
                'service_type' => $def['type'],
                'license_no' => 'LAB-'.random_int(10000, 99999),
                'description' => "Accredited {$def['type']} partner serving Prompt Aid patients across Gauteng.",
                'rating_avg' => round(random_int(40, 50) / 10, 1),
                'rating_count' => random_int(10, 60),
                'status' => 'active',
            ]);
        });
    }

    protected function seedLabRequests($doctors, $patients, $thirdParties): void
    {
        $testPanels = [
            ['Full Blood Count', 'blood'], ['Lipogram', 'blood'], ['HbA1c (Diabetes)', 'blood'],
            ['Chest X-Ray', 'imaging'], ['Urinalysis', 'urine'], ['Thyroid Function Panel', 'blood'],
        ];

        foreach (range(1, 8) as $i) {
            $doctor = $doctors->random();
            $patient = $patients->random();
            $thirdParty = fake()->boolean(70) ? $thirdParties->random() : null;
            $status = $thirdParty
                ? fake()->randomElement([LabRequest::STATUS_ACCEPTED, LabRequest::STATUS_SAMPLE_COLLECTED, LabRequest::STATUS_PROCESSING, LabRequest::STATUS_COMPLETED, LabRequest::STATUS_COMPLETED])
                : LabRequest::STATUS_REQUESTED;

            $request = LabRequest::query()->create([
                'doctor_profile_id' => $doctor->id,
                'patient_profile_id' => $patient->id,
                'third_party_profile_id' => $thirdParty?->id,
                'priority' => fake()->randomElement(['routine', 'routine', 'urgent']),
                'clinical_notes' => fake()->sentence(),
                'collection_address' => $patient->address,
                'collection_lat' => $patient->lat,
                'collection_lng' => $patient->lng,
                'status' => $status,
                'accepted_at' => $thirdParty ? now()->subDays(random_int(1, 5)) : null,
                'sample_collected_at' => in_array($status, [LabRequest::STATUS_SAMPLE_COLLECTED, LabRequest::STATUS_PROCESSING, LabRequest::STATUS_COMPLETED], true) ? now()->subDays(random_int(1, 4)) : null,
                'completed_at' => $status === LabRequest::STATUS_COMPLETED ? now()->subDay() : null,
            ]);

            foreach (fake()->randomElements($testPanels, random_int(1, 2)) as [$name, $sample]) {
                LabRequestItem::query()->create([
                    'lab_request_id' => $request->id,
                    'test_name' => $name,
                    'sample_type' => $sample,
                ]);
            }

            if ($status === LabRequest::STATUS_COMPLETED) {
                LabResult::query()->create([
                    'lab_request_id' => $request->id,
                    'uploaded_by' => $thirdParty->user_id,
                    'label' => 'Lab Report — '.$request->request_ref,
                    'file_path' => 'demo/sample-lab-report.pdf',
                    'summary' => 'All results within normal reference range.',
                    'visible_to_patient' => true,
                ]);
            }
        }
    }

    protected function seedPharmacies()
    {
        $defs = [
            ['name' => 'Prompt Aid Pharmacy Sandton', 'city' => 'Sandton'],
            ['name' => 'Wellness Corner Pharmacy', 'city' => 'Rosebank'],
        ];

        $catalog = [
            ['name' => 'Paracetamol 500mg (20 tabs)', 'category' => 'Pain Relief', 'price' => 45, 'rx' => false],
            ['name' => 'Ibuprofen 400mg (24 tabs)', 'category' => 'Pain Relief', 'price' => 55, 'rx' => false],
            ['name' => 'Vitamin C 1000mg (30 tabs)', 'category' => 'Vitamins & Supplements', 'price' => 89, 'rx' => false],
            ['name' => 'Multivitamin Daily (60 tabs)', 'category' => 'Vitamins & Supplements', 'price' => 129, 'rx' => false],
            ['name' => 'Amoxicillin 500mg (21 caps)', 'category' => 'Prescription', 'price' => 145, 'rx' => true],
            ['name' => 'Metformin 500mg (28 tabs)', 'category' => 'Prescription', 'price' => 99, 'rx' => true],
            ['name' => 'Digital Thermometer', 'category' => 'Devices', 'price' => 159, 'rx' => false],
            ['name' => 'Blood Pressure Monitor', 'category' => 'Devices', 'price' => 649, 'rx' => false],
            ['name' => 'Antiseptic Wound Spray', 'category' => 'First Aid', 'price' => 65, 'rx' => false],
            ['name' => 'Allergy Relief Tablets (10s)', 'category' => 'Allergy', 'price' => 75, 'rx' => false],
        ];

        return collect($defs)->map(function ($def, $i) use ($catalog) {
            $vendor = User::factory()->create([
                'name' => $def['name'].' Vendor',
                'email' => 'pharmacy'.($i + 1).'@promptaid.health',
                'role' => User::ROLE_PHARMACY_ADMIN,
            ]);

            $pharmacy = Pharmacy::query()->create([
                'vendor_id' => $vendor->id,
                'name' => $def['name'],
                'slug' => Str::slug($def['name']),
                'description' => 'Licensed retail pharmacy delivering medication straight to your door.',
                'phone' => '+2711'.random_int(1000000, 9999999),
                'email' => Str::slug($def['name']).'@promptaid.health',
                'address' => fake()->streetAddress(),
                'city' => $def['city'],
                'lat' => -26.1 + (random_int(-500, 500) / 10000),
                'lng' => 28.05 + (random_int(-500, 500) / 10000),
                'commission_rate' => 12,
                'delivery_fee' => 45,
                'rating_avg' => round(random_int(40, 50) / 10, 1),
                'rating_count' => random_int(20, 150),
                'status' => 'active',
            ]);

            foreach ($catalog as $item) {
                Product::query()->create([
                    'pharmacy_id' => $pharmacy->id,
                    'name' => $item['name'],
                    'slug' => Str::slug($item['name']),
                    'category' => $item['category'],
                    'description' => "{$item['name']} — available for delivery from {$pharmacy->name}.",
                    'price' => $item['price'],
                    'stock' => random_int(10, 200),
                    'requires_prescription' => $item['rx'],
                    'is_active' => true,
                ]);
            }

            return $pharmacy;
        });
    }

    protected function seedOrders($patients, $pharmacies): void
    {
        foreach (range(1, 10) as $i) {
            $patient = $patients->random();
            $pharmacy = $pharmacies->random();
            $products = $pharmacy->products()->inRandomOrder()->limit(random_int(1, 3))->get();

            $subtotal = 0;
            $needsRx = false;
            $lines = [];
            foreach ($products as $product) {
                $qty = random_int(1, 2);
                $amount = $product->price * $qty;
                $subtotal += $amount;
                $needsRx = $needsRx || $product->requires_prescription;
                $lines[] = ['product' => $product, 'qty' => $qty, 'amount' => $amount];
            }

            $status = fake()->randomElement([
                Order::STATUS_DELIVERED, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED,
                Order::STATUS_PREPARING, Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_PENDING_PAYMENT,
            ]);

            $order = Order::query()->create([
                'patient_profile_id' => $patient->id,
                'pharmacy_id' => $pharmacy->id,
                'subtotal' => $subtotal,
                'delivery_fee' => $pharmacy->delivery_fee,
                'total' => $subtotal + $pharmacy->delivery_fee,
                'commission_amount' => round($subtotal * ($pharmacy->commission_rate / 100), 2),
                'delivery_address' => $patient->address,
                'delivery_lat' => $patient->lat,
                'delivery_lng' => $patient->lng,
                'status' => $needsRx && fake()->boolean(30) ? Order::STATUS_AWAITING_PRESCRIPTION : $status,
                'payment_status' => $status === Order::STATUS_PENDING_PAYMENT ? 'unpaid' : 'paid',
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'qty' => $line['qty'],
                    'unit_price' => $line['product']->price,
                    'amount' => $line['amount'],
                ]);
            }
        }
    }

    protected function seedSettings(): void
    {
        Setting::query()->updateOrCreate(['key' => 'site_name'], ['value' => 'Prompt Aid']);
        Setting::query()->updateOrCreate(['key' => 'currency'], ['value' => 'ZAR']);
        Setting::query()->updateOrCreate(['key' => 'currency_symbol'], ['value' => 'R']);
        Setting::query()->updateOrCreate(['key' => 'support_phone'], ['value' => '+27 87 000 0000']);
        Setting::query()->updateOrCreate(['key' => 'support_email'], ['value' => 'support@promptaid.health']);
        Setting::query()->updateOrCreate(['key' => 'ride_base_fare'], ['value' => '30']);
        Setting::query()->updateOrCreate(['key' => 'ride_per_km_rate'], ['value' => '12']);
    }
}
