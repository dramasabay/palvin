<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = t('nav_order_hist');
$total = (int)db_value($pdo, 'SELECT COUNT(*) FROM retail_orders');
$meta = paginate_meta($total, 10, 100);
$rows = db_all($pdo, 'SELECT ro.*, COALESCE(SUM(roi.quantity),0) qty FROM retail_orders ro LEFT JOIN retail_order_items roi ON roi.order_id = ro.id GROUP BY ro.id, ro.order_no, ro.customer_name, ro.contact_number, ro.address_text, ro.deliver_by, ro.payment_type, ro.subtotal, ro.discount_amount, ro.grand_total, ro.customer_type, ro.order_date ORDER BY ro.id DESC LIMIT '.(int)$meta['per_page'].' OFFSET '.(int)$meta['offset']);
require __DIR__ . '/includes/header.php';
?>
<div class="pvn-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 border-b border-slate-100">
        <h3 class="font-semibold text-slate-800"><?= t('nav_order_hist') ?></h3>
        <div class="flex items-center gap-2">
            <form class="flex items-center gap-2">
                <select name="per_page" class="pvn-input pvn-select text-sm" style="padding:7px 32px 7px 12px;">
                    <?php foreach (page_size_options(['all',10,20,50]) as $opt): ?>
                    <option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all']?'all':$meta['per_page']))===(string)$opt?'selected':'' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button>
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="pvn-table">
            <thead>
                <tr>
                    <th><?= t('date') ?></th>
                    <th><?= t('order_no') ?></th>
                    <th><?= t('customer_name') ?></th>
                    <th><?= t('phone') ?></th>
                    <th><?= t('quantity') ?></th>
                    <th><?= t('grand_total') ?></th>
                    <th><?= t('payment_type') ?></th>
                    <th><?= t('customer_type') ?></th>
                    <th class="no-print"><?= t('invoice') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-xs text-slate-500"><?= e(date('d M Y H:i', strtotime($row['order_date']))) ?></td>
                    <td><span class="font-mono text-xs text-indigo-700"><?= e($row['order_no']) ?></span></td>
                    <td class="font-medium"><?= e($row['customer_name']) ?></td>
                    <td class="text-slate-500"><?= e($row['contact_number']) ?></td>
                    <td><span class="pvn-badge pvn-badge-blue"><?= e((string)$row['qty']) ?></span></td>
                    <td class="font-semibold"><?= money_dual($pdo, $row['grand_total'], true) ?></td>
                    <td><span class="pvn-badge pvn-badge-green"><?= e($row['payment_type']) ?></span></td>
                    <td><span class="pvn-badge <?= $row['customer_type']==='Old'?'pvn-badge-purple':'pvn-badge-blue' ?>"><?= e($row['customer_type']) ?></span></td>
                    <td class="no-print">
                        <a href="invoice.php?type=retail&id=<?= (int)$row['id'] ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center py-10 text-slate-400"><?= t('no_data') ?></td></tr>
                <?php endif; ?>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
