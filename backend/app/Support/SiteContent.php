<?php

namespace App\Support;

use App\Models\ThemeSetting;

/**
 * Editable website/app copy — home page hero, benefits, section headings,
 * the PromptAid Emergency Scale popup, practitioner status wording and the
 * Find care tab labels. Edited by super admins at /staff → Site → Site
 * content (App\Filament\Pages\SiteContentSettings) and stored as one JSON
 * row in theme_settings ('site_content'). DEFAULTS is only what shows until
 * an admin saves a value.
 */
class SiteContent
{
    public const KEY = 'site_content';

    public const DEFAULTS = [
        'home' => [
            'eyebrow' => 'ONE TECH, ONE GLOBE, ONE EXCELLENT CARE',
            'title' => 'Find a doctor, connect virtually and get a lift to your consultation',
            'lead' => 'Download our sleek app and get all the benefits of promptaid right at the palm of your hand',
            'app_store_url' => '',
            'play_store_url' => '',
            'status_note' => 'Please note the status colour of our practitioners; where green means the practitioner is available to see you in 30 min, orange means the practitioner is available to see you on the next available slot on the queue for that day and red means the practitioner is not available to see you on the day but future consults can be booked through the facility\'s admin.',
            'search_label' => 'Speciality or name',
            'search_placeholder' => 'e.g. Dr Smith or cardiologist',
            'tab_care' => 'Book care',
            'tab_virtual' => 'Virtual consult',
            'tab_shuttle' => 'Shuttle',
            'benefits_eyebrow' => 'Why PromptAid',
            'benefits_heading' => 'BENEFITS OF USING PROMPTAID',
            'top_doctors_heading' => 'Top rated doctors today',
            'top_services_heading' => 'Top rated services today',
            'topic_heading' => 'Topic of the month',
            'topic_read_label' => 'Read topic',
            'topic_testimonial_label' => 'Submit a testimonial',
        ],
        'benefits' => [
            ['title' => 'Identifying the level of care', 'summary' => 'The PromptAid Emergency Scale tells you how urgently you need to be seen and where to go.', 'detail' => 'Answer a few quick questions or pick the colour that matches how you feel. Red connects you to our dispatch centre, orange to a GP within 30 minutes, and yellow or green to the right practitioner for today or the next few days.'],
            ['title' => 'Quick access to the health system', 'summary' => 'Doctors, clinics, pharmacies, labs and transport in one account.', 'detail' => 'One PromptAid account opens the whole care journey — search, book, attend, collect medicine and get results — on the website or the app.'],
            ['title' => 'Choice of practitioner', 'summary' => 'Compare GPs, specialists, dentists and allied practitioners by rating, fee and availability.', 'detail' => 'Every practitioner shows a live green, orange or red status, their fees, operating hours and reviews from verified visits, so you choose who treats you.'],
            ['title' => 'Choice of transportation', 'summary' => 'Book a standard, comfort, wheelchair or stretcher shuttle with a return leg.', 'detail' => 'Get a fare estimate before you book, watch your driver arrive on the map and schedule the trip home at the same time.'],
            ['title' => 'Eliminating waiting time', 'summary' => 'See who is available now and book a time instead of queuing.', 'detail' => 'Green practitioners can see you within 30 minutes; orange practitioners slot you into today\'s queue. You arrive at your booked time, not hours early.'],
            ['title' => 'Access to pharmacies, radiology centres and laboratories', 'summary' => 'Order medicine, book scans and lab tests from one place.', 'detail' => 'Upload a script for delivery, find radiology and x-ray centres, and book laboratory tests with results sent to your record and your doctor.'],
            ['title' => 'Have a say in your treatment', 'summary' => 'You choose the practitioner, the time, the mode and how you get there.', 'detail' => 'Pick in-person or video consults, see fees upfront and review every visit — your feedback shapes the ratings other patients see.'],
            ['title' => 'Continuation of care', 'summary' => 'Prescriptions, results and visit notes follow you to every provider.', 'detail' => 'Your medical record keeps appointments, prescriptions and results together, so the next practitioner you see can pick up where the last one left off.'],
            ['title' => 'Ask a doctor feature', 'summary' => 'Ask a doctor a question on WhatsApp.', 'detail' => 'Not sure whether you need to be seen? Tap "Ask a doctor" to chat with our medical team on WhatsApp.'],
            ['title' => 'Access to medical information', 'summary' => 'Trusted health topics every month, plus your own records.', 'detail' => 'Read the Topic of the month, share your own experience, and access your prescriptions and results whenever you need them.'],
        ],
        'emergency' => [
            'dispatch_phone' => '081 670 9522',
            'whatsapp_number' => '27816709522',
            'eyebrow' => 'PromptAid Emergency Scale',
            'title' => 'Do you have an emergency?',
            'intro' => 'Pick the line that sounds most like you.',
            'note' => 'If someone is not breathing, call the PromptAid dispatch centre now.',
            'ask_doctor_label' => 'ASK A DOCTOR',
            'video_label' => 'VIRTUAL/VIDEO consult',
            'levels' => [
                'red' => ['title' => 'I need help right now', 'description' => 'Not breathing properly, chest pain, heavy bleeding, unconscious, fitting, a serious injury.', 'timeframe' => 'Immediately', 'action' => 'Get ambulance now!'],
                'orange' => ['title' => 'This is very urgent', 'description' => 'Severe pain, a burn, a deep cut, a head knock, sudden weakness, a baby who will not feed.', 'timeframe' => 'Within 30 minutes', 'action' => 'Get connected to a Doctor in 30min'],
                'yellow' => ['title' => 'I need to be seen today', 'description' => 'Fever, vomiting, a wound, pain that is getting worse, a child who is unwell.', 'timeframe' => 'Within 8 hours', 'action' => 'Manually book a Doctor to see today'],
                'green' => ['title' => 'It can wait', 'description' => 'Book a specialist, dentist, allied practitioner e.g. physio, radiology/x-ray, lab services.', 'timeframe' => 'In the next few days', 'action' => 'Book a non urgent service or consult'],
            ],
        ],
        'status' => [
            'green' => ['label' => 'Available in 30 min', 'description' => 'Open to see you immediately — within 30 minutes.'],
            'orange' => ['label' => 'Available today', 'description' => 'Available to see you today, at a booked slot in the queue.'],
            'red' => ['label' => 'Not available today', 'description' => 'Not available today — book a slot on the next calendar days.'],
        ],
        'care' => [
            'see_now_title' => 'Don\'t want to wait for a slot?',
            'see_now_text' => 'See the next available doctor now — by video or in person.',
            'see_now_button' => 'See a doctor now',
            'tabs' => [
                'gp' => ['label' => 'General Practitioners', 'heading' => 'General Practitioners', 'description' => 'Family doctors for everyday illness, check-ups and referrals.'],
                'dentists' => ['label' => 'Dentists', 'heading' => 'Dentists', 'description' => 'Dentists and dental practices near you.'],
                'specialists' => ['label' => 'Specialists', 'heading' => 'Specialists', 'description' => 'Cardiologists, paediatricians, gynaecologists and other medical specialists.'],
                'allied' => ['label' => 'Allied', 'heading' => 'Allied health practitioners', 'description' => 'Physiotherapy, optometry, dietetics, audiology and home nursing.'],
                'radiology' => ['label' => 'Radiology', 'heading' => 'Radiology', 'description' => 'X-ray, ultrasound, CT and MRI centres.'],
                'laboratories' => ['label' => 'Laboratories', 'heading' => 'Laboratories', 'description' => 'Blood tests, pathology and home draws with results into your record.'],
            ],
            'virtual_heading' => 'Virtual / video consult',
            'virtual_description' => 'Doctors who offer video consultations.',
        ],
    ];

