<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pageTitle = t('settings');

$settingDefaults = [
    'company_name'                  => 'PALVIN',
    'company_phone'                 => '',
    'company_email'                 => '',
    'invoice_footer'                => 'Thank you for your business!',
    'invoice_note'                  => '',
    'invoice_size'                  => 'A4',
    'bank_name'                     => '',
    'account_name'                  => '',
    'account_number'                => '',
    'business_address'              => '',
    'default_consignor_commission'  => '5',
    'claim_alert_mode'              => 'auto',
    'manual_claim_cutoff'           => '',
    'consignor_view_mode'           => 'grid',
    'pos_display_mode'              => 'grid',
    'fixed_discount_price'          => '0',
    'telegram_enabled'              => '0',
    'telegram_bot_token'            => '',
    'telegram_chat_id'              => '',
    'telegram_message_thread_id'    => '',
    'telegram_retail_alerts'        => '1',
    'telegram_consignment_alerts'   => '1',
    'exchange_rate'                 => '4100',
    'currency_display'              => 'both',
    'app_language'                  => 'en',
    'language_switch_enabled'       => '1',
    'custom_css'                    => '',
];
$checkboxKeys = ['telegram_enabled', 'telegram_retail_alerts', 'telegram_consignment_alerts'];

/* ── Handle lang override saves ── */
if (is_post() && post_action() === 'save_lang_overrides') {
    // Delete existing km overrides then re-insert
    $pdo->exec("DELETE FROM system_settings WHERE setting_key LIKE 'lang_km_%'");
    $pairs = trim((string)($_POST['lang_overrides_text'] ?? ''));
    foreach (explode("\n", $pairs) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($k !== '') save_setting($pdo, 'lang_km_' . $k, $v);
    }
    flash('success', t('lang_saved'));
    redirect_to('settings.php?tab=translator');
}

/* ── Handle CSS save ── */
if (is_post() && post_action() === 'save_css') {
    save_setting($pdo, 'custom_css', trim((string)($_POST['custom_css'] ?? '')));
    flash('success', t('settings_saved'));
    redirect_to('settings.php?tab=css');
}

/* ── Main settings save ── */
if (is_post() && !in_array(post_action(), ['save_lang_overrides','save_css','test_telegram'], true) || (is_post() && post_action() === 'test_telegram')) {
    if (!in_array(post_action(), ['save_lang_overrides','save_css'], true)) {
        // Determine which non-checkbox keys were actually submitted in this form
        $submittedKeys = array_keys(array_intersect_key($settingDefaults, $_POST));
        $nonCheckboxSubmitted = array_diff($submittedKeys, $checkboxKeys);

        foreach ($settingDefaults as $key => $default) {
            if (in_array($key, $checkboxKeys, true)) {
                // Only update a checkbox when its owning tab was submitted
                // (detected by presence of at least one non-checkbox sibling key in POST)
                if (count($nonCheckboxSubmitted) > 0 || array_key_exists($key, $_POST)) {
                    save_setting($pdo, $key, isset($_POST[$key]) ? '1' : '0');
                }
                continue;
            }
            // Only save fields that were actually present in this form submission
            if (array_key_exists($key, $_POST)) {
                save_setting($pdo, $key, trim((string)$_POST[$key]));
            }
        }
        $logo = upload_file($_FILES['invoice_logo'] ?? [], 'branding', ['jpg','jpeg','png','webp','svg']);
        if ($logo) save_setting($pdo, 'invoice_logo', $logo);
        $favicon = upload_file($_FILES['app_favicon'] ?? [], 'branding', ['ico','png','svg','jpg','jpeg']);
        if ($favicon) save_setting($pdo, 'app_favicon', $favicon);

        if (post_action() === 'test_telegram') {
            $result = send_telegram_message($pdo, telegram_message_from_lines([
                'PALVIN Telegram test message',
                'Company: ' . setting($pdo, 'company_name', 'PALVIN'),
                'Time: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')',
                'Source: System Settings',
            ]), ['ignore_enabled' => true]);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Settings saved and Telegram test sent.' : 'Settings saved, but Telegram test failed: ' . $result['error']);
        } else {
            flash('success', t('settings_saved'));
        }
        redirect_to('settings.php');
    }
}

$telegramEnabled           = setting($pdo, 'telegram_enabled', '0') === '1';
$telegramRetailEnabled     = setting($pdo, 'telegram_retail_alerts', '1') === '1';
$telegramConsignmentEnabled= setting($pdo, 'telegram_consignment_alerts', '1') === '1';
$langSwitchEnabled         = setting($pdo, 'language_switch_enabled', '1') === '1';
$activeTab                 = $_GET['tab'] ?? 'general';

