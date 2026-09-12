<?php
declare(strict_types=1);

/** 1-indexed column number -> spreadsheet letter (1=A, 27=AA, ...). */
function xlsxColLetter(int $n): string
{
    $s = '';
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $s = chr(65 + $rem) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

function xlsxEscape(string $s): string
{
    // Strip control chars invalid in XML 1.0 (keep tab/LF/CR), then escape entities.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsxSheetXml(array $columns, array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>';

    $rowNum = 1;
    $xml .= '<row r="' . $rowNum . '">';
    foreach ($columns as $i => $col) {
        $ref = xlsxColLetter($i + 1) . $rowNum;
        $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEscape((string)$col) . '</t></is></c>';
    }
    $xml .= '</row>';

    foreach ($rows as $row) {
        $rowNum++;
        $xml .= '<row r="' . $rowNum . '">';
        $i = 0;
        foreach ($row as $value) {
            $ref = xlsxColLetter($i + 1) . $rowNum;
            if ($value === null) {
                // omit cell entirely
            } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && !preg_match('/^0\d/', $value))) {
                $xml .= '<c r="' . $ref . '"><v>' . (string)$value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEscape((string)$value) . '</t></is></c>';
            }
            $i++;
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/** Builds one .xlsx workbook, one sheet per table. Returns the temp file path (caller deletes it). */
function exportAllTablesAsXlsx(PDO $db): string
{
    $tables = backupTableNames($db);

    $tmpPath = tempnam(sys_get_temp_dir(), 'busybase_xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        implode('', array_map(
            fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
            range(1, count($tables))
        )) .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
        '<borders count="1"><border/></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
        '</styleSheet>'
    );

    $sheetEntries = [];
    foreach ($tables as $i => $table) {
        $sheetEntries[] = ['name' => substr($table, 0, 31), 'id' => $i + 1];
    }

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' .
        implode('', array_map(
            fn($s) => '<sheet name="' . xlsxEscape($s['name']) . '" sheetId="' . $s['id'] . '" r:id="rId' . $s['id'] . '"/>',
            $sheetEntries
        )) .
        '</sheets></workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        implode('', array_map(
            fn($s) => '<Relationship Id="rId' . $s['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $s['id'] . '.xml"/>',
            $sheetEntries
        )) .
        '<Relationship Id="rId' . (count($sheetEntries) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    foreach ($tables as $i => $table) {
        $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsxSheetXml($columns, $rows));
    }

    $zip->close();
    return $tmpPath;
}
