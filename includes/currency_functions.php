<?php
function getCurrencySettings(): array
{
    static $settings = null;
    if ($settings === null) {
        try {
            $row = getDB()->query('SELECT * FROM currency_settings LIMIT 1')->fetch();
            $settings = $row ?: [
                'currency_code'       => 'TSh',
                'currency_symbol'     => 'TSh',
                'currency_name'       => 'Tanzanian Shilling',
                'decimal_places'      => 0,
                'thousands_separator' => ',',
                'decimal_separator'   => '.',
                'symbol_position'     => 'before',
            ];
        } catch (PDOException) {
            $settings = [
                'currency_code'       => 'TSh',
                'currency_symbol'     => 'TSh',
                'currency_name'       => 'Tanzanian Shilling',
                'decimal_places'      => 0,
                'thousands_separator' => ',',
                'decimal_separator'   => '.',
                'symbol_position'     => 'before',
            ];
        }
    }
    return $settings;
}

function formatCurrency(float $amount): string
{
    $s = getCurrencySettings();
    $formatted = number_format(
        $amount,
        (int)$s['decimal_places'],
        $s['decimal_separator'],
        $s['thousands_separator']
    );
    return $s['symbol_position'] === 'before'
        ? $s['currency_symbol'] . ' ' . $formatted
        : $formatted . ' ' . $s['currency_symbol'];
}

function currencySymbol(): string
{
    return getCurrencySettings()['currency_symbol'];
}