require __DIR__ . '/includes/header.php';

function si(string $name, string $label, string $type = 'text', string $placeholder = ''): void {
    global $pdo;
    $val = setting($pdo, $name, '');
    $extra = $type === 'number' ? 'step="0.01"' : '';
    echo '<div>';
    echo '<label class="block text-sm font-medium text-slate-600 mb-1.5">' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
    echo '<input name="' . htmlspecialchars($name, ENT_QUOTES) . '" type="' . $type . '" ' . $extra . ' class="pvn-input" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '" value="' . htmlspecialchars($val, ENT_QUOTES) . '">';
    echo '</div>';
}

// Build current km overrides as key=value text
$existingOverrides = db_all($pdo, "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'lang_km_%' ORDER BY setting_key");
$overrideText = '';
foreach ($existingOverrides as $row) {
    $k = substr($row['setting_key'], 7);
    $overrideText .= $k . '=' . $row['setting_value'] . "\n";
}
$overrideText = trim($overrideText);
?>

<!-- Tab navigation -->
<div class="mb-6 flex flex-wrap gap-2 border-b border-slate-200 pb-0">
    <?php
    $tabs = [
        'general'    => '🏢 General',
        'display'    => '🎨 Display & POS',
        'currency'   => '💱 Currency & Language',
        'business'   => '⚙ Business Rules',
        'telegram'   => '📱 Telegram',
        'css'        => '🖌 Custom CSS',
        'translator' => '🌐 Translator',
    ];
    foreach ($tabs as $slug => $label): ?>
    <a href="?tab=<?= $slug ?>" class="px-4 py-2.5 rounded-t-xl text-sm font-medium transition-all -mb-px <?= $activeTab === $slug ? 'bg-white border border-b-white border-slate-200 text-indigo-600' : 'text-slate-500 hover:text-slate-700' ?>">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid gap-6 xl:grid-cols-[1fr,380px]">
<div>

<!-- ═══════════ TAB: GENERAL ═══════════ -->
<?php if ($activeTab === 'general'): ?>
<form method="post" enctype="multipart/form-data" class="space-y-6">
    <div class="pvn-card p-6 space-y-5">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">🏢 <?= t('company_name') ?> & Invoice</h3>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php si('company_name', t('company_name'), 'text', 'PALVIN') ?>
            <?php si('company_phone', t('company_phone')) ?>
            <div class="sm:col-span-2"><?php si('company_email', t('company_email'), 'email') ?></div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('business_address') ?></label>
                <textarea name="business_address" class="pvn-input" rows="2"><?= e(setting($pdo, 'business_address', '')) ?></textarea>
            </div>
            <?php si('bank_name', t('bank_name')) ?>
            <?php si('account_name', t('account_name')) ?>
            <div class="sm:col-span-2"><?php si('account_number', t('account_number')) ?></div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('invoice_footer') ?></label>
            <textarea name="invoice_footer" class="pvn-input" rows="2"><?= e(setting($pdo, 'invoice_footer', '')) ?></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('invoice_note') ?></label>
            <textarea name="invoice_note" class="pvn-input" rows="2"><?= e(setting($pdo, 'invoice_note', '')) ?></textarea>
        </div>
        <!-- Invoice paper size -->
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('invoice_size') ?></label>
            <select name="invoice_size" class="pvn-input pvn-select" style="max-width:200px;">
                <option value="A4" <?= setting($pdo,'invoice_size','A4') === 'A4' ? 'selected' : '' ?>>A4 (210×297mm)</option>
                <option value="A5" <?= setting($pdo,'invoice_size','A4') === 'A5' ? 'selected' : '' ?>>A5 (148×210mm)</option>
            </select>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('invoice_logo') ?></label>
                <?php $curLogo = setting($pdo, 'invoice_logo', ''); if ($curLogo): ?>
                <img src="<?= e($curLogo) ?>" class="h-12 mb-2 rounded-lg object-contain bg-slate-100 p-1">
                <?php endif; ?>
                <input type="file" name="invoice_logo" class="pvn-input" accept="image/*,.svg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">App Favicon / Icon</label>
                <?php $curFav = setting($pdo, 'app_favicon', ''); if ($curFav): ?>
                <img src="<?= e($curFav) ?>" class="h-8 w-8 mb-2 rounded object-contain">
                <?php endif; ?>
                <input type="file" name="app_favicon" class="pvn-input" accept=".ico,.png,.svg,image/*">
                <p class="text-xs text-slate-400 mt-1">ICO, PNG or SVG</p>
            </div>
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_settings" class="pvn-btn pvn-btn-primary">✓ <?= t('save') ?> <?= t('settings') ?></button>
    </div>
