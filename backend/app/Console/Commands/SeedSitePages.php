<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Pharmacy;
use Illuminate\Console\Command;

/**
 * Builds the pure-content pages (About, How It Works, FAQ, Privacy, Terms,
 * POPIA notice) as real page-builder pages — editable from /staff/pages —
 * using the exact copy from new-ui/promptaid-website. Kept out of the
 * builder deliberately: For Providers and Contact, which carry working
 * lead-capture forms (raw builder HTML can't run Blade's route()/@csrf,
 * so anything with a real POST stays as compiled Blade+controller code).
 * Safe to run repeatedly — each page's blocks are replaced, not duplicated.
 */
class SeedSitePages extends Command
{
    protected $signature = 'page-builder:seed-site-pages';

    protected $description = 'Create the About, How It Works, FAQ, Privacy, Terms and POPIA pages';

    public function handle(): int
    {
        $this->about();
        $this->howItWorks();
        $this->faq();
        $this->privacy();
        $this->terms();
        $this->legalPopia();

        $this->info('Pages ready: /about /how-it-works /faq /privacy /terms /legal-popia');

        return self::SUCCESS;
    }

    protected function page(string $slug, string $title): Page
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => $slug],
            ['title' => $title, 'kind' => 'page', 'status' => 'published'],
        );
        $page->allBlocks()->delete();

        return $page;
    }

    protected function section(Page $page, int $sort, array $styleOverrides = []): PageBlock
    {
        return PageBlock::query()->create([
            'page_id' => $page->id,
            'sort' => $sort,
            'type' => 'section',
            'props' => [],
            'styles' => array_replace_recursive([
                'layout' => ['width' => 'container'],
                'space' => ['pt' => 36, 'pr' => 0, 'pb' => 16, 'pl' => 0, 'mt' => 0, 'mb' => 0],
            ], $styleOverrides),
        ]);
    }

    protected function block(Page $page, PageBlock $parent, int $sort, string $type, array $props): PageBlock
    {
        return PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $parent->id,
            'sort' => $sort,
            'type' => $type,
            'props' => $props,
        ]);
    }

    protected function crumb(string $label): string
    {
        return '<div class="pa-crumb"><a href="/">Home</a> <span style="color:#CFC8B8">/</span> '.$label.'</div>';
    }

    protected function legalSection(string $heading, string $body): string
    {
        return '<h3 style="margin:28px 0 12px">'.$heading.'</h3><p style="font-size:15px;line-height:1.7;color:var(--pa-ink-soft)">'.$body.'</p>';
    }

    protected function about(): void
    {
        $page = $this->page('about', 'About Prompt Aid');

        $doctors = DoctorProfile::where('status', 'active')->count();
        $clinics = Clinic::where('status', 'active')->count();
        $pharmacies = Pharmacy::where('status', 'active')->count();
        $drivers = DriverProfile::where('status', 'active')->count();

        $s = $this->section($page, 1);
        $this->block($page, $s, 1, 'rich-text', ['html' =>
            '<div class="pa-row" style="margin-bottom:22px"><span class="pa-tick"></span><span class="pa-eyebrow">About Prompt Aid</span></div>'.
            '<h1 style="font-size:54px;margin-bottom:24px;max-width:760px">Getting to the appointment is half the treatment.</h1>'.
            '<p style="font-size:19px;line-height:1.6;color:var(--pa-ink-soft);max-width:760px">Prompt Aid started with a South African problem that has nothing to do with medicine: people miss appointments because they cannot get there. A taxi is unreliable, a family member has to take a day off, and the chronic-medication collection slips another month.</p>'.
            '<p style="font-size:19px;line-height:1.6;color:var(--pa-ink-soft);max-width:760px;margin:0">So we built the booking, the pharmacy and the ride into one account. Book a doctor and the lift arrives with the reminder. Order a repeat script and it comes to the door. Send a patient for a scan and the return trip is already scheduled.</p>',
        ]);

        $this->block($page, $s, 2, 'stats', ['items' => [
            ['value' => (string) ($doctors + $clinics + $pharmacies), 'label' => 'Doctors, clinics, pharmacies, labs and specialists on the platform'],
            ['value' => (string) $pharmacies, 'label' => 'Independent pharmacy vendors dispensing through the store'],
            ['value' => (string) $drivers, 'label' => 'Vetted shuttle drivers across three provinces'],
            ['value' => '31%', 'label' => 'Fewer missed appointments among patients who book a shuttle'],
        ]]);

        $s2 = $this->section($page, 2);
        $this->block($page, $s2, 1, 'heading', ['text' => 'What we believe', 'level' => 2]);
        $this->block($page, $s2, 2, 'icon-list', ['items' => [
            ['icon' => '🎯', 'title' => 'Independent by design', 'description' => 'Every clinic, pharmacy and lab on Prompt Aid runs its own practice, sets its own fees and keeps its own records. We are the booking layer and the road between them, not the owner.'],
            ['icon' => '🔒', 'title' => 'Your record is yours', 'description' => 'Consultations, prescriptions, results and invoices live in one place you control. Share a record with a new doctor in a tap, or revoke it just as fast.'],
            ['icon' => '🛡️', 'title' => 'Built for POPIA', 'description' => 'Health information is special personal information under South African law. Access is logged, consent is explicit, and nothing is sold to anyone, ever.'],
        ]]);

        $s3 = $this->section($page, 3);
        $this->block($page, $s3, 1, 'rich-text', ['html' =>
            '<div class="pa-card pa-spread" style="padding:32px 36px"><div><h3 style="margin-bottom:6px">Work with us</h3><p class="pa-muted" style="margin:0">Practices, pharmacies, labs and drivers can join in about a week.</p></div>'.
            '<div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn" href="/for-providers">List your practice</a><a class="pa-btn-ghost" href="/contact">Talk to us</a></div></div>',
        ]);
    }

    protected function howItWorks(): void
    {
        $page = $this->page('how-it-works', 'How Prompt Aid Works');

        $s = $this->section($page, 1);
        $this->block($page, $s, 1, 'rich-text', ['html' => $this->crumb('How it works')]);
        $this->block($page, $s, 2, 'heading', ['text' => 'How it works', 'level' => 1]);
        $this->block($page, $s, 3, 'rich-text', ['html' => '<p class="pa-muted" style="font-size:15px">Four steps for patients, and what each kind of provider sees on the other side.</p>']);

        $s2 = $this->section($page, 2);
        $this->block($page, $s2, 1, 'rich-text', ['html' =>
            '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:40px">'.
            '<div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 01</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Search and compare</div><div style="font-size:14px;color:var(--pa-ink-soft)">Filter by speciality, suburb, fee, medical scheme and who has an opening today. Every fee shown is the fee you pay.</div></div>'.
            '<div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 02</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Book and add a lift</div><div style="font-size:14px;color:var(--pa-ink-soft)">Pick a slot. Tick the shuttle box and the outbound and return legs are scheduled against the appointment time.</div></div>'.
            '<div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 03</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Attend or dial in</div><div style="font-size:14px;color:var(--pa-ink-soft)">The driver tracks to your door, or a Google Meet link opens ten minutes before a video consultation.</div></div>'.
            '<div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 04</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Scripts and results follow</div><div style="font-size:14px;color:var(--pa-ink-soft)">Prescriptions go to the pharmacy you choose, results to your record and back to the referring doctor.</div></div>'.
            '</div>',
        ]);

        $s3 = $this->section($page, 3);
        $this->block($page, $s3, 1, 'heading', ['text' => 'What each role gets', 'level' => 2]);
        $this->block($page, $s3, 2, 'rich-text', ['html' =>
            '<div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Patient</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Book care, order medicine, track a shuttle and keep your record.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Doctor</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Your day list, encounters, prescriptions and lab requests.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Clinic</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Practice diary, doctors, receptionists, invoices and claims.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Pharmacy</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Orders, script approvals, stock, deliveries and payouts.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Lab / X-ray / specialist</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Your request queue, collections, results and catalogue.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '<a href="/login" style="padding:24px;display:block;color:inherit;text-decoration:none"><div style="font-size:16px;font-weight:700;margin-bottom:8px">Shuttle driver</div><div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">Trip offers, active trip, earnings and documents.</div><span class="pa-badge is-ink">Open the dashboard</span></a>'.
            '</div>',
        ]);

        $s4 = $this->section($page, 4);
        $this->block($page, $s4, 1, 'rich-text', ['html' =>
            '<div class="pa-card"><div class="pa-card-head"><h3>What it costs</h3><a class="pa-btn-ghost pa-btn-sm" href="/faq">Full FAQ</a></div>'.
            '<div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)"><span style="font-size:15px;font-weight:700">Booking a doctor, lab or specialist</span><span style="font-size:14px;color:var(--pa-ink-soft);text-align:right">Free. You pay the provider their listed fee.</span></div>'.
            '<div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)"><span style="font-size:15px;font-weight:700">Medicine and products</span><span style="font-size:14px;color:var(--pa-ink-soft);text-align:right">The vendor price plus delivery. No platform markup.</span></div>'.
            '<div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)"><span style="font-size:15px;font-weight:700">Shuttle trips</span><span style="font-size:14px;color:var(--pa-ink-soft);text-align:right">Quoted before you confirm, by vehicle class and distance.</span></div>'.
            '<div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)"><span style="font-size:15px;font-weight:700">Medical scheme claims</span><span style="font-size:14px;color:var(--pa-ink-soft);text-align:right">Submitted for you at no charge, where the provider bills direct.</span></div>'.
            '<div class="pa-spread" style="padding:16px 22px"><span style="font-size:15px;font-weight:700">Listing a practice</span><span style="font-size:14px;color:var(--pa-ink-soft);text-align:right">12% commission on store sales. No monthly fee for bookings.</span></div>'.
            '</div>',
        ]);
    }

    protected function faq(): void
    {
        $page = $this->page('faq', 'Frequently Asked Questions');
        $items = [
            ['Is Prompt Aid a medical aid?', 'No. Prompt Aid is a booking and delivery platform. You keep your own scheme, and we submit claims to it where the provider bills directly.'],
            ['What does booking cost?', 'Nothing. You pay the provider their listed fee. There is no booking fee and no subscription for patients.'],
            ['Can I use it without a medical scheme?', 'Yes. Every fee on the site is the cash price. Schemes simply reduce what you pay at checkout.'],
            ['How do video consultations work?', 'Book a slot marked Video. A Google Meet link appears in your record and in the app ten minutes before the appointment, and opens in the Meet app on your phone.'],
            ['Who can see my record?', 'You, and the practitioners you have an active appointment or sharing grant with. Every access is logged and you can revoke sharing at any time.'],
            ['How are shuttle fares calculated?', 'By vehicle class and distance, quoted before you confirm. Waiting time is charged after the first fifteen minutes. Return legs are quoted with the outbound trip.'],
            ['Can someone else book a shuttle for me?', 'Yes. A family member, a clinic receptionist or a doctor can book on your behalf, and you get the tracking link by SMS.'],
            ['What happens if my script is rejected?', 'The pharmacist tells you why and nothing is charged. Illegible scripts and expired repeats are the two common reasons.'],
            ['Do you deliver Schedule 5 and 6 medicines?', 'Schedule 5 is delivered against a valid original script. Schedule 6 must be collected in person with your ID.'],
            ['How do I get my data or delete my account?', 'Email privacy@promptaid.health. We respond within 30 days as POPIA requires. Clinical records are retained for the period the HPCSA requires even after an account closes.'],
        ];

        $s = $this->section($page, 1);
        $this->block($page, $s, 1, 'rich-text', ['html' => $this->crumb('FAQ')]);
        $this->block($page, $s, 2, 'heading', ['text' => 'Frequently asked questions', 'level' => 1]);
        $this->block($page, $s, 3, 'rich-text', ['html' => '<p class="pa-muted" style="font-size:15px">If yours is not here, <a href="/contact">send us a message</a>.</p>']);

        $s2 = $this->section($page, 2, ['layout' => ['width' => 'container']]);
        $html = '';
        foreach ($items as [$q, $a]) {
            $html .= '<div style="border-top:1px solid var(--pa-line);padding:20px 0"><div style="font-size:16px;font-weight:700;margin-bottom:8px">'.$q.'</div><div style="font-size:15px;color:var(--pa-ink-soft)">'.$a.'</div></div>';
        }
        $html .= '<div class="pa-card pa-spread" style="padding:28px 32px;margin-top:40px"><div><h3 style="margin-bottom:6px">Still stuck?</h3><p class="pa-muted" style="margin:0">Patient support is open 06:00–22:00, seven days a week.</p></div><a class="pa-btn" href="/contact">Contact support</a></div>';
        $this->block($page, $s2, 1, 'rich-text', ['html' => $html]);
    }

    protected function legalPage(string $slug, string $title, string $crumbLabel, string $lede, array $sections): void
    {
        $page = $this->page($slug, $title);

        $s = $this->section($page, 1);
        $this->block($page, $s, 1, 'rich-text', ['html' => $this->crumb($crumbLabel)]);
        $this->block($page, $s, 2, 'heading', ['text' => $title, 'level' => 1]);
        $this->block($page, $s, 3, 'rich-text', ['html' => '<p class="pa-muted" style="font-size:15px">'.$lede.'</p>']);

        $s2 = $this->section($page, 2, ['layout' => ['width' => 'container']]);
        $html = '';
        foreach ($sections as [$heading, $body]) {
            $html .= $this->legalSection($heading, $body);
        }
        $html .= '<div class="pa-note" style="margin-top:32px">This page is a content template. Have your attorney review the final wording before launch.</div>';
        $this->block($page, $s2, 1, 'rich-text', ['html' => $html]);
    }

    protected function privacy(): void
    {
        $this->legalPage('privacy', 'Privacy policy', 'Privacy policy', 'How we use cookies, analytics and communication preferences. Read alongside the POPIA notice.', [
            ['Cookies', 'We set a session cookie to keep you signed in and a preference cookie to remember your suburb and scheme. Analytics cookies are optional and off until you accept them.'],
            ['Analytics', 'We measure page views, search terms and booking completion in aggregate to improve the service. Analytics data is not linked to your clinical record.'],
            ['Communications', 'We send transactional messages — booking confirmations, driver updates, script approvals — by SMS, email and push notification. These cannot be switched off while you hold an active booking. Marketing messages are opt-in and every one carries an unsubscribe link.'],
            ['Location', 'The app uses your location only while you have an active shuttle booking, to show your driver the pick-up point and to give you an accurate arrival time. Background location is never collected.'],
            ['Third parties', 'Payment processing is handled by PayFast, Yoco and Ozow. Video consultations run on Google Meet. Each processes data under its own terms and under an operator agreement with us.'],
            ['Changes', 'Material changes are announced in the app and by email at least 14 days before they take effect.'],
        ]);
    }

    protected function terms(): void
    {
        $this->legalPage('terms', 'Terms of use', 'Terms of use', 'The agreement between you and Prompt Aid (Pty) Ltd.', [
            ['What Prompt Aid is', 'Prompt Aid is a platform that connects patients with independent healthcare providers, pharmacies, laboratories and transport operators. We are not a healthcare provider and we do not practise medicine.'],
            ['Not for emergencies', 'Do not use Prompt Aid for medical emergencies. Call 10177 for an ambulance or go to your nearest casualty department. Our shuttle is a non-emergency patient transport service and its vehicles are not ambulances.'],
            ['Bookings and cancellation', 'Appointments may be cancelled free of charge up to four hours before the start time. Later cancellations and no-shows may be charged at the provider discretion, up to the full consultation fee. Shuttle trips may be cancelled free within ten minutes of booking or up to one hour before a scheduled pick-up.'],
            ['Payments', 'Prices shown include VAT where applicable. Payment is taken at checkout and settled to each provider individually. Where a medical scheme claim is rejected, the balance becomes payable by you.'],
            ['Medicine', 'Prescription medicine is dispensed by a registered pharmacy against a valid prescription and is subject to pharmacist review. Schedule 6 medicines must be collected in person with identification.'],
            ['Provider obligations', 'Providers warrant that their registrations are current and that they carry professional indemnity cover. Suspension or removal from the platform follows any lapse.'],
            ['Liability', 'To the extent the law allows, Prompt Aid is not liable for the clinical outcome of any consultation, for the quality of goods supplied by a vendor, or for indirect loss. Nothing here limits liability that cannot be limited under the Consumer Protection Act.'],
            ['Governing law', 'These terms are governed by the law of the Republic of South Africa and the parties submit to the jurisdiction of the Gauteng Local Division of the High Court.'],
        ]);
    }

    protected function legalPopia(): void
    {
        $this->legalPage('legal-popia', 'POPIA notice', 'POPIA notice', 'How Prompt Aid processes personal and health information under the Protection of Personal Information Act, 2013.', [
            ['Who we are', 'Prompt Aid (Pty) Ltd, registration 2024/518402/07, of 158 Jan Smuts Avenue, Rosebank, is the responsible party for the personal information described here. Our Information Officer is reachable at privacy@promptaid.health.'],
            ['What we collect', 'Identity and contact details, medical scheme membership, appointment and transaction history, pick-up and drop-off locations for shuttle trips, and the clinical records created by practitioners who treat you through the platform. Health information is special personal information under section 26 of the Act. We process it only with your express consent, or where a treating practitioner needs it to provide care to you.'],
            ['Why we process it', 'To make and manage bookings, to dispense and deliver medicine, to dispatch shuttle vehicles, to submit claims to your medical scheme, and to keep the clinical record the HPCSA requires practitioners to keep.'],
            ['Who sees it', 'Only the practitioners, pharmacies and laboratories involved in your care, the driver assigned to your trip (name and address only), and our staff where support requires it. Every access to a clinical record is logged with the identity of the person and the time. We do not sell personal information and we do not share it for marketing.'],
            ['Your rights', 'You may request access to your information, correction of it, or deletion of your account. Email privacy@promptaid.health and we will respond within 30 days. Clinical records are retained for the period the HPCSA prescribes, currently six years from the last entry, even where an account is closed. This retention is a legal obligation and overrides a deletion request for those records only.'],
            ['Security', 'Information is encrypted in transit and at rest, hosted in South Africa, and access is restricted by role. We notify the Information Regulator and affected users of any breach as section 22 requires.'],
        ]);
    }
}
