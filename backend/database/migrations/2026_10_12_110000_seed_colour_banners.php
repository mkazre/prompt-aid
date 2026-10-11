<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Colourful promo banners across the app (and patient web dashboard).
 * Each is an ordinary App Widget — edit the text, colour, style, icon,
 * image and link, reorder or switch it off in Site → App Widgets.
 * Only inserts keys that don't exist yet, so admin edits are never undone.
 */
return new class extends Migration
{
    protected const BANNERS = [
        ['patient_doctor_now_banner', 'Feeling unwell right now?', 'Get matched with an available doctor — by video in minutes or at rooms near you.', 'patient', 'home', 25, ['accent' => '#E4701E', 'style' => 'gradient', 'icon' => 'zap', 'cta_label' => 'See a doctor now', 'destination' => 'doctor_now']],
        ['patient_virtual_banner', 'Consult from your couch', 'Video consults with registered doctors — no travel, no waiting room.', 'patient', 'home', 85, ['accent' => '#6A4C93', 'style' => 'gradient', 'icon' => 'video', 'cta_label' => 'Find a video doctor', 'destination' => 'virtual']],
        ['care_green_banner', 'Green means 30 minutes', 'Practitioners flagged green can see you within 30 minutes. Orange: today. Red: book another day.', 'patient', 'find_care', 5, ['accent' => '#1F7A4C', 'style' => 'gradient', 'icon' => 'activity', 'cta_label' => 'See a doctor now', 'destination' => 'doctor_now']],
        ['record_upload_banner', 'Keep your record complete', 'Your appointments, prescriptions and results — in one place for every practitioner you see.', 'patient', 'record', 5, ['accent' => '#0F7C8C', 'style' => 'gradient', 'icon' => 'file-text', 'cta_label' => 'View my results', 'destination' => 'lab_results']],
        ['shuttle_access_banner', 'Wheelchair & stretcher vehicles', 'Choose the vehicle you need — and book the trip home at the same time.', 'patient', 'shuttle', 5, ['accent' => '#B4520F', 'style' => 'gradient', 'icon' => 'navigation', 'cta_label' => 'Book a shuttle', 'destination' => 'shuttle']],
        ['appointments_shuttle_banner', 'Need a lift to your appointment?', 'Add a shuttle to any booking: it fetches you from home and brings you back.', 'patient', 'appointments', 5, ['accent' => '#1F5FBF', 'style' => 'gradient', 'icon' => 'truck', 'cta_label' => 'Book a shuttle', 'destination' => 'shuttle']],
        ['doctor_go_green_banner', 'Go green, get instant consults', 'Set your status to green when you can see a patient within 30 minutes — you will hear an alert for every request.', 'doctor', 'home', 5, ['accent' => '#1F7A4C', 'style' => 'gradient', 'icon' => 'bell']],
        ['driver_peak_banner', 'Busy times = more trips', 'Weekday mornings 07:00–09:00 and afternoons 15:00–17:00 have the most clinic trips. Stay online.', 'driver', 'home', 5, ['accent' => '#1F4E7A', 'style' => 'gradient', 'icon' => 'trending-up']],
        ['pharmacy_scripts_banner', 'Review scripts quickly', 'Patients are notified the moment you approve a script — fast reviews mean more orders.', 'pharmacy_admin', 'home', 5, ['accent' => '#6A4C93', 'style' => 'gradient', 'icon' => 'clipboard']],
        ['partner_results_banner', 'Upload results promptly', 'Results go straight to the patient and the referring doctor as soon as you upload them.', 'third_party', 'home', 5, ['accent' => '#0F7C8C', 'style' => 'gradient', 'icon' => 'upload-cloud']],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::BANNERS as [$key, $title, $subtitle, $role, $screen, $sort, $settings]) {
            if (DB::table('app_widgets')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('app_widgets')->insert([
                'key' => $key,
                'title' => $title,
                'subtitle' => $subtitle,
                'role' => $role,
                'screen' => $screen,
                'is_enabled' => true,
                'sort_order' => $sort,
                'settings' => json_encode($settings),
                'image' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Existing seeded banners: give them the new style options too.
        foreach (['patient_promo_banner' => ['style' => 'gradient', 'icon' => 'shield', 'destination' => 'find_care'], 'shop_promo_banner' => ['style' => 'gradient', 'icon' => 'package', 'destination' => 'script_upload']] as $key => $extra) {
            $row = DB::table('app_widgets')->where('key', $key)->first();
            if ($row) {
                $settings = json_decode($row->settings ?? '[]', true) ?: [];
                DB::table('app_widgets')->where('key', $key)->update(['settings' => json_encode($settings + $extra), 'updated_at' => $now]);
            }
        }

        Cache::forget('app-widgets');
    }

    public function down(): void
    {
        DB::table('app_widgets')->whereIn('key', array_column(self::BANNERS, 0))->delete();
        Cache::forget('app-widgets');
    }
};
