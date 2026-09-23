<?php

namespace App\PageBuilder\Rendering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Resolves `{{ item.name }}`, `{{ entity.consult_fee | money }}`,
 * `{{ item.next_slot | time }}` style tokens inside a block's text props
 * against the current loop row (`item`) or single-template entity
 * (`entity`). Dot notation walks Eloquent relations/attributes; the
 * optional `| filter` formats the resolved value.
 */
class TokenResolver
{
    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function resolveProps(array $props, ?Model $item = null, ?Model $entity = null): array
    {
        array_walk_recursive($props, function (&$value) use ($item, $entity) {
            if (is_string($value) && str_contains($value, '{{')) {
                $value = $this->resolveString($value, $item, $entity);
            }
        });

        return $props;
    }

    public function resolveString(string $text, ?Model $item = null, ?Model $entity = null): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function ($matches) use ($item, $entity) {
            return $this->resolveToken($matches[1], $item, $entity);
        }, $text) ?? $text;
    }

    protected function resolveToken(string $token, ?Model $item, ?Model $entity): string
    {
        [$path, $filter] = array_pad(array_map('trim', explode('|', $token, 2)), 2, null);

        [$root, $rest] = array_pad(explode('.', $path, 2), 2, null);

        $subject = match ($root) {
            'item' => $item,
            'entity' => $entity,
            default => null,
        };

        if (! $subject || ! $rest) {
            return '';
        }

        $value = $this->walk($subject, $rest);

        return $filter ? $this->applyFilter($value, $filter) : (string) ($value ?? '');
    }

    protected function walk(mixed $subject, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if ($subject === null) {
                return null;
            }

            $subject = is_array($subject)
                ? ($subject[$segment] ?? null)
                : data_get($subject, $segment);
        }

        return $subject;
    }

    protected function applyFilter(mixed $value, string $filter): string
    {
        return match ($filter) {
            'money' => 'R'.number_format((float) $value, 0),
            'money2' => 'R'.number_format((float) $value, 2),
            'time' => $value ? \Illuminate\Support\Carbon::parse($value)->format('H:i') : '',
            'date' => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '',
            'upper' => Str::upper((string) $value),
            'lower' => Str::lower((string) $value),
            'ucfirst' => Str::ucfirst((string) $value),
            default => (string) ($value ?? ''),
        };
    }
}
