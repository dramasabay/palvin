<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Retail Reports';

$printMode = (($_GET['print'] ?? '') === '1');
if ($printMode && !isset($_GET['per_page'])) {
    $_GET['per_page'] = 'all';
}

[$startDate, $endDate] = report_range();
[$rangeStart, $rangeEndExclusive] = report_datetime_bounds($startDate, $endDate);
$exportMode = trim((string)($_GET['export'] ?? ''));

$summary = db_one($pdo,
    'SELECT
        COUNT(*) AS orders_count,
        COALESCE(SUM(subtotal),0) AS subtotal_sales,
        COALESCE(SUM(discount_amount),0) AS discounts,
        COALESCE(SUM(grand_total),0) AS total_sales,
        COALESCE(SUM(CASE WHEN payment_type = "Cash" THEN grand_total ELSE 0 END),0) AS cash_sales,
        COALESCE(SUM(CASE WHEN payment_type = "ABA" THEN grand_total ELSE 0 END),0) AS aba_sales,
        COALESCE(SUM(CASE WHEN payment_type = "Bank Transfer" THEN grand_total ELSE 0 END),0) AS bank_sales
     FROM retail_orders
     WHERE order_date >= ? AND order_date < ?',
    [$rangeStart, $rangeEndExclusive]
);

$paymentRows = db_all($pdo,
    'SELECT
        COALESCE(NULLIF(payment_type, ""), "Unspecified") AS payment_type,
        COUNT(*) AS orders_count,
        COALESCE(SUM(grand_total),0) AS sales_total
     FROM retail_orders
     WHERE order_date >= ? AND order_date < ?
     GROUP BY COALESCE(NULLIF(payment_type, ""), "Unspecified")
     ORDER BY sales_total DESC, payment_type ASC',
    [$rangeStart, $rangeEndExclusive]
);

$stockOut = (int)db_value($pdo,
    'SELECT COALESCE(SUM(roi.quantity),0)
     FROM retail_order_items roi
     JOIN retail_orders ro ON ro.id = roi.order_id
     WHERE ro.order_date >= ? AND ro.order_date < ?',
    [$rangeStart, $rangeEndExclusive]
);

$total = (int)db_value($pdo,
    'SELECT COUNT(*)
     FROM retail_orders
     WHERE order_date >= ? AND order_date < ?',
    [$rangeStart, $rangeEndExclusive]
);
$meta = paginate_meta($total, 10, 200);

$orderSelect = 'SELECT
        DATE(order_date) AS order_day,
        order_no,
        COALESCE(NULLIF(customer_name, ""), "Walk-in Customer") AS customer_name,
        COALESCE(NULLIF(payment_type, ""), "Unspecified") AS payment_type,
        COALESCE(NULLIF(customer_type, ""), "—") AS customer_type,
        subtotal,
        discount_amount,
        grand_total
     FROM retail_orders
     WHERE order_date >= ? AND order_date < ?
     ORDER BY order_date DESC';

$rows = db_all(
    $pdo,
    $orderSelect . ' LIMIT ' . (int)$meta['per_page'] . ' OFFSET ' . (int)$meta['offset'],
    [$rangeStart, $rangeEndExclusive]
);

$exportRows = in_array($exportMode, ['csv', 'excel'], true)
    ? db_all($pdo, $orderSelect, [$rangeStart, $rangeEndExclusive])
    : $rows;

$itemRows = db_all($pdo,
    'SELECT
        roi.item_name,
        SUM(roi.quantity) AS qty_sold,
        AVG(roi.unit_price) AS avg_price,
        SUM(roi.total_price) AS line_total
     FROM retail_order_items roi
     JOIN retail_orders ro ON ro.id = roi.order_id
     WHERE ro.order_date >= ? AND ro.order_date < ?
     GROUP BY roi.item_name
     ORDER BY line_total DESC, roi.item_name ASC',
    [$rangeStart, $rangeEndExclusive]
);

$pageSubtotal = 0.0;
$pageDiscount = 0.0;
$pageNetSales = 0.0;
foreach ($rows as $row) {
    $pageSubtotal += (float)$row['subtotal'];
    $pageDiscount += (float)$row['discount_amount'];
    $pageNetSales += (float)$row['grand_total'];
}

$itemQtyTotal = 0;
$itemSalesTotal = 0.0;
foreach ($itemRows as $item) {
    $itemQtyTotal += (int)$item['qty_sold'];
    $itemSalesTotal += (float)$item['line_total'];
}

