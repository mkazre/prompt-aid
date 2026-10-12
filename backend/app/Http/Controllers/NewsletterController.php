<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/**
 * Footer newsletter signup (AJAX, POPIA consent) + the signed one-click
 * unsubscribe link. Signing up twice is harmless: the e-mail is unique, a
 * repeat just re-confirms consent (and re-subscribes a past unsubscriber).
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request)
    {
        if (! SupportSettings::bool('newsletter_enabled')) {
            return response()->json(['message' => 'Newsletter signups are closed right now.'], 404);
        }

        // Honeypot — bots fill every field; pretend it worked.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true, 'message' => SupportSettings::str('newsletter_success')]);
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Please tick the box to agree to receive our emails.',
        ]);

        $email = mb_strtolower(trim($data['email']));

        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);
        $subscriber->fill([
            'name' => $data['name'] ?? $subscriber->name,
            'user_id' => $subscriber->user_id ?? $request->user()?->id,
            'source' => $subscriber->exists ? $subscriber->source : 'footer',
            'consented_at' => now(),
            'consent_text' => mb_substr(SupportSettings::str('newsletter_consent_text'), 0, 500),
            'ip_address' => $request->ip(),
            'unsubscribed_at' => null,
        ]);

        try {
            $subscriber->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // A double submit raced us to the unique key — already subscribed.
        }

        return response()->json(['ok' => true, 'message' => SupportSettings::str('newsletter_success')]);
    }

    public function unsubscribe(Request $request, NewsletterSubscriber $subscriber)
    {
        if ($subscriber->isSubscribed()) {
            $subscriber->update(['unsubscribed_at' => now()]);
        }

        return view('newsletter.unsubscribed', ['email' => $subscriber->email]);
    }
}