</form>

<!-- ═══════════ TAB: DISPLAY & POS ═══════════ -->
<?php elseif ($activeTab === 'display'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-5">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">🎨 Display & POS Settings</h3>
        <div class="grid sm:grid-cols-2 gap-5">
            <!-- POS display mode -->
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('pos_display_mode') ?></label>
                <div class="flex gap-3 mt-2">
                    <label class="flex items-center gap-2 cursor-pointer p-3 rounded-xl border-2 flex-1 <?= setting($pdo,'pos_display_mode','grid')==='grid' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200' ?>">
                        <input type="radio" name="pos_display_mode" value="grid" <?= setting($pdo,'pos_display_mode','grid')==='grid' ? 'checked' : '' ?> class="text-indigo-600">
                        <span class="text-sm font-medium">⊞ <?= t('grid_view') ?></span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer p-3 rounded-xl border-2 flex-1 <?= setting($pdo,'pos_display_mode','grid')==='list' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200' ?>">
                        <input type="radio" name="pos_display_mode" value="list" <?= setting($pdo,'pos_display_mode','grid')==='list' ? 'checked' : '' ?> class="text-indigo-600">
                        <span class="text-sm font-medium">☰ <?= t('list_view') ?></span>
                    </label>
                </div>
                <p class="text-xs text-slate-400 mt-1.5">POS Orders product items layout</p>
            </div>
            <!-- Consignor display mode -->
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('consignor_display') ?></label>
                <div class="flex gap-3 mt-2">
                    <label class="flex items-center gap-2 cursor-pointer p-3 rounded-xl border-2 flex-1 <?= setting($pdo,'consignor_view_mode','grid')==='grid' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200' ?>">
                        <input type="radio" name="consignor_view_mode" value="grid" <?= setting($pdo,'consignor_view_mode','grid')==='grid' ? 'checked' : '' ?> class="text-indigo-600">
                        <span class="text-sm font-medium">⊞ <?= t('grid_view') ?></span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer p-3 rounded-xl border-2 flex-1 <?= setting($pdo,'consignor_view_mode','grid')==='list' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200' ?>">
                        <input type="radio" name="consignor_view_mode" value="list" <?= setting($pdo,'consignor_view_mode','grid')==='list' ? 'checked' : '' ?> class="text-indigo-600">
                        <span class="text-sm font-medium">☰ <?= t('list_view') ?></span>
                    </label>
                </div>
                <p class="text-xs text-slate-400 mt-1.5">Consignors page layout</p>
            </div>
        </div>

        <!-- Language switch toggle -->
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-medium text-slate-700 text-sm"><?= t('language_mode') ?></div>
                    <div class="text-xs text-slate-400 mt-0.5"><?= t('language_mode_hint') ?></div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="language_switch_enabled" value="0">
                    <input type="checkbox" name="language_switch_enabled" value="1" <?= $langSwitchEnabled ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                </label>
            </div>
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_settings" class="pvn-btn pvn-btn-primary">✓ <?= t('save') ?> <?= t('settings') ?></button>
    </div>
</form>

<!-- ═══════════ TAB: CURRENCY & LANGUAGE ═══════════ -->
<?php elseif ($activeTab === 'currency'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-5">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">💱 <?= t('currency_display') ?> & <?= t('language') ?></h3>
        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('exchange_rate') ?></label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">&#x17DB;</span>
                    <input name="exchange_rate" type="number" step="1" min="1" class="pvn-input pl-8" value="<?= e(setting($pdo, 'exchange_rate', '4100')) ?>" placeholder="4100">
                </div>
                <p class="text-xs text-slate-400 mt-1">1 USD = ? KHR (e.g. 4100)</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('currency_display') ?></label>
                <select name="currency_display" class="pvn-input pvn-select">
                    <option value="both" <?= setting($pdo,'currency_display','both')==='both' ? 'selected' : '' ?>>USD + KHR (both)</option>
                    <option value="usd"  <?= setting($pdo,'currency_display','both')==='usd'  ? 'selected' : '' ?>>USD only ($)</option>
                    <option value="khr"  <?= setting($pdo,'currency_display','both')==='khr'  ? 'selected' : '' ?>>KHR only (&#x17DB;)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('language') ?></label>
                <select name="app_language" class="pvn-input pvn-select" onchange="window.location.href='?lang='+this.value+'&tab=currency'">
                    <option value="en" <?= get_lang()==='en' ? 'selected' : '' ?>>English</option>
                    <option value="km" <?= get_lang()==='km' ? 'selected' : '' ?>>ភាសាខ្មែរ (Khmer)</option>
                </select>
                <p class="text-xs text-slate-400 mt-1">Switching reloads the page</p>
            </div>
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_settings" class="pvn-btn pvn-btn-primary">✓ <?= t('save') ?> <?= t('settings') ?></button>
    </div>
</form>

<!-- ═══════════ TAB: BUSINESS RULES ═══════════ -->
<?php elseif ($activeTab === 'business'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-4">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">⚙ Business Rules</h3>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php si('default_consignor_commission', t('commission_rate'), 'number') ?>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Fixed Consignment Discount Preset Type</label>
                <select name="fixed_consignment_discount_type" class="pvn-input pvn-select">
                    <option value="amount" <?= setting($pdo,'fixed_consignment_discount_type','amount')==='amount' ? 'selected' : '' ?>>Amount ($)</option>
                    <option value="percent" <?= setting($pdo,'fixed_consignment_discount_type','amount')==='percent' ? 'selected' : '' ?>>Percent (%)</option>
                </select>
            </div>
            <?php si('fixed_consignment_discount_value', 'Fixed Consignment Discount Preset Value', 'number', '0') ?>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Fixed Retail Discount Preset Type</label>
                <select name="fixed_retail_discount_type" class="pvn-input pvn-select">
                    <option value="amount" <?= setting($pdo,'fixed_retail_discount_type','amount')==='amount' ? 'selected' : '' ?>>Amount ($)</option>
                    <option value="percent" <?= setting($pdo,'fixed_retail_discount_type','amount')==='percent' ? 'selected' : '' ?>>Percent (%)</option>
                </select>
            </div>
            <?php si('fixed_discount_price', 'Fixed Retail Discount Preset Value', 'number', '0') ?>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Monthly Claim Alert Mode</label>
                <select name="claim_alert_mode" class="pvn-input pvn-select">
                    <option value="auto"   <?= setting($pdo,'claim_alert_mode','auto')==='auto'   ? 'selected' : '' ?>>Auto monthly</option>
                    <option value="manual" <?= setting($pdo,'claim_alert_mode','auto')==='manual' ? 'selected' : '' ?>>Manual test date</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Manual Cutoff Date</label>
                <input type="date" name="manual_claim_cutoff" class="pvn-input" value="<?= e(setting($pdo,'manual_claim_cutoff','')) ?>">
            </div>
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_settings" class="pvn-btn pvn-btn-primary">✓ <?= t('save') ?> <?= t('settings') ?></button>
    </div>
</form>

<!-- ═══════════ TAB: TELEGRAM ═══════════ -->
<?php elseif ($activeTab === 'telegram'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-4" style="border-color:#bae6fd;">
        <div class="flex items-center justify-between border-b border-sky-100 pb-3">
            <h3 class="font-semibold text-sky-900">📱 Telegram Alerts</h3>
            <label class="flex items-center gap-2 text-sm font-medium text-sky-800 cursor-pointer">
                <input type="checkbox" name="telegram_enabled" value="1" <?= $telegramEnabled ? 'checked' : '' ?> class="rounded">
                Enable
            </label>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Bot Token</label>
                <input name="telegram_bot_token" class="pvn-input" placeholder="1234567890:AABB..." value="<?= e(setting($pdo,'telegram_bot_token','')) ?>" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Chat ID</label>
                <input name="telegram_chat_id" class="pvn-input" placeholder="-100..." value="<?= e(setting($pdo,'telegram_chat_id','')) ?>" autocomplete="off">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Thread ID (optional)</label>
                <input name="telegram_message_thread_id" class="pvn-input" placeholder="Forum topic thread ID" value="<?= e(setting($pdo,'telegram_message_thread_id','')) ?>" autocomplete="off">
            </div>
        </div>
        <div class="flex gap-3">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-600 cursor-pointer">
                <input type="checkbox" name="telegram_retail_alerts" value="1" <?= $telegramRetailEnabled ? 'checked' : '' ?> class="rounded">
                Retail POS alerts
            </label>
            <label class="flex items-center gap-2 text-sm font-medium text-slate-600 cursor-pointer">
                <input type="checkbox" name="telegram_consignment_alerts" value="1" <?= $telegramConsignmentEnabled ? 'checked' : '' ?> class="rounded">
                Issue INV alerts
            </label>
        </div>
    </div>
    <div class="flex flex-wrap gap-3">
        <button name="action" value="save_settings" class="pvn-btn pvn-btn-primary">✓ <?= t('save') ?> <?= t('settings') ?></button>
        <button name="action" value="test_telegram" class="pvn-btn pvn-btn-secondary">📱 Save &amp; Test Telegram</button>
    </div>
</form>

<!-- ═══════════ TAB: CUSTOM CSS ═══════════ -->
<?php elseif ($activeTab === 'css'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-4">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">🖌 <?= t('custom_css') ?></h3>
        <p class="text-sm text-slate-500"><?= t('custom_css_hint') ?></p>
        <div>
            <textarea name="custom_css" class="pvn-input font-mono text-xs" rows="18" placeholder="/* Your custom CSS here */
.pvn-card { border-radius: 8px; }
body { font-size: 15px; }"><?= e(setting($pdo,'custom_css','')) ?></textarea>
        </div>
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
            ⚠ CSS is applied to <strong>all pages</strong> including the admin panel. Also injected into all invoice print pages. Use carefully.
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_css" class="pvn-btn pvn-btn-primary">✓ Save Custom CSS</button>
        <button type="button" onclick="document.querySelector('[name=custom_css]').value=''" class="pvn-btn pvn-btn-secondary">✕ Clear CSS</button>
    </div>
</form>

<!-- ═══════════ TAB: TRANSLATOR ═══════════ -->
<?php elseif ($activeTab === 'translator'): ?>
<form method="post" class="space-y-6">
    <div class="pvn-card p-6 space-y-4">
        <h3 class="font-semibold text-slate-800 border-b border-slate-100 pb-3">🌐 <?= t('lang_overrides') ?> <span class="text-xs font-normal text-slate-400">(English → Khmer)</span></h3>
        <p class="text-sm text-slate-500"><?= t('lang_overrides_hint') ?></p>

        <!-- Quick reference table -->
        <details class="rounded-xl border border-slate-200 overflow-hidden">
            <summary class="px-4 py-3 bg-slate-50 text-sm font-medium text-slate-700 cursor-pointer hover:bg-slate-100">📖 View all translation keys</summary>
            <div class="overflow-auto max-h-72 p-4">
                <table class="w-full text-xs">
                    <thead><tr class="text-left border-b"><th class="pb-2 text-slate-500">Key</th><th class="pb-2 text-slate-500">English</th><th class="pb-2 text-slate-500">Current Khmer</th></tr></thead>
                    <tbody>
                    <?php
                    $enStrings = lang_strings('en');
                    $kmStrings = lang_strings('km');
                    foreach ($enStrings as $k => $v): ?>
                    <tr class="border-b border-slate-50 hover:bg-slate-50 cursor-pointer" onclick="appendKey('<?= htmlspecialchars(addslashes($k)) ?>')">
                        <td class="py-1.5 font-mono text-indigo-600 pr-2"><?= e($k) ?></td>
                        <td class="py-1.5 text-slate-600 pr-2"><?= e($v) ?></td>
                        <td class="py-1.5 text-slate-500"><?= e($kmStrings[$k] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5">
                Overrides <span class="text-xs font-normal text-slate-400">(one per line, format: key=ការបកប្រែ)</span>
            </label>
            <textarea id="overridesText" name="lang_overrides_text" class="pvn-input font-mono text-sm" rows="16"
                placeholder="nav_pos=ការលក់
pos_orders=ការបញ្ជាទិញ POS
place_order=បញ្ជាទិញ"><?= e($overrideText) ?></textarea>
        </div>

        <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
            ℹ Only Khmer (km) overrides are supported. Click any key in the table above to append it to the editor. Overrides take effect immediately on next page load in Khmer mode.
        </div>
    </div>
    <div class="flex gap-3">
        <button name="action" value="save_lang_overrides" class="pvn-btn pvn-btn-primary">✓ <?= t('lang_save') ?></button>
        <button type="button" onclick="document.getElementById('overridesText').value=''" class="pvn-btn pvn-btn-secondary">✕ Clear All</button>
    </div>
</form>
<script>
function appendKey(key) {
    const ta = document.getElementById('overridesText');
    const lines = ta.value.split('\n').filter(l => l.trim() !== '');
    // Remove existing line with same key
    const filtered = lines.filter(l => !l.startsWith(key + '='));
    filtered.push(key + '=');
    ta.value = filtered.join('\n');
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
}
</script>
<?php endif; ?>

</div>

<!-- ── Right sidebar preview ── -->
<div class="space-y-4">
    <div class="pvn-card p-5 space-y-3">
        <h4 class="font-semibold text-slate-700 text-sm">System Status</h4>
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-slate-50 p-3">
                <div class="text-xs text-slate-400 mb-1">Server Time</div>
                <div class="font-semibold text-sm"><?= date('H:i:s') ?></div>
                <div class="text-xs text-slate-400"><?= date_default_timezone_get() ?></div>
            </div>
            <div class="rounded-xl <?= $telegramEnabled ? 'bg-emerald-50' : 'bg-slate-50' ?> p-3">
                <div class="text-xs text-slate-400 mb-1">Telegram</div>
                <div class="font-semibold text-sm <?= $telegramEnabled ? 'text-emerald-700' : 'text-slate-500' ?>"><?= $telegramEnabled ? 'Enabled' : 'Disabled' ?></div>
            </div>
            <div class="rounded-xl bg-indigo-50 p-3">
                <div class="text-xs text-slate-400 mb-1"><?= t('exchange_rate') ?></div>
                <div class="font-semibold text-sm text-indigo-700">1 USD = <?= number_format((float)setting($pdo,'exchange_rate','4100'),0) ?> &#x17DB;</div>
            </div>
            <div class="rounded-xl bg-violet-50 p-3">
                <div class="text-xs text-slate-400 mb-1"><?= t('language') ?></div>
                <div class="font-semibold text-sm text-violet-700"><?= get_lang()==='km' ? 'ភាសាខ្មែរ' : 'English' ?></div>
            </div>
            <div class="rounded-xl bg-amber-50 p-3">
                <div class="text-xs text-slate-400 mb-1">Invoice Size</div>
                <div class="font-semibold text-sm text-amber-700"><?= e(setting($pdo,'invoice_size','A4')) ?></div>
            </div>
            <div class="rounded-xl <?= $langSwitchEnabled ? 'bg-emerald-50' : 'bg-red-50' ?> p-3">
                <div class="text-xs text-slate-400 mb-1">Lang Switch</div>
                <div class="font-semibold text-sm <?= $langSwitchEnabled ? 'text-emerald-700' : 'text-red-600' ?>"><?= $langSwitchEnabled ? 'Enabled' : 'Disabled' ?></div>
            </div>
        </div>
    </div>
    <!-- Invoice preview -->
    <div class="pvn-card p-5">
        <h4 class="font-semibold text-slate-700 text-sm mb-4">Invoice Preview (<?= e(setting($pdo,'invoice_size','A4')) ?>)</h4>
        <div class="flex items-center justify-between mb-4">
            <img src="<?= e(invoice_logo($pdo)) ?>" class="h-10 object-contain" alt="logo" onerror="this.style.display='none'">
            <div class="text-right">
                <div class="font-bold text-slate-800 text-sm">INVOICE</div>
                <div class="text-xs text-slate-400"><?= date('d M Y') ?></div>
            </div>
        </div>
        <div class="text-xs space-y-1 text-slate-600">
            <div class="font-semibold"><?= e(setting($pdo,'company_name','PALVIN')) ?></div>
            <div><?= e(setting($pdo,'company_phone','')) ?></div>
            <div><?= e(setting($pdo,'company_email','')) ?></div>
        </div>
        <div class="my-3 border-t border-slate-100"></div>
        <div class="flex justify-between text-xs py-1">
            <span class="text-slate-500">Sample Item x1</span>
            <span class="font-medium">$10.00</span>
        </div>
        <div class="border-t border-slate-100 pt-2 mt-2 flex justify-between">
            <span class="text-xs font-semibold text-slate-700">Grand Total</span>
            <span class="text-sm font-bold text-indigo-700"><?= money_dual($pdo, 10, true) ?></span>
        </div>
        <?php if (setting($pdo,'invoice_footer','')): ?>
        <div class="mt-3 text-xs text-slate-400 italic"><?= e(setting($pdo,'invoice_footer','')) ?></div>
        <?php endif; ?>
    </div>
</div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
