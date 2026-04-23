<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Consignment Reports';
$printMode = (($_GET['print'] ?? '') === '1');
if ($printMode && !isset($_GET['per_page'])) {
    $_GET['per_page'] = 'all';
}
update_overdue_payouts($pdo);

[$startDate, $endDate] = report_range();
[$rangeStart, $rangeEndExclusive] = report_datetime_bounds($startDate, $endDate);
$consignorFilter = (int)($_GET['consignor_id'] ?? 0);

$whereBase   = 'cs.sold_at >= ? AND cs.sold_at < ?';
$paramsBase  = [$rangeStart, $rangeEndExclusive];
$whereFull   = $whereBase;
$paramsFull  = $paramsBase;
if ($consignorFilter > 0) {
    $whereFull  .= ' AND cs.consignor_id = ?';
    $paramsFull[] = $consignorFilter;
}

/* ── KPI summary ───────────────────────────────────────────────────── */
$summary = db_one($pdo,
    'SELECT
        COUNT(DISTINCT cs.invoice_no)            AS inv_count,
        COUNT(*)                                  AS line_count,
        COALESCE(SUM(cs.quantity),0)              AS qty_sold,
        COALESCE(SUM(cs.gross_amount),0)          AS gross_sales,
        COALESCE(SUM(cs.commission_amount),0)     AS commission,
        COALESCE(SUM(cs.payout_due),0)            AS payout_due
     FROM consignment_sales cs
     WHERE ' . $whereFull,
    $paramsFull
);

/* ── Sales lines with pagination ──────────────────────────────────── */
$total = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_sales cs WHERE ' . $whereFull, $paramsFull);
$meta  = paginate_meta($total, 10, 200);
$salesQuery = 'SELECT DATE(cs.sold_at) sold_day, cs.invoice_no, c.store_name, c.branch_location,
            cs.item_name, cs.quantity, cs.unit_price, cs.gross_amount,
            cs.commission_rate, cs.commission_amount, cs.payout_due,
            cs.opening_stock, cs.closing_stock
     FROM consignment_sales cs
     JOIN consignors c ON c.id = cs.consignor_id
     WHERE ' . $whereFull . '
     ORDER BY cs.sold_at DESC';
$salesRows = db_all($pdo,
    $salesQuery . ' LIMIT ' . (int)$meta['per_page'] . ' OFFSET ' . (int)$meta['offset'],
    $paramsFull
);
$exportSalesRows = isset($_GET['export']) ? db_all($pdo, $salesQuery, $paramsFull) : $salesRows;

/* ── Stock snapshot (all items, regardless of date) ───────────────── */
$stockRows = db_all($pdo,
    'SELECT
        mi.item_name,
        mi.item_code,
        mi.reference_code,
        mi.total_stock,
        COALESCE(asgn.total_assigned, 0)                                AS total_assigned,
        GREATEST(mi.total_stock - COALESCE(asgn.total_assigned,0), 0)  AS available_to_issue,
        COALESCE(inv.on_hand, 0)                                        AS on_hand,
        COALESCE(sold.total_sold, 0)                                    AS total_sold,
        mi.sale_price
     FROM consignment_main_inventory mi
     LEFT JOIN (
         SELECT main_inventory_id, SUM(assigned_stock) AS total_assigned
         FROM consignment_assignments GROUP BY main_inventory_id
     ) asgn ON asgn.main_inventory_id = mi.id
     LEFT JOIN (
         SELECT main_inventory_id, SUM(stock_balance) AS on_hand
         FROM consignment_inventory GROUP BY main_inventory_id
     ) inv ON inv.main_inventory_id = mi.id
     LEFT JOIN (
         SELECT ci.main_inventory_id, SUM(cs.quantity) AS total_sold
         FROM consignment_sales cs
         JOIN consignment_inventory ci ON ci.id = cs.inventory_id
         GROUP BY ci.main_inventory_id
     ) sold ON sold.main_inventory_id = mi.id
     ORDER BY mi.item_name ASC'
);

