<?php

namespace App\PageBuilder\Templates;

use App\Models\PageTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Picks which PageTemplate to render for a given entity_type + context.
 * `conditions` on a template are a flat [field => value] match against the
 * entity's attributes (e.g. {"specialization":"Cardiologist"}) — the
 * highest-priority template whose conditions all match wins; the
 * `is_default` template (conditions empty) is the fallback.
 */
class TemplateResolver
{
    public function forArchive(string $entityType): ?PageTemplate
    {
        return PageTemplate::query()
            ->where('kind', 'archive')
            ->where('entity_type', $entityType)
            ->orderByDesc('priority')
            ->with('page')
            ->first();
    }

    public function forSingle(string $entityType, ?Model $entity = null): ?PageTemplate
    {
        $candidates = PageTemplate::query()
            ->where('kind', 'single')
            ->where('entity_type', $entityType)
            ->orderByDesc('priority')
            ->with('page')
            ->get();

        if ($entity) {
            foreach ($candidates as $candidate) {
                if ($this->matches($candidate, $entity)) {
                    return $candidate;
                }
            }
        }

        return $candidates->firstWhere('is_default', true) ?? $candidates->first();
    }

    protected function matches(PageTemplate $template, Model $entity): bool
    {
        $conditions = $template->conditions ?? [];

        if (empty($conditions)) {
            return $template->is_default;
        }

        foreach ($conditions as $field => $value) {
            if ((string) data_get($entity, $field) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
