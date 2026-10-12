<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Help centre (/help + the app's Help screen), managed at /staff → Support.
        if (! Schema::hasTable('faq_categories')) {
            Schema::create('faq_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->integer('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('faq_category_id')->nullable()->constrained()->nullOnDelete();
                $table->string('question');
                $table->longText('answer');
                $table->string('audience', 20)->default('all'); // all | patient | doctor | driver
                $table->integer('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'audience', 'sort']);
            });
        }

        $this->seedStarterFaqs();
    }

    /**
     * Starter content. Production runs migrations but not seeders, so the
     * rows go in here — only into an empty table, so a re-run (or an admin
     * who already edited/deleted them) never gets duplicates back.
     */
    protected function seedStarterFaqs(): void
    {
        if (DB::table('faqs')->exists()) {
            return;
        }

        $now = now();
        $categories = [
            'booking' => ['Booking & appointments', 'Finding a practitioner, booking, rescheduling and cancelling.'],
            'video' => ['Video consults', 'Seeing a doctor by video from your phone or computer.'],
            'shuttle' => ['Medical shuttle', 'Getting a lift to your appointment and home again.'],
            'payments' => ['Payments & medical aid', 'Fees, payment methods and scheme claims.'],
            'pharmacy' => ['Pharmacy & scripts', 'Ordering medicine and uploading scripts.'],
            'privacy' => ['Privacy & your account', 'Your data, POPIA and account settings.'],
            'providers' => ['For doctors & drivers', 'Using PromptAid as a practitioner or shuttle driver.'],
        ];

        $ids = [];
        $sort = 1;
        foreach ($categories as $slug => [$name, $description]) {
            $existing = DB::table('faq_categories')->where('slug', $slug)->value('id');
            $ids[$slug] = $existing ?: DB::table('faq_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'sort' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sort++;
        }

        $faqs = [
            ['booking', 'all', 'How do I book an appointment?', '<p>Search for a doctor, dentist, specialist or service under <strong>Find care</strong>, pick a practitioner and choose an open time slot. You get a booking reference straight away and the appointment shows under <strong>Appointments</strong> on the website and in the app.</p>'],
            ['booking', 'patient', 'How do I cancel or reschedule an appointment?', '<p>Open <strong>Appointments</strong>, choose the booking and tap <strong>Cancel</strong>. Some practices charge a late-cancellation fee inside their notice window — the fee, if any, is shown before you confirm. To move a booking, cancel it and book the new time.</p>'],
            ['booking', 'all', 'What do the green, orange and red practitioner colours mean?', '<p><strong>Green</strong> means the practitioner can see you within 30 minutes, <strong>orange</strong> means a slot in today\'s queue, and <strong>red</strong> means they are not available today but you can book a future date.</p>'],
            ['booking', 'all', 'What does booking cost?', '<p>Nothing. You pay the provider their listed fee. There is no booking fee and no subscription for patients.</p>'],
            ['video', 'all', 'How do video consultations work?', '<p>Book a slot marked <strong>Video</strong>, or use <strong>See a doctor now</strong>. Ten minutes before the appointment a <strong>Join video</strong> button appears in your appointments on the website and in the app. You need a camera, a microphone and a stable data connection.</p>'],
            ['shuttle', 'all', 'How do I book the medical shuttle?', '<p>Go to <strong>Shuttle</strong>, enter your pick-up and drop-off address and choose a vehicle (standard, comfort, wheelchair or stretcher). You see the fare before you confirm and can book the trip home at the same time. Track your driver live on the map.</p>'],
            ['shuttle', 'patient', 'Can I cancel a shuttle trip?', '<p>Yes. Open the trip and tap <strong>Cancel</strong>. Cancelling before a driver is on the way is free; after that a cancellation fee may apply, and it is always shown before you confirm.</p>'],
            ['shuttle', 'all', 'How are shuttle fares calculated?', '<p>By vehicle class and distance, quoted before you confirm. Waiting time is charged after the first fifteen minutes. Return legs are quoted with the outbound trip.</p>'],
            ['payments', 'all', 'Is PromptAid a medical aid?', '<p>No. PromptAid is a booking and delivery platform. You keep your own medical scheme, and we submit claims to it where the provider bills directly.</p>'],
            ['payments', 'patient', 'Can I use my medical aid?', '<p>Yes. Add your scheme and membership number under <strong>Profile &amp; scheme</strong>. Where the provider bills your scheme directly we submit the claim for you; otherwise you pay the cash price and receive an invoice to claim back.</p>'],
            ['payments', 'all', 'Which payment methods do you accept?', '<p>Card, instant EFT and the other methods shown at checkout. Every fee on the site is the cash price — a medical scheme simply reduces what you pay.</p>'],
            ['pharmacy', 'patient', 'What happens if my script is rejected?', '<p>The pharmacist tells you why and nothing is charged. Illegible scripts and expired repeats are the two common reasons.</p>'],
            ['privacy', 'all', 'Who can see my medical record?', '<p>You, and the practitioners you have an active appointment or sharing grant with. Every access is logged and you can revoke sharing at any time.</p>'],
            ['privacy', 'all', 'How do I get a copy of my data or delete my account?', '<p>Go to <strong>Your data &amp; consents</strong> in your account to download your data or ask for your account to be closed. We respond within 30 days as POPIA requires. Clinical records are kept for the period the HPCSA requires even after an account closes.</p>'],
            ['providers', 'doctor', 'How do I set my consulting hours and time off?', '<p>In the app open <strong>Settings → Sessions</strong> to set your weekly hours and <strong>Settings → Holiday</strong> to block time off. Patients can only book inside your open sessions.</p>'],
            ['providers', 'driver', 'How do I receive shuttle trip offers?', '<p>Go online on the <strong>Drive</strong> screen and keep location sharing on. New trip offers appear under <strong>Offers</strong> with the pick-up, drop-off and fare — accept before the timer runs out. Keep your licence and vehicle documents up to date under <strong>Profile</strong>.</p>'],
        ];

        $sort = 1;
        foreach ($faqs as [$cat, $audience, $question, $answer]) {
            DB::table('faqs')->insert([
                'faq_category_id' => $ids[$cat] ?? null,
                'question' => $question,
                'answer' => $answer,
                'audience' => $audience,
                'sort' => $sort++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('faq_categories');
    }
};