/* ── Per-consignor summary ─────────────────────────────────────────── */
$byConsignor = db_all($pdo,
    'SELECT c.store_name, c.branch_location,
            COUNT(DISTINCT cs.invoice_no)            AS inv_count,
            COALESCE(SUM(cs.quantity),0)              AS qty_sold,
            COALESCE(SUM(cs.gross_amount),0)          AS gross_sales,
            COALESCE(SUM(cs.commission_amount),0)     AS commission,
            COALESCE(SUM(cs.payout_due),0)            AS payout_due
     FROM consignment_sales cs
     JOIN consignors c ON c.id = cs.consignor_id
     WHERE ' . $whereFull . '
     GROUP BY cs.consignor_id
     ORDER BY gross_sales DESC',
    $paramsFull
);

/* ── Payout / claim rows ───────────────────────────────────────────── */
$claimWhere = 'p.claim_month BETWEEN ? AND ?';
$claimParams = [$startDate, $endDate];
if ($consignorFilter > 0) {
    $claimWhere .= ' AND p.consignor_id = ?';
    $claimParams[] = $consignorFilter;
}
$claimRows = db_all($pdo,
    'SELECT p.claim_month, p.invoice_no, c.store_name,
            p.payout_due, p.status,
            u.full_name AS claimed_by_name, p.claimed_at
     FROM consignment_payouts p
     JOIN consignors c ON c.id = p.consignor_id
     LEFT JOIN users u ON u.id = p.claimed_by_user_id
     WHERE ' . $claimWhere . '
     ORDER BY p.claim_month DESC, c.store_name ASC',
    $claimParams
);

/* ── Consignor list for filter dropdown ───────────────────────────── */
$consignorList = db_all($pdo, 'SELECT id, store_name FROM consignors ORDER BY store_name ASC');

