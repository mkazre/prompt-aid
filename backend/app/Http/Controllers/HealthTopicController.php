<?php

namespace App\Http\Controllers;

use App\Models\HealthTopic;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;

/** Topic of the month article + patient testimonial submissions. */
class HealthTopicController extends Controller
{
    public function current()
    {
        return $this->render(HealthTopic::current());
    }

    public function show(HealthTopic $topic)
    {
        abort_unless($topic->is_published || request()->user()?->isSuperAdmin(), 404);

        return $this->render($topic);
    }

    protected function render(?HealthTopic $topic)
    {
        return view('pages.health-topic', [
            'topic' => $topic,
            'testimonials' => $topic
                ? $topic->testimonials()->where('status', 'approved')->where('consent_to_publish', true)->latest()->limit(12)->get()
                : collect(),
            'pastTopics' => HealthTopic::query()->published()->when($topic, fn ($q) => $q->whereKeyNot($topic->id))->orderByDesc('month')->limit(6)->get(),
        ]);
    }

    public function createTestimonial(Request $request)
    {
        $current = HealthTopic::current();

        return view('pages.testimonial-create', [
            'topics' => HealthTopic::query()->published()->orderByDesc('month')->get(),
            'selectedTopicId' => (int) $request->query('topic', $current?->id),
        ]);
    }

    public function storeTestimonial(Request $request)
    {
        $data = $request->validate([
            'health_topic_id' => ['nullable', 'integer', 'exists:health_topics,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,heic,txt'],
            'consent_to_publish' => ['nullable', 'boolean'],
        ], [
            'body.required_without' => 'Please type your experience or upload a document.',
        ]);

        $paths = [];
        foreach ($request->file('attachments', []) as $file) {
            $paths[] = $file->store('testimonials', 'public');
        }

        $testimonial = Testimonial::create([
            'user_id' => $request->user()?->id,
            'health_topic_id' => $data['health_topic_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'body' => $data['body'] ?? null,
            'attachments' => $paths ?: null,
            'consent_to_publish' => (bool) ($data['consent_to_publish'] ?? false),
            'status' => 'pending',
        ]);

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                "New testimonial from {$testimonial->name}",
                \Illuminate\Support\Str::limit((string) $testimonial->body, 140) ?: 'Document(s) uploaded.',
                icon: 'heroicon-o-chat-bubble-left-right',
                url: route('filament.admin.resources.testimonials.edit', $testimonial),
                actionLabel: 'Review',
            );
        }

        return redirect()->route('testimonials.create')->with('success', 'Thank you for sharing your experience. Our team will review it shortly.');
    }
}
