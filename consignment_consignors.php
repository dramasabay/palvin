<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Consignors';
if (is_post()) {
    $action = post_action();
    $data = [trim($_POST['store_name'] ?? ''), trim($_POST['branch_location'] ?? ''), trim($_POST['contact_person'] ?? ''), trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['address_text'] ?? ''), (float)($_POST['commission_rate'] ?? 5), trim($_POST['notes'] ?? '')];
    if ($action === 'save') {
        $pdo->prepare('INSERT INTO consignors (store_name, branch_location, contact_person, phone, email, address_text, commission_rate, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($data);
        flash('success', 'Consignor created.');
    }
    if ($action === 'update') {
        $data[] = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE consignors SET store_name=?, branch_location=?, contact_person=?, phone=?, email=?, address_text=?, commission_rate=?, notes=? WHERE id=?')->execute($data);
        flash('success', 'Consignor updated.');
    }
    redirect_to('consignment_consignors.php');
}
if (isset($_GET['delete'])) {
    $id=(int)$_GET['delete'];
    $hasSales=(int)db_value($pdo,'SELECT COUNT(*) FROM consignment_sales WHERE consignor_id=?',[$id]);
    if($hasSales>0){flash('error','Cannot delete this consignor because sales records exist.');} else {$pdo->prepare('DELETE FROM consignors WHERE id=?')->execute([$id]);flash('success','Consignor deleted.');}
    redirect_to('consignment_consignors.php');
}
$edit = isset($_GET['edit']) ? db_one($pdo, 'SELECT * FROM consignors WHERE id=?', [(int)$_GET['edit']]) : null;
$total=(int)db_value($pdo,'SELECT COUNT(*) FROM consignors');
$meta=paginate_meta($total,6,50);
$rows=db_all($pdo,'SELECT * FROM consignors ORDER BY id DESC LIMIT '.(int)$meta['per_page'].' OFFSET '.(int)$meta['offset']);
$view=($_GET['view'] ?? setting($pdo,'consignor_view_mode','grid'))==='list'?'list':'grid';
require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-6 xl:grid-cols-[420px,1fr]">
<form method="post" class="pvn-card p-6 space-y-4 no-print">
<div class="flex items-center justify-between"><h3 class="font-semibold text-lg"><?= $edit ? 'Edit consignor profile' : 'Create consignor profile' ?></h3><?php if ($edit): ?><a href="consignment_consignors.php" class="text-sm text-neutral-500">Cancel</a><?php endif; ?></div>
<input type="hidden" name="action" value="<?= $edit ? 'update' : 'save' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
<input name="store_name" value="<?= e($edit['store_name'] ?? '') ?>" class="pvn-input" placeholder="Store Name" required>
<input name="branch_location" value="<?= e($edit['branch_location'] ?? '') ?>" class="pvn-input" placeholder="Branch Location">
<input name="contact_person" value="<?= e($edit['contact_person'] ?? '') ?>" class="pvn-input" placeholder="Contact Person">
<input name="phone" value="<?= e($edit['phone'] ?? '') ?>" class="pvn-input" placeholder="Phone">
<input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" class="pvn-input" placeholder="Email">
<textarea name="address_text" class="pvn-input" placeholder="Address"><?= e($edit['address_text'] ?? '') ?></textarea>
<input type="number" step="0.01" name="commission_rate" class="pvn-input" value="<?= e((string)($edit['commission_rate'] ?? setting($pdo, 'default_consignor_commission', '5'))) ?>" placeholder="Commission %">
<textarea name="notes" class="pvn-input" placeholder="Notes"><?= e($edit['notes'] ?? '') ?></textarea>
<button class="pvn-btn pvn-btn-primary"><?= $edit ? 'Update Consignor' : 'Save Consignor' ?></button>
</form>
<div>
<div class="flex items-center justify-between mb-4 no-print"><h3 class="font-semibold text-lg">Consignor directory</h3><div class="flex gap-2 items-center"><form class="flex items-center gap-2"><select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?php foreach (page_size_options([6,8,10,'all']) as $opt): ?><option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option><?php endforeach; ?></select><input type="hidden" name="view" value="<?= e($view) ?>"><button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button></form><a href="?view=grid&per_page=<?= $meta['per_page'] ?>" class="rounded-xl border px-3 py-2 <?= $view==='grid'?'bg-neutral-950 text-white border-neutral-950':'' ?>">Grid</a><a href="?view=list&per_page=<?= $meta['per_page'] ?>" class="rounded-xl border px-3 py-2 <?= $view==='list'?'bg-neutral-950 text-white border-neutral-950':'' ?>">List</a></div></div>
<?php if($view==='list'): ?>
<div class="pvn-card p-6 overflow-auto"><table class="pvn-table"><thead><tr class="border-b text-left text-neutral-500"><th class="py-3">Store</th><th>Contact</th><th>Commission</th><th>Status</th><th class="no-print">Action</th></tr></thead><tbody><?php foreach($rows as $row): $uncleared=consignor_unclaimed_status($pdo,(int)$row['id']); ?><tr class="border-b last:border-b-0"><td class="py-3"><div class="font-medium"><?= e($row['store_name']) ?></div><div class="text-xs text-neutral-500"><?= e($row['branch_location']) ?></div></td><td><?= e($row['contact_person']) ?><div class="text-xs text-neutral-500"><?= e($row['phone']) ?></div></td><td><?= e((string)$row['commission_rate']) ?>%</td><td><?= $uncleared ? '<span class="text-amber-700">'.e(count($uncleared).' pending').'</span>' : '<span class="text-emerald-700">Clear</span>' ?></td><td class="no-print"><div class="flex gap-2"><a class="pvn-badge pvn-badge-amber" href="?edit=<?= (int)$row['id'] ?>">Edit</a><a class="pvn-badge pvn-badge-red" data-confirm="Delete consignor?" href="?delete=<?= (int)$row['id'] ?>">Delete</a></div></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?>
<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4"><?php foreach($rows as $row): $uncleared=consignor_unclaimed_status($pdo,(int)$row['id']); ?><div class="pvn-card p-5 border border-neutral-200"><div class="flex justify-between gap-3"><div><div class="font-semibold text-lg"><?= e($row['store_name']) ?></div><div class="text-sm text-neutral-500"><?= e($row['branch_location']) ?></div></div><div class="rounded-full bg-neutral-100 px-3 py-1 text-sm"><?= e((string)$row['commission_rate']) ?>%</div></div><div class="mt-4 text-sm space-y-1"><div>Contact: <?= e($row['contact_person']) ?></div><div>Phone: <?= e($row['phone']) ?></div><div>Email: <?= e($row['email']) ?></div></div><div class="mt-4 rounded-2xl <?= $uncleared ? 'bg-amber-50 border border-amber-200' : 'bg-emerald-50 border border-emerald-200' ?> p-3 text-sm"><?= $uncleared ? e(count($uncleared) . ' uncleared monthly claim(s)') : 'All invoices claimed / clear.' ?></div><div class="mt-4 flex gap-2 text-sm"><a class="pvn-badge pvn-badge-amber" href="?edit=<?= (int)$row['id'] ?>">Edit</a><a class="pvn-badge pvn-badge-red" data-confirm="Delete consignor?" href="?delete=<?= (int)$row['id'] ?>">Delete</a></div></div><?php endforeach; ?></div>
<?php endif; ?>
<div class="mt-4 flex flex-wrap gap-2 no-print"><?php foreach (pagination_links($meta) as $link): ?><a href="<?= e($link['href'].'&view='.$view) ?>" class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>"><?= e($link['label']) ?></a><?php endforeach; ?></div>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>