/* ── Export ────────────────────────────────────────────────────────── */
if (isset($_GET['export'])) {
    $mode = $_GET['export'];

    if ($mode === 'csv') {
        /* ── CSV export ── */
        csv_headers('consignment-report.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

        fputcsv($out, ['CONSIGNMENT REPORT', 'Period: ' . $startDate . ' to ' . $endDate]);
        fputcsv($out, []);
        fputcsv($out, ['SUMMARY']);
        fputcsv($out, ['Invoices',    $summary['inv_count']]);
        fputcsv($out, ['Qty Sold',    $summary['qty_sold']]);
        fputcsv($out, ['Gross Sales', number_format((float)$summary['gross_sales'], 2, '.', '')]);
        fputcsv($out, ['Commission',  number_format((float)$summary['commission'],  2, '.', '')]);
        fputcsv($out, ['Payout Due',  number_format((float)$summary['payout_due'], 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['STOCK SNAPSHOT']);
        fputcsv($out, ['Item','Code','Ref','Total Stock','Issued','On Hand','Total Sold','Available','Price']);
        foreach ($stockRows as $r) {
            fputcsv($out, [
                $r['item_name'], $r['item_code'], $r['reference_code'],
                (int)$r['total_stock'], (int)$r['total_assigned'], (int)$r['on_hand'],
                (int)$r['total_sold'], (int)$r['available_to_issue'],
                number_format((float)$r['sale_price'], 2, '.', ''),
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['PER CONSIGNOR']);
        fputcsv($out, ['Consignor','Branch','Invoices','Qty','Gross','Commission','Payout']);
        foreach ($byConsignor as $r) {
            fputcsv($out, [
                $r['store_name'], $r['branch_location'],
                (int)$r['inv_count'], (int)$r['qty_sold'],
                number_format((float)$r['gross_sales'], 2, '.', ''),
                number_format((float)$r['commission'],  2, '.', ''),
                number_format((float)$r['payout_due'],  2, '.', ''),
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['SALES LINES']);
        fputcsv($out, ['Date','Invoice','Consignor','Branch','Item','Qty','Unit Price','Gross','Comm%','Commission','Payout','Opening','Closing']);
        foreach ($exportSalesRows as $r) {
            fputcsv($out, [
                $r['sold_day'], $r['invoice_no'], $r['store_name'], $r['branch_location'],
                $r['item_name'], (int)$r['quantity'],
                number_format((float)$r['unit_price'],        2, '.', ''),
                number_format((float)$r['gross_amount'],      2, '.', ''),
                $r['commission_rate'] . '%',
                number_format((float)$r['commission_amount'], 2, '.', ''),
                number_format((float)$r['payout_due'],        2, '.', ''),
                (int)$r['opening_stock'], (int)$r['closing_stock'],
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['PAYOUTS / CLAIMS']);
        fputcsv($out, ['Claim Month','Invoice','Consignor','Payout Due','Status','Claimed By','Claimed At']);
        foreach ($claimRows as $r) {
            fputcsv($out, [
                $r['claim_month'], $r['invoice_no'], $r['store_name'],
                number_format((float)$r['payout_due'], 2, '.', ''),
                $r['status'], $r['claimed_by_name'] ?? '', $r['claimed_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    /* ── Excel (HTML table) export ── */
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="consignment-report-' . $startDate . '.xls"');
    header('Cache-Control: max-age=0');

    function xls_h(string $text, int $colspan = 1): string {
        $cs = $colspan > 1 ? " colspan=\"{$colspan}\"" : '';
        return "<th{$cs} style=\"background:#1a1a2e;color:#fff;font-weight:bold;padding:6px 10px;border:1px solid #ccc;text-align:left;white-space:nowrap\">" . htmlspecialchars($text) . "</th>";
    }
    function xls_th(string $text, string $align = 'left'): string {
        return "<th style=\"background:#f0f0f0;font-weight:bold;padding:5px 8px;border:1px solid #ccc;text-align:{$align};white-space:nowrap\">" . htmlspecialchars($text) . "</th>";
    }
    function xls_td(string $text, string $align = 'left', string $extra = ''): string {
        return "<td style=\"padding:5px 8px;border:1px solid #ddd;text-align:{$align};{$extra}\">" . htmlspecialchars($text) . "</td>";
    }
    function xls_section(string $title, int $cols): string {
        return "<tr><td colspan=\"{$cols}\" style=\"background:#2d6a4f;color:#fff;font-weight:bold;padding:8px 10px;font-size:13px;border:1px solid #999\">{$title}</td></tr>";
    }
    function xls_blank(int $cols): string {
        return "<tr><td colspan=\"{$cols}\" style=\"padding:4px;border:none\">&nbsp;</td></tr>";
    }
    ?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head><body>
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">

    <!-- Title -->
    <tr><?= xls_h('CONSIGNMENT REPORT', 9) ?></tr>
    <tr><?= xls_h('Period: ' . $startDate . ' to ' . $endDate, 9) ?></tr>
    <?= xls_blank(9) ?>

    <!-- Summary -->
    <?= xls_section('SUMMARY', 9) ?>
    <tr><?= xls_th('Invoices') ?><?= xls_td((string)(int)$summary['inv_count']) ?></tr>
    <tr><?= xls_th('Qty Sold') ?><?= xls_td((string)(int)$summary['qty_sold']) ?></tr>
    <tr><?= xls_th('Gross Sales') ?><?= xls_td(number_format((float)$summary['gross_sales'], 2)) ?></tr>
    <tr><?= xls_th('Commission') ?><?= xls_td(number_format((float)$summary['commission'], 2)) ?></tr>
    <tr><?= xls_th('Payout Due') ?><?= xls_td(number_format((float)$summary['payout_due'], 2), 'left', 'color:#b45309;font-weight:bold') ?></tr>
    <?= xls_blank(9) ?>

    <!-- Stock Snapshot -->
    <?= xls_section('STOCK SNAPSHOT', 9) ?>
    <tr>
        <?= xls_th('Item') ?><?= xls_th('Code') ?><?= xls_th('Ref') ?>
        <?= xls_th('Total Stock','right') ?><?= xls_th('Issued (DO)','right') ?>
        <?= xls_th('On Hand','right') ?><?= xls_th('Total Sold','right') ?>
        <?= xls_th('Available','right') ?><?= xls_th('Price','right') ?>
    </tr>
    <?php foreach ($stockRows as $r):
        $sOut = (int)$r['available_to_issue'] <= 0 && (int)$r['on_hand'] <= 0;
        $rowBg = $sOut ? 'background:#fff0f0' : '';
    ?>
    <tr>
        <?= xls_td($r['item_name'], 'left', $rowBg . ';font-weight:bold') ?>
        <?= xls_td($r['item_code'], 'left', $rowBg) ?>
        <?= xls_td($r['reference_code'], 'left', $rowBg) ?>
        <?= xls_td((string)(int)$r['total_stock'],       'right', $rowBg) ?>
        <?= xls_td((string)(int)$r['total_assigned'],    'right', $rowBg) ?>
        <?= xls_td((string)(int)$r['on_hand'],           'right', $rowBg . ';color:#15803d;font-weight:bold') ?>
        <?= xls_td((string)(int)$r['total_sold'],        'right', $rowBg . ';color:#0369a1') ?>
        <?= xls_td((string)(int)$r['available_to_issue'],'right', $rowBg . ';color:#b45309;font-weight:bold') ?>
        <?= xls_td(number_format((float)$r['sale_price'], 2), 'right', $rowBg) ?>
    </tr>
    <?php endforeach; ?>
    <?= xls_blank(9) ?>

    <!-- Per Consignor -->
    <?= xls_section('PER CONSIGNOR', 9) ?>
    <tr>
        <?= xls_th('Consignor') ?><?= xls_th('Branch') ?><?= xls_th('Invoices','right') ?>
        <?= xls_th('Qty Sold','right') ?><?= xls_th('Gross Sales','right') ?>
        <?= xls_th('Commission','right') ?><?= xls_th('Payout Due','right') ?>
    </tr>
    <?php foreach ($byConsignor as $r): ?>
    <tr>
        <?= xls_td($r['store_name'], 'left', 'font-weight:bold') ?>
        <?= xls_td($r['branch_location'] ?? '') ?>
        <?= xls_td((string)(int)$r['inv_count'], 'right') ?>
        <?= xls_td((string)(int)$r['qty_sold'],  'right') ?>
        <?= xls_td(number_format((float)$r['gross_sales'], 2), 'right') ?>
        <?= xls_td(number_format((float)$r['commission'],  2), 'right') ?>
        <?= xls_td(number_format((float)$r['payout_due'],  2), 'right', 'color:#b45309;font-weight:bold') ?>
    </tr>
    <?php endforeach; ?>
    <?= xls_blank(9) ?>

    <!-- Sales Lines -->
    <?= xls_section('SALES LINES', 13) ?>
    <tr>
        <?= xls_th('Date') ?><?= xls_th('Invoice') ?><?= xls_th('Consignor') ?><?= xls_th('Branch') ?>
        <?= xls_th('Item') ?><?= xls_th('Qty','right') ?><?= xls_th('Unit Price','right') ?>
        <?= xls_th('Gross','right') ?><?= xls_th('Comm%','right') ?>
        <?= xls_th('Commission','right') ?><?= xls_th('Payout','right') ?>
        <?= xls_th('Opening','right') ?><?= xls_th('Closing','right') ?>
    </tr>
    <?php foreach ($exportSalesRows as $r): ?>
    <tr>
        <?= xls_td($r['sold_day']) ?>
        <?= xls_td($r['invoice_no'], 'left', 'color:#047857') ?>
        <?= xls_td($r['store_name']) ?>
        <?= xls_td($r['branch_location'] ?? '') ?>
        <?= xls_td($r['item_name']) ?>
        <?= xls_td((string)(int)$r['quantity'],                         'right') ?>
        <?= xls_td(number_format((float)$r['unit_price'],        2),    'right') ?>
        <?= xls_td(number_format((float)$r['gross_amount'],      2),    'right') ?>
        <?= xls_td($r['commission_rate'] . '%',                         'right') ?>
        <?= xls_td(number_format((float)$r['commission_amount'], 2),    'right') ?>
        <?= xls_td(number_format((float)$r['payout_due'],        2),    'right', 'color:#b45309;font-weight:bold') ?>
        <?= xls_td((string)(int)$r['opening_stock'],                    'right') ?>
        <?= xls_td((string)(int)$r['closing_stock'],                    'right') ?>
    </tr>
    <?php endforeach; ?>
    <?= xls_blank(13) ?>

    <!-- Payouts / Claims -->
    <?= xls_section('PAYOUTS / CLAIMS', 7) ?>
    <tr>
        <?= xls_th('Claim Month') ?><?= xls_th('Invoice') ?><?= xls_th('Consignor') ?>
        <?= xls_th('Payout Due','right') ?><?= xls_th('Status') ?>
        <?= xls_th('Claimed By') ?><?= xls_th('Claimed At') ?>
    </tr>
    <?php foreach ($claimRows as $r):
        $stBg = match($r['status']) {
            'claimed' => 'color:#15803d',
            'overdue' => 'color:#dc2626',
            default   => 'color:#b45309',
        };
    ?>
    <tr>
        <?= xls_td($r['claim_month']) ?>
        <?= xls_td($r['invoice_no'], 'left', 'color:#047857') ?>
        <?= xls_td($r['store_name'], 'left', 'font-weight:bold') ?>
        <?= xls_td(number_format((float)$r['payout_due'], 2), 'right', 'font-weight:bold;color:#b45309') ?>
        <?= xls_td(ucfirst($r['status']), 'left', $stBg) ?>
        <?= xls_td($r['claimed_by_name'] ?? '—') ?>
        <?= xls_td($r['claimed_at'] ?? '—') ?>
    </tr>
    <?php endforeach; ?>

</table>
</body></html>
<?php
    exit;
}

require __DIR__ . '/includes/header.php';
?>

<!-- toolbar -->
<div class="mb-4 flex flex-wrap gap-3 no-print items-center">
    <form class="flex flex-wrap items-center gap-3">
        <input type="date" name="start_date" value="<?= e($startDate) ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
        <input type="date" name="end_date"   value="<?= e($endDate) ?>"   class="pvn-btn pvn-btn-secondary pvn-btn-sm">
        <select name="consignor_id" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
            <option value="0">All Consignors</option>
            <?php foreach ($consignorList as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $consignorFilter===(int)$c['id']?'selected':'' ?>>
                <?= e($c['store_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
            <?php foreach (page_size_options(['all',8,10,12]) as $opt): ?>
            <option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>>
                <?= e(is_string($opt) ? strtoupper($opt) : (string)$opt) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="rounded-2xl px-4 py-2 border">View</button>
    </form>
    <a href="?<?= e(http_build_query(['start_date'=>$startDate,'end_date'=>$endDate,'consignor_id'=>$consignorFilter,'export'=>'csv'])) ?>"
       class="rounded-2xl px-4 py-2 border">Export CSV</a>
    <a href="?<?= e(http_build_query(['start_date'=>$startDate,'end_date'=>$endDate,'consignor_id'=>$consignorFilter,'export'=>'excel'])) ?>"
       class="rounded-2xl px-4 py-2 border">Export Excel</a>
    <a href="?<?= e(http_build_query(['start_date'=>$startDate,'end_date'=>$endDate,'consignor_id'=>$consignorFilter,'per_page'=>'all','print'=>'1'])) ?>"
       target="_blank"
       class="rounded-2xl px-4 py-2 border">Export PDF</a>
</div>

<div class="print-wrap pvn-card p-8 space-y-10">

    <!-- Header -->
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-2xl font-semibold">Consignment Report</h3>
            <div class="text-neutral-500 mt-1">Period: <?= e($startDate) ?> to <?= e($endDate) ?><?= $consignorFilter > 0 ? ' · Filtered by consignor' : '' ?></div>
            <div class="text-sm text-neutral-400 mt-2">Default filter range is the latest 1 month.</div>
        </div>
        <img src="<?= e(invoice_logo($pdo)) ?>" class="h-16 object-contain">
    </div>

    <!-- KPI cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="rounded-2xl bg-neutral-50 p-4 border">
            <div class="text-xs text-neutral-500 mb-1">Invoices</div>
            <div class="font-semibold text-2xl"><?= (int)$summary['inv_count'] ?></div>
        </div>
        <div class="rounded-2xl bg-neutral-50 p-4 border">
            <div class="text-xs text-neutral-500 mb-1">Qty Sold</div>
            <div class="font-semibold text-2xl"><?= (int)$summary['qty_sold'] ?></div>
        </div>
        <div class="rounded-2xl bg-neutral-50 p-4 border">
            <div class="text-xs text-neutral-500 mb-1">Gross Sales</div>
            <div class="font-semibold text-2xl"><?= e(money($summary['gross_sales'])) ?></div>
        </div>
        <div class="rounded-2xl bg-neutral-50 p-4 border">
            <div class="text-xs text-neutral-500 mb-1">Commission</div>
            <div class="font-semibold text-2xl"><?= e(money($summary['commission'])) ?></div>
        </div>
        <div class="rounded-2xl bg-amber-50 border border-amber-200 p-4 col-span-2">
            <div class="text-xs text-amber-700 mb-1">Payout Due (period)</div>
            <div class="font-semibold text-2xl text-amber-800"><?= money_dual($pdo, $summary['payout_due'], true) ?></div>
        </div>
    </div>

    <!-- Stock snapshot -->
    <div>
        <h4 class="font-semibold text-lg mb-3">Stock Snapshot</h4>
        <p class="text-sm text-neutral-500 mb-3">Current inventory status across all items — not filtered by date.</p>
        <div class="overflow-x-auto">
        <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
            <thead class="bg-neutral-50">
                <tr>
                    <th class="text-left p-3">Item</th>
                    <th class="text-left p-3">Code</th>
                    <th class="text-right p-3">Total Stock</th>
                    <th class="text-right p-3">Issued (DOs)</th>
                    <th class="text-right p-3">On Hand</th>
                    <th class="text-right p-3">Total Sold</th>
                    <th class="text-right p-3">Available</th>
                    <th class="text-right p-3">Price</th>
                    <th class="text-right p-3">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sTotStock = $sTotAssigned = $sTotOnHand = $sTotSold = $sTotAvail = 0;
            foreach ($stockRows as $r):
                $soldOut     = (int)$r['available_to_issue'] <= 0 && (int)$r['on_hand'] <= 0;
                $fullyIssued = (int)$r['available_to_issue'] <= 0 && (int)$r['on_hand'] > 0;
                $sTotStock   += (int)$r['total_stock'];
                $sTotAssigned+= (int)$r['total_assigned'];
                $sTotOnHand  += (int)$r['on_hand'];
                $sTotSold    += (int)$r['total_sold'];
                $sTotAvail   += (int)$r['available_to_issue'];
            ?>
            <tr class="border-t <?= $soldOut ? 'bg-rose-50' : '' ?>">
                <td class="p-3 font-medium"><?= e($r['item_name']) ?><div class="text-xs text-neutral-400"><?= e($r['reference_code']) ?></div></td>
                <td class="p-3 text-neutral-500"><?= e($r['item_code']) ?></td>
                <td class="p-3 text-right"><?= (int)$r['total_stock'] ?></td>
                <td class="p-3 text-right text-neutral-500"><?= (int)$r['total_assigned'] ?></td>
                <td class="p-3 text-right font-semibold text-emerald-700"><?= (int)$r['on_hand'] ?></td>
                <td class="p-3 text-right text-sky-700"><?= (int)$r['total_sold'] ?></td>
                <td class="p-3 text-right font-bold <?= (int)$r['available_to_issue'] > 0 ? 'text-amber-700' : 'text-neutral-400' ?>"><?= (int)$r['available_to_issue'] ?></td>
                <td class="p-3 text-right"><?= e(money($r['sale_price'])) ?></td>
                <td class="p-3 text-right">
                    <?php if ($soldOut): ?>
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700">Sold Out</span>
                    <?php elseif ($fullyIssued): ?>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">Fully Issued</span>
                    <?php else: ?>
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Available</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-neutral-100 font-semibold text-sm">
                <tr>
                    <td class="p-3" colspan="2">Total</td>
                    <td class="p-3 text-right"><?= $sTotStock ?></td>
                    <td class="p-3 text-right"><?= $sTotAssigned ?></td>
                    <td class="p-3 text-right text-emerald-700"><?= $sTotOnHand ?></td>
                    <td class="p-3 text-right text-sky-700"><?= $sTotSold ?></td>
                    <td class="p-3 text-right text-amber-700"><?= $sTotAvail ?></td>
                    <td class="p-3" colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <!-- Per consignor summary -->
    <?php if ($byConsignor): ?>
    <div>
        <h4 class="font-semibold text-lg mb-3">By Consignor</h4>
        <div class="overflow-x-auto">
        <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
            <thead class="bg-neutral-50">
                <tr>
                    <th class="text-left p-3">Consignor</th>
                    <th class="text-left p-3">Branch</th>
                    <th class="text-right p-3">Invoices</th>
                    <th class="text-right p-3">Qty Sold</th>
                    <th class="text-right p-3">Gross Sales</th>
                    <th class="text-right p-3">Commission</th>
                    <th class="text-right p-3">Payout Due</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $cTotQty = $cTotGross = $cTotComm = $cTotPay = 0;
            foreach ($byConsignor as $r):
                $cTotQty   += (int)$r['qty_sold'];
                $cTotGross += (float)$r['gross_sales'];
                $cTotComm  += (float)$r['commission'];
                $cTotPay   += (float)$r['payout_due'];
            ?>
            <tr class="border-t">
                <td class="p-3 font-medium"><?= e($r['store_name']) ?></td>
                <td class="p-3 text-neutral-500"><?= e($r['branch_location']) ?></td>
                <td class="p-3 text-right"><?= (int)$r['inv_count'] ?></td>
                <td class="p-3 text-right"><?= (int)$r['qty_sold'] ?></td>
                <td class="p-3 text-right"><?= e(money($r['gross_sales'])) ?></td>
                <td class="p-3 text-right text-neutral-500"><?= e(money($r['commission'])) ?></td>
                <td class="p-3 text-right font-semibold text-amber-700"><?= money_dual($pdo, $r['payout_due'], true) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-neutral-100 font-semibold text-sm">
                <tr>
                    <td class="p-3" colspan="3">Total</td>
                    <td class="p-3 text-right"><?= $cTotQty ?></td>
                    <td class="p-3 text-right"><?= e(money($cTotGross)) ?></td>
                    <td class="p-3 text-right"><?= e(money($cTotComm)) ?></td>
                    <td class="p-3 text-right text-amber-700"><?= e(money($cTotPay)) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sales lines -->
    <div>
        <h4 class="font-semibold text-lg mb-3">Sales Lines</h4>
        <?php if (!$salesRows): ?>
        <div class="rounded-2xl border border-dashed p-6 text-center text-neutral-500">No sales in this period.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
            <thead class="bg-neutral-50">
                <tr>
                    <th class="text-left p-3">Date</th>
                    <th class="text-left p-3">Invoice</th>
                    <th class="text-left p-3">Consignor</th>
                    <th class="text-left p-3">Item</th>
                    <th class="text-right p-3">Qty</th>
                    <th class="text-right p-3">Unit Price</th>
                    <th class="text-right p-3">Gross</th>
                    <th class="text-right p-3">Comm%</th>
                    <th class="text-right p-3">Commission</th>
                    <th class="text-right p-3">Payout</th>
                    <th class="text-right p-3">Open</th>
                    <th class="text-right p-3">Close</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $lTotQty = $lTotGross = $lTotComm = $lTotPay = 0;
            foreach ($salesRows as $r):
                $lTotQty   += (int)$r['quantity'];
                $lTotGross += (float)$r['gross_amount'];
                $lTotComm  += (float)$r['commission_amount'];
                $lTotPay   += (float)$r['payout_due'];
            ?>
            <tr class="border-t">
                <td class="p-3 text-neutral-500"><?= e($r['sold_day']) ?></td>
                <td class="p-3">
                    <a href="invoice.php?type=consignment_sale&no=<?= urlencode($r['invoice_no']) ?>"
                       target="_blank"
                       class="text-emerald-700 underline underline-offset-2"><?= e($r['invoice_no']) ?></a>
                </td>
                <td class="p-3"><?= e($r['store_name']) ?><div class="text-xs text-neutral-400"><?= e($r['branch_location']) ?></div></td>
                <td class="p-3"><?= e($r['item_name']) ?></td>
                <td class="p-3 text-right"><?= (int)$r['quantity'] ?></td>
                <td class="p-3 text-right"><?= e(money($r['unit_price'])) ?></td>
                <td class="p-3 text-right"><?= e(money($r['gross_amount'])) ?></td>
                <td class="p-3 text-right text-neutral-500"><?= e((string)$r['commission_rate']) ?>%</td>
                <td class="p-3 text-right text-neutral-500"><?= money_dual($pdo, $r['commission_amount'], true) ?></td>
                <td class="p-3 text-right font-semibold text-amber-700"><?= money_dual($pdo, $r['payout_due'], true) ?></td>
                <td class="p-3 text-right text-neutral-400"><?= (int)$r['opening_stock'] ?></td>
                <td class="p-3 text-right text-neutral-400"><?= (int)$r['closing_stock'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-neutral-100 font-semibold text-sm">
                <tr>
                    <td class="p-3" colspan="4">Total</td>
                    <td class="p-3 text-right"><?= $lTotQty ?></td>
                    <td class="p-3"></td>
                    <td class="p-3 text-right"><?= e(money($lTotGross)) ?></td>
                    <td class="p-3"></td>
                    <td class="p-3 text-right"><?= e(money($lTotComm)) ?></td>
                    <td class="p-3 text-right text-amber-700"><?= e(money($lTotPay)) ?></td>
                    <td class="p-3" colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <!-- pagination -->
        <div class="mt-4 flex flex-wrap gap-2 no-print">
        <?php foreach (pagination_links($meta) as $link): ?>
            <a href="<?= e($link['href']) ?>"
               class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>">
                <?= e($link['label']) ?>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payouts / Claims -->
    <?php if ($claimRows): ?>
    <div>
        <h4 class="font-semibold text-lg mb-3">Payouts &amp; Claims</h4>
        <div class="overflow-x-auto">
        <table class="w-full text-sm border border-neutral-200 rounded-2xl overflow-hidden">
            <thead class="bg-neutral-50">
                <tr>
                    <th class="text-left p-3">Claim Month</th>
                    <th class="text-left p-3">Invoice</th>
                    <th class="text-left p-3">Consignor</th>
                    <th class="text-right p-3">Payout Due</th>
                    <th class="text-left p-3">Status</th>
                    <th class="text-left p-3">Claimed By</th>
                    <th class="text-left p-3">Claimed At</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($claimRows as $r):
                $statusClass = match($r['status']) {
                    'claimed' => 'bg-emerald-100 text-emerald-700',
                    'overdue' => 'bg-rose-100 text-rose-700',
                    default   => 'bg-amber-100 text-amber-700',
                };
            ?>
            <tr class="border-t">
                <td class="p-3"><?= e($r['claim_month']) ?></td>
                <td class="p-3">
                    <a href="invoice.php?type=consignment_sale&no=<?= urlencode($r['invoice_no']) ?>"
                       target="_blank"
                       class="text-emerald-700 underline underline-offset-2"><?= e($r['invoice_no']) ?></a>
                </td>
                <td class="p-3"><?= e($r['store_name']) ?></td>
                <td class="p-3 text-right font-semibold text-amber-700"><?= money_dual($pdo, $r['payout_due'], true) ?></td>
                <td class="p-3"><span class="rounded-full px-2 py-0.5 text-xs <?= $statusClass ?>"><?= e($r['status']) ?></span></td>
                <td class="p-3 text-neutral-500"><?= e($r['claimed_by_name'] ?? '—') ?></td>
                <td class="p-3 text-neutral-500"><?= e($r['claimed_at'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php if ($printMode): ?>
<script>
window.addEventListener('load', () => window.print());
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
