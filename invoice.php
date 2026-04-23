<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$type = $_GET['type'] ?? 'retail';
$id   = (int)($_GET['id'] ?? 0);
$no   = trim((string)($_GET['no'] ?? ''));

function invoice_paper_size(PDO $pdo): string {
    return setting($pdo, 'invoice_size', 'A4');
}

function invoice_shell_start(PDO $pdo, string $title, string $number, string $dateText): void {
    $size = invoice_paper_size($pdo);
    $customCss = setting($pdo, 'custom_css', '');
    // A5 = 148×210mm, A4 = 210×297mm
    $maxW = $size === 'A5' ? 'max-width:560px' : 'max-width:860px';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> <?= e($number) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
@media print {
    .no-print { display:none!important }
    body { background:#fff; }
    .inv-wrap { box-shadow:none!important; border:none!important; }
}
<?php if ($customCss): echo $customCss; endif; ?>
</style>
</head>
<body class="bg-slate-100 p-6">
<div class="inv-wrap mx-auto bg-white rounded-2xl p-10 shadow print:shadow-none" style="<?= $maxW ?>">
    <div class="no-print mb-6 flex gap-3">
        <button onclick="window.print()" class="rounded-xl border border-slate-200 bg-slate-50 px-5 py-2 text-sm font-medium hover:bg-slate-100">🖨 Print / Save PDF</button>
        <button onclick="if(window.history.length>1&&document.referrer){window.history.back();}else{window.close();}" class="rounded-xl border border-slate-200 bg-slate-50 px-5 py-2 text-sm font-medium hover:bg-slate-100">← Back</button>
        <span class="ml-auto text-xs text-slate-400 self-center">Paper: <?= e($size) ?></span>
    </div>
    <!-- Header: logo + invoice info -->
    <div class="flex justify-between items-start mb-10 gap-8">
        <img src="<?= e(invoice_logo($pdo)) ?>" class="h-16 max-w-[180px] object-contain">
        <div class="text-right">
            <div class="font-bold text-xl text-slate-800"><?= e($title) ?></div>
            <div class="text-slate-600 font-medium mt-0.5"># <?= e($number) ?></div>
            <div class="text-slate-400 text-sm mt-0.5"><?= e($dateText) ?></div>
        </div>
    </div>
<?php }

function invoice_shell_end(PDO $pdo): void { ?>
    <div class="mt-10 pt-6 border-t border-slate-100">
        <div class="text-slate-600 font-medium"><?= e(setting($pdo, 'invoice_footer', 'Thank you for your business!')) ?></div>
        <?php if (setting($pdo, 'invoice_note', '') !== ''): ?>
        <div class="mt-2 text-sm text-slate-400"><?= nl2br(e(setting($pdo, 'invoice_note', ''))) ?></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php }

/* ─── RETAIL INVOICE ─── */
if ($type === 'retail') {
    $order = db_one($pdo, 'SELECT * FROM retail_orders WHERE id = ?', [$id]);
    if (!$order) { http_response_code(404); exit('Invoice not found.'); }
    $items = db_all($pdo, 'SELECT * FROM retail_order_items WHERE order_id = ?', [$id]);
    invoice_shell_start($pdo, 'Invoice', $order['order_no'], date('d M Y', strtotime($order['order_date'])));
    ?>
    <!-- Billed To + Company -->
    <div class="grid grid-cols-2 gap-8 mb-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Billed To</div>
            <div class="font-semibold text-slate-800"><?= e($order['customer_name']) ?></div>
            <div class="text-slate-500 text-sm"><?= e($order['contact_number']) ?></div>
            <div class="text-slate-500 text-sm"><?= nl2br(e($order['address_text'])) ?></div>
        </div>
        <div class="text-right">
            <div class="font-bold text-lg text-slate-800"><?= e(setting($pdo, 'company_name', 'PALVIN')) ?></div>
            <div class="text-slate-500 text-sm"><?= nl2br(e(setting($pdo, 'business_address', ''))) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_phone', '')) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_email', '')) ?></div>
        </div>
    </div>
    <!-- Items table -->
    <table class="w-full mb-8 text-sm">
        <thead>
            <tr class="border-b-2 border-slate-200">
                <th class="text-left py-3 text-slate-500 font-semibold text-xs uppercase">Item</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Qty</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Unit Price</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr class="border-b border-slate-100">
                <td class="py-3 text-slate-800"><?= e($item['item_name']) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$item['quantity']) ?></td>
                <td class="text-right text-slate-600"><?= e(money($item['unit_price'])) ?></td>
                <td class="text-right font-medium text-slate-800"><?= e(money($item['total_price'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <!-- Footer: payment + totals -->
    <div class="flex justify-between items-end gap-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Payment Information</div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'bank_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_number', '')) ?></div>
            <?php if ($order['payment_type']): ?>
            <div class="mt-2 text-sm text-slate-500">Paid By: <?= e($order['payment_type']) ?></div>
            <?php endif; ?>
        </div>
        <div class="w-60 space-y-2 text-sm">
            <div class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
            <div class="flex justify-between text-slate-500"><span>Discount</span><span><?= e(money($order['discount_amount'])) ?></span></div>
            <div class="flex justify-between text-lg font-bold text-slate-800 border-t-2 border-slate-200 pt-3 mt-2">
                <span>Total Due</span><span><?= money_dual($pdo, $order['grand_total'], true) ?></span>
            </div>
        </div>
    </div>
    <?php
    invoice_shell_end($pdo);
    exit;
}

/* ─── DELIVERY NOTE ─── */
if ($type === 'delivery_note') {
    if ($no === '') { http_response_code(404); exit('Delivery note not found.'); }
    $rows = db_all($pdo,
        'SELECT ca.*, c.store_name, c.branch_location, mi.item_name, mi.item_code, mi.reference_code,
                COALESCE(NULLIF(ca.delivery_no,\'\'), CONCAT(\'DO-\',ca.id)) AS display_do
         FROM consignment_assignments ca
         JOIN consignors c  ON c.id  = ca.consignor_id
         JOIN consignment_main_inventory mi ON mi.id = ca.main_inventory_id
         WHERE ca.delivery_no = ?
            OR (ca.delivery_no IS NULL  AND ? = CONCAT(\'DO-\',ca.id))
            OR (ca.delivery_no = \'\'   AND ? = CONCAT(\'DO-\',ca.id))
         ORDER BY ca.id ASC',
        [$no, $no, $no]
    );
    if (!$rows) { http_response_code(404); exit('Delivery note not found.'); }
    $first = $rows[0];
    invoice_shell_start($pdo, 'Delivery Note', $no, date('d M Y', strtotime($first['updated_at'] ?: 'now')));
    ?>
    <div class="grid grid-cols-2 gap-8 mb-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Delivered To</div>
            <div class="font-semibold text-slate-800"><?= e($first['store_name']) ?></div>
            <div class="text-slate-500 text-sm"><?= e($first['branch_location']) ?></div>
        </div>
        <div class="text-right">
            <div class="font-bold text-lg text-slate-800"><?= e(setting($pdo, 'company_name', 'PALVIN')) ?></div>
            <div class="text-slate-500 text-sm"><?= nl2br(e(setting($pdo, 'business_address', ''))) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_phone', '')) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_email', '')) ?></div>
            <div class="text-slate-400 text-xs mt-1">Issued By: <?= e($first['issued_by']) ?></div>
        </div>
    </div>
    <table class="w-full mb-8 text-sm">
        <thead>
            <tr class="border-b-2 border-slate-200">
                <th class="text-left py-3 text-slate-500 font-semibold text-xs uppercase">Item</th>
                <th class="text-slate-500 font-semibold text-xs uppercase">Ref</th>
                <th class="text-slate-500 font-semibold text-xs uppercase">Code</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Qty</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Sale Price</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Commission</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr class="border-b border-slate-100">
                <td class="py-3 text-slate-800"><?= e($row['item_name']) ?></td>
                <td class="text-slate-600"><?= e($row['reference_code']) ?></td>
                <td class="text-slate-600"><?= e($row['item_code']) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$row['assigned_stock']) ?></td>
                <td class="text-right text-slate-600"><?= e(money($row['sale_price'])) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$row['commission_rate']) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="flex justify-between items-end gap-8">
        <div class="text-sm text-slate-500"><?= nl2br(e(implode("\n", array_filter(array_map(fn($r) => $r['notes'], $rows))))) ?></div>
        <div class="w-52 space-y-2 text-sm">
            <div class="flex justify-between text-slate-500"><span>Total Lines</span><span><?= e((string)count($rows)) ?></span></div>
            <div class="flex justify-between font-bold text-slate-800 border-t-2 border-slate-200 pt-2 mt-2">
                <span>Total Qty</span><span><?= e((string)array_sum(array_map(fn($r)=>(int)$r['assigned_stock'], $rows))) ?></span>
            </div>
        </div>
    </div>
    <?php
    invoice_shell_end($pdo);
    exit;
}

/* ─── CONSIGNMENT SALE INVOICE ─── */
if ($type === 'consignment_sale') {
    if ($no === '') { http_response_code(404); exit('Consignment invoice not found.'); }
    $rows = db_all($pdo, 'SELECT cs.*, c.store_name, c.branch_location FROM consignment_sales cs JOIN consignors c ON c.id=cs.consignor_id WHERE cs.invoice_no=? ORDER BY cs.id ASC', [$no]);
    if (!$rows) { http_response_code(404); exit('Consignment invoice not found.'); }
    $first = $rows[0];
    invoice_shell_start($pdo, 'Invoice', $no, date('d M Y', strtotime($first['sold_at'])));
    ?>
    <div class="grid grid-cols-2 gap-8 mb-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Consignor</div>
            <div class="font-semibold text-slate-800"><?= e($first['store_name']) ?></div>
            <div class="text-slate-500 text-sm"><?= e($first['branch_location']) ?></div>
        </div>
        <div class="text-right">
            <div class="font-bold text-lg text-slate-800"><?= e(setting($pdo, 'company_name', 'PALVIN')) ?></div>
            <div class="text-slate-500 text-sm"><?= nl2br(e(setting($pdo, 'business_address', ''))) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_phone', '')) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_email', '')) ?></div>
        </div>
    </div>
    <table class="w-full mb-8 text-sm">
        <thead>
            <tr class="border-b-2 border-slate-200">
                <th class="text-left py-3 text-slate-500 font-semibold text-xs uppercase">Item</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Qty Sold</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Unit Price</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Opening</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Closing</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Gross</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr class="border-b border-slate-100">
                <td class="py-3 text-slate-800"><?= e($row['item_name']) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$row['quantity']) ?></td>
                <td class="text-right text-slate-600"><?= e(money($row['unit_price'])) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$row['opening_stock']) ?></td>
                <td class="text-right text-slate-600"><?= e((string)$row['closing_stock']) ?></td>
                <td class="text-right font-medium text-slate-800"><?= e(money($row['gross_amount'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php $gross=array_sum(array_map(fn($r)=>(float)$r['gross_amount'], $rows)); $comm=array_sum(array_map(fn($r)=>(float)$r['commission_amount'], $rows)); $payout=array_sum(array_map(fn($r)=>(float)$r['payout_due'], $rows)); ?>
    <div class="flex justify-between items-end gap-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Payment Information</div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'bank_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_number', '')) ?></div>
        </div>
        <div class="w-60 space-y-2 text-sm">
            <div class="flex justify-between text-slate-500"><span>Gross Sales</span><span><?= e(money($gross)) ?></span></div>
            <div class="flex justify-between text-slate-500"><span>Commission</span><span><?= e(money($comm)) ?></span></div>
            <div class="flex justify-between text-lg font-bold text-slate-800 border-t-2 border-slate-200 pt-3 mt-2">
                <span>Payout Due</span><span><?= e(money($payout)) ?></span>
            </div>
        </div>
    </div>
    <?php
    invoice_shell_end($pdo);
    exit;
}

/* ─── ISSUE INV ─── */
if ($type === 'issue_inv') {
    if ($no === '') { http_response_code(404); exit('Invoice number not found.'); }
    $rows = db_all($pdo,
        'SELECT ca.id, ca.delivery_no, ca.assigned_stock, ca.sale_price, ca.commission_rate,
                ca.notes, ca.issued_by, ca.updated_at,
                c.store_name, c.branch_location,
                mi.item_name, mi.item_code, mi.reference_code,
                ci.stock_balance,
                COALESCE((SELECT SUM(cs.quantity) FROM consignment_sales cs WHERE cs.inventory_id = ci.id),0) AS sold_qty
         FROM consignment_assignments ca
         JOIN consignors c   ON c.id  = ca.consignor_id
         JOIN consignment_main_inventory mi ON mi.id = ca.main_inventory_id
         LEFT JOIN consignment_inventory ci ON ci.assignment_id = ca.id
         WHERE ca.delivery_no = ?
            OR (ca.delivery_no IS NULL AND ? = CONCAT(\'DO-\',ca.id))
            OR (ca.delivery_no = \'\'  AND ? = CONCAT(\'DO-\',ca.id))
         ORDER BY ca.id ASC',
        [$no, $no, $no]
    );
    if (!$rows) { http_response_code(404); exit('No DO lines found for this number.'); }
    $first = $rows[0];
    $invNo = preg_replace('/^DO-?/i', 'INV-', $no);
    if ($invNo === $no) $invNo = 'INV-' . $no;
    $doDate = date('d M Y', strtotime($first['updated_at'] ?: 'now'));
    invoice_shell_start($pdo, 'Invoice', $invNo, $doDate);
    ?>
    <div class="grid grid-cols-2 gap-8 mb-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Billed To</div>
            <div class="font-semibold text-slate-800"><?= e($first['store_name']) ?></div>
            <div class="text-slate-500 text-sm"><?= e($first['branch_location']) ?></div>
        </div>
        <div class="text-right">
            <div class="font-bold text-lg text-slate-800"><?= e(setting($pdo, 'company_name', 'PALVIN')) ?></div>
            <div class="text-slate-500 text-sm"><?= nl2br(e(setting($pdo, 'business_address', ''))) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_phone', '')) ?></div>
            <div class="text-slate-500 text-sm"><?= e(setting($pdo, 'company_email', '')) ?></div>
            <div class="text-slate-400 text-xs mt-1">Ref DO: <?= e($no) ?></div>
            <div class="text-slate-400 text-xs">Issued By: <?= e($first['issued_by']) ?></div>
        </div>
    </div>
    <table class="w-full mb-8 text-sm">
        <thead>
            <tr class="border-b-2 border-slate-200">
                <th class="text-left py-3 text-slate-500 font-semibold text-xs uppercase">Item</th>
                <th class="text-slate-500 font-semibold text-xs uppercase">Ref</th>
                <th class="text-slate-500 font-semibold text-xs uppercase">Code</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Qty</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Unit Price</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Gross</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Comm%</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Commission</th>
                <th class="text-right text-slate-500 font-semibold text-xs uppercase">Payout</th>
            </tr>
        </thead>
        <tbody>
        <?php
            $totalGross = 0; $totalComm = 0; $totalPayout = 0;
            foreach ($rows as $row):
                $gross   = (float)$row['assigned_stock'] * (float)$row['sale_price'];
                $comm    = round($gross * (float)$row['commission_rate'] / 100, 2);
                $payout  = round($gross - $comm, 2);
                $totalGross  += $gross;
                $totalComm   += $comm;
                $totalPayout += $payout;
        ?>
        <tr class="border-b border-slate-100">
            <td class="py-3 text-slate-800"><?= e($row['item_name']) ?></td>
            <td class="text-slate-500"><?= e($row['reference_code']) ?></td>
            <td class="text-slate-500"><?= e($row['item_code']) ?></td>
            <td class="text-right text-slate-600"><?= e((string)(int)$row['assigned_stock']) ?></td>
            <td class="text-right text-slate-600"><?= e(money($row['sale_price'])) ?></td>
            <td class="text-right text-slate-600"><?= e(money($gross)) ?></td>
            <td class="text-right text-slate-500"><?= e((string)$row['commission_rate']) ?>%</td>
            <td class="text-right text-slate-600"><?= e(money($comm)) ?></td>
            <td class="text-right font-medium text-slate-800"><?= e(money($payout)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="flex justify-between items-end gap-8">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Payment Information</div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'bank_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_name', '')) ?></div>
            <div class="text-sm text-slate-600"><?= e(setting($pdo, 'account_number', '')) ?></div>
            <?php $allNotes = array_filter(array_map(fn($r) => $r['notes'], $rows)); if ($allNotes): ?>
            <div class="mt-2 text-sm text-slate-400">Notes: <?= nl2br(e(implode('; ', $allNotes))) ?></div>
            <?php endif; ?>
        </div>
        <div class="w-64 space-y-2 text-sm">
            <div class="flex justify-between text-slate-500"><span>Total Lines</span><span><?= e((string)count($rows)) ?></span></div>
            <div class="flex justify-between text-slate-500"><span>Gross Sales</span><span><?= e(money($totalGross)) ?></span></div>
            <div class="flex justify-between text-slate-500"><span>Commission</span><span><?= e(money($totalComm)) ?></span></div>
            <div class="flex justify-between text-lg font-bold text-slate-800 border-t-2 border-slate-200 pt-3 mt-2">
                <span>Payout Due</span><span><?= e(money($totalPayout)) ?></span>
            </div>
        </div>
    </div>
    <?php
    invoice_shell_end($pdo);
    exit;
}

http_response_code(404);
echo 'Unsupported invoice type.';
