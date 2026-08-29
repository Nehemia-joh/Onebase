<?php
declare(strict_types=1);

/**
 * Minimal, dependency-free PDF writer (no Composer/vendor needed).
 * Supports A4 pages, Helvetica/Helvetica-Bold text, lines and rectangles —
 * enough to lay out a clean, table-based document like an invoice.
 * Coordinates are top-left origin (y grows downward), in points.
 */
final class SimplePdf
{
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;

    /** @var array<int, array<int, string>> */
    private array $pages = [];
    private int $pageIndex = -1;
    private string $font = 'F1';
    private float $fontSize = 10;
    /** @var array{0:float,1:float,2:float} */
    private array $color = [0, 0, 0];

    private static ?array $widthsRegular = null;
    private static ?array $widthsBold = null;

    public function __construct()
    {
        self::initWidths();
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->pageIndex++;
    }

    public function pageWidth(): float { return self::PAGE_W; }
    public function pageHeight(): float { return self::PAGE_H; }

    public function setFont(string $style = '', float $size = 10): void
    {
        $this->font = strtoupper($style) === 'B' ? 'F2' : 'F1';
        $this->fontSize = $size;
    }

    public function setColor(int $r, int $g, int $b): void
    {
        $this->color = [$r / 255, $g / 255, $b / 255];
    }

    public function textWidth(string $text): float
    {
        $widths = $this->font === 'F2' ? self::$widthsBold : self::$widthsRegular;
        $w = 0;
        foreach (str_split(self::toPdfEncoding($text)) as $ch) {
            $w += $widths[ord($ch)] ?? 556;
        }
        return $w * $this->fontSize / 1000;
    }

    public function text(float $x, float $y, string $text): void
    {
        $py = self::PAGE_H - $y;
        $escaped = self::escape(self::toPdfEncoding($text));
        [$r, $g, $b] = $this->color;
        $this->pages[$this->pageIndex][] = sprintf(
            'q %.3F %.3F %.3F rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET Q',
            $r, $g, $b, $this->font, $this->fontSize, $x, $py, $escaped
        );
    }

    public function textRight(float $xRight, float $y, string $text): void
    {
        $this->text($xRight - $this->textWidth($text), $y, $text);
    }

    public function textCenter(float $centerX, float $y, string $text): void
    {
        $this->text($centerX - $this->textWidth($text) / 2, $y, $text);
    }

    /** Shortens $text with a trailing ellipsis until it fits within $maxWidth. */
    public function truncateToWidth(string $text, float $maxWidth): string
    {
        if ($this->textWidth($text) <= $maxWidth) return $text;
        $ellipsis = '…';
        while ($text !== '' && $this->textWidth($text . $ellipsis) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . $ellipsis;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
    {
        $py1 = self::PAGE_H - $y1;
        $py2 = self::PAGE_H - $y2;
        [$r, $g, $b] = $this->color;
        $this->pages[$this->pageIndex][] = sprintf(
            'q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q',
            $r, $g, $b, $width, $x1, $py1, $x2, $py2
        );
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $py = self::PAGE_H - $y - $h;
        [$r, $g, $b] = $this->color;
        $colorOp = $fill ? 'rg' : 'RG';
        $op = $fill ? 'f' : 'S';
        $this->pages[$this->pageIndex][] = sprintf(
            'q %.3F %.3F %.3F %s %.2F %.2F %.2F %.2F re %s Q',
            $r, $g, $b, $colorOp, $x, $py, $w, $h, $op
        );
    }

    /** Sends the PDF to the browser (dest 'D') or returns it as a string (dest 'S'). */
    public function output(string $filename, string $dest = 'D'): ?string
    {
        $pdf = $this->build();
        if ($dest === 'S') return $pdf;

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        return null;
    }

    private function build(): string
    {
        $n = count($this->pages);
        $pageObjIds = [];
        $contentObjIds = [];
        $id = 5;
        for ($i = 0; $i < $n; $i++) {
            $pageObjIds[$i] = $id++;
            $contentObjIds[$i] = $id++;
        }

        $objects = [];
        $objects[1] = ['body' => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = implode(' ', array_map(fn($pid) => "$pid 0 R", $pageObjIds));
        $objects[2] = ['body' => "<< /Type /Pages /Kids [$kids] /Count $n >>"];
        $objects[3] = ['body' => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $objects[4] = ['body' => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];

        $w = self::numStr(self::PAGE_W);
        $h = self::numStr(self::PAGE_H);
        for ($i = 0; $i < $n; $i++) {
            $pid = $pageObjIds[$i];
            $cid = $contentObjIds[$i];
            $objects[$pid] = ['body' =>
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $cid 0 R >>"
            ];
            $objects[$cid] = ['stream' => implode("\n", $this->pages[$i])];
        }

        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($out);
            if (isset($obj['stream'])) {
                $stream = $obj['stream'];
                $out .= "$num 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n";
            } else {
                $out .= "$num 0 obj\n{$obj['body']}\nendobj\n";
            }
        }

        $maxNum = max(array_keys($objects));
        $xrefStart = strlen($out);
        $out .= "xref\n0 " . ($maxNum + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root 1 0 R >>\nstartxref\n$xrefStart\n%%EOF";

        return $out;
    }

    private static function numStr(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private static function toPdfEncoding(string $s): string
    {
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $r !== false ? $r : (string)preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    private static function initWidths(): void
    {
        if (self::$widthsRegular !== null) return;

        // Standard Helvetica / Helvetica-Bold AFM metrics (WinAnsiEncoding, codes 32-126).
        $reg = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
                556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,
                667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,
                278,278,278,469,556,333,
                556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,
                334,260,334,584];
        $bold = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
                 556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,
                 722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,
                 333,278,333,584,556,333,
                 556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,
                 389,280,389,584];

        self::$widthsRegular = [];
        self::$widthsBold = [];
        for ($i = 32; $i <= 126; $i++) {
            self::$widthsRegular[$i] = $reg[$i - 32];
            self::$widthsBold[$i] = $bold[$i - 32];
        }
    }
}
