<?php

namespace App\Support;

/**
 * Contact points used by the PromptAid Emergency Scale popup, the home page
 * and the mobile app. Admin-editable at /staff → Site → Site content
 * (Emergency popup tab); see SiteContent::DEFAULTS for the fallbacks.
 */
class SiteLinks
{
    public static function dispatchPhone(): string
    {
        return (string) SiteContent::get('emergency.dispatch_phone');
    }

    public static function dispatchTel(): string
    {
        return 'tel:'.preg_replace('/[^0-9+]/', '', self::dispatchPhone());
    }

    public static function whatsappNumber(): string
    {
        $number = preg_replace('/\D/', '', (string) SiteContent::get('emergency.whatsapp_number'));

        // Accept a local "0XX…" number typed in by an admin.
        if (str_starts_with($number, '0')) {
            $number = '27'.substr($number, 1);
        }

        return $number;
    }

    public static function whatsappUrl(): string
    {
        return 'https://wa.me/'.self::whatsappNumber().'?text='.rawurlencode('Hi PromptAid, I would like to ask a doctor a question.');
    }

    /** @return array<string, mixed> */
    public static function forJs(): array
    {
        $emergency = SiteContent::get('emergency');

        return [
            'dispatch' => ['phone' => self::dispatchPhone(), 'tel' => self::dispatchTel()],
            'popup' => [
                'eyebrow' => $emergency['eyebrow'],
                'title' => $emergency['title'],
                'intro' => $emergency['intro'],
                'note' => $emergency['note'],
                'askDoctorLabel' => $emergency['ask_doctor_label'],
                'videoLabel' => $emergency['video_label'],
                'levels' => $emergency['levels'],
            ],
            'urls' => [
                'doctorNow' => route('instant-consult'),
                'findCare' => route('doctors.index'),
                'virtualConsult' => route('virtual-consult'),
                'whatsapp' => self::whatsappUrl(),
            ],
        ];
    }
}
