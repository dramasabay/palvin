<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = t('nav_retail_inv');
if (is_post()) {
    $action = post_action();
    $imagePath = upload_file($_FILES['image'] ?? [], 'products');
    if ($action === 'save') {
        $pdo->prepare('INSERT INTO retail_inventory (restock_date,item_name,item_code,reference_code,description_text,quantity,price,image_path) VALUES (?,?,?,?,?,?,?,?)')->execute([$_POST['restock_date']?:date('Y-m-d'),trim($_POST['item_name']??''),trim($_POST['item_code']??''),trim($_POST['reference_code']??''),trim($_POST['description_text']??''),(int)($_POST['quantity']??0),(float)($_POST['price']??0),$imagePath]);
        flash('success', 'Retail item added.');
    }
    if ($action === 'update') {
        $id = (int)($_POST['id']??0);
        $current = db_one($pdo, 'SELECT image_path FROM retail_inventory WHERE id=?', [$id]);
        $pdo->prepare('UPDATE retail_inventory SET restock_date=?,item_name=?,item_code=?,reference_code=?,description_text=?,quantity=?,price=?,image_path=? WHERE id=?')->execute([$_POST['restock_date']?:date('Y-m-d'),trim($_POST['item_name']??''),trim($_POST['item_code']??''),trim($_POST['reference_code']??''),trim($_POST['description_text']??''),(int)($_POST['quantity']??0),(float)($_POST['price']??0),$imagePath?:($current['image_path']??null),$id]);
        flash('success', 'Retail item updated.');
    }
    redirect_to('retail_inventory.php');
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $used = (int)db_value($pdo,'SELECT COUNT(*) FROM retail_order_items WHERE inventory_id=?',[$id]);
    if ($used > 0) flash('error','Cannot delete: item has order history.');
    else { $pdo->prepare('DELETE FROM retail_inventory WHERE id=?')->execute([$id]); flash('success','Item deleted.'); }
    redirect_to('retail_inventory.php');
}
$edit = isset($_GET['edit']) ? db_one($pdo,'SELECT * FROM retail_inventory WHERE id=?',[(int)$_GET['edit']]) : null;
$total = (int)db_value($pdo,'SELECT COUNT(*) FROM retail_inventory');
$meta = paginate_meta($total, 10, 50);
$rows = db_all($pdo,'SELECT * FROM retail_inventory ORDER BY id DESC LIMIT '.(int)$meta['per_page'].' OFFSET '.(int)$meta['offset']);
require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-6 xl:grid-cols-[380px,1fr]">
    <form method="post" enctype="multipart/form-data" class="pvn-card p-6 space-y-4 no-print">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-slate-800"><?= $edit ? t('edit') : t('add') ?> Item</h3>
            <?php if ($edit): ?><a href="retail_inventory.php" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?= t('cancel') ?></a><?php endif; ?>
        </div>
        <input type="hidden" name="action" value="<?= $edit?'update':'save' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Restock Date</label>
            <input type="date" name="restock_date" value="<?= e($edit['restock_date']??date('Y-m-d')) ?>" class="pvn-input" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1"><?= t('name') ?></label>
            <input name="item_name" value="<?= e($edit['item_name']??'') ?>" class="pvn-input" placeholder="Item Name" required>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1">Item Code</label>
                <input name="item_code" value="<?= e($edit['item_code']??'') ?>" class="pvn-input" placeholder="SKU-001" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1">Reference Code</label>
                <input name="reference_code" value="<?= e($edit['reference_code']??'') ?>" class="pvn-input" placeholder="REF-001">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
            <textarea name="description_text" class="pvn-input" rows="2"><?= e($edit['description_text']??'') ?></textarea>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1"><?= t('quantity') ?></label>
                <input type="number" name="quantity" value="<?= e((string)($edit['quantity']??'')) ?>" class="pvn-input" placeholder="0" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1"><?= t('price') ?> (USD)</label>
                <input type="number" step="0.01" name="price" value="<?= e((string)($edit['price']??'')) ?>" class="pvn-input" placeholder="0.00" required>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Product Image</label>
            <?php if (!empty($edit['image_path'])): ?><img src="<?= e($edit['image_path']) ?>" class="h-20 rounded-xl mb-2 object-cover"><?php endif; ?>
            <input type="file" name="image" class="pvn-input" accept="image/*">
        </div>
        <button type="submit" class="pvn-btn pvn-btn-primary w-full justify-center">
            <?= $edit ? t('save') : t('add') ?> Item
        </button>
    </form>

    <div class="pvn-card overflow-hidden">
        <div class="flex items-center justify-between p-5 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Retail Stock (<?= $total ?>)</h3>
            <form class="flex items-center gap-2">
                <select name="per_page" class="pvn-input pvn-select text-sm" style="padding:7px 32px 7px 12px;">
                    <?php foreach ([8,10,20,50] as $opt): ?><option value="<?= $opt ?>" <?= $meta['per_page']===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?>
                </select>
                <button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Go</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="pvn-table">
                <thead>
                    <tr>
                        <th>Restock</th><th>Item</th><th>Code</th><th><?= t('quantity') ?></th><th><?= t('price') ?></th><th class="no-print"><?= t('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-xs text-slate-400"><?= e($row['restock_date']) ?></td>
                        <td>
                            <div class="flex items-center gap-3">
                                <?php if ($row['image_path']): ?>
                                <img src="<?= e($row['image_path']) ?>" class="w-10 h-10 rounded-xl object-cover flex-shrink-0">
                                <?php endif; ?>
                                <div>
                                    <div class="font-medium text-slate-800"><?= e($row['item_name']) ?></div>
                                    <?php if ($row['description_text']): ?><div class="text-xs text-slate-400 truncate max-w-[200px]"><?= e($row['description_text']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="font-mono text-xs text-indigo-700"><?= e($row['item_code']) ?></td>
                        <td>
                            <span class="pvn-badge <?= (int)$row['quantity'] <= 5 ? 'pvn-badge-amber' : 'pvn-badge-green' ?>">
                                <?= (int)$row['quantity'] ?>
                            </span>
                        </td>
                        <td class="font-semibold"><?= money_dual($pdo, $row['price'], true) ?></td>
                        <td class="no-print">
                            <div class="flex gap-2">
                                <a href="?edit=<?= (int)$row['id'] ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?= t('edit') ?></a>
                                <a href="?delete=<?= (int)$row['id'] ?>" class="pvn-btn pvn-btn-danger pvn-btn-sm" data-confirm="<?= e(t('confirm_delete')) ?>"><?= t('delete') ?></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-center py-10 text-slate-400"><?= t('no_data') ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php $plinks = pagination_links($meta); if ($plinks): ?>
        <div class="p-4 flex flex-wrap gap-2">
            <?php foreach ($plinks as $link): ?>
            <a href="<?= e($link['href']) ?>" class="pvn-btn pvn-btn-sm <?= $link['active']?'pvn-btn-primary':'pvn-btn-secondary' ?>"><?= e($link['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
