<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Issue DO';

if (isset($_GET['delete'])) {
    $assignmentId = (int)$_GET['delete'];
    $inventoryRow = db_one($pdo, 'SELECT ci.id, ci.assignment_id FROM consignment_inventory ci WHERE ci.assignment_id=?', [$assignmentId]);
    if (!$inventoryRow) {
        flash('error', 'Delivery note line not found.');
        redirect_to('consignment_assign.php');
    }
    $linkedSales = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_sales WHERE inventory_id=?', [(int)$inventoryRow['id']]);
    if ($linkedSales > 0) {
        flash('error', 'Cannot delete this delivery note because sales already exist for the stock line.');
        redirect_to('consignment_assign.php');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM consignment_inventory WHERE assignment_id=?')->execute([$assignmentId]);
        $pdo->prepare('DELETE FROM consignment_assignments WHERE id=?')->execute([$assignmentId]);
        $pdo->commit();
        flash('success', 'Delivery note line deleted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect_to('consignment_assign.php');
}

if (is_post()) {
    $action = post_action();

    if ($action === 'update_do') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $deliveryNo = trim((string)($_POST['delivery_no'] ?? ''));
        $qty = max(0, (int)($_POST['assigned_stock'] ?? 0));
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $discountAmount = (float)($_POST['discount_amount'] ?? 0);
        $commissionRate = (float)($_POST['commission_rate'] ?? 0);
        $note = trim((string)($_POST['notes'] ?? ''));
        if ($assignmentId <= 0 || $deliveryNo === '' || $qty <= 0) {
            flash('error', 'Please complete the required delivery note fields.');
            redirect_to('consignment_assign.php');
        }
        $assignment = db_one($pdo, 'SELECT ca.*, ci.id AS inventory_id, ci.stock_balance FROM consignment_assignments ca JOIN consignment_inventory ci ON ci.assignment_id=ca.id WHERE ca.id=?', [$assignmentId]);
        if (!$assignment) {
            flash('error', 'Delivery note line not found.');
            redirect_to('consignment_assign.php');
        }
        $soldQty = (int)db_value($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM consignment_sales WHERE inventory_id=?', [(int)$assignment['inventory_id']]);
        if ($qty < $soldQty) {
            flash('error', 'Assigned stock cannot be less than already sold quantity (' . $soldQty . ').');
            redirect_to('consignment_assign.php');
        }
        $main = db_one($pdo, 'SELECT mi.total_stock FROM consignment_main_inventory mi WHERE mi.id=?', [(int)$assignment['main_inventory_id']]);
        $otherAssigned = (int)db_value($pdo, 'SELECT COALESCE(SUM(assigned_stock),0) FROM consignment_assignments WHERE main_inventory_id=? AND id<>?', [(int)$assignment['main_inventory_id'], $assignmentId]);
        if ($qty + $otherAssigned > (int)($main['total_stock'] ?? 0)) {
            $available = max(0, (int)($main['total_stock'] ?? 0) - $otherAssigned);
            flash('error', 'Only ' . $available . ' stock is available for this item.');
            redirect_to('consignment_assign.php');
        }
        $remaining = $qty - $soldQty;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE consignment_assignments SET delivery_no=?, assigned_stock=?, sale_price=?, discount_amount=?, commission_rate=?, notes=?, updated_at=NOW() WHERE id=?')->execute([$deliveryNo, $qty, $salePrice, $discountAmount, $commissionRate, $note, $assignmentId]);
            $pdo->prepare('UPDATE consignment_inventory SET stock_balance=?, sale_price=?, discount_amount=?, commission_rate=?, updated_at=NOW() WHERE assignment_id=?')->execute([$remaining, $salePrice, $discountAmount, $commissionRate, $assignmentId]);
            $pdo->commit();
            flash('success', 'Delivery note line updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $e->getMessage());
        }
        redirect_to('consignment_assign.php');
    }

    $consignorId = (int)($_POST['consignor_id'] ?? 0);
    $mainIds = $_POST['main_inventory_id'] ?? [];
    $qtys = $_POST['assigned_stock'] ?? [];
    $prices = $_POST['sale_price'] ?? [];
    $discounts = $_POST['discount_amount'] ?? [];
    $rates = $_POST['commission_rate'] ?? [];
    $notes = $_POST['notes'] ?? [];
    $deliveryNo = trim((string)($_POST['delivery_no'] ?? ''));
    if ($deliveryNo === '') $deliveryNo = 'DO-' . date('YmdHis');
    if ($consignorId <= 0) { flash('error', 'Please select a consignor.'); redirect_to('consignment_assign.php'); }
    $pdo->beginTransaction();
    try {
        $saved = 0;
        foreach ($mainIds as $i => $mainId) {
            $mainId = (int)$mainId;
            $qty = max(0, (int)($qtys[$i] ?? 0));
            if ($mainId <= 0 || $qty <= 0) continue;
            $salePrice = (float)($prices[$i] ?? 0);
            $discountAmount = (float)($discounts[$i] ?? 0);
            $commissionRate = (float)($rates[$i] ?? 0);
            $note = trim((string)($notes[$i] ?? ''));
            $main = db_one($pdo, 'SELECT mi.*, GREATEST(mi.total_stock - COALESCE((SELECT SUM(ca.assigned_stock) FROM consignment_assignments ca WHERE ca.main_inventory_id = mi.id),0),0) available_to_assign FROM consignment_main_inventory mi WHERE mi.id=?', [$mainId]);
            if (!$main) throw new RuntimeException('A selected stock item was not found.');
            if ($qty > (int)$main['available_to_assign']) throw new RuntimeException($main['item_name'] . ' only has ' . (int)$main['available_to_assign'] . ' available to issue.');
            $pdo->prepare('INSERT INTO consignment_assignments (consignor_id, main_inventory_id, delivery_no, assigned_stock, sale_price, discount_amount, commission_rate, notes, issued_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$consignorId, $mainId, $deliveryNo, $qty, $salePrice, $discountAmount, $commissionRate, $note, auth_user()['full_name'] ?? '']);
            $assignmentId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO consignment_inventory (assignment_id, consignor_id, main_inventory_id, item_name, reference_code, item_code, stock_balance, sale_price, discount_amount, commission_rate, image_path, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$assignmentId, $consignorId, $mainId, $main['item_name'], $main['reference_code'], $main['item_code'], $qty, $salePrice, $discountAmount, $commissionRate, $main['image_path']]);
            $saved++;
        }
        if ($saved < 1) throw new RuntimeException('Please add at least one stock line.');
        $pdo->commit();
        flash('success', 'Delivery note issued. DO no: ' . $deliveryNo);
        redirect_to('consignment_assign.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect_to('consignment_assign.php');
    }
}
$consignors = db_all($pdo, 'SELECT * FROM consignors ORDER BY store_name ASC');
$mainRows = db_all($pdo, 'SELECT mi.*, GREATEST(mi.total_stock - COALESCE((SELECT SUM(ca.assigned_stock) FROM consignment_assignments ca WHERE ca.main_inventory_id = mi.id),0),0) available_to_assign FROM consignment_main_inventory mi ORDER BY item_name ASC');
$historyTotal = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_assignments');
$historyMeta = paginate_meta($historyTotal, 4, 100);
$historyRows = db_all($pdo, 'SELECT ca.*, c.store_name, mi.item_name, mi.item_code, ci.stock_balance, COALESCE((SELECT SUM(cs.quantity) FROM consignment_sales cs WHERE cs.inventory_id = ci.id),0) sold_qty FROM consignment_assignments ca JOIN consignors c ON c.id=ca.consignor_id JOIN consignment_main_inventory mi ON mi.id=ca.main_inventory_id LEFT JOIN consignment_inventory ci ON ci.assignment_id = ca.id ORDER BY ca.id DESC LIMIT ' . (int)$historyMeta['per_page'] . ' OFFSET ' . (int)$historyMeta['offset']);
$editRow = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $editRow = db_one($pdo, 'SELECT ca.*, c.store_name, mi.item_name, mi.item_code, ci.stock_balance, COALESCE((SELECT SUM(cs.quantity) FROM consignment_sales cs WHERE cs.inventory_id = ci.id),0) sold_qty FROM consignment_assignments ca JOIN consignors c ON c.id=ca.consignor_id JOIN consignment_main_inventory mi ON mi.id=ca.main_inventory_id LEFT JOIN consignment_inventory ci ON ci.assignment_id = ca.id WHERE ca.id=?', [$editId]);
    }
}
require __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="pvn-card p-6 no-print">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg">Issue delivery note</h3>
                <p class="text-sm text-neutral-500">Open a popup, choose items like POS, then issue the DO.</p>
            </div>
            <button type="button" id="openDoModal" class="pvn-btn pvn-btn-primary">Issue DO</button>
        </div>
    </div>

    <?php if ($editRow): ?>
    <div class="pvn-card p-6 border border-amber-200">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-lg">Update delivery note line</h3>
                <p class="text-sm text-neutral-500"><?= e($editRow['store_name']) ?> · <?= e($editRow['item_name']) ?> · sold <?= e((string)$editRow['sold_qty']) ?></p>
            </div>
            <a href="consignment_assign.php" class="pvn-btn pvn-btn-secondary pvn-btn-sm">Close</a>
        </div>
        <form method="post" class="grid md:grid-cols-5 gap-3">
            <input type="hidden" name="action" value="update_do">
            <input type="hidden" name="assignment_id" value="<?= (int)$editRow['id'] ?>">
            <div><label class="text-xs text-neutral-500">DO No</label><input name="delivery_no" class="mt-1 w-full rounded-xl border px-3 py-2" value="<?= e($editRow['delivery_no']) ?>" required></div>
            <div><label class="text-xs text-neutral-500">Qty</label><input type="number" name="assigned_stock" min="<?= (int)$editRow['sold_qty'] ?>" class="mt-1 w-full rounded-xl border px-3 py-2" value="<?= (int)$editRow['assigned_stock'] ?>" required></div>
            <div><label class="text-xs text-neutral-500">Sale price</label><input type="number" step="0.01" name="sale_price" class="mt-1 w-full rounded-xl border px-3 py-2" value="<?= e((string)$editRow['sale_price']) ?>" required></div>
            <div><label class="text-xs text-neutral-500">Commission %</label><input type="number" step="0.01" name="commission_rate" class="mt-1 w-full rounded-xl border px-3 py-2" value="<?= e((string)$editRow['commission_rate']) ?>" required></div>
            <div><label class="text-xs text-neutral-500">Note</label><input name="notes" class="mt-1 w-full rounded-xl border px-3 py-2" value="<?= e((string)$editRow['notes']) ?>"></div>
            <div class="md:col-span-5 flex justify-end"><button class="rounded-2xl bg-amber-500 px-4 py-3 text-white font-semibold">Update DO</button></div>
        </form>
    </div>
    <?php endif; ?>

    <div class="pvn-card p-6">
        <div class="flex items-center justify-between gap-3 mb-4 no-print">
            <h3 class="font-semibold text-lg">Assignment history</h3>
            <form class="flex items-center gap-2"><select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?php foreach (page_size_options([4,6,8,10,'all']) as $opt): ?><option value="<?= e((string)$opt) ?>" <?= ((string)($historyMeta['show_all'] ? 'all' : $historyMeta['per_page'])) === (string)$opt ? 'selected' : '' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option><?php endforeach; ?></select><button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button></form>
        </div>
        <table class="pvn-table">
            <thead><tr class="border-b text-left text-neutral-500"><th class="py-3">DO No</th><th>Consignor</th><th>Item</th><th>Qty</th><th>Remaining</th><th>Price</th><th>Issued By</th><th>Date</th><th class="no-print">Action</th></tr></thead>
            <tbody><?php foreach($historyRows as $row): ?><tr class="border-b last:border-b-0"><td class="py-3"><?= e($row['delivery_no'] ?: ('DO-' . $row['id'])) ?></td><td><?= e($row['store_name']) ?></td><td><?= e($row['item_name']) ?><div class="text-xs text-neutral-500"><?= e($row['item_code']) ?></div></td><td><?= e((string)$row['assigned_stock']) ?><div class="text-xs text-neutral-500">Sold <?= e((string)$row['sold_qty']) ?></div></td><td><?= e((string)$row['stock_balance']) ?></td><td><?= e(money($row['sale_price'])) ?></td><td><?= e($row['issued_by']) ?></td><td><?= e($row['updated_at']) ?></td><td class="no-print"><div class="flex flex-wrap gap-2"><a class="rounded-xl bg-sky-100 px-3 py-1 text-sky-700" href="?edit=<?= (int)$row['id'] ?>">Update</a><a class="pvn-badge pvn-badge-red" href="?delete=<?= (int)$row['id'] ?>" onclick="return confirm('Delete this DO line?')">Delete</a><a class="rounded-xl bg-neutral-100 px-3 py-1 text-neutral-700" href="invoice.php?type=delivery_note&amp;no=<?= urlencode($row['delivery_no']) ?>">DO</a></div></td></tr><?php endforeach; ?></tbody>
        </table>
        <div class="mt-4 flex flex-wrap gap-2 no-print"><?php foreach (pagination_links($historyMeta) as $link): ?><a href="<?= e($link['href']) ?>" class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>"><?= e($link['label']) ?></a><?php endforeach; ?></div>
    </div>
</div>

<div id="doModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4 no-print z-50">
    <form method="post" class="w-full max-w-6xl rounded-[28px] bg-white p-6 max-h-[92vh] overflow-auto space-y-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg">Issue delivery note</h3>
                <p class="text-sm text-neutral-500">Select items from stock and set quantity.</p>
            </div>
            <button type="button" id="closeDoModal" class="text-xl text-neutral-500">✕</button>
        </div>
        <div class="grid md:grid-cols-3 gap-3">
            <select name="consignor_id" class="pvn-input" required><option value="">Select Consignor</option><?php foreach($consignors as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['store_name']) ?></option><?php endforeach; ?></select>
            <input name="delivery_no" class="pvn-input" placeholder="Delivery Note No (auto if blank)">
            <div class="rounded-2xl border bg-neutral-50 px-4 py-3 text-sm text-neutral-500">Click item card to add to DO cart.</div>
        </div>
        <div class="grid lg:grid-cols-[1.2fr,0.8fr] gap-5">
            <div>
                <div class="mb-3 font-medium">Stock list</div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <?php foreach($mainRows as $m): ?>
                        <button type="button" class="text-left rounded-[22px] border border-neutral-200 bg-neutral-50 p-4 add-stock-card" 
                            data-id="<?= (int)$m['id'] ?>" 
                            data-name="<?= e($m['item_name']) ?>" 
                            data-code="<?= e($m['item_code']) ?>" 
                            data-avail="<?= (int)$m['available_to_assign'] ?>" 
                            data-price="<?= e((string)$m['sale_price']) ?>" 
                            data-discount="<?= e((string)($m['discount_amount'] ?? 0)) ?>" 
                            data-rate="<?= e((string)setting($pdo,'default_consignor_commission','5')) ?>">
                            <div class="font-medium"><?= e($m['item_name']) ?></div>
                            <div class="text-xs text-neutral-500 mt-1"><?= e($m['item_code']) ?> · avail <?= e((string)$m['available_to_assign']) ?></div>
                            <div class="mt-3 text-sm font-semibold"><?= e(money($m['sale_price'])) ?></div>
                            <?php if (!empty($m['discount_amount'])): ?>
                            <div class="text-xs text-emerald-600 font-medium">Discount: <?= e(money($m['discount_amount'])) ?></div>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="mb-3 font-medium">DO cart</div>
                <div id="doCart" class="space-y-3"></div>
                <div class="mt-4 flex justify-end">
                    <button class="pvn-btn pvn-btn-primary">Issue DO</button>
                </div>
            </div>
        </div>
    </form>
</div>

<template id="cartRowTemplate">
    <div class="rounded-2xl border p-4 cart-row space-y-3">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="font-medium item-name"></div>
                <div class="text-xs text-neutral-500 item-code"></div>
            </div>
            <button type="button" class="rounded-xl bg-rose-100 px-3 py-1 text-rose-700 remove-cart">Remove</button>
        </div>
        <input type="hidden" name="main_inventory_id[]" class="item-id">
        <div class="grid grid-cols-2 gap-3">
            <div><label class="text-xs text-neutral-500">Qty</label><input type="number" name="assigned_stock[]" min="1" class="mt-1 w-full rounded-xl border px-3 py-2 item-qty" required></div>
            <div><label class="text-xs text-neutral-500">Sale price</label><input type="number" step="0.01" name="sale_price[]" class="mt-1 w-full rounded-xl border px-3 py-2 item-price" required></div>
            <div><label class="text-xs text-neutral-500">Discount</label><input type="number" step="0.01" name="discount_amount[]" class="mt-1 w-full rounded-xl border px-3 py-2 item-discount" value="0"></div>
            <div><label class="text-xs text-neutral-500">Commission %</label><input type="number" step="0.01" name="commission_rate[]" class="mt-1 w-full rounded-xl border px-3 py-2 item-rate" required></div>
            <div><label class="text-xs text-neutral-500">Note</label><input type="text" name="notes[]" class="mt-1 w-full rounded-xl border px-3 py-2"></div>
        </div>
    </div>
</template>

<script>
const doModal = document.getElementById('doModal');
const doCart = document.getElementById('doCart');
const rowTemplate = document.getElementById('cartRowTemplate');
document.getElementById('openDoModal').addEventListener('click', () => { doModal.classList.remove('hidden'); doModal.classList.add('flex'); });
document.getElementById('closeDoModal').addEventListener('click', () => { doModal.classList.add('hidden'); doModal.classList.remove('flex'); });
doModal.addEventListener('click', (e) => { if (e.target === doModal) { doModal.classList.add('hidden'); doModal.classList.remove('flex'); } });

document.querySelectorAll('.add-stock-card').forEach((btn) => btn.addEventListener('click', () => {
    const existing = doCart.querySelector('.item-id[value="' + btn.dataset.id + '"]');
    if (existing) {
        const qtyInput = existing.closest('.cart-row').querySelector('.item-qty');
        const max = parseInt(qtyInput.max || '1', 10);
        qtyInput.value = Math.min(max, parseInt(qtyInput.value || '1', 10) + 1);
        return;
    }
    const clone = rowTemplate.content.firstElementChild.cloneNode(true);
    clone.querySelector('.item-name').textContent = btn.dataset.name;
    clone.querySelector('.item-code').textContent = btn.dataset.code + ' · avail ' + btn.dataset.avail;
    clone.querySelector('.item-id').value = btn.dataset.id;
    clone.querySelector('.item-qty').value = 1;
    clone.querySelector('.item-qty').max = btn.dataset.avail;
    clone.querySelector('.item-price').value = btn.dataset.price;
    clone.querySelector('.item-discount').value = btn.dataset.discount || 0;
    clone.querySelector('.item-rate').value = btn.dataset.rate;
    clone.querySelector('.remove-cart').addEventListener('click', () => clone.remove());
    doCart.appendChild(clone);
}));
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('item-qty')) {
        const max = parseInt(e.target.max || '0', 10);
        const val = parseInt(e.target.value || '0', 10);
        if (val > max) {
            alert('Only ' + max + ' available for this stock item.');
            e.target.value = max;
        }
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
