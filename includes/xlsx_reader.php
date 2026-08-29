<?php
declare(strict_types=1);

/**
 * Minimal native .xlsx reader — no third-party code.
 * An .xlsx is a zip of XML; we need xl/sharedStrings.xml and the
 * first worksheet. Returns rows as 0-indexed arrays of trimmed strings
 * (numeric cells come back as their raw numeric string).
 *
 * Throws RuntimeException with a human-readable message on bad files.
 */
function readXlsxRows(string $path, int $maxRows = 20000): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP zip extension is not available on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the file — is it a valid .xlsx workbook?');
    }

    // ── Shared strings (may be absent in numeric-only sheets) ──
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else { // rich-text runs: concatenate all <r><t>
                    $txt = '';
                    foreach ($si->r as $r) { $txt .= (string)$r->t; }
                    $shared[] = $txt;
                }
            }
        }
    }

    // ── Locate the first sheet via workbook + rels (fallback: sheet1) ──
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wb   = @simplexml_load_string($wbXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    break;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not find a worksheet inside the workbook.');
    }
    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false || !isset($sheet->sheetData)) {
        throw new RuntimeException('Worksheet XML could not be parsed.');
    }

    $rows = [];
    $n = 0;
    foreach ($sheet->sheetData->row as $row) {
        if (++$n > $maxRows) break;
        $cells = [];
        foreach ($row->c as $c) {
            $ref  = (string)$c['r'];                       // e.g. "B12"
            $col  = xlsxColIndex(preg_replace('/\d+/', '', $ref));
            $type = (string)$c['t'];
            if ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string)$c->is->t : '';
            } else {
                $val = isset($c->v) ? (string)$c->v : '';
                if ($type === 's') { $val = $shared[(int)$val] ?? ''; }
            }
            $cells[$col] = trim($val);
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** "A" => 0, "B" => 1, ..., "AA" => 26 */
function xlsxColIndex(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/**
 * Excel stores dates as day serials from 1899-12-30.
 * Accepts a serial number or a textual date; returns 'Y-m-d' or null.
 * Blank / "0000-00-00" / zero => null (no expiry).
 */
function xlsxToDate(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || $raw === '0000-00-00') return null;
    if (is_numeric($raw)) {
        $serial = (float)$raw;
        if ($serial < 1) return null;
        $ts = (int)round(($serial - 25569) * 86400); // 25569 = days 1899-12-30 → 1970-01-01
        return gmdate('Y-m-d', $ts);
    }
    // Textual date — accept common formats
    $ts = strtotime(str_replace('/', '-', $raw));
    if ($ts === false) return null;
    $d = date('Y-m-d', $ts);
    return $d === '0000-00-00' ? null : $d;
}
