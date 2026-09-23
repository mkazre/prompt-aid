<?php

namespace App\PageBuilder\Rendering;

/**
 * Compiles a block's `styles` JSON (see AbstractBlock docblock for the
 * fixed shape) into scoped CSS, keyed by `.pa-block-{id}`. Colour values in
 * `styles` are always token names (e.g. "signal", "paper"), never hex —
 * they compile to `var(--pa-{token})`, which is what makes a Theme Settings
 * change repaint every page ever built with the block builder.
 */
class StyleCompiler
{
    protected const BREAKPOINTS = [
        'sm' => '(max-width: 640px)',
        'md' => '(max-width: 1024px)',
    ];

    /**
     * @param  array<string, mixed>  $styles
     */
    public function compile(string $blockId, array $styles): string
    {
        $selector = ".pa-block-{$blockId}";
        $base = $this->declarations($styles);

        $css = $base ? "{$selector}{" . implode(';', $base) . '}' : '';

        foreach (self::BREAKPOINTS as $key => $mediaQuery) {
            $overrides = $styles['resp'][$key] ?? null;

            if (! $overrides) {
                continue;
            }

            $decls = $this->declarations($overrides);

            if ($decls) {
                $css .= "@media {$mediaQuery}{{$selector}{" . implode(';', $decls) . '}}';
            }
        }

        return $css;
    }

    /**
     * @param  array<string, mixed>  $styles
     * @return array<int, string>
     */
    protected function declarations(array $styles): array
    {
        $decls = [];

        if ($space = $styles['space'] ?? null) {
            $decls[] = sprintf(
                'padding:%dpx %dpx %dpx %dpx',
                $space['pt'] ?? 0, $space['pr'] ?? 0, $space['pb'] ?? 0, $space['pl'] ?? 0,
            );
            $decls[] = sprintf('margin:%dpx 0 %dpx 0', $space['mt'] ?? 0, $space['mb'] ?? 0);
        }

        if ($bg = $styles['bg'] ?? null) {
            if (! empty($bg['color'])) {
                $decls[] = 'background-color:'.$this->token($bg['color']);
            }
            if (! empty($bg['image'])) {
                $decls[] = "background-image:url('{$bg['image']}')";
                $decls[] = 'background-size:cover';
                $decls[] = 'background-position:center';
            }
        }

        if ($text = $styles['text'] ?? null) {
            if (! empty($text['color'])) {
                $decls[] = 'color:'.$this->token($text['color']);
            }
            if (! empty($text['align'])) {
                $decls[] = "text-align:{$text['align']}";
            }
            if (! empty($text['size'])) {
                $decls[] = 'font-size:'.$this->fontSize($text['size']);
            }
        }

        if ($border = $styles['border'] ?? null) {
            $width = $border['width'] ?? 0;
            if ($width > 0) {
                $sides = $border['sides'] ?? ['top', 'right', 'bottom', 'left'];
                $color = $this->token($border['color'] ?? 'line');
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (in_array($side, $sides, true)) {
                        $decls[] = "border-{$side}:{$width}px solid {$color}";
                    }
                }
            }
            if (! empty($border['radius'])) {
                $decls[] = "border-radius:{$border['radius']}px";
            }
        }

        if ($layout = $styles['layout'] ?? null) {
            if (($layout['width'] ?? null) === 'container') {
                $decls[] = 'max-width:var(--pa-container)';
                $decls[] = 'margin-left:auto';
                $decls[] = 'margin-right:auto';
                $decls[] = 'padding-left:var(--pa-gutter)';
                $decls[] = 'padding-right:var(--pa-gutter)';
            } elseif (($layout['width'] ?? null) === 'full') {
                $decls[] = 'width:100%';
            }
            if (! empty($layout['minH'])) {
                $decls[] = "min-height:{$layout['minH']}px";
            }
            if (isset($layout['gap'])) {
                $decls[] = "gap:{$layout['gap']}px";
            }
        }

        return array_filter($decls);
    }

    protected function token(string $token): string
    {
        // Already a raw value (hex/rgb/etc) — pass through unchanged.
        if (str_starts_with($token, '#') || str_starts_with($token, 'rgb') || str_starts_with($token, 'var(')) {
            return $token;
        }

        return "var(--pa-{$token})";
    }

    protected function fontSize(string $size): string
    {
        return match ($size) {
            'xs' => '12px', 'sm' => '13px', 'base' => '15px', 'lg' => '20px',
            'xl' => '26px', '2xl' => '34px', '3xl' => '46px', '4xl' => '62px',
            default => $size,
        };
    }
}