if ($exportMode === 'csv') {
    csv_headers('retail-report-' . $startDate . '-to-' . $endDate . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['RETAIL REPORT', 'Period: ' . $startDate . ' to ' . $endDate]);
    fputcsv($out, []);

    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Orders', (int)$summary['orders_count']]);
    fputcsv($out, ['Subtotal', number_format((float)$summary['subtotal_sales'], 2, '.', '')]);
    fputcsv($out, ['Discounts', number_format((float)$summary['discounts'], 2, '.', '')]);
    fputcsv($out, ['Net Sales', number_format((float)$summary['total_sales'], 2, '.', '')]);
    fputcsv($out, ['Cash', number_format((float)$summary['cash_sales'], 2, '.', '')]);
    fputcsv($out, ['ABA', number_format((float)$summary['aba_sales'], 2, '.', '')]);
    fputcsv($out, ['Bank Transfer', number_format((float)$summary['bank_sales'], 2, '.', '')]);
    fputcsv($out, ['Stock Out Qty', $stockOut]);
    fputcsv($out, []);

    fputcsv($out, ['PAYMENT BREAKDOWN']);
    fputcsv($out, ['Payment Type', 'Orders', 'Sales', 'Share %']);
    foreach ($paymentRows as $row) {
        $share = (float)$summary['total_sales'] > 0 ? (((float)$row['sales_total'] / (float)$summary['total_sales']) * 100) : 0;
        fputcsv($out, [
            $row['payment_type'],
            (int)$row['orders_count'],
            number_format((float)$row['sales_total'], 2, '.', ''),
            number_format($share, 2, '.', ''),
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['ITEM SALES BREAKDOWN']);
    fputcsv($out, ['Item Name', 'Qty Sold', 'Avg Price', 'Sales Total']);
    foreach ($itemRows as $item) {
        fputcsv($out, [
            $item['item_name'],
            (int)$item['qty_sold'],
            number_format((float)$item['avg_price'], 2, '.', ''),
            number_format((float)$item['line_total'], 2, '.', ''),
        ]);
    }
    fputcsv($out, ['Total', $itemQtyTotal, '', number_format($itemSalesTotal, 2, '.', '')]);
    fputcsv($out, []);

    fputcsv($out, ['ORDER LIST']);
    fputcsv($out, ['Date', 'Order No', 'Customer', 'Payment', 'Customer Type', 'Subtotal', 'Discount', 'Total']);
    foreach ($exportRows as $row) {
        fputcsv($out, [
            $row['order_day'],
            $row['order_no'],
            $row['customer_name'],
            $row['payment_type'],
            $row['customer_type'],
            number_format((float)$row['subtotal'], 2, '.', ''),
            number_format((float)$row['discount_amount'], 2, '.', ''),
            number_format((float)$row['grand_total'], 2, '.', ''),
        ]);
    }
    fputcsv($out, ['Total', '', '', '', '', number_format((float)$summary['subtotal_sales'], 2, '.', ''), number_format((float)$summary['discounts'], 2, '.', ''), number_format((float)$summary['total_sales'], 2, '.', '')]);

    fclose($out);
    exit;
}

if ($exportMode === 'excel') {
    excel_headers('retail-report-' . $startDate . '-to-' . $endDate . '.xls');
    header('Cache-Control: max-age=0');

    if (!function_exists('retail_xls_h')) {
        function retail_xls_h(string $text, int $colspan = 1): string {
            $cs = $colspan > 1 ? ' colspan="' . $colspan . '"' : '';
            return '<th' . $cs . ' style="background:#1a1a2e;color:#fff;font-weight:bold;padding:6px 10px;border:1px solid #ccc;text-align:left;white-space:nowrap">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</th>';
        }
    }
    if (!function_exists('retail_xls_th')) {
        function retail_xls_th(string $text, string $align = 'left'): string {
            return '<th style="background:#f0f0f0;font-weight:bold;padding:5px 8px;border:1px solid #ccc;text-align:' . $align . ';white-space:nowrap">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</th>';
        }
    }
    if (!function_exists('retail_xls_td')) {
        function retail_xls_td(string $text, string $align = 'left', string $extra = ''): string {
            return '<td style="padding:5px 8px;border:1px solid #ddd;text-align:' . $align . ';' . $extra . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</td>';
        }
    }
    if (!function_exists('retail_xls_section')) {
        function retail_xls_section(string $title, int $cols): string {
            return '<tr><td colspan="' . $cols . '" style="background:#2d6a4f;color:#fff;font-weight:bold;padding:8px 10px;font-size:13px;border:1px solid #999">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
    }
    if (!function_exists('retail_xls_blank')) {
        function retail_xls_blank(int $cols): string {
            return '<tr><td colspan="' . $cols . '" style="padding:4px;border:none">&nbsp;</td></tr>';
        }
    }
    ?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head><body>
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">

    <tr><?= retail_xls_h('RETAIL REPORT', 8) ?></tr>
    <tr><?= retail_xls_h('Period: ' . $startDate . ' to ' . $endDate, 8) ?></tr>
    <?= retail_xls_blank(8) ?>

    <?= retail_xls_section('SUMMARY', 8) ?>
    <tr><?= retail_xls_th('Orders') ?><?= retail_xls_td((string)(int)$summary['orders_count']) ?></tr>
    <tr><?= retail_xls_th('Subtotal') ?><?= retail_xls_td(number_format((float)$summary['subtotal_sales'], 2), 'left', 'color:#0369a1;font-weight:bold') ?></tr>
    <tr><?= retail_xls_th('Discounts') ?><?= retail_xls_td(number_format((float)$summary['discounts'], 2), 'left', 'color:#dc2626;font-weight:bold') ?></tr>
    <tr><?= retail_xls_th('Net Sales') ?><?= retail_xls_td(number_format((float)$summary['total_sales'], 2), 'left', 'color:#047857;font-weight:bold') ?></tr>
    <tr><?= retail_xls_th('Cash') ?><?= retail_xls_td(number_format((float)$summary['cash_sales'], 2), 'left', 'color:#047857') ?></tr>
    <tr><?= retail_xls_th('ABA') ?><?= retail_xls_td(number_format((float)$summary['aba_sales'], 2), 'left', 'color:#1d4ed8') ?></tr>
    <tr><?= retail_xls_th('Bank Transfer') ?><?= retail_xls_td(number_format((float)$summary['bank_sales'], 2), 'left', 'color:#6d28d9') ?></tr>
    <tr><?= retail_xls_th('Stock Out Qty') ?><?= retail_xls_td((string)$stockOut, 'left', 'color:#b45309;font-weight:bold') ?></tr>
    <?= retail_xls_blank(8) ?>

    <?= retail_xls_section('PAYMENT BREAKDOWN', 8) ?>
    <tr>
        <?= retail_xls_th('Payment Type') ?><?= retail_xls_th('Orders', 'right') ?><?= retail_xls_th('Sales', 'right') ?><?= retail_xls_th('Share %', 'right') ?>
    </tr>
    <?php foreach ($paymentRows as $row):
        $paymentStyle = match (strtolower((string)$row['payment_type'])) {
            'cash' => 'background:#ecfdf5;color:#047857;font-weight:bold',
            'aba' => 'background:#eff6ff;color:#1d4ed8;font-weight:bold',
            'bank transfer' => 'background:#f5f3ff;color:#6d28d9;font-weight:bold',
            default => 'background:#fffbeb;color:#b45309;font-weight:bold',
        };
        $share = (float)$summary['total_sales'] > 0 ? (((float)$row['sales_total'] / (float)$summary['total_sales']) * 100) : 0;
    ?>
    <tr>
        <?= retail_xls_td($row['payment_type'], 'left', $paymentStyle) ?>
        <?= retail_xls_td((string)(int)$row['orders_count'], 'right') ?>
        <?= retail_xls_td(number_format((float)$row['sales_total'], 2), 'right') ?>
        <?= retail_xls_td(number_format($share, 2) . '%', 'right') ?>
    </tr>
    <?php endforeach; ?>
    <?= retail_xls_blank(8) ?>

    <?= retail_xls_section('ITEM SALES BREAKDOWN', 8) ?>
    <tr>
        <?= retail_xls_th('Item Name') ?><?= retail_xls_th('Qty Sold', 'right') ?><?= retail_xls_th('Avg Price', 'right') ?><?= retail_xls_th('Sales Total', 'right') ?>
    </tr>
    <?php foreach ($itemRows as $item): ?>
    <tr>
        <?= retail_xls_td($item['item_name'], 'left', 'font-weight:bold') ?>
        <?= retail_xls_td((string)(int)$item['qty_sold'], 'right') ?>
        <?= retail_xls_td(number_format((float)$item['avg_price'], 2), 'right') ?>
        <?= retail_xls_td(number_format((float)$item['line_total'], 2), 'right', 'color:#047857;font-weight:bold') ?>
    </tr>
    <?php endforeach; ?>
    <tr>
        <?= retail_xls_td('Total', 'left', 'background:#f5f5f5;font-weight:bold') ?>
        <?= retail_xls_td((string)$itemQtyTotal, 'right', 'background:#f5f5f5;font-weight:bold') ?>
        <?= retail_xls_td('', 'right', 'background:#f5f5f5') ?>
        <?= retail_xls_td(number_format($itemSalesTotal, 2), 'right', 'background:#f5f5f5;color:#047857;font-weight:bold') ?>
    </tr>
    <?= retail_xls_blank(8) ?>

    <?= retail_xls_section('ORDER LIST', 8) ?>
    <tr>
        <?= retail_xls_th('Date') ?><?= retail_xls_th('Order No') ?><?= retail_xls_th('Customer') ?><?= retail_xls_th('Payment') ?>
        <?= retail_xls_th('Customer Type') ?><?= retail_xls_th('Subtotal', 'right') ?><?= retail_xls_th('Discount', 'right') ?><?= retail_xls_th('Total', 'right') ?>
    </tr>
    <?php foreach ($exportRows as $row):
        $paymentStyle = match (strtolower((string)$row['payment_type'])) {
            'cash' => 'color:#047857;font-weight:bold',
            'aba' => 'color:#1d4ed8;font-weight:bold',
            'bank transfer' => 'color:#6d28d9;font-weight:bold',
            default => 'color:#b45309;font-weight:bold',
        };
        $discountStyle = (float)$row['discount_amount'] > 0 ? 'color:#dc2626;font-weight:bold' : 'color:#737373';
    ?>
    <tr>
        <?= retail_xls_td($row['order_day']) ?>
        <?= retail_xls_td($row['order_no'], 'left', 'color:#0369a1;font-weight:bold') ?>
        <?= retail_xls_td($row['customer_name']) ?>
        <?= retail_xls_td($row['payment_type'], 'left', $paymentStyle) ?>
        <?= retail_xls_td($row['customer_type']) ?>
        <?= retail_xls_td(number_format((float)$row['subtotal'], 2), 'right') ?>
        <?= retail_xls_td((float)$row['discount_amount'] > 0 ? number_format((float)$row['discount_amount'], 2) : '—', 'right', $discountStyle) ?>
        <?= retail_xls_td(number_format((float)$row['grand_total'], 2), 'right', 'color:#047857;font-weight:bold') ?>
    </tr>
    <?php endforeach; ?>
    <tr>
        <?= retail_xls_td('Total', 'left', 'background:#f5f5f5;font-weight:bold') ?>
        <?= retail_xls_td('', 'left', 'background:#f5f5f5') ?>
        <?= retail_xls_td('', 'left', 'background:#f5f5f5') ?>
        <?= retail_xls_td('', 'left', 'background:#f5f5f5') ?>
        <?= retail_xls_td('', 'left', 'background:#f5f5f5') ?>
        <?= retail_xls_td(number_format((float)$summary['subtotal_sales'], 2), 'right', 'background:#f5f5f5;font-weight:bold') ?>
        <?= retail_xls_td(number_format((float)$summary['discounts'], 2), 'right', 'background:#f5f5f5;color:#dc2626;font-weight:bold') ?>
        <?= retail_xls_td(number_format((float)$summary['total_sales'], 2), 'right', 'background:#f5f5f5;color:#047857;font-weight:bold') ?>
    </tr>

</table>
</body></html>
<?php
    exit;
}

require __DIR__ . '/includes/header.php';
?>

<div class="mb-4 flex flex-wrap gap-3 no-print items-center">
    <form class="flex flex-wrap items-center gap-3">
        <input type="date" name="start_date" value="<?= e($startDate) ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
        <input type="date" name="end_date" value="<?= e($endDate) ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
        <select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
            <?php foreach (page_size_options(['all',8,10,12]) as $opt): ?>
            <option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>>
                <?= e(is_string($opt) ? strtoupper($opt) : (string)$opt) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="rounded-2xl px-4 py-2 border">View</button>
    </form>
    <a href="?<?= e(http_build_query(['start_date' => $startDate, 'end_date' => $endDate, 'export' => 'csv'])) ?>" class="rounded-2xl px-4 py-2 border">Export CSV</a>
    <a href="?<?= e(http_build_query(['start_date' => $startDate, 'end_date' => $endDate, 'export' => 'excel'])) ?>" class="rounded-2xl px-4 py-2 border">Export Excel</a>
    <a href="?<?= e(http_build_query(['start_date' => $startDate, 'end_date' => $endDate, 'per_page' => 'all', 'print' => '1'])) ?>" target="_blank" class="rounded-2xl px-4 py-2 border">Export PDF</a>
</div>

<div class="print-wrap pvn-card p-8 space-y-10">
    <div class="flex justify-between items-start gap-6">
        <div>
            <h3 class="text-2xl font-semibold">Retail Report</h3>
            <div class="text-neutral-500 mt-1">Period: <?= e($startDate) ?> to <?= e($endDate) ?></div>
            <div class="text-sm text-neutral-400 mt-2">Default filter range is the latest 1 month.</div>
            <div class="text-sm text-neutral-400 mt-2">CSV and Excel exports include the full date range. PDF opens a print-ready page with all rows.</div>
        </div>
        <img src="<?= e(invoice_logo($pdo)) ?>" class="h-16 object-contain">
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="rounded-2xl bg-neutral-50 p-4 border">
            <div class="text-xs text-neutral-500 mb-1">Orders</div>
            <div class="font-semibold text-2xl"><?= (int)$summary['orders_count'] ?></div>
        </div>
        <div class="rounded-2xl bg-sky-50 border border-sky-200 p-4">
            <div class="text-xs text-sky-700 mb-1">Subtotal</div>
            <div class="font-semibold text-2xl text-sky-800"><?= e(money($summary['subtotal_sales'])) ?></div>
        </div>
        <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4">
            <div class="text-xs text-rose-700 mb-1">Discounts</div>
            <div class="font-semibold text-2xl text-rose-800"><?= e(money($summary['discounts'])) ?></div>
        </div>
        <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4">
            <div class="text-xs text-emerald-700 mb-1">Net Sales</div>
            <div class="font-semibold text-2xl text-emerald-800"><?= money_dual($pdo, $summary['total_sales'], true) ?></div>
        </div>
        <div class="rounded-2xl bg-violet-50 border border-violet-200 p-4">
            <div class="text-xs text-violet-700 mb-1">ABA + Bank</div>
            <div class="font-semibold text-2xl text-violet-800"><?= e(money((float)$summary['aba_sales'] + (float)$summary['bank_sales'])) ?></div>
        </div>
        <div class="rounded-2xl bg-amber-50 border border-amber-200 p-4">
            <div class="text-xs text-amber-700 mb-1">Stock Out Qty</div>
            <div class="font-semibold text-2xl text-amber-800"><?= $stockOut ?></div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[0.95fr,1.05fr]">
        <div>
            <h4 class="font-semibold text-lg mb-3">Payment Breakdown</h4>
            <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="text-left p-3">Payment Type</th>
                        <th class="text-right p-3">Orders</th>
                        <th class="text-right p-3">Sales</th>
                        <th class="text-right p-3">Share</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($paymentRows as $row):
                    $share = (float)$summary['total_sales'] > 0 ? (((float)$row['sales_total'] / (float)$summary['total_sales']) * 100) : 0;
                    $badgeClass = match (strtolower((string)$row['payment_type'])) {
                        'cash' => 'bg-emerald-100 text-emerald-700',
                        'aba' => 'bg-sky-100 text-sky-700',
                        'bank transfer' => 'bg-violet-100 text-violet-700',
                        default => 'bg-amber-100 text-amber-700',
                    };
                ?>
                    <tr class="border-t">
                        <td class="p-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badgeClass ?>"><?= e($row['payment_type']) ?></span></td>
                        <td class="p-3 text-right"><?= (int)$row['orders_count'] ?></td>
                        <td class="p-3 text-right font-semibold"><?= money_dual($pdo, $row['sales_total'], true) ?></td>
                        <td class="p-3 text-right text-neutral-500"><?= e(number_format($share, 2)) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-neutral-100 font-semibold text-sm">
                    <tr>
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right"><?= (int)$summary['orders_count'] ?></td>
                        <td class="p-3 text-right text-emerald-700"><?= money_dual($pdo, $summary['total_sales'], true) ?></td>
                        <td class="p-3 text-right">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div>
            <h4 class="font-semibold text-lg mb-3">Item Sales Breakdown</h4>
            <?php if (!$itemRows): ?>
                <div class="rounded-2xl border border-dashed p-6 text-center text-neutral-500">No item sales data for the selected period.</div>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="text-left p-3">Item Name</th>
                        <th class="text-right p-3">Qty Sold</th>
                        <th class="text-right p-3">Avg Price</th>
                        <th class="text-right p-3">Sales Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($itemRows as $item): ?>
                    <tr class="border-t">
                        <td class="p-3 font-medium"><?= e($item['item_name']) ?></td>
                        <td class="p-3 text-right"><?= (int)$item['qty_sold'] ?></td>
                        <td class="p-3 text-right"><?= e(money($item['avg_price'])) ?></td>
                        <td class="p-3 text-right font-semibold text-emerald-700"><?= e(money($item['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-neutral-100 font-semibold text-sm">
                    <tr>
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right"><?= $itemQtyTotal ?></td>
                        <td class="p-3"></td>
                        <td class="p-3 text-right text-emerald-700"><?= e(money($itemSalesTotal)) ?></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
                <h4 class="font-semibold text-lg">Order List</h4>
                <p class="text-sm text-neutral-500">Showing <?= count($rows) ?> record(s)<?= !$meta['show_all'] ? ' on this page' : '' ?>.</p>
            </div>
            <?php if (!$meta['show_all']): ?>
            <div class="text-sm text-neutral-500">Page totals: <?= e(money($pageNetSales)) ?> net sales</div>
            <?php endif; ?>
        </div>
        <?php if (!$rows): ?>
            <div class="rounded-2xl border border-dashed p-6 text-center text-neutral-500">No retail orders in the selected period.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
            <thead class="bg-neutral-50">
                <tr>
                    <th class="text-left p-3">Date</th>
                    <th class="text-left p-3">Order No</th>
                    <th class="text-left p-3">Customer</th>
                    <th class="text-left p-3">Payment</th>
                    <th class="text-left p-3">Type</th>
                    <th class="text-right p-3">Subtotal</th>
                    <th class="text-right p-3">Discount</th>
                    <th class="text-right p-3">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $paymentBadge = match (strtolower((string)$row['payment_type'])) {
                    'cash' => 'bg-emerald-100 text-emerald-700',
                    'aba' => 'bg-sky-100 text-sky-700',
                    'bank transfer' => 'bg-violet-100 text-violet-700',
                    default => 'bg-amber-100 text-amber-700',
                };
            ?>
                <tr class="border-t">
                    <td class="p-3"><?= e($row['order_day']) ?></td>
                    <td class="p-3 font-mono text-xs text-sky-700 font-semibold"><?= e($row['order_no']) ?></td>
                    <td class="p-3"><?= e($row['customer_name']) ?></td>
                    <td class="p-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $paymentBadge ?>"><?= e($row['payment_type']) ?></span></td>
                    <td class="p-3"><?= e($row['customer_type']) ?></td>
                    <td class="p-3 text-right"><?= money_dual($pdo, $row['subtotal'], true) ?></td>
                    <td class="p-3 text-right <?= (float)$row['discount_amount'] > 0 ? 'text-rose-600 font-semibold' : 'text-neutral-400' ?>">
                        <?= (float)$row['discount_amount'] > 0 ? e(money($row['discount_amount'])) : '—' ?>
                    </td>
                    <td class="p-3 text-right font-semibold text-emerald-700"><?= money_dual($pdo, $row['grand_total'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-neutral-100 font-semibold text-sm">
                <tr>
                    <td class="p-3" colspan="5"><?= $meta['show_all'] ? 'Total' : 'This page' ?></td>
                    <td class="p-3 text-right"><?= e(money($meta['show_all'] ? $summary['subtotal_sales'] : $pageSubtotal)) ?></td>
                    <td class="p-3 text-right text-rose-600"><?= e(money($meta['show_all'] ? $summary['discounts'] : $pageDiscount)) ?></td>
                    <td class="p-3 text-right text-emerald-700"><?= e(money($meta['show_all'] ? $summary['total_sales'] : $pageNetSales)) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
        <div class="mt-4 flex flex-wrap gap-2 no-print">
            <?php foreach (pagination_links($meta) as $link): ?>
            <a href="<?= e($link['href']) ?>" class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>"><?= e($link['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($printMode): ?>
<script>
window.addEventListener('load', () => window.print());
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
