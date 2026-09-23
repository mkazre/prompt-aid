<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PageBlock;
use Illuminate\Console\Command;

/**
 * Fills the genuine content gaps from new-ui/promptaid-website/HANDOVER.md
 * §7's route table that had no page at all before this: About, How It
 * Works, For Providers, Privacy, Terms. Built on the page builder so
 * they're editable from Pages -> Site without a deploy. Safe to run
 * repeatedly (each page's blocks are replaced, not duplicated).
 */
class SeedSitePages extends Command
{
    protected $signature = 'page-builder:seed-site-pages';

    protected $description = 'Create the About, How It Works, For Providers, Privacy and Terms pages';

    public function handle(): int
    {
        $this->about();
        $this->howItWorks();
        $this->forProviders();
        $this->privacy();
        $this->terms();

        $this->info('Pages ready: /about /how-it-works /for-providers /privacy /terms');

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
                'space' => ['pt' => 56, 'pr' => 0, 'pb' => 16, 'pl' => 0, 'mt' => 0, 'mb' => 0],
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

    protected function about(): void
    {
        $page = $this->page('about', 'About Prompt Aid');
        $s = $this->section($page, 1);

        $this->block($page, $s, 1, 'heading', ['text' => 'Healthcare that comes to you', 'level' => 1]);
        $this->block($page, $s, 2, 'rich-text', ['html' =>
            '<p>Prompt Aid connects patients with doctors, clinics, pharmacies and diagnostic partners across South Africa — and gets you there and back with a free patient shuttle, because the best care in the world does not help if you cannot reach it.</p>'.
            '<p>We built Prompt Aid because too many appointments are missed, delayed or abandoned over something as ordinary as a taxi fare or a missed lift. Every feature on this platform is designed around one question: does this help a patient actually get seen?</p>',
        ]);

        $this->block($page, $s, 3, 'stats', ['items' => [
            ['value' => '3', 'label' => 'Cities', 'description' => 'Johannesburg, Cape Town, Durban'],
            ['value' => '24/7', 'label' => 'Shuttle availability', 'description' => 'Book a ride any time'],
            ['value' => '100%', 'label' => 'Verified providers', 'description' => 'Every doctor is credential-checked'],
        ]]);

        $s2 = $this->section($page, 2);
        $this->block($page, $s2, 1, 'heading', ['text' => 'What we believe', 'level' => 2]);
        $this->block($page, $s2, 2, 'icon-list', ['items' => [
            ['icon' => '🎯', 'title' => 'Access first', 'description' => 'Care is only as good as your ability to reach it. The shuttle is not an add-on — it is core to the product.'],
            ['icon' => '🔍', 'title' => 'Transparent pricing', 'description' => 'Consultation fees, delivery fees and ride fares are shown up front, before you commit.'],
            ['icon' => '🤝', 'title' => 'Verified partners only', 'description' => 'Every doctor, clinic, pharmacy and diagnostics partner on Prompt Aid is credential-checked before they can accept a patient.'],
        ]]);
    }

    protected function howItWorks(): void
    {
        $page = $this->page('how-it-works', 'How Prompt Aid Works');
        $s = $this->section($page, 1);

        $this->block($page, $s, 1, 'heading', ['text' => 'How it works', 'level' => 1]);
        $this->block($page, $s, 2, 'rich-text', ['html' => '<p>Three ways to use Prompt Aid — book care, order medication, or request a shuttle. Most patients end up using all three in the same visit.</p>']);

        $this->block($page, $s, 3, 'icon-list', ['items' => [
            ['icon' => '1️⃣', 'title' => 'Find a doctor or clinic', 'description' => 'Search by specialty, location or availability. See real consultation fees and today\'s open slots before you book.'],
            ['icon' => '2️⃣', 'title' => 'Book your appointment', 'description' => 'Choose in-person or telemedicine. You will get a confirmation the moment the clinic accepts.'],
            ['icon' => '3️⃣', 'title' => 'Request your free shuttle', 'description' => 'Add a ride to and from your appointment in the same booking — no separate app, no extra fare.'],
            ['icon' => '4️⃣', 'title' => 'See the doctor, get your prescription', 'description' => 'Your encounter, prescription and invoice all land in your Prompt Aid account automatically.'],
            ['icon' => '5️⃣', 'title' => 'Order medication for delivery', 'description' => 'Send your prescription straight to a partner pharmacy and track delivery — no need to queue twice.'],
        ]]);

        $s2 = $this->section($page, 2);
        $this->block($page, $s2, 1, 'button', ['text' => 'Find a doctor', 'url' => '/doctors', 'variant' => 'primary']);
    }

    protected function forProviders(): void
    {
        $page = $this->page('for-providers', 'For Providers — Prompt Aid');
        $s = $this->section($page, 1);

        $this->block($page, $s, 1, 'heading', ['text' => 'Grow your practice with Prompt Aid', 'level' => 1]);
        $this->block($page, $s, 2, 'rich-text', ['html' => '<p>Whether you run a clinic, a pharmacy, or a diagnostics lab, Prompt Aid brings you patients who are ready to book — and removes the transport barrier that causes so many no-shows.</p>']);

        $this->block($page, $s, 3, 'icon-list', ['items' => [
            ['icon' => '🩺', 'title' => 'Clinics & doctors', 'description' => 'List your services, manage your own availability, and get paid through the platform — no separate booking system to maintain.'],
            ['icon' => '💊', 'title' => 'Pharmacies', 'description' => 'Join the marketplace, manage your own stock and pricing, and receive prescription orders directly with built-in script review.'],
            ['icon' => '🧪', 'title' => 'Labs & diagnostics', 'description' => 'Accept sample-collection requests from doctors across the network and deliver results straight into the patient\'s and doctor\'s dashboards.'],
        ]]);

        $s2 = $this->section($page, 2);
        $this->block($page, $s2, 1, 'button', ['text' => 'Register your practice', 'url' => '/register', 'variant' => 'primary']);
    }

    protected function privacy(): void
    {
        $page = $this->page('privacy', 'Privacy Policy — Prompt Aid');
        $s = $this->section($page, 1);

        $this->block($page, $s, 1, 'heading', ['text' => 'Privacy Policy', 'level' => 1]);
        $this->block($page, $s, 2, 'rich-text', ['html' =>
            '<p><em>This is placeholder policy text — replace it with your reviewed, POPIA-compliant privacy policy before launch.</em></p>'.
            '<p>Prompt Aid collects personal and health information solely to provide booking, billing, pharmacy and shuttle services. We do not sell patient data. Health records are treated as special personal information under the Protection of Personal Information Act (POPIA): access is logged, data is encrypted at rest, and retention follows a documented schedule. Contact our Information Officer at privacy@promptaid.health with any request to access, correct or delete your information.</p>',
        ]);
    }

    protected function terms(): void
    {
        $page = $this->page('terms', 'Terms of Service — Prompt Aid');
        $s = $this->section($page, 1);

        $this->block($page, $s, 1, 'heading', ['text' => 'Terms of Service', 'level' => 1]);
        $this->block($page, $s, 2, 'rich-text', ['html' =>
            '<p><em>This is placeholder terms text — replace it with reviewed terms before launch.</em></p>'.
            '<p>By using Prompt Aid you agree to book appointments, order medication and request rides in good faith, to provide accurate information, and to pay for confirmed bookings. Prompt Aid is a marketplace connecting patients with independent healthcare providers, pharmacies and drivers; clinical decisions remain the responsibility of the treating provider.</p>',
        ]);
    }
}