    /** @var array<string, mixed>|null */
    protected static ?array $resolved = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return static::$resolved ??= static::merge(self::DEFAULTS, (array) ThemeSetting::get(self::KEY, []));
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return data_get(static::all(), $path, $default);
    }

    /** @param  array<string, mixed>  $data */
    public static function save(array $data): void
    {
        ThemeSetting::set(self::KEY, $data);
        static::$resolved = null;
    }

    /**
     * Defaults overlaid with saved values. Associative arrays merge key by
     * key (so a newly added default still appears); lists (benefits) and
     * scalars are replaced wholesale. Blank saved strings fall back to the
     * default so an emptied field never leaves a hole on the site.
     */
    protected static function merge(mixed $defaults, mixed $saved): mixed
    {
        if (is_array($defaults) && is_array($saved) && ! array_is_list($defaults)) {
            foreach ($saved as $key => $value) {
                $defaults[$key] = array_key_exists($key, $defaults) ? static::merge($defaults[$key], $value) : $value;
            }

            return $defaults;
        }

        if (is_array($defaults) && array_is_list($defaults)) {
            return is_array($saved) && count($saved) ? array_values($saved) : $defaults;
        }

        return ($saved === null || $saved === '') && is_string($defaults) && $defaults !== '' ? $defaults : $saved;
    }
}
