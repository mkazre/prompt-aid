<?php

namespace App\Support;

/**
 * Minimal dependency-free PDF writer (A4 portrait, Helvetica, text + lines).
 * Enough for statements / simple reports: real PDF 1.4 files that open in
 * any viewer. Text is WinAnsi-encoded (Latin-1); other characters become "?".
 */
class SimplePdf
{
    /** @var array<int, string> page content streams */
    protected array $pages = [];

    protected string $cur = '';

    protected float $y = 800;

    public const W = 595.28;

    public const H = 841.89;

    public const MARGIN = 40;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
        $this->y = self::H - self::MARGIN;
    }

    protected function ensure(float $h): void
    {
        if ($this->y - $h < self::MARGIN) {
            $this->addPage();
        }
    }

    public static function esc(string $s): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($s === false) {
            $s = '?';
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    /** Write text at absolute x on the current line. */
    public function textAt(float $x, string $text, float $size = 10, bool $bold = false, string $align = 'left', ?float $width = null): void
    {
        $font = $bold ? 'F2' : 'F1';
        if ($align === 'right' && $width !== null) {
            $x = $x + $width - $this->textWidth($text, $size);
        }
        $this->cur .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $this->y, self::esc($text));
    }

    /** Approximate Helvetica width (average glyph 0.5em; digits 0.556em). */
    public function textWidth(string $text, float $size): float
    {
        $w = 0.0;
        foreach (str_split((string) @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text)) as $ch) {
            $w += match (true) {
                ctype_digit($ch) => 0.556,
                ctype_upper($ch) => 0.667,
                in_array($ch, [' ', '.', ',', ':', ';', 'i', 'l', 'j', '|', "'"], true) => 0.278,
                default => 0.52,
            };
        }

        return $w * $size;
    }

    public function line(string $text, float $size = 10, bool $bold = false, float $gap = 4): void
    {
        $this->ensure($size + $gap);
        $this->y -= $size + $gap;
        $this->textAt(self::MARGIN, $text, $size, $bold);
    }

    public function space(float $h = 8): void
    {
        $this->y -= $h;
    }

    public function rule(): void
    {
        $this->ensure(6);
        $this->y -= 4;
        $this->cur .= sprintf("0.7 G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G\n", self::MARGIN, $this->y, self::W - self::MARGIN, $this->y);
        $this->y -= 2;
    }

    /**
     * A table row. $cols = [[text, width, align], ...]
     */
    public function row(array $cols, float $size = 9, bool $bold = false): void
    {
        $this->ensure($size + 6);
        $this->y -= $size + 6;
        $x = self::MARGIN;
        foreach ($cols as [$text, $width, $align]) {
            $t = (string) $text;
            while ($t !== '' && $this->textWidth($t, $size) > $width - 4) {
                $t = mb_substr($t, 0, -1);
            }
            $this->textAt($align === 'right' ? $x : $x, $t, $size, $bold, $align, $width - 4);
            $x += $width;
        }
    }

    public function output(): string
    {
        $pages = $this->pages;
        if ($this->cur !== '' || ! $pages) {
            $pages[] = $this->cur;
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = count($pages);
        $first = 5;
        for ($i = 0; $i < $n; $i++) {
            $kids[] = ($first + $i * 2).' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$n.' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pages as $i => $content) {
            $p = $first + $i * 2;
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $p + 1);
            $objs[$p + 1] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        }
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $count = max(array_keys($objs)) + 1;
        $out .= "xref\n0 ".$count."\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $out;
    }
}
