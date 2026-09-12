<?php
declare(strict_types=1);

/** Builds a zip with one CSV per table. Returns the temp file path (caller deletes it). */
function exportAllTablesAsZipCsv(PDO $db): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'busybase_csv_');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);

    foreach (backupTableNames($db) as $table) {
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        $fh = fopen('php://temp', 'r+');
        if ($rows) {
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($fh, $row);
        } else {
            $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            fputcsv($fh, $columns);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $zip->addFromString("$table.csv", $csv);
    }

    $zip->close();
    return $tmpPath;
}
