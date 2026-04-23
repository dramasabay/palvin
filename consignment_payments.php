<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Consignment Payments';
update_overdue_payouts($pdo);
if (isset($_GET['clear'])) {
    $id = (int)$_GET['clear'];
    if ($id > 0) {
        try {
            $pdo->prepare("UPDATE consignment_payouts SET status='claimed', claimed_at=NOW(), claimed_by_user_id=? WHERE id=? AND status != 'claimed'")
                ->execute([auth_user()['id'] ?? null, $id]);
            flash('success', 'Consignor payout marked as claimed.');
        } catch (Throwable $e) {
            flash('error', 'Failed to claim payout: ' . $e->getMessage());
        }
    }
    redirect_to('consignment_payments.php');
}
$total = (int)db_value($pdo, 'SELECT COUNT(*) FROM consignment_payouts');
$meta = paginate_meta($total, 8, 100);
$rows = db_all($pdo, 'SELECT p.*, c.store_name, c.branch_location, c.commission_rate, u.full_name claimed_by_name FROM consignment_payouts p JOIN consignors c ON c.id = p.consignor_id LEFT JOIN users u ON u.id = p.claimed_by_user_id ORDER BY FIELD(p.status, "overdue", "pending", "claimed"), p.claim_month ASC LIMIT ' . (int)$meta['per_page'] . ' OFFSET ' . (int)$meta['offset']);
require __DIR__ . '/includes/header.php';
?>
<div class="pvn-card p-6 border border-slate-200/70 overflow-auto">
    <div class="flex flex-wrap justify-between gap-3 mb-4 no-print"><h3 class="font-semibold text-lg">Consignment payments</h3><form class="flex items-center gap-2"><select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?php foreach (page_size_options(['all',8,10,12]) as $opt): ?><option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option><?php endforeach; ?></select><button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button></form></div>
    <table class="pvn-table">
        <thead><tr class="border-b text-left text-slate-500"><th class="py-3">Claim Month</th><th>Invoice</th><th>Consignor</th><th>Branch</th><th>Payout Due</th><th>Status</th><th>Claimed At</th><th>Claim By</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="border-b last:border-b-0">
                    <td class="py-3"><?= e($row['claim_month']) ?></td>
                    <td><?= e($row['invoice_no']) ?></td>
                    <td><?= e($row['store_name']) ?></td>
                    <td><?= e($row['branch_location']) ?></td>
                    <td class="font-medium"><?= money_dual($pdo, $row['payout_due'], true) ?></td>
                    <td><span class="rounded-full px-3 py-1 text-xs <?= $row['status']==='overdue' ? 'bg-amber-100 text-amber-800' : ($row['status']==='claimed' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700') ?>"><?= e(strtoupper($row['status'])) ?></span></td>
                    <td><?= e($row['claimed_at']) ?></td><td><?= e($row['claimed_by_name']) ?></td>
                    <td class="no-print"><?php if ($row['status'] !== 'claimed'): ?><a class="pvn-badge pvn-badge-green" href="?clear=<?= (int)$row['id'] ?>">Mark Claimed</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="mt-4 flex flex-wrap gap-2 no-print"><?php foreach (pagination_links($meta) as $link): ?><a href="<?= e($link['href']) ?>" class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>"><?= e($link['label']) ?></a><?php endforeach; ?></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
