<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = t('dashboard');
update_overdue_payouts($pdo);
[$todayStart, $todayEnd] = report_datetime_bounds(date('Y-m-d'), date('Y-m-d'));
$stats = [
    'retail_stock'       => db_value($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM retail_inventory'),
    'retail_sales'       => db_value($pdo, 'SELECT COALESCE(SUM(grand_total),0) FROM retail_orders WHERE order_date >= ? AND order_date < ?', [$todayStart, $todayEnd]),
    'consignment_stock'  => db_value($pdo, 'SELECT COALESCE(SUM(stock_balance),0) FROM consignment_inventory'),
    'unclaimed'          => db_value($pdo, "SELECT COALESCE(SUM(payout_due),0) FROM consignment_payouts WHERE status IN ('pending','overdue')"),
];
$alerts = db_all($pdo, "SELECT c.store_name, p.invoice_no, p.claim_month, p.payout_due FROM consignment_payouts p JOIN consignors c ON c.id = p.consignor_id WHERE p.status='overdue' ORDER BY p.claim_month ASC LIMIT 10");
require __DIR__ . '/includes/header.php';
?>

<!-- Stat cards -->
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    <div class="pvn-card p-6">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= t('retail_stock') ?></span>
            <span class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600 text-lg">▦</span>
        </div>
        <div class="text-3xl font-bold text-slate-800"><?= number_format((int)$stats['retail_stock']) ?></div>
        <div class="text-xs text-slate-400 mt-1"><?= t('nav_retail_inv') ?></div>
    </div>
    <div class="pvn-card p-6">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= t('today_sales') ?></span>
            <span class="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 text-lg">$</span>
        </div>
        <div class="text-2xl font-bold text-slate-800"><?= money_dual($pdo, $stats['retail_sales'], true) ?></div>
        <div class="text-xs text-slate-400 mt-1"><?= date('d M Y') ?></div>
    </div>
    <div class="pvn-card p-6">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= t('consignment_on_hand') ?></span>
            <span class="w-9 h-9 rounded-xl bg-violet-100 flex items-center justify-center text-violet-600 text-lg">◫</span>
        </div>
        <div class="text-3xl font-bold text-slate-800"><?= number_format((int)$stats['consignment_stock']) ?></div>
        <div class="text-xs text-slate-400 mt-1"><?= t('nav_consignment') ?></div>
    </div>
    <div class="pvn-card p-6">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= t('unclaimed_payout') ?></span>
            <span class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600 text-lg">!</span>
        </div>
        <div class="text-2xl font-bold text-slate-800"><?= money_dual($pdo, $stats['unclaimed'], true) ?></div>
        <div class="text-xs text-slate-400 mt-1"><?= t('nav_payments') ?></div>
    </div>
</div>

<div class="grid gap-6 xl:grid-cols-3">
    <!-- Overdue alerts -->
    <div class="xl:col-span-2 pvn-card overflow-hidden">
        <div class="flex items-center justify-between p-6 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= t('overdue_claims') ?></h3>
            <?php if ($alerts): ?>
            <span class="pvn-badge pvn-badge-red"><?= count($alerts) ?></span>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <?php if ($alerts): ?>
            <div class="grid sm:grid-cols-2 gap-3">
                <?php foreach ($alerts as $row): ?>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="font-semibold text-slate-800"><?= e($row['store_name']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Invoice: <?= e($row['invoice_no']) ?></div>
                    <div class="text-xs text-slate-500">Claim: <?= e($row['claim_month']) ?></div>
                    <div class="mt-2 font-semibold text-amber-700"><?= money_dual($pdo, $row['payout_due'], true) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="flex flex-col items-center justify-center py-10 text-slate-400">
                <span class="text-4xl mb-3">✓</span>
                <span class="text-sm"><?= t('no_overdue') ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick links -->
    <div class="pvn-card p-6">
        <h3 class="font-semibold text-slate-800 mb-4"><?= t('quick_links') ?></h3>
        <div class="space-y-2">
            <a href="retail_orders.php" class="flex items-center gap-3 p-4 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-indigo-50 transition group">
                <span class="text-2xl">◉</span>
                <span class="font-medium text-slate-700 group-hover:text-indigo-700"><?= t('open_pos') ?></span>
            </a>
            <a href="consignment_assign.php" class="flex items-center gap-3 p-4 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-indigo-50 transition group">
                <span class="text-2xl">◐</span>
                <span class="font-medium text-slate-700 group-hover:text-indigo-700"><?= t('assign_stock') ?></span>
            </a>
            <a href="settings.php" class="flex items-center gap-3 p-4 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-indigo-50 transition group">
                <span class="text-2xl">⊙</span>
                <span class="font-medium text-slate-700 group-hover:text-indigo-700"><?= t('system_settings') ?></span>
            </a>
            <a href="backup.php" class="flex items-center gap-3 p-4 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-indigo-50 transition group">
                <span class="text-2xl">◴</span>
                <span class="font-medium text-slate-700 group-hover:text-indigo-700"><?= t('nav_backup') ?></span>
            </a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
