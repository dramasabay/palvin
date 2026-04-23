<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Issue INV';
update_overdue_payouts($pdo);

$view         = (($_GET['view'] ?? 'grid') === 'list') ? 'list' : 'grid';
$perPageInput = $_GET['per_page'] ?? 8;
$doRefFilter  = trim((string)($_GET['do_ref'] ?? ''));
$baseQuery    = ['view' => $view, 'per_page' => $perPageInput, 'do_ref' => $doRefFilter];

/* ──────────────────────────────────────────────
   POST: process a sale from the Sell modal
   ────────────────────────────────────────────── */
if (is_post() && post_action() === 'sell') {
    $consignorId    = (int)($_POST['consignor_id'] ?? 0);
    $assignmentIds  = $_POST['assignment_id'] ?? [];
    $qtys           = $_POST['qty'] ?? [];

    $selected = [];
    foreach ($assignmentIds as $i => $aid) {
        $aid = (int)$aid;
        $qty = max(0, (int)($qtys[$i] ?? 0));
        if ($aid > 0 && $qty > 0) {
            $selected[] = [$aid, $qty];
        }
    }

    if ($consignorId <= 0 || !$selected) {
        flash('error', 'Please choose a consignor and enter at least one sold item quantity.');
        redirect_to('consignment_inventory.php?' . http_build_query($baseQuery));
    }

    $pdo->beginTransaction();
    try {
        $invoiceNo        = generate_document_number($pdo, 'consignment_sales', 'invoice_no', 'CINV');
        $payoutTotal      = 0.0;
        $grossTotal       = 0.0;
        $commissionTotal  = 0.0;
        $qtyTotal         = 0;
        $soldLines        = [];
        $deliveryRefs     = [];
        $consignorName    = '';
        $consignorBranch  = '';

        foreach ($selected as [$aid, $qty]) {
            $row = db_one($pdo, "
                SELECT
                    ca.id                                                      AS assignment_id,
                    ca.consignor_id,
                    ca.main_inventory_id,
                    COALESCE(NULLIF(ca.delivery_no,''), CONCAT('DO-',ca.id))  AS delivery_no,
                    ca.assigned_stock,
                    ca.sale_price,
                    ca.commission_rate,
                    c.store_name                                               AS consignor_name,
                    COALESCE(c.branch_location,'')                            AS branch_location,
                    mi.item_name,
                    COALESCE(mi.reference_code,'')                            AS reference_code,
                    COALESCE(mi.item_code,'')                                 AS item_code,
                    inv.id                                                     AS inventory_id,
                    COALESCE(inv.stock_balance,
                        GREATEST(ca.assigned_stock - COALESCE(sold.total_sold,0), 0))
                                                                               AS current_balance,
                    COALESCE(sold.total_sold, 0)                              AS sold_qty
                FROM consignment_assignments ca
                INNER JOIN consignors c ON c.id = ca.consignor_id
                INNER JOIN consignment_main_inventory mi ON mi.id = ca.main_inventory_id
                LEFT JOIN consignment_inventory inv ON inv.assignment_id = ca.id
                LEFT JOIN (
                    SELECT assignment_id, SUM(quantity) AS total_sold
                    FROM consignment_sales GROUP BY assignment_id
                ) sold ON sold.assignment_id = ca.id
                WHERE ca.id = ? AND ca.consignor_id = ?
                LIMIT 1
                FOR UPDATE
            ", [$aid, $consignorId]);

            if (!$row) {
                throw new RuntimeException('A selected DO item was not found.');
            }

            $remaining = max(0, (int)$row['current_balance']);
            if ($qty > $remaining) {
                throw new RuntimeException($row['item_name'] . ' only has ' . $remaining . ' remaining.');
            }

            $inventoryId = (int)($row['inventory_id'] ?? 0);
            if ($inventoryId <= 0) {
                $pdo->prepare('INSERT INTO consignment_inventory
                    (assignment_id, consignor_id, main_inventory_id, item_name, reference_code,
                     item_code, stock_balance, sale_price, commission_rate, image_path, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,NULL,NOW())')
                ->execute([
                    $row['assignment_id'], $row['consignor_id'], $row['main_inventory_id'],
                    $row['item_name'], $row['reference_code'], $row['item_code'],
                    $remaining, $row['sale_price'], $row['commission_rate'],
                ]);
                $inventoryId = (int)$pdo->lastInsertId();
            }

            $opening          = $remaining;
            $closing          = $remaining - $qty;
            $gross            = $qty * (float)$row['sale_price'];
            $rate             = (float)$row['commission_rate'];
            $commissionAmount = round($gross * $rate / 100, 2);
            $payout           = round($gross - $commissionAmount, 2);

            $pdo->prepare('UPDATE consignment_inventory SET stock_balance=?, updated_at=NOW() WHERE id=?')
                ->execute([$closing, $inventoryId]);

            $pdo->prepare('INSERT INTO consignment_sales
                (inventory_id, assignment_id, consignor_id, invoice_no, item_name, quantity,
                 unit_price, gross_amount, commission_rate, commission_amount, payout_due,
                 opening_stock, closing_stock, sold_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([
                $inventoryId, $row['assignment_id'], $row['consignor_id'],
                $invoiceNo, $row['item_name'], $qty,
                $row['sale_price'], $gross, $rate, $commissionAmount, $payout,
                $opening, $closing,
            ]);

            $payoutTotal += $payout;
            $grossTotal += $gross;
            $commissionTotal += $commissionAmount;
            $qtyTotal += $qty;
            $deliveryRefs[$row['delivery_no']] = true;
            $consignorName = (string)$row['consignor_name'];
            $consignorBranch = (string)$row['branch_location'];
            $soldLines[] = [
                'item_name' => (string)$row['item_name'],
                'quantity' => $qty,
                'unit_price' => (float)$row['sale_price'],
                'gross_amount' => $gross,
                'commission_rate' => $rate,
                'payout_due' => $payout,
                'delivery_no' => (string)$row['delivery_no'],
            ];
        }

        $claimMonth = date('Y-m-01');
        $existing   = db_one($pdo,
            'SELECT id FROM consignment_payouts WHERE consignor_id=? AND claim_month=? AND status IN ("pending","overdue")',
            [$consignorId, $claimMonth]);
        if ($existing) {
            $pdo->prepare('UPDATE consignment_payouts SET payout_due=payout_due+?, invoice_no=? WHERE id=?')
                ->execute([$payoutTotal, $invoiceNo, $existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO consignment_payouts (consignor_id, claim_month, invoice_no, payout_due, status)
                VALUES (?,?,?,?,"pending")')
                ->execute([$consignorId, $claimMonth, $invoiceNo, $payoutTotal]);
        }

        $pdo->commit();

        if (telegram_enabled_for_channel($pdo, 'consignment')) {
            $lines = [
                'Issue INV sale recorded',
                'Company: ' . setting($pdo, 'company_name', 'PALVIN'),
                'Invoice: ' . $invoiceNo,
                'Time: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')',
                'Consignor: ' . ($consignorName !== '' ? $consignorName : ('ID ' . $consignorId)),
            ];
            if ($consignorBranch !== '') {
                $lines[] = 'Branch: ' . $consignorBranch;
            }
            $lines[] = 'DO Ref: ' . implode(', ', array_keys($deliveryRefs));
            $lines[] = 'Qty Sold: ' . $qtyTotal;
            $lines[] = 'Gross Sales: ' . money($grossTotal);
            $lines[] = 'Commission: ' . money($commissionTotal);
            $lines[] = 'Payout Due: ' . money($payoutTotal);
            $lines[] = 'Sold By: ' . (auth_user()['full_name'] ?? 'System');
            $lines[] = 'Items:';
            foreach ($soldLines as $line) {
                $lines[] = '- ' . $line['item_name'] . ' x' . $line['quantity'] . ' @ ' . money($line['unit_price']) . ' = ' . money($line['gross_amount']) . ' | payout ' . money($line['payout_due']);
            }
            $telegramResult = send_telegram_message($pdo, telegram_message_from_lines($lines));
            if (!$telegramResult['ok']) {
                flash('warning', 'Issue INV sale saved, but Telegram alert failed: ' . $telegramResult['error']);
            }
        }

        flash('success', 'Invoice saved: ' . $invoiceNo . '|||' . $invoiceNo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }

    redirect_to('consignment_inventory.php?' . http_build_query($baseQuery));
}


/* ──────────────────────────────────────────────
   POST: recall stock back from consignor
   ────────────────────────────────────────────── */
if (is_post() && post_action() === 'recall') {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $recallQty    = max(0, (int)($_POST['recall_qty'] ?? 0));
    $recallNote   = trim((string)($_POST['recall_note'] ?? ''));

    if ($assignmentId <= 0 || $recallQty <= 0) {
        flash('error', 'Please enter a valid recall quantity.');
        redirect_to('consignment_inventory.php?' . http_build_query($baseQuery));
    }

    $pdo->beginTransaction();
    try {
        $row = db_one($pdo, "
            SELECT
                ca.id AS assignment_id,
                ca.consignor_id,
                ca.main_inventory_id,
                ca.assigned_stock,
                ca.sale_price,
                ca.commission_rate,
                inv.id AS inventory_id,
                GREATEST(COALESCE(inv.stock_balance,
                    ca.assigned_stock - COALESCE(sold.total_sold,0)), 0) AS stock_balance,
                mi.item_name,
                COALESCE(mi.reference_code,'') AS reference_code,
                COALESCE(mi.item_code,'') AS item_code,
                mi.image_path
            FROM consignment_assignments ca
            INNER JOIN consignment_main_inventory mi ON mi.id = ca.main_inventory_id
            LEFT  JOIN consignment_inventory inv ON inv.assignment_id = ca.id
            LEFT  JOIN (
                SELECT assignment_id, SUM(quantity) AS total_sold
                FROM consignment_sales GROUP BY assignment_id
            ) sold ON sold.assignment_id = ca.id
            WHERE ca.id = ?
            LIMIT 1
            FOR UPDATE
        ", [$assignmentId]);

        if (!$row) throw new RuntimeException('Assignment not found.');
        $currentBalance = (int)$row['stock_balance'];
        if ($recallQty > $currentBalance) {
            throw new RuntimeException('Recall qty (' . $recallQty . ') exceeds current on-hand stock (' . $currentBalance . ').');
        }

        $newBalance  = $currentBalance - $recallQty;
        $newAssigned = max(0, (int)$row['assigned_stock'] - $recallQty);
        $noteAppend  = ' | RECALL ' . date('Y-m-d') . ': -' . $recallQty . ($recallNote ? ' (' . $recallNote . ')' : '');

        $pdo->prepare('UPDATE consignment_assignments SET assigned_stock=?, notes=CONCAT(COALESCE(notes,""),?), updated_at=NOW() WHERE id=?')
            ->execute([$newAssigned, $noteAppend, $assignmentId]);

        if ((int)$row['inventory_id'] > 0) {
            $pdo->prepare('UPDATE consignment_inventory SET stock_balance=?, sale_price=?, commission_rate=?, updated_at=NOW() WHERE id=?')
                ->execute([$newBalance, $row['sale_price'], $row['commission_rate'], (int)$row['inventory_id']]);
        } elseif ($newBalance > 0) {
            $pdo->prepare('INSERT INTO consignment_inventory
                (assignment_id, consignor_id, main_inventory_id, item_name, reference_code, item_code, stock_balance, sale_price, commission_rate, image_path, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([
                $row['assignment_id'], $row['consignor_id'], $row['main_inventory_id'],
                $row['item_name'], $row['reference_code'], $row['item_code'],
                $newBalance, $row['sale_price'], $row['commission_rate'], $row['image_path'],
            ]);
        }

        $pdo->commit();
        flash('success', 'Recalled ' . $recallQty . ' unit(s) of "' . $row['item_name'] . '". Consignment stock master availability updated.' . ($recallNote ? ' Note: ' . $recallNote : ''));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect_to('consignment_inventory.php?' . http_build_query($baseQuery));
}

/* ──────────────────────────────────────────────
   Load all DO lines with real stock
   ────────────────────────────────────────────── */
$sql = "
    SELECT
        ca.id                                                               AS assignment_id,
        COALESCE(inv.id, 0)                                                AS inventory_id,
        ca.consignor_id,
        COALESCE(NULLIF(ca.delivery_no,''), CONCAT('DO-',ca.id))          AS delivery_no,
        ca.assigned_stock,
        ca.sale_price,
        ca.commission_rate,
        c.store_name                                                        AS consignor_name,
        COALESCE(c.branch_location,'')                                     AS branch_location,
        mi.item_name,
        COALESCE(mi.reference_code,'')                                     AS reference_code,
        COALESCE(mi.item_code,'')                                          AS item_code,
        GREATEST(COALESCE(inv.stock_balance,
            ca.assigned_stock - COALESCE(sold.total_sold,0)), 0)          AS stock_balance,
        COALESCE(sold.total_sold,0)                                        AS sold_qty
    FROM consignment_assignments ca
    INNER JOIN consignors c ON c.id = ca.consignor_id
    INNER JOIN consignment_main_inventory mi ON mi.id = ca.main_inventory_id
    LEFT  JOIN consignment_inventory inv ON inv.assignment_id = ca.id
    LEFT  JOIN (
        SELECT assignment_id, SUM(quantity) AS total_sold
        FROM consignment_sales GROUP BY assignment_id
    ) sold ON sold.assignment_id = ca.id
    WHERE GREATEST(COALESCE(inv.stock_balance,
            ca.assigned_stock - COALESCE(sold.total_sold,0)), 0) > 0
";
$params = [];
if ($doRefFilter !== '') {
    $sql    .= ' AND COALESCE(NULLIF(ca.delivery_no,""), CONCAT("DO-",ca.id)) LIKE ?';
    $params[] = '%' . $doRefFilter . '%';
}
$sql .= ' ORDER BY c.store_name ASC, ca.delivery_no ASC, ca.id ASC';

$inventoryRows = db_all($pdo, $sql, $params);

/* Group by consignor → delivery_no */
$byConsignor = [];
foreach ($inventoryRows as $row) {
    $cid       = (int)$row['consignor_id'];
    $doRef     = (string)$row['delivery_no'];
    $remaining = max(0, (int)$row['stock_balance']);
    if ($cid <= 0 || $remaining <= 0) continue;

    if (!isset($byConsignor[$cid])) {
        $byConsignor[$cid] = [
            'consignor_id'    => $cid,
            'consignor_name'  => (string)$row['consignor_name'],
            'branch_location' => (string)$row['branch_location'],
            'dos'             => [],
        ];
    }
    if (!isset($byConsignor[$cid]['dos'][$doRef])) {
        $byConsignor[$cid]['dos'][$doRef] = [
            'delivery_no'   => $doRef,
            'total_items'   => 0,
            'total_on_hand' => 0,
            'total_sold'    => 0,
            'rows'          => [],
        ];
    }
    $byConsignor[$cid]['dos'][$doRef]['rows'][] = [
        'assignment_id'   => (int)$row['assignment_id'],
        'inventory_id'    => (int)$row['inventory_id'],
        'item_name'       => (string)$row['item_name'],
        'reference_code'  => (string)$row['reference_code'],
        'item_code'       => (string)$row['item_code'],
        'delivery_no'     => $doRef,
        'assigned_stock'  => (int)$row['assigned_stock'],
        'sold_qty'        => (int)$row['sold_qty'],
        'stock_balance'   => $remaining,
        'sale_price'      => (float)$row['sale_price'],
        'commission_rate' => (float)$row['commission_rate'],
    ];
    $byConsignor[$cid]['dos'][$doRef]['total_items']++;
    $byConsignor[$cid]['dos'][$doRef]['total_on_hand'] += $remaining;
    $byConsignor[$cid]['dos'][$doRef]['total_sold']    += (int)$row['sold_qty'];
}

/* Flatten into DO-level cards */
$doCards = [];
foreach ($byConsignor as $cid => $cGroup) {
    foreach ($cGroup['dos'] as $doRef => $doGroup) {
        $doCards[] = array_merge($doGroup, [
            'consignor_id'    => $cid,
            'consignor_name'  => $cGroup['consignor_name'],
            'branch_location' => $cGroup['branch_location'],
        ]);
    }
}
usort($doCards, fn($a,$b) => strcasecmp($a['consignor_name'],$b['consignor_name']) ?: strcmp($a['delivery_no'],$b['delivery_no']));

$totalCards = count($doCards);
$meta       = paginate_meta($totalCards, 8, 200);
$cards      = ($meta['show_all'] ?? false)
    ? $doCards
    : array_slice($doCards, (int)$meta['offset'], (int)$meta['per_page']);

/* JS data: key = "consignor_id|||delivery_no" */
$modalData = [];
foreach ($cards as $card) {
    $key = $card['consignor_id'] . '|||' . $card['delivery_no'];
    $modalData[$key] = $card['rows'];
}

/* Parse flash */
$flashSuccess = flash('success');
$flashInvNo   = null;
if ($flashSuccess && str_contains($flashSuccess, '|||')) {
    [$flashSuccess, $flashInvNo] = explode('|||', $flashSuccess, 2);
}
$flashError = flash('error');

require __DIR__ . '/includes/header.php';
?>

<?php if ($flashSuccess): ?>
<div class="rounded-2xl bg-emerald-50 border border-emerald-200 px-5 py-4 mb-4 flex flex-wrap items-center justify-between gap-3">
    <span class="text-emerald-800"><?= e($flashSuccess) ?></span>
    <?php if ($flashInvNo): ?>
    <a href="invoice.php?type=consignment_sale&no=<?= urlencode($flashInvNo) ?>"
       target="_blank"
       class="rounded-xl bg-emerald-600 px-4 py-2 text-white font-semibold text-sm">
        View Invoice <?= e($flashInvNo) ?>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="rounded-2xl bg-rose-50 border border-rose-200 px-5 py-4 mb-4 text-rose-700"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="space-y-6">
<div class="pvn-card p-6">
    <!-- toolbar -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 no-print">
        <div>
            <h3 class="font-semibold text-lg">Issue INV</h3>
            <p class="text-sm text-neutral-500">Each card is one DO. Real stock pulled from Issue DO — sell items and remaining updates instantly.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="?<?= e(http_build_query(array_merge($_GET, ['view'=>'grid','page'=>1]))) ?>"
               class="rounded-xl px-3 py-2 border <?= $view==='grid' ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>">Grid</a>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['view'=>'list','page'=>1]))) ?>"
               class="rounded-xl px-3 py-2 border <?= $view==='list' ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>">List</a>
            <form class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input name="do_ref" value="<?= e($doRefFilter) ?>" placeholder="DO Ref" class="rounded-xl border px-3 py-2 w-32">
                <span class="text-sm text-neutral-500">Per page</span>
                <select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
                    <?php foreach (page_size_options(['all',6,8,10,12]) as $opt): ?>
                    <option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>>
                        <?= e(is_string($opt) ? strtoupper($opt) : (string)$opt) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button>
            </form>
        </div>
    </div>

    <?php if (!$cards): ?>
    <div class="rounded-2xl border border-dashed p-8 text-center text-neutral-500">
        No issued DO stock available yet. Create an Issue DO first — items will appear here automatically.
    </div>

    <?php elseif ($view === 'list'): ?>
    <div class="overflow-x-auto">
        <table class="pvn-table">
            <thead>
                <tr class="border-b text-left text-neutral-500">
                    <th class="py-3">DO Ref</th><th>Consignor</th><th>Branch</th>
                    <th class="text-right">Items</th><th class="text-right">On Hand</th>
                    <th class="text-right">Sold</th><th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cards as $card): ?>
            <tr class="border-b hover:bg-neutral-50">
                <td class="py-3 font-semibold text-emerald-700"><?= e($card['delivery_no']) ?></td>
                <td class="font-medium"><?= e($card['consignor_name']) ?></td>
                <td class="text-neutral-500"><?= e($card['branch_location']) ?></td>
                <td class="text-right"><?= (int)$card['total_items'] ?></td>
                <td class="text-right font-semibold text-emerald-700"><?= (int)$card['total_on_hand'] ?></td>
                <td class="text-right"><?= (int)$card['total_sold'] ?></td>
                <td class="no-print py-2">
                    <div class="flex gap-2 justify-end">
                        <a href="invoice.php?type=delivery_note&no=<?= urlencode($card['delivery_no']) ?>"
                           target="_blank"
                           class="rounded-xl bg-neutral-100 px-3 py-1 text-neutral-700 text-xs">View DO</a>
                        <button type="button"
                            class="rounded-xl bg-emerald-600 px-4 py-1 text-white font-semibold text-xs sell-btn"
                            data-key="<?= e($card['consignor_id'] . '|||' . $card['delivery_no']) ?>"
                            data-consignor-id="<?= (int)$card['consignor_id'] ?>"
                            data-consignor-name="<?= e($card['consignor_name']) ?>"
                            data-do-ref="<?= e($card['delivery_no']) ?>">Sell</button>
                    </div>
                </td>
            </tr>
            <tr class="border-b bg-neutral-50">
                <td colspan="7" class="px-4 pb-3">
                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3 pt-2">
                    <?php foreach ($card['rows'] as $row): ?>
                        <div class="rounded-2xl bg-white border px-3 py-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-sm"><?= e($row['item_name']) ?></div>
                                <button type="button"
                                    class="shrink-0 rounded-lg bg-amber-100 px-2 py-0.5 text-amber-800 text-xs recall-btn"
                                    data-assignment-id="<?= (int)$row['assignment_id'] ?>"
                                    data-item-name="<?= e($row['item_name']) ?>"
                                    data-max="<?= (int)$row['stock_balance'] ?>">Recall</button>
                            </div>
                            <div class="text-xs text-neutral-500">Ref <?= e($row['reference_code']) ?> · <?= e($row['item_code']) ?></div>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-xs">
                                <span class="text-neutral-500">Issued: <b><?= $row['assigned_stock'] ?></b></span>
                                <span class="text-emerald-700 font-semibold">Remaining: <?= $row['stock_balance'] ?></span>
                                <span class="text-neutral-400">Sold: <?= $row['sold_qty'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: /* GRID */ ?>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($cards as $card): ?>
        <div class="rounded-[24px] border border-neutral-200 bg-neutral-50 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-bold text-emerald-700 uppercase tracking-widest mb-1"><?= e($card['delivery_no']) ?></div>
                    <div class="font-semibold text-base"><?= e($card['consignor_name']) ?></div>
                    <?php if ($card['branch_location']): ?>
                    <div class="text-sm text-neutral-500"><?= e($card['branch_location']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col gap-2 items-end shrink-0">
                    <button type="button"
                        class="rounded-xl bg-emerald-600 px-4 py-2 text-white font-semibold text-sm sell-btn"
                        data-key="<?= e($card['consignor_id'] . '|||' . $card['delivery_no']) ?>"
                        data-consignor-id="<?= (int)$card['consignor_id'] ?>"
                        data-consignor-name="<?= e($card['consignor_name']) ?>"
                        data-do-ref="<?= e($card['delivery_no']) ?>">Sell</button>
                    <a href="invoice.php?type=delivery_note&no=<?= urlencode($card['delivery_no']) ?>"
                       target="_blank"
                       class="rounded-xl bg-neutral-200 px-3 py-1 text-neutral-700 text-xs">View DO</a>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
                <div class="rounded-2xl bg-white p-3 border"><div class="text-neutral-500 text-xs">Items</div><div class="font-semibold text-lg"><?= (int)$card['total_items'] ?></div></div>
                <div class="rounded-2xl bg-white p-3 border"><div class="text-neutral-500 text-xs">On Hand</div><div class="font-semibold text-lg text-emerald-700"><?= (int)$card['total_on_hand'] ?></div></div>
                <div class="rounded-2xl bg-white p-3 border"><div class="text-neutral-500 text-xs">Sold</div><div class="font-semibold text-lg"><?= (int)$card['total_sold'] ?></div></div>
            </div>
            <div class="mt-4 space-y-2 text-sm max-h-48 overflow-auto pr-1">
            <?php foreach ($card['rows'] as $row): ?>
                <div class="rounded-2xl bg-white border p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-medium text-sm"><?= e($row['item_name']) ?></div>
                        <button type="button"
                            class="shrink-0 rounded-lg bg-amber-100 px-2 py-0.5 text-amber-800 text-xs recall-btn"
                            data-assignment-id="<?= (int)$row['assignment_id'] ?>"
                            data-item-name="<?= e($row['item_name']) ?>"
                            data-max="<?= (int)$row['stock_balance'] ?>">Recall</button>
                    </div>
                    <div class="text-xs text-neutral-500">Ref <?= e($row['reference_code']) ?> · <?= e($row['item_code']) ?></div>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs">
                        <span class="text-neutral-500">Issued: <?= $row['assigned_stock'] ?></span>
                        <span class="font-semibold text-emerald-700">Remaining: <?= $row['stock_balance'] ?></span>
                        <span class="text-neutral-400">Sold: <?= $row['sold_qty'] ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-4 flex flex-wrap gap-2 no-print">
    <?php foreach (pagination_links($meta) as $link): ?>
        <a href="<?= e($link['href']) ?>"
           class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>">
            <?= e($link['label']) ?>
        </a>
    <?php endforeach; ?>
    </div>
</div>
</div>

<!-- ─── SELL MODAL ─────────────────────────────────────────────────── -->
<div id="sellModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4 no-print z-50">
    <form method="post" class="w-full max-w-3xl rounded-[28px] bg-white p-6 space-y-4 max-h-[90vh] overflow-auto shadow-xl">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg">Issue consignment invoice</h3>
                <div id="sellModalMeta" class="text-sm text-neutral-500 mt-1"></div>
            </div>
            <button type="button" id="closeSell" class="text-neutral-400 text-2xl leading-none">✕</button>
        </div>
        <input type="hidden" name="action" value="sell">
        <input type="hidden" name="consignor_id" id="sellConsignorId">
        <div id="sellLines" class="space-y-3"></div>
        <div id="sellSummary" class="rounded-2xl bg-neutral-50 border px-4 py-3 text-sm hidden">
            <div class="flex justify-between font-semibold">
                <span>Estimated Payout</span>
                <span id="sellSummaryAmt">$0.00</span>
            </div>
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <button type="button" id="cancelSell" class="rounded-2xl border px-4 py-3">Cancel</button>
            <button class="rounded-2xl bg-emerald-600 px-6 py-3 text-white font-semibold">Save &amp; Issue INV</button>
        </div>
    </form>
</div>

<!-- ─── RECALL MODAL ──────────────────────────────────────────────── -->
<div id="recallModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4 no-print z-50">
    <form method="post" class="w-full max-w-md rounded-[28px] bg-white p-6 space-y-4 shadow-xl">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg">Recall Stock</h3>
                <div id="recallItemName" class="text-sm text-neutral-500 mt-1"></div>
            </div>
            <button type="button" id="closeRecall" class="text-neutral-400 text-2xl leading-none">✕</button>
        </div>
        <input type="hidden" name="action" value="recall">
        <input type="hidden" name="assignment_id" id="recallAssignmentId">
        <div>
            <label class="text-xs text-neutral-500 mb-1 block">Recall Qty <span id="recallMaxLabel" class="text-amber-700"></span></label>
            <input type="number" name="recall_qty" id="recallQty" min="1" value="1"
                   class="w-full rounded-2xl border px-4 py-3 font-semibold" required>
        </div>
        <div>
            <label class="text-xs text-neutral-500 mb-1 block">Recall Note (optional)</label>
            <input type="text" name="recall_note" id="recallNote" placeholder="e.g. Damaged, expired, returned…"
                   class="pvn-input">
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <button type="button" id="cancelRecall" class="rounded-2xl border px-4 py-3">Cancel</button>
            <button class="rounded-2xl bg-amber-500 px-6 py-3 text-white font-semibold">Confirm Recall</button>
        </div>
    </form>
</div>

<script>
const modalData       = <?= json_encode($modalData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const sellModal       = document.getElementById('sellModal');
const sellLines       = document.getElementById('sellLines');
const sellConsignorId = document.getElementById('sellConsignorId');
const sellModalMeta   = document.getElementById('sellModalMeta');
const sellSummary     = document.getElementById('sellSummary');
const sellSummaryAmt  = document.getElementById('sellSummaryAmt');

function fmt(n) {
    return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function recalc() {
    let total = 0;
    sellLines.querySelectorAll('.sell-line-row').forEach(wrap => {
        const qty   = parseInt(wrap.querySelector('.sell-qty').value) || 0;
        const price = parseFloat(wrap.dataset.price) || 0;
        const rate  = parseFloat(wrap.dataset.rate) || 0;
        const payout = qty * price * (1 - rate / 100);
        total += payout;
        const est = wrap.querySelector('.sell-est');
        if (est) est.textContent = qty > 0 ? 'Payout: ' + fmt(payout) : '';
    });
    sellSummaryAmt.textContent = fmt(total);
    sellSummary.classList.toggle('hidden', total <= 0);
}

function renderSellLines(key, consignorName, doRef) {
    const rows = modalData[key] || [];
    sellConsignorId.value = key.split('|||')[0];
    sellModalMeta.textContent = consignorName + '  ·  ' + doRef;
    sellLines.innerHTML = '';

    if (!rows.length) {
        sellLines.innerHTML = '<div class="rounded-2xl border border-dashed p-4 text-center text-neutral-500">No remaining stock for this DO.</div>';
        return;
    }

    rows.forEach(row => {
        const wrap = document.createElement('div');
        wrap.className = 'rounded-2xl border p-4 sell-line-row';
        wrap.dataset.price = row.sale_price;
        wrap.dataset.rate  = row.commission_rate;
        wrap.innerHTML = `
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <div class="font-medium">${row.item_name}</div>
                    <div class="text-xs text-neutral-500 mt-0.5">
                        DO ${row.delivery_no}
                        ${row.reference_code ? ' · Ref ' + row.reference_code : ''}
                        ${row.item_code ? ' · ' + row.item_code : ''}
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-4 text-xs text-neutral-600">
                        <span>Issued: <b>${row.assigned_stock}</b></span>
                        <span class="text-emerald-700">Remaining: <b>${row.stock_balance}</b></span>
                        <span>Sold: ${row.sold_qty}</span>
                        <span>Price: <b>${fmt(row.sale_price)}</b></span>
                        <span>Comm: ${row.commission_rate}%</span>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1 shrink-0">
                    <input type="hidden" name="assignment_id[]" value="${row.assignment_id}">
                    <label class="text-xs text-neutral-500">Sell qty (max ${row.stock_balance})</label>
                    <input type="number" min="0" max="${row.stock_balance}"
                           name="qty[]" value="0"
                           class="sell-qty rounded-xl border px-3 py-2 w-28 text-right font-semibold">
                    <div class="sell-est text-xs text-emerald-700 font-medium h-4"></div>
                </div>
            </div>`;
        sellLines.appendChild(wrap);
    });

    sellLines.querySelectorAll('.sell-qty').forEach(inp => {
        inp.addEventListener('input', () => {
            const max = parseInt(inp.max) || 0;
            if (parseInt(inp.value) > max) inp.value = max;
            recalc();
        });
    });
    recalc();
}

document.querySelectorAll('.sell-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        renderSellLines(btn.dataset.key, btn.dataset.consignorName, btn.dataset.doRef);
        sellModal.classList.remove('hidden');
        sellModal.classList.add('flex');
    });
});

function closeSellModal() {
    sellModal.classList.add('hidden');
    sellModal.classList.remove('flex');
}
document.getElementById('closeSell').addEventListener('click', closeSellModal);
document.getElementById('cancelSell').addEventListener('click', closeSellModal);
sellModal.addEventListener('click', e => { if (e.target === sellModal) closeSellModal(); });

// ── Recall Modal ──────────────────────────────────────────────────
const recallModal     = document.getElementById('recallModal');
const recallAssId     = document.getElementById('recallAssignmentId');
const recallItemName  = document.getElementById('recallItemName');
const recallQty       = document.getElementById('recallQty');
const recallMaxLabel  = document.getElementById('recallMaxLabel');
const recallNote      = document.getElementById('recallNote');
const closeRecallBtn  = document.getElementById('closeRecall');
const cancelRecallBtn = document.getElementById('cancelRecall');

if (recallModal && recallAssId && recallItemName && recallQty && recallMaxLabel && closeRecallBtn && cancelRecallBtn) {
    document.querySelectorAll('.recall-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            recallAssId.value = btn.dataset.assignmentId;
            recallItemName.textContent = btn.dataset.itemName;
            const max = parseInt(btn.dataset.max) || 0;
            recallQty.min = 1;
            recallQty.max = max;
            recallQty.value = max > 0 ? 1 : 0;
            recallMaxLabel.textContent = '(max ' + max + ' on hand)';
            if (recallNote) recallNote.value = '';
            recallModal.classList.remove('hidden');
            recallModal.classList.add('flex');
        });
    });

    const closeRecallModal = () => {
        recallModal.classList.add('hidden');
        recallModal.classList.remove('flex');
    };

    closeRecallBtn.addEventListener('click', closeRecallModal);
    cancelRecallBtn.addEventListener('click', closeRecallModal);
    recallModal.addEventListener('click', e => { if (e.target === recallModal) closeRecallModal(); });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
