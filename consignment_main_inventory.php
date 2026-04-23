<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Stock';

if (is_post()) {
    $action    = post_action();
    $imagePath = upload_file($_FILES['image'] ?? [], 'products');

    if ($action === 'save') {
        $pdo->prepare('INSERT INTO consignment_main_inventory
            (item_name, reference_code, item_code, total_stock, sale_price, image_path, updated_at)
            VALUES (?,?,?,?,?,?,NOW())')
        ->execute([
            trim($_POST['item_name'] ?? ''),
            trim($_POST['reference_code'] ?? ''),
            trim($_POST['item_code'] ?? ''),
            (int)($_POST['total_stock'] ?? 0),
            (float)($_POST['sale_price'] ?? 0),
            $imagePath,
        ]);
        flash('success', 'Stock item added.');
    }
    if ($action === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = db_one($pdo, 'SELECT image_path FROM consignment_main_inventory WHERE id=?', [$id]);
        $pdo->prepare('UPDATE consignment_main_inventory
            SET item_name=?, reference_code=?, item_code=?, total_stock=?, sale_price=?, image_path=?, updated_at=NOW()
            WHERE id=?')
        ->execute([
            trim($_POST['item_name'] ?? ''),
            trim($_POST['reference_code'] ?? ''),
            trim($_POST['item_code'] ?? ''),
            (int)($_POST['total_stock'] ?? 0),
            (float)($_POST['sale_price'] ?? 0),
            $imagePath ?: ($current['image_path'] ?? null),
            $id,
        ]);
        flash('success', 'Stock item updated.');
    }
    redirect_to('consignment_main_inventory.php');
}

if (isset($_GET['delete'])) {
    $id       = (int)$_GET['delete'];
    $assigned = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_assignments WHERE main_inventory_id=?', [$id]);
    if ($assigned > 0) {
        flash('error', 'Cannot delete: this item is assigned to consignors.');
    } else {
        $pdo->prepare('DELETE FROM consignment_main_inventory WHERE id=?')->execute([$id]);
        flash('success', 'Stock item deleted.');
    }
    redirect_to('consignment_main_inventory.php');
}

$edit  = isset($_GET['edit']) ? db_one($pdo, 'SELECT * FROM consignment_main_inventory WHERE id=?', [(int)$_GET['edit']]) : null;
$flash = flash('success');
$ferr  = flash('error');

/* ── Stock master with real availability ──────────────────────────────
   Available  = total_stock  - SUM(assigned_stock from assignments)
   Sold Out   = items where available <= 0
   On Hand    = SUM(stock_balance in consignment_inventory) across all DOs
   Total Sold = SUM(quantity) from consignment_sales
*/
$total = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_main_inventory');
$meta  = paginate_meta($total, 8, 50);

$rows = db_all($pdo, "
    SELECT
        mi.*,
        COALESCE(asgn.total_assigned, 0)                                AS total_assigned,
        GREATEST(mi.total_stock - COALESCE(asgn.total_assigned, 0), 0) AS available_to_assign,
        COALESCE(inv.on_hand, 0)                                        AS on_hand,
        COALESCE(sold.total_sold, 0)                                    AS total_sold
    FROM consignment_main_inventory mi
    LEFT JOIN (
        SELECT main_inventory_id, SUM(assigned_stock) AS total_assigned
        FROM consignment_assignments
        GROUP BY main_inventory_id
    ) asgn ON asgn.main_inventory_id = mi.id
    LEFT JOIN (
        SELECT main_inventory_id, SUM(stock_balance) AS on_hand
        FROM consignment_inventory
        GROUP BY main_inventory_id
    ) inv ON inv.main_inventory_id = mi.id
    LEFT JOIN (
        SELECT ci.main_inventory_id, SUM(cs.quantity) AS total_sold
        FROM consignment_sales cs
        JOIN consignment_inventory ci ON ci.id = cs.inventory_id
        GROUP BY ci.main_inventory_id
    ) sold ON sold.main_inventory_id = mi.id
    ORDER BY mi.id DESC
    LIMIT " . (int)$meta['per_page'] . " OFFSET " . (int)$meta['offset']
);

require __DIR__ . '/includes/header.php';
?>

<?php if ($flash): ?>
<div class="rounded-2xl bg-emerald-50 border border-emerald-200 px-5 py-3 mb-4 text-emerald-800"><?= e($flash) ?></div>
<?php endif; ?>
<?php if ($ferr): ?>
<div class="rounded-2xl bg-rose-50 border border-rose-200 px-5 py-3 mb-4 text-rose-700"><?= e($ferr) ?></div>
<?php endif; ?>

<div class="grid gap-6 xl:grid-cols-[400px,1fr]">

    <!-- Add / Edit form -->
    <form method="post" enctype="multipart/form-data"
          class="pvn-card p-6 space-y-4 no-print self-start">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-lg"><?= $edit ? 'Edit stock item' : 'Add stock item' ?></h3>
            <?php if ($edit): ?>
            <a href="consignment_main_inventory.php" class="text-sm text-neutral-500">Cancel</a>
            <?php endif; ?>
        </div>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'save' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

        <input name="item_name"      value="<?= e($edit['item_name']      ?? '') ?>" class="pvn-input" placeholder="Item Name" required>
        <input name="reference_code" value="<?= e($edit['reference_code'] ?? '') ?>" class="pvn-input" placeholder="Reference Code">
        <input name="item_code"      value="<?= e($edit['item_code']      ?? '') ?>" class="pvn-input" placeholder="Item Code" required>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs text-neutral-500 mb-1 block">Total Stock</label>
                <input type="number" name="total_stock" value="<?= e((string)($edit['total_stock'] ?? '')) ?>"
                       class="pvn-input" placeholder="Total Stock" required>
            </div>
            <div>
                <label class="text-xs text-neutral-500 mb-1 block">Sale Price</label>
                <input type="number" step="0.01" name="sale_price" value="<?= e((string)($edit['sale_price'] ?? '')) ?>"
                       class="pvn-input" placeholder="Sale Price" required>
            </div>
        </div>
        <input type="file" name="image" class="pvn-input">
        <button class="w-full pvn-btn pvn-btn-primary">
            <?= $edit ? 'Update Item' : 'Save Item' ?>
        </button>
    </form>

    <!-- Stock table -->
    <div class="pvn-card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5 no-print">
            <div>
                <h3 class="font-semibold text-lg">Consignment stock master</h3>
                <p class="text-sm text-neutral-500">Available = Total stock minus all issued DOs. Sold Out items are highlighted.</p>
            </div>
            <form class="flex items-center gap-2">
                <span class="text-sm text-neutral-500">Per page</span>
                <select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm">
                    <?php foreach ([6,8,10,12] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $meta['per_page']===$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button>
            </form>
        </div>

        <!-- summary bar -->
        <?php
            $totStock   = array_sum(array_column($rows, 'total_stock'));
            $totAssigned= array_sum(array_column($rows, 'total_assigned'));
            $totOnHand  = array_sum(array_column($rows, 'on_hand'));
            $totSold    = array_sum(array_column($rows, 'total_sold'));
            $totAvail   = array_sum(array_column($rows, 'available_to_assign'));
        ?>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            <div class="rounded-2xl bg-neutral-50 p-3 border">
                <div class="text-xs text-neutral-500">Total Stock</div>
                <div class="font-semibold text-lg"><?= $totStock ?></div>
            </div>
            <div class="rounded-2xl bg-neutral-50 p-3 border">
                <div class="text-xs text-neutral-500">Issued (DO)</div>
                <div class="font-semibold text-lg"><?= $totAssigned ?></div>
            </div>
            <div class="rounded-2xl bg-emerald-50 p-3 border border-emerald-200">
                <div class="text-xs text-emerald-700">On Hand (Consignors)</div>
                <div class="font-semibold text-lg text-emerald-800"><?= $totOnHand ?></div>
            </div>
            <div class="rounded-2xl bg-sky-50 p-3 border border-sky-200">
                <div class="text-xs text-sky-700">Total Sold</div>
                <div class="font-semibold text-lg text-sky-800"><?= $totSold ?></div>
            </div>
            <div class="rounded-2xl bg-amber-50 p-3 border border-amber-200">
                <div class="text-xs text-amber-700">Available to Issue</div>
                <div class="font-semibold text-lg text-amber-800"><?= $totAvail ?></div>
            </div>
        </div>

        <div class="overflow-auto">
            <table class="pvn-table">
                <thead>
                    <tr class="border-b text-left text-neutral-500 text-xs uppercase tracking-wide">
                        <th class="py-3">Item</th>
                        <th>Code</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Issued</th>
                        <th class="text-right">On Hand</th>
                        <th class="text-right">Sold</th>
                        <th class="text-right">Available</th>
                        <th class="text-right">Price</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $soldOut = (int)$row['available_to_assign'] <= 0 && (int)$row['on_hand'] <= 0;
                    $rowClass = $soldOut ? 'bg-rose-50' : 'hover:bg-neutral-50';
                ?>
                <tr class="border-b last:border-b-0 <?= $rowClass ?>">
                    <td class="py-3 font-medium">
                        <?= e($row['item_name']) ?>
                        <?php if ($soldOut): ?>
                        <span class="ml-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700">Sold Out</span>
                        <?php elseif ((int)$row['available_to_assign'] === 0): ?>
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">Fully Issued</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-neutral-500"><?= e($row['item_code']) ?><div class="text-xs"><?= e($row['reference_code']) ?></div></td>
                    <td class="text-right font-semibold"><?= (int)$row['total_stock'] ?></td>
                    <td class="text-right text-neutral-500"><?= (int)$row['total_assigned'] ?></td>
                    <td class="text-right font-semibold text-emerald-700"><?= (int)$row['on_hand'] ?></td>
                    <td class="text-right text-sky-700"><?= (int)$row['total_sold'] ?></td>
                    <td class="text-right font-bold <?= (int)$row['available_to_assign'] > 0 ? 'text-amber-700' : 'text-neutral-400' ?>">
                        <?= (int)$row['available_to_assign'] ?>
                    </td>
                    <td class="text-right"><?= e(money($row['sale_price'])) ?></td>
                    <td class="no-print py-2">
                        <div class="flex gap-2 justify-end">
                            <a class="rounded-xl bg-amber-100 px-3 py-1 text-amber-800 text-xs" href="?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <a class="rounded-xl bg-rose-100 px-3 py-1 text-rose-700 text-xs"
                               href="?delete=<?= (int)$row['id'] ?>"
                               onclick="return confirm('Delete this stock item?')">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

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
<?php require __DIR__ . '/includes/footer.php'; ?>
