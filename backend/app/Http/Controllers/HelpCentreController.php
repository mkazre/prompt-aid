<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\PageBuilder\Rendering\PageRenderer;
use App\PageBuilder\Templates\TemplateResolver;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/**
 * The Help centre (/help): admin-managed FAQs grouped by section, with
 * search. /faq used to be a static page-builder page; while "Send /faq to
 * the Help centre" is on (Support settings) it redirects here, otherwise
 * the old page-builder page still renders.
 */
class HelpCentreController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $role = $request->user()?->role;

        $faqs = Faq::forViewer($role, $term);

        $categories = FaqCategory::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        $groups = $categories
            ->map(fn (FaqCategory $c) => ['category' => $c, 'faqs' => $faqs->where('faq_category_id', $c->id)->values()])
            ->filter(fn ($g) => $g['faqs']->isNotEmpty())
            ->values();

        $uncategorised = $faqs->whereNull('faq_category_id')->values();
        if ($uncategorised->isNotEmpty()) {
            $groups->push(['category' => null, 'faqs' => $uncategorised]);
        }

        return view('help.index', [
            'groups' => $groups,
            'term' => $term,
            'total' => $faqs->count(),
            'heading' => SupportSettings::str('help_heading'),
            'intro' => SupportSettings::str('help_intro'),
        ]);
    }

    public function faq(Request $request, PageBuilderRenderController $controller, PageRenderer $renderer, TemplateResolver $templates)
    {
        if (SupportSettings::bool('help_faq_redirect')) {
            return redirect()->route('help');
        }

        return $controller->render($request, 'faq', $renderer, $templates);
    }
}
