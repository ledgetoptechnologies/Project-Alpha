<?php

/**
 * @param resource $stream
 * @return int|false
 */
function csv_write_row($stream, array $fields)
{
    $safe = array_map(static function ($value) {
        if (!is_string($value) || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            return $value;
        }
        // Spreadsheet applications may ignore leading whitespace or controls
        // before a formula prefix. Mark the entire cell as literal text.
        return preg_match('/^[\p{Z}\p{C}]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }, $fields);
    return fputcsv($stream, $safe, ',', '"', '');
}
