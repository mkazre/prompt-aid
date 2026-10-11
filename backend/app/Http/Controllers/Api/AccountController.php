<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Account-level actions shared by every role in the mobile app: change
 * password, and the CMS-backed "Terms & Conditions" / "About us" pages.
 */
class AccountController extends Controller
{
    /**
     * Setting key that picks which page-builder page backs each in-app page
     * (editable in Staff → System → Settings), with the default slug.
     */
    protected const APP_PAGES = [
        'terms' => ['app_terms_page_slug', 'terms'],
        'about' => ['app_about_page_slug', 'about'],
        'privacy' => ['app_privacy_page_slug', 'privacy'],
    ];

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);

        // Sign out every other device; keep the one that made this request.
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    /**
     * A page-builder page flattened into simple sections (headings,
     * paragraphs, bullet items) the app can render natively.
     */
    public function page(string $key)
    {
        abort_unless(isset(self::APP_PAGES[$key]), 404);

        [$settingKey, $default] = self::APP_PAGES[$key];
        $slug = trim((string) (Setting::query()->where('key', $settingKey)->value('value') ?: $default), '/');

        $page = Page::query()->where('slug', $slug)->first();

        if (! $page || ! $page->isPublished()) {
            return response()->json(['message' => 'This page has not been published yet.'], 404);
        }

        $blocks = $page->allBlocks()->get();
        $content = $this->flatten($blocks, null);

        return response()->json([
            'key' => $key,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => url('/'.$page->slug),
            'updated_at' => $page->updated_at?->toIso8601String(),
            'content' => $content,
        ]);
    }

    /**
     * @param  Collection<int, PageBlock>  $blocks
     * @return array<int, array{type: string, text: string, title?: string}>
     */
    protected function flatten(Collection $blocks, ?int $parentId): array
    {
        $out = [];

        foreach ($blocks->where('parent_id', $parentId)->sortBy('sort') as $block) {
            $props = is_array($block->props) ? $block->props : [];

            foreach ($this->blockContent((string) $block->type, $props) as $item) {
                $out[] = $item;
            }

            foreach ($this->flatten($blocks, $block->id) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function blockContent(string $type, array $props): array
    {
        $out = [];

        if ($type === 'heading' && filled($props['text'] ?? null)) {
            return [['type' => 'heading', 'text' => $this->clean($props['text'])]];
        }

        foreach (['eyebrow', 'title', 'heading'] as $k) {
            if (is_string($props[$k] ?? null) && filled($props[$k])) {
                $out[] = ['type' => 'heading', 'text' => $this->clean($props[$k])];
            }
        }

        foreach (['html', 'body', 'content'] as $k) {
            if (is_string($props[$k] ?? null) && filled($props[$k])) {
                foreach ($this->htmlToParagraphs($props[$k]) as $p) {
                    $out[] = $p;
                }
            }
        }

        foreach (['text', 'subtitle', 'description', 'lead', 'intro'] as $k) {
            if (is_string($props[$k] ?? null) && filled($props[$k])) {
                $out[] = ['type' => 'paragraph', 'text' => $this->clean($props[$k])];
            }
        }

        foreach (['items', 'faqs', 'questions'] as $k) {
            if (! is_array($props[$k] ?? null)) {
                continue;
            }
            foreach ($props[$k] as $item) {
                if (is_string($item)) {
                    $out[] = ['type' => 'bullet', 'text' => $this->clean($item)];

                    continue;
                }
                if (! is_array($item)) {
                    continue;
                }
                $title = $item['title'] ?? $item['question'] ?? $item['value'] ?? $item['label'] ?? null;
                $body = $item['description'] ?? $item['answer'] ?? $item['body'] ?? $item['text'] ?? (isset($item['value']) ? ($item['label'] ?? null) : null);
                if (is_string($title) || is_string($body)) {
                    $out[] = array_filter([
                        'type' => 'bullet',
                        'title' => is_string($title) ? $this->clean($title) : null,
                        'text' => is_string($body) ? $this->clean($body) : '',
                    ], fn ($v) => $v !== null);
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    protected function htmlToParagraphs(string $html): array
    {
        $out = [];
        preg_match_all('#<(h[1-6]|p|li)[^>]*>(.*?)</\1>#is', $html, $m, PREG_SET_ORDER);

        if (! $m) {
            $text = $this->clean($html);

            return $text !== '' ? [['type' => 'paragraph', 'text' => $text]] : [];
        }

        foreach ($m as [$_, $tag, $inner]) {
            $text = $this->clean($inner);
            if ($text === '') {
                continue;
            }
            $tag = strtolower($tag);
            $out[] = ['type' => $tag === 'li' ? 'bullet' : ($tag[0] === 'h' ? 'heading' : 'paragraph'), 'text' => $text];
        }

        return $out;
    }

    protected function clean(string $s): string
    {
        $s = preg_replace('#<br\s*/?>#i', "\n", $s);

        return trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
