<?php
declare(strict_types=1);

/** @return string[] */
function backupTableNames(PDO $db): array
{
    return $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}

/** One INSERT value, safely quoted/formatted for inline SQL. */
function backupSqlLiteral(PDO $db, mixed $value): string
{
    if ($value === null) return 'NULL';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $db->quote((string)$value);
}

/**
 * Full schema + data dump as one .sql string, safe to import into an empty
 * (or same-structure) database. Uses CREATE TABLE IF NOT EXISTS — never a
 * DROP TABLE — so importing it can't wipe out an existing table.
 *
 * @return array{sql:string, tables:int, rows:int}
 */
function fullDatabaseSqlDump(PDO $db, int $batchSize = 300): array
{
    $tables = backupTableNames($db);
    $out    = [];
    $out[]  = "-- Busybase database backup";
    $out[]  = "-- Generated: " . date('Y-m-d H:i:s');
    $out[]  = "SET NAMES utf8mb4;";
    $out[]  = "SET FOREIGN_KEY_CHECKS=0;";
    $out[]  = "";

    $totalRows = 0;

    foreach ($tables as $table) {
        $createRow = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? '';
        $createSql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $createSql, 1);

        $out[] = "-- --------------------------------------------------------";
        $out[] = "-- Table: `$table`";
        $out[] = "-- --------------------------------------------------------";
        $out[] = $createSql . ';';
        $out[] = '';

        $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $colList = '`' . implode('`, `', $columns) . '`';

        $stmt = $db->query("SELECT * FROM `$table`");
        $batch = [];
        $flush = function () use (&$batch, &$out, $table, $colList) {
            if (!$batch) return;
            $out[] = "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $batch) . ";";
            $out[] = '';
            $batch = [];
        };

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => backupSqlLiteral($db, $v), $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            $totalRows++;
            if (count($batch) >= $batchSize) $flush();
        }
        $flush();
    }

    $out[] = "SET FOREIGN_KEY_CHECKS=1;";

    return ['sql' => implode("\n", $out), 'tables' => count($tables), 'rows' => $totalRows];
}
