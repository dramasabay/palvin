<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = t('pos_orders');
$fixedDiscount    = (float)setting($pdo, 'fixed_discount_price', '0');
$exchangeRate     = (float)setting($pdo, 'exchange_rate', '4100');
if ($exchangeRate <= 0) $exchangeRate = 4100;
$currencyDisplay  = setting($pdo, 'currency_display', 'both');
$posDisplayMode   = setting($pdo, 'pos_display_mode', 'grid'); // grid | list

if (is_post()) {
    $items  = $_POST['item_id'] ?? [];
    $qtys   = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];
    $notes  = $_POST['line_note'] ?? [];
    $pdo->beginTransaction();
    try {
        $subtotal = 0; $validLines = 0; $checked = [];
        foreach ($items as $i => $itemId) {
            $itemId = (int)$itemId; $qty = max(0,(int)($qtys[$i]??0)); $price = max(0,(float)($prices[$i]??0));
            if ($itemId > 0 && $qty > 0) {
                $inventory = db_one($pdo, 'SELECT id, item_name, quantity FROM retail_inventory WHERE id = ? FOR UPDATE', [$itemId]);
                if (!$inventory) throw new RuntimeException('Item not found.');
                if ($qty > (int)$inventory['quantity']) throw new RuntimeException($inventory['item_name'] . ' has only ' . (int)$inventory['quantity'] . ' left.');
                $checked[$i] = $inventory; $subtotal += $price * $qty; $validLines++;
            }
        }
        if ($validLines === 0) throw new RuntimeException('Please add at least one item.');
        $discountType  = trim((string)($_POST['discount_type'] ?? 'amount'));
        $discountValue = max(0,(float)($_POST['discount_value'] ?? 0));
        if ($discountType === 'percent')       $discount = $subtotal * max(0, min(100, $discountValue)) / 100;
        elseif ($discountType === 'fixed')     $discount = $fixedDiscount;
        else                                   $discount = $discountValue;
        $grand       = max(0, $subtotal - $discount);
        $orderNo     = generate_document_number($pdo, 'retail_orders', 'order_no', 'RO');
        $customerName= trim((string)($_POST['customer_name'] ?? 'Walk-in Customer')) ?: 'Walk-in Customer';
        $contact     = trim((string)($_POST['contact_number'] ?? ''));
        $addressText = trim((string)($_POST['address_text'] ?? ''));
        $deliverBy   = trim((string)($_POST['deliver_by'] ?? ''));
        $paymentType = trim((string)($_POST['payment_type'] ?? 'Cash')) ?: 'Cash';
        $customerType= trim((string)($_POST['customer_type'] ?? '')) ?: customer_type_label($pdo, $contact);
        $pdo->prepare('INSERT INTO retail_orders (order_no, customer_name, contact_number, address_text, deliver_by, payment_type, subtotal, discount_amount, grand_total, customer_type, order_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$orderNo, $customerName, $contact, $addressText, $deliverBy, $paymentType, $subtotal, $discount, $grand, $customerType]);
        $orderId   = (int)$pdo->lastInsertId();
        $itemStmt  = $pdo->prepare('INSERT INTO retail_order_items (order_id, inventory_id, item_name, quantity, unit_price, total_price, line_note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE retail_inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
        $telegramItems = [];
        foreach ($items as $i => $itemId) {
            $itemId = (int)$itemId; $qty = max(0,(int)($qtys[$i]??0)); $price = max(0,(float)($prices[$i]??0));
            if ($itemId <= 0 || $qty <= 0) continue;
            $inventory = $checked[$i]; $stockStmt->execute([$qty, $itemId, $qty]);
            if ($stockStmt->rowCount() < 1) throw new RuntimeException($inventory['item_name'] . ' stock changed. Try again.');
            $lineTotal = $qty * $price; $lineNote = trim((string)($notes[$i]??''));
            $itemStmt->execute([$orderId, $itemId, $inventory['item_name'], $qty, $price, $lineTotal, $lineNote]);
            $telegramItems[] = ['item_name'=>$inventory['item_name'],'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$lineTotal,'line_note'=>$lineNote];
        }
        $pdo->commit();
        if (telegram_enabled_for_channel($pdo, 'retail')) {
            $lines = ['Retail POS invoice created','Company: '.setting($pdo,'company_name','PALVIN'),'Invoice: '.$orderNo,'Time: '.date('Y-m-d H:i:s').' ('.date_default_timezone_get().')','Customer: '.$customerName,'Phone: '.($contact?:'-'),'Customer Type: '.$customerType,'Payment: '.$paymentType,'Subtotal: '.money($subtotal),'Discount: '.money($discount),'Grand Total: '.money($grand),'Grand Total KHR: &#x17DB;'.number_format($grand*$exchangeRate,0)];
            if ($deliverBy) $lines[] = 'Deliver By: '.$deliverBy;
            if ($addressText) $lines[] = 'Address: '.preg_replace('/\s+/',' ',$addressText);
            $lines[] = 'Sold By: '.(auth_user()['full_name']??'System'); $lines[] = 'Items:';
            foreach ($telegramItems as $item) { $l='- '.$item['item_name'].' x'.$item['quantity'].' @ '.money($item['unit_price']).' = '.money($item['line_total']); if($item['line_note'])$l.=' ['.$item['line_note'].']'; $lines[]=$l; }
            $r = send_telegram_message($pdo, telegram_message_from_lines($lines));
            if (!$r['ok']) flash('warning', 'Order saved, but Telegram alert failed: ' . $r['error']);
        }
        flash('success', 'Order created: ' . $orderNo);
        redirect_to('retail_orders.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect_to('retail_orders.php');
    }
}

$total = (int)db_value($pdo, 'SELECT COUNT(*) FROM retail_inventory WHERE quantity > 0');
$meta  = paginate_meta($total, 6, 50);
$inventory = db_all($pdo, 'SELECT * FROM retail_inventory WHERE quantity > 0 ORDER BY item_name ASC LIMIT '.(int)$meta['per_page'].' OFFSET '.(int)$meta['offset']);
require __DIR__ . '/includes/header.php';
?>

<div class="grid gap-5 xl:grid-cols-[1fr,430px]">

    <!-- ══ Product Panel ══ -->
    <section class="pvn-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 border-b border-slate-100">
            <div>
                <h3 class="font-semibold text-slate-800"><?= t('nav_retail_inv') ?></h3>
                <p class="text-xs text-slate-400"><?= t('nav_pos') ?> — <?= t('retail_label') ?> · click product to add</p>
            </div>
            <div class="flex items-center gap-2">
                <!-- View mode toggle -->
                <div class="flex rounded-xl border border-slate-200 overflow-hidden">
                    <button type="button" id="btn-grid" onclick="setDisplayMode('grid')"
                        class="px-3 py-1.5 text-sm transition <?= $posDisplayMode==='grid' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50' ?>">
                        ⊞
                    </button>
                    <button type="button" id="btn-list" onclick="setDisplayMode('list')"
                        class="px-3 py-1.5 text-sm border-l border-slate-200 transition <?= $posDisplayMode==='list' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50' ?>">
                        ☰
                    </button>
                </div>
                <form class="flex items-center gap-2">
                    <input type="hidden" name="view" value="<?= e($posDisplayMode) ?>">
                    <select name="per_page" class="pvn-input pvn-select text-sm" style="padding:7px 32px 7px 12px;">
                        <?php foreach (page_size_options([6,8,12,'all']) as $opt): ?>
                        <option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all']?'all':$meta['per_page']))===(string)$opt?'selected':'' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button>
                </form>
                <a href="retail_inventory.php" class="pvn-btn pvn-btn-secondary pvn-btn-sm">Manage Stock</a>
            </div>
        </div>

        <div class="p-5">
            <!-- GRID VIEW -->
            <div id="pos-grid" class="<?= $posDisplayMode==='list' ? 'hidden' : '' ?>">
                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
                    <?php foreach ($inventory as $item): ?>
                    <button type="button" class="product-card text-left rounded-2xl border border-slate-200 p-3 hover:border-indigo-400 hover:shadow-lg transition-all duration-150 group"
                        data-id="<?= (int)$item['id'] ?>" data-name="<?= e($item['item_name']) ?>"
                        data-price="<?= e((string)$item['price']) ?>" data-stock="<?= e((string)$item['quantity']) ?>">
                        <?php if ($item['image_path']): ?>
                        <img src="<?= e($item['image_path']) ?>" class="h-28 w-full object-cover rounded-xl mb-2">
                        <?php else: ?>
                        <div class="h-28 rounded-xl bg-slate-100 mb-2 flex items-center justify-center text-slate-300 text-3xl">▦</div>
                        <?php endif; ?>
                        <div class="font-semibold text-sm text-slate-800 truncate leading-tight"><?= e($item['item_name']) ?></div>
                        <div class="text-xs text-slate-400 truncate"><?= e($item['item_code']) ?></div>
                        <div class="flex items-center justify-between mt-1.5">
                            <div>
                                <div class="font-bold text-indigo-700 text-sm"><?= e(money($item['price'])) ?></div>
                                <?php if ($currencyDisplay !== 'usd'): ?>
                                <div class="text-xs text-slate-400">&#x17DB;<?= number_format((float)$item['price'] * $exchangeRate, 0) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="pvn-badge <?= (int)$item['quantity'] <= 5 ? 'pvn-badge-amber' : 'pvn-badge-green' ?>">
                                <?= (int)$item['quantity'] ?>
                            </span>
                        </div>
                    </button>
                    <?php endforeach; ?>
                    <?php if (!$inventory): ?>
                    <div class="col-span-full text-center py-12 text-slate-400"><?= t('no_data') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LIST VIEW -->
            <div id="pos-list" class="<?= $posDisplayMode==='grid' ? 'hidden' : '' ?>">
                <table class="pvn-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Code</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Stock</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $item): ?>
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <?php if ($item['image_path']): ?>
                                    <img src="<?= e($item['image_path']) ?>" class="h-10 w-10 rounded-lg object-cover flex-shrink-0">
                                    <?php else: ?>
                                    <div class="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-300 flex-shrink-0">▦</div>
                                    <?php endif; ?>
                                    <div class="font-medium text-slate-800 text-sm"><?= e($item['item_name']) ?></div>
                                </div>
                            </td>
                            <td class="text-xs text-slate-400"><?= e($item['item_code']) ?></td>
                            <td class="text-right">
                                <div class="font-bold text-indigo-700 text-sm"><?= e(money($item['price'])) ?></div>
                                <?php if ($currencyDisplay !== 'usd'): ?>
                                <div class="text-xs text-slate-400">&#x17DB;<?= number_format((float)$item['price'] * $exchangeRate, 0) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <span class="pvn-badge <?= (int)$item['quantity'] <= 5 ? 'pvn-badge-amber' : 'pvn-badge-green' ?>">
                                    <?= (int)$item['quantity'] ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <button type="button" class="product-card pvn-btn pvn-btn-primary pvn-btn-sm"
                                    data-id="<?= (int)$item['id'] ?>" data-name="<?= e($item['item_name']) ?>"
                                    data-price="<?= e((string)$item['price']) ?>" data-stock="<?= e((string)$item['quantity']) ?>">
                                    + Add
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$inventory): ?>
                        <tr><td colspan="5" class="text-center py-8 text-slate-400"><?= t('no_data') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php $plinks = pagination_links($meta); if ($plinks): ?>
            <div class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($plinks as $link): ?>
                <a href="<?= e($link['href']) ?>" class="pvn-btn pvn-btn-sm <?= $link['active'] ? 'pvn-btn-primary' : 'pvn-btn-secondary' ?>"><?= e($link['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ POS Cart ══ -->
    <form method="post" class="sticky top-20 self-start rounded-2xl text-white shadow-2xl overflow-hidden" style="background:#0f0f13; border:1px solid rgba(255,255,255,0.08);">
        <!-- Cart header -->
        <div class="flex items-center justify-between p-5 border-b" style="border-color:rgba(255,255,255,0.08);">
            <div>
                <h3 class="font-bold text-lg">
                    <?= t('pos_orders') ?>
                    <span id="cartCount" class="hidden ml-2 text-xs bg-indigo-500 text-white rounded-full px-2 py-0.5">0</span>
                </h3>
                <div class="text-xs mt-0.5" style="color:rgba(255,255,255,0.3);"><?= t('retail_label') ?> · <?= t('consignment_label') ?></div>
            </div>
        </div>

        <!-- Customer info -->
        <div class="p-4 grid grid-cols-2 gap-2.5 border-b" style="border-color:rgba(255,255,255,0.06);">
            <input name="customer_name" class="pvn-pos-input col-span-2" placeholder="<?= t('customer_name') ?>" value="Walk-in Customer">
            <input name="contact_number" class="pvn-pos-input" placeholder="<?= t('phone') ?>">
            <select name="payment_type" class="pvn-pos-input"><?php foreach (['Cash','ABA','Wing','ACLEDA','Bank Transfer'] as $pt): ?><option><?= $pt ?></option><?php endforeach; ?></select>
            <input name="deliver_by" class="pvn-pos-input" placeholder="Deliver By">
            <select name="customer_type" class="pvn-pos-input">
                <option value="">Auto Detect</option>
                <option>Member</option><option>Old</option><option>New</option>
            </select>
            <textarea name="address_text" class="pvn-pos-input col-span-2 resize-none" rows="2" placeholder="<?= t('address') ?>"></textarea>
        </div>

        <!-- Cart lines -->
        <div id="cartEmptyMsg" class="text-center py-8 text-sm" style="color:rgba(255,255,255,0.3);">
            No items yet. Tap a product to add.
        </div>
        <div id="cartLines" class="space-y-2 px-4 max-h-64 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.15) transparent;"></div>

        <!-- Discount -->
        <div class="p-4 grid grid-cols-2 gap-2.5 border-t" style="border-color:rgba(255,255,255,0.06);">
            <select name="discount_type" id="discountType" class="pvn-pos-input">
                <?php foreach (discount_types() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <input name="discount_value" id="discountValue" type="number" step="0.01" value="0" class="pvn-pos-input" placeholder="<?= t('discount') ?>">
        </div>

        <!-- Totals -->
        <div class="mx-4 mb-4 rounded-xl p-4 space-y-2" style="background:rgba(255,255,255,0.05);">
            <div class="flex justify-between text-sm" style="color:rgba(255,255,255,0.6);">
                <span><?= t('subtotal') ?></span><span id="subtotalView">$0.00</span>
            </div>
            <div class="flex justify-between text-sm" style="color:rgba(255,255,255,0.6);">
                <span><?= t('discount') ?></span><span id="discountView" class="text-red-400">$0.00</span>
            </div>
            <div class="pt-2 border-t flex justify-between" style="border-color:rgba(255,255,255,0.1);">
                <span class="font-bold text-lg"><?= t('grand_total') ?></span>
                <div class="text-right">
                    <div class="font-bold text-xl text-indigo-300" id="grandView">$0.00</div>
                    <?php if ($currencyDisplay !== 'usd'): ?>
                    <div class="text-xs mt-0.5" style="color:rgba(255,255,255,0.4);" id="grandKhrView">&#x17DB;0</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="px-4 pb-4">
            <button class="w-full pvn-btn pvn-btn-primary justify-center py-3 text-base">
                ✓ <?= t('place_order') ?>
            </button>
        </div>
    </form>
</div>

<style>
.pvn-pos-input {
    width:100%; padding:9px 12px; border-radius:11px; font-size:13px;
    background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1);
    color:#fff; outline:none; transition:border-color 0.15s;
    font-family: inherit;
}
.pvn-pos-input:focus { border-color:#6366f1; }
.pvn-pos-input::placeholder { color:rgba(255,255,255,0.3); }
.pvn-pos-input option { background:#1e1b4b; color:#fff; }
</style>

<script>
const cartLines     = document.getElementById('cartLines');
const fixedDiscount = <?= json_encode($fixedDiscount) ?>;
const exchangeRate  = <?= json_encode($exchangeRate) ?>;
const currencyDisplay = <?= json_encode($currencyDisplay) ?>;
const money    = n => '$' + Number(n||0).toFixed(2);
const moneyKhr = n => '\u17DB' + Math.round(n * exchangeRate).toLocaleString();

// Display mode toggle (client-side instant switch + persists via settings)
let currentMode = <?= json_encode($posDisplayMode) ?>;
function setDisplayMode(mode) {
    currentMode = mode;
    document.getElementById('pos-grid').classList.toggle('hidden', mode !== 'grid');
    document.getElementById('pos-list').classList.toggle('hidden', mode !== 'list');
    document.getElementById('btn-grid').className = 'px-3 py-1.5 text-sm transition ' + (mode==='grid' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50');
    document.getElementById('btn-list').className = 'px-3 py-1.5 text-sm border-l border-slate-200 transition ' + (mode==='list' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50');
    // Persist to backend via AJAX (best-effort)
    fetch('settings.php?tab=display', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'pos_display_mode=' + mode + '&action=save_settings' }).catch(()=>{});
}

function refreshTotals() {
    const rows = cartLines.querySelectorAll('.cart-row');
    document.getElementById('cartEmptyMsg').style.display = rows.length ? 'none' : 'block';
    const cnt = document.getElementById('cartCount');
    if(rows.length){ cnt.textContent=rows.length; cnt.classList.remove('hidden'); } else cnt.classList.add('hidden');
    let subtotal = 0;
    rows.forEach(row => {
        const stock = +row.dataset.stock;
        const qi    = row.querySelector('.qty-input');
        let qty = Math.max(1, +qi.value);
        if(qty > stock){ alert(row.dataset.name+' has only '+stock+' left.'); qty=stock; qi.value=stock; }
        const price = +(row.querySelector('.price-input').value)||0;
        const line  = qty * price;
        subtotal += line;
        row.querySelector('.line-total').textContent = money(line);
    });
    const dtype    = document.getElementById('discountType').value;
    const dval     = +(document.getElementById('discountValue').value)||0;
    let discount   = dtype==='percent' ? subtotal*Math.min(100,Math.max(0,dval))/100 : dtype==='fixed' ? fixedDiscount : dval;
    const grand    = Math.max(0, subtotal - discount);
    document.getElementById('subtotalView').textContent  = money(subtotal);
    document.getElementById('discountView').textContent  = money(discount);
    document.getElementById('grandView').textContent     = money(grand);
    const khrEl = document.getElementById('grandKhrView');
    if(khrEl) khrEl.textContent = moneyKhr(grand);
}

function addToCart(item) {
    const existing = cartLines.querySelector('.cart-row[data-id="'+item.id+'"]');
    if(existing){
        const qi   = existing.querySelector('.qty-input');
        const next = +qi.value + 1;
        if(next > +existing.dataset.stock){ alert(item.name+' has only '+existing.dataset.stock+' left.'); return; }
        qi.value = next; refreshTotals(); return;
    }
    const row = document.createElement('div');
    row.className    = 'cart-row rounded-xl p-3 hover:bg-white/5 transition';
    row.style.cssText= 'background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);margin-bottom:6px;';
    row.dataset.id    = item.id;
    row.dataset.stock = item.stock;
    row.dataset.name  = item.name;
    row.innerHTML = `
        <input type="hidden" name="item_id[]" value="${item.id}">
        <div class="flex items-center gap-2 mb-2">
            <div class="flex-1 min-w-0">
                <div class="font-medium text-sm text-white truncate">${item.name}</div>
                <div class="text-xs" style="color:rgba(255,255,255,0.35)">Stock: ${item.stock}</div>
            </div>
            <span class="line-total text-xs font-bold text-indigo-300 shrink-0">${money(item.price)}</span>
            <button type="button" class="remove-line text-base leading-none" style="color:rgba(255,255,255,0.3);" onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='rgba(255,255,255,0.3)'">✕</button>
        </div>
        <div class="flex gap-2">
            <div class="flex items-center gap-1 rounded-lg px-2 py-1" style="background:rgba(255,255,255,0.1);">
                <button type="button" class="qty-dec w-5 text-center text-base leading-none" style="color:rgba(255,255,255,0.5);">−</button>
                <input name="qty[]" type="number" min="1" max="${item.stock}" value="1" class="qty-input w-10 text-center text-sm bg-transparent text-white outline-none">
                <button type="button" class="qty-inc w-5 text-center text-base leading-none" style="color:rgba(255,255,255,0.5);">+</button>
            </div>
            <input name="price[]" type="number" step="0.01" value="${item.price}" class="price-input w-20 rounded-lg px-2 py-1 text-sm text-center text-white outline-none" style="background:rgba(255,255,255,0.1);">
            <input name="line_note[]" placeholder="Note…" class="flex-1 min-w-0 rounded-lg px-2 py-1 text-xs text-white/70 outline-none" style="background:rgba(255,255,255,0.06);">
        </div>`;
    cartLines.appendChild(row);
    row.querySelector('.remove-line').addEventListener('click', () => { row.remove(); refreshTotals(); });
    row.querySelectorAll('input').forEach(i => i.addEventListener('input', refreshTotals));
    row.querySelector('.qty-dec').addEventListener('click', () => { const qi=row.querySelector('.qty-input'); qi.value=Math.max(1,+qi.value-1); refreshTotals(); });
    row.querySelector('.qty-inc').addEventListener('click', () => { const qi=row.querySelector('.qty-input'); qi.value=Math.min(+qi.max,+qi.value+1); refreshTotals(); });
    refreshTotals();
}

document.querySelectorAll('.product-card').forEach(c => c.addEventListener('click', () => addToCart(c.dataset)));
document.getElementById('discountType').addEventListener('change', function(){ if(this.value==='fixed') document.getElementById('discountValue').value=fixedDiscount; refreshTotals(); });
document.getElementById('discountValue').addEventListener('input', refreshTotals);
refreshTotals();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
