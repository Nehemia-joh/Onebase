<?php
declare(strict_types=1);
require_once __DIR__ . '/pdf/SimplePdf.php';

/**
 * Renders a sale (with items/payments already attached, as built by sales.php's
 * $viewSale) as a downloadable PDF and exits.
 */
function outputInvoicePdf(array $sale): never
{
    $pdf     = new SimplePdf();
    $margin  = 40;
    $rightX  = $pdf->pageWidth() - $margin;
    $y       = $margin;

    // ── Header: business info (left) + invoice no / date (right) ──────────
    $pdf->setColor(30, 41, 59);
    $pdf->setFont('B', 16);
    $pdf->text($margin, $y, getSetting('business_name', 'OneSystem BMS'));

    $pdf->setFont('B', 18);
    $pdf->textRight($rightX, $margin, (string)$sale['invoice_no']);
    $pdf->setFont('', 9);
    $pdf->setColor(100, 116, 139);
    $pdf->textRight($rightX, $margin + 16, date('d M Y, H:i', strtotime($sale['created_at'])));

    $y += 18;
    $pdf->setFont('', 9);
    $pdf->setColor(100, 116, 139);
    if (!empty($sale['branch_name']))       { $pdf->text($margin, $y, (string)$sale['branch_name']); $y += 12; }
    if (getSetting('business_address'))     { $pdf->text($margin, $y, getSetting('business_address')); $y += 12; }
    $contactLine = [];
    if (getSetting('business_phone')) $contactLine[] = 'Tel: ' . getSetting('business_phone');
    if (getSetting('business_tin'))   $contactLine[] = 'TIN: ' . getSetting('business_tin');
    if (getSetting('business_vrn'))   $contactLine[] = 'VRN: ' . getSetting('business_vrn');
    if ($contactLine) { $pdf->text($margin, $y, implode('   ', $contactLine)); $y += 12; }

    $y = max($y, $margin + 40) + 16;
    $pdf->setColor(226, 232, 240);
    $pdf->line($margin, $y, $rightX, $y, 1);
    $y += 22;

    // ── Meta grid: customer / type / payment / status ──────────────────────
    $colW = ($rightX - $margin) / 2;
    $meta = [
        ['Customer', (string)$sale['customer_name']],
        ['Type', ucfirst((string)$sale['customer_type'])],
        ['Payment', ucwords(str_replace('_', ' ', (string)$sale['payment_method']))],
        ['Status', ucfirst((string)$sale['payment_status'])],
    ];
    $pdf->setFont('', 9);
    foreach ($meta as $i => [$label, $val]) {
        $cx = $margin + ($i % 2) * $colW;
        $cy = $y + intdiv($i, 2) * 16;
        $pdf->setColor(100, 116, 139);
        $pdf->text($cx, $cy, $label . ':');
        $pdf->setColor(30, 41, 59);
        $pdf->text($cx + 60, $cy, $val);
    }
    $y += 16 * 2 + 14;

    // ── Item table ──────────────────────────────────────────────────────────
    $qtyX = $rightX - 220;
    $priceX = $rightX - 120;

    $pdf->setFont('B', 9);
    $pdf->setColor(100, 116, 139);
    $pdf->text($margin, $y, 'PRODUCT');
    $pdf->textRight($qtyX, $y, 'QTY');
    $pdf->textRight($priceX, $y, 'PRICE');
    $pdf->textRight($rightX, $y, 'TOTAL');
    $y += 6;
    $pdf->setColor(203, 213, 225);
    $pdf->line($margin, $y, $rightX, $y, 1);
    $y += 16;

    foreach ($sale['items'] as $si) {
        if ($y > $pdf->pageHeight() - 170) {
            $pdf->addPage();
            $y = $margin;
        }
        $pdf->setFont('', 9);
        $pdf->setColor(30, 41, 59);
        $name = $pdf->truncateToWidth((string)$si['product_name'], $qtyX - $margin - 20);
        $pdf->text($margin, $y, $name);
        $pdf->textRight($qtyX, $y, (string)$si['quantity']);
        $pdf->textRight($priceX, $y, formatCurrency((float)$si['unit_price']));
        $pdf->textRight($rightX, $y, formatCurrency((float)$si['total_price']));
        $y += 12;

        $pdf->setFont('', 7.5);
        $pdf->setColor(148, 163, 184);
        $pdf->text($margin, $y, trim(($si['sku'] ?: '') . '  •  ' . $si['price_type']));
        $y += 16;
    }

    $y += 4;
    $pdf->setColor(30, 41, 59);
    $pdf->line($margin, $y, $rightX, $y, 1.2);
    $y += 18;

    // ── Totals ──────────────────────────────────────────────────────────────
    $totals = [['Subtotal', (float)$sale['subtotal']]];
    if ((float)$sale['discount'] > 0) $totals[] = ['Discount', -(float)$sale['discount']];
    if ((float)$sale['tax'] > 0) {
        $label = vatMode() === 'inclusive' ? 'Includes VAT' : 'VAT';
        $totals[] = [$label, (float)$sale['tax']];
    }
    foreach ($totals as [$label, $amt]) {
        $pdf->setFont('', 9);
        $pdf->setColor(71, 85, 105);
        $pdf->text($margin, $y, $label);
        $pdf->textRight($rightX, $y, ($amt < 0 ? '- ' : '') . formatCurrency(abs($amt)));
        $y += 14;
    }

    $pdf->setFont('B', 12);
    $pdf->setColor(15, 23, 42);
    $pdf->text($margin, $y, 'TOTAL');
    $pdf->textRight($rightX, $y, formatCurrency((float)$sale['total']));
    $y += 20;

    $outstanding = (float)$sale['total'] - (float)$sale['amount_paid'];
    if ($outstanding > 0 || $sale['payment_method'] === 'credit') {
        $pdf->setFont('', 9);
        $pdf->setColor(71, 85, 105);
        $pdf->text($margin, $y, 'Amount Paid');
        $pdf->textRight($rightX, $y, formatCurrency((float)$sale['amount_paid']));
        $y += 14;
        if ($outstanding > 0) {
            $pdf->setFont('B', 10);
            $pdf->setColor(190, 18, 60);
            $pdf->text($margin, $y, 'BALANCE DUE');
            $pdf->textRight($rightX, $y, formatCurrency($outstanding));
            $y += 14;
        }
    }

    if (!empty($sale['notes'])) {
        $y += 10;
        $pdf->setFont('', 8);
        $pdf->setColor(100, 116, 139);
        $pdf->text($margin, $y, 'Notes: ' . $sale['notes']);
        $y += 12;
    }

    $y += 16;
    $pdf->setFont('', 8);
    $pdf->setColor(148, 163, 184);
    $pdf->textCenter(($margin + $rightX) / 2, $y, getSetting('receipt_footer', 'Thank you for your business!'));

    $pdf->output($sale['invoice_no'] . '.pdf');
    exit;
}
