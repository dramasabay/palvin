<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'PALVIN Premium';
$user = auth_user();
$current = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$lang = get_lang();
$langSwitchEnabled = isset($pdo) ? (setting($pdo, 'language_switch_enabled', '1') === '1') : true;
$customCss = isset($pdo) ? setting($pdo, 'custom_css', '') : '';
$faviconPath = isset($pdo) ? app_favicon($pdo) : '';

$groups = [
    ['title' => t('nav_overview'), 'icon' => '⊞', 'items' => [
        'dashboard.php'    => ['label' => t('nav_dashboard'),  'icon' => '◈'],
        'main_inventory.php' => ['label' => t('nav_main_inv'), 'icon' => '▤'],
    ]],
    ['title' => t('nav_retail'), 'icon' => '◎', 'items' => [
        'retail_inventory.php' => ['label' => t('nav_retail_inv'), 'icon' => '▦'],
        'retail_orders.php'    => ['label' => t('nav_pos'),        'icon' => '◉'],
        'retail_history.php'   => ['label' => t('nav_order_hist'), 'icon' => '◷'],
        'retail_reports.php'   => ['label' => t('nav_reports'),    'icon' => '◑'],
    ]],
    ['title' => t('nav_consignment'), 'icon' => '◫', 'items' => [
        'consignment_consignors.php'     => ['label' => t('nav_consignors'), 'icon' => '◯'],
        'consignment_main_inventory.php' => ['label' => t('nav_stock'),      'icon' => '◧'],
        'consignment_assign.php'         => ['label' => t('nav_issue_do'),   'icon' => '◐'],
        'consignment_inventory.php'      => ['label' => t('nav_issue_inv'),  'icon' => '◑'],
        'consignment_payments.php'       => ['label' => t('nav_payments'),   'icon' => '◈'],
        'consignment_reports.php'        => ['label' => t('nav_reports'),    'icon' => '◑'],
    ]],
    ['title' => t('nav_system'), 'icon' => '◎', 'items' => [
        'settings.php' => ['label' => t('nav_settings'), 'icon' => '⊙'],
        'media.php'    => ['label' => t('nav_media'),    'icon' => '◫'],
        'backup.php'   => ['label' => t('nav_backup'),   'icon' => '◴'],
        'users.php'    => ['label' => t('nav_users'),    'icon' => '◯'],
    ]],
];
?>
<!doctype html>
<html lang="<?= $lang === 'km' ? 'km' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — PALVIN</title>
    <?php if ($faviconPath): ?>
    <link rel="icon" href="<?= e($faviconPath) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Khmer:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-light: #eef2ff;
            --sidebar-bg: #0f0f13;
            --sidebar-hover: rgba(99,102,241,0.12);
            --sidebar-active: rgba(99,102,241,0.18);
        }
        * { scroll-behavior: smooth; }
        body {
            font-family: <?= $lang === 'km' ? "'Noto Sans Khmer', " : '' ?>'Inter', system-ui, sans-serif;
        }
        /* Sidebar scrollbar */
        aside::-webkit-scrollbar { width: 4px; }
        aside::-webkit-scrollbar-track { background: transparent; }
        aside::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
        /* Table styles */
        .pvn-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .pvn-table thead th {
            background: #f8fafc; padding: 12px 16px; text-align: left;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
            color: #64748b; border-bottom: 1px solid #e2e8f0;
            position: sticky; top: 0; z-index: 1;
        }
        .pvn-table tbody tr { transition: background 0.15s; }
        .pvn-table tbody tr:hover { background: #f8fafc; }
        .pvn-table tbody td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        .pvn-table tbody tr:last-child td { border-bottom: none; }
        /* Cards */
        .pvn-card { background: #fff; border: 1px solid #e8ecf0; border-radius: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        /* Buttons */
        .pvn-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 12px; font-size: 14px; font-weight: 500; transition: all 0.15s; cursor: pointer; border: none; }
        .pvn-btn-primary { background: var(--accent); color: #fff; }
        .pvn-btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3); }
        .pvn-btn-secondary { background: #f1f5f9; color: #334155; }
        .pvn-btn-secondary:hover { background: #e2e8f0; }
        .pvn-btn-danger { background: #fef2f2; color: #dc2626; }
        .pvn-btn-danger:hover { background: #fee2e2; }
        .pvn-btn-sm { padding: 5px 12px; font-size: 13px; border-radius: 9px; }
        /* Inputs */
        .pvn-input { width: 100%; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 12px; font-size: 14px; transition: border-color 0.15s, box-shadow 0.15s; outline: none; background: #fff; }
        .pvn-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
        .pvn-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
        /* Badges */
        .pvn-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .pvn-badge-green { background: #dcfce7; color: #16a34a; }
        .pvn-badge-amber { background: #fef3c7; color: #d97706; }
        .pvn-badge-red { background: #fee2e2; color: #dc2626; }
        .pvn-badge-blue { background: #dbeafe; color: #2563eb; }
        .pvn-badge-purple { background: #ede9fe; color: #7c3aed; }
        /* Smooth mobile overlay */
        #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 39; backdrop-filter: blur(2px); }
        #sidebar-overlay.active { display: block; }
        /* Sidebar transition */
        #main-sidebar { transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); }
        @media (max-width: 1023px) {
            #main-sidebar { transform: translateX(-100%); position: fixed; z-index: 40; height: 100vh; }
            #main-sidebar.open { transform: translateX(0); }
        }
        /* Flash animation */
        @keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .pvn-alert { animation: slideDown 0.2s ease; }
        /* Scroll top btn */
        #scroll-top { position: fixed; bottom: 24px; right: 24px; width: 40px; height: 40px; border-radius: 50%; background: var(--accent); color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; opacity: 0; transition: opacity 0.2s; z-index: 50; border: none; font-size: 18px; box-shadow: 0 4px 12px rgba(99,102,241,0.4); }
        #scroll-top.visible { opacity: 1; }
        /* Print */
        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
        /* Active nav glow */
        .nav-active-item { background: var(--sidebar-active) !important; border-left: 3px solid var(--accent); }
    </style>
    <?php if ($customCss): ?><style><?= $customCss ?></style><?php endif; ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900" x-data>

<!-- Mobile overlay -->
<div id="sidebar-overlay" onclick="toggleSidebar(false)"></div>

<!-- Scroll to top -->
<button id="scroll-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">↑</button>

<div class="flex min-h-screen">
    <!-- ====== SIDEBAR ====== -->
    <aside id="main-sidebar" class="w-72 flex flex-col no-print" style="background: var(--sidebar-bg); color: #e2e8f0; overflow-y: auto; flex-shrink: 0;">
        <!-- Logo -->
        <div class="p-6 pb-4">
            <div class="flex items-center gap-3">
                <?php $logoSrc = isset($pdo) ? invoice_logo($pdo) : app_config('default_logo'); ?>
                <div class="w-10 h-10 rounded-2xl overflow-hidden bg-indigo-600 flex items-center justify-center flex-shrink-0">
                    <img src="<?= e($logoSrc) ?>" alt="Logo" class="w-full h-full object-contain" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=\'color:#fff;font-weight:700;font-size:18px\'>P</span>'">
                </div>
                <div>
                    <div class="font-bold text-lg leading-none text-white">PALVIN</div>
                    <div class="text-xs mt-0.5" style="color:#6b7280;">Retail &amp; Consignment</div>
                </div>
            </div>
        </div>
        <!-- Nav -->
        <nav class="flex-1 px-3 pb-4 space-y-1">
            <?php foreach ($groups as $group): ?>
            <div class="mt-5 mb-1 px-3 text-[10px] font-semibold uppercase tracking-widest" style="color:#4b5563;"><?= e($group['title']) ?></div>
            <?php foreach ($group['items'] as $link => $info): ?>
            <a href="<?= e($link) ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 <?= $current === $link ? 'nav-active-item text-indigo-300' : '' ?>" style="<?= $current !== $link ? 'color:#9ca3af;' : '' ?>" onmouseover="if(!this.classList.contains('nav-active-item')) this.style.background='var(--sidebar-hover)'; this.style.color='#e2e8f0';" onmouseout="if(!this.classList.contains('nav-active-item')) {this.style.background=''; this.style.color='#9ca3af';}">
                <span class="text-base leading-none w-5 text-center flex-shrink-0"><?= $info['icon'] ?></span>
                <span><?= e($info['label']) ?></span>
            </a>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <!-- User card -->
        <div class="p-4 mx-3 mb-4 rounded-2xl" style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08);">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                    <?= e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-sm text-white truncate"><?= e($user['full_name'] ?? 'User') ?></div>
                    <div class="text-xs" style="color:#6b7280;"><?= e(ucfirst($user['role'] ?? '')) ?></div>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <!-- Language switch -->
                <?php $otherLang = $lang === 'km' ? 'en' : 'km'; $langLabel = $lang === 'km' ? 'English' : 'ខ្មែរ'; ?>
                <?php if ($langSwitchEnabled): ?>
                <a href="?lang=<?= $otherLang ?>" class="flex-1 text-center py-1.5 rounded-lg text-xs font-medium transition" style="background:rgba(255,255,255,0.08);color:#9ca3af;" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    🌐 <?= $langLabel ?>
                </a>
                <?php endif; ?>
                <a href="logout.php" class="flex-1 text-center py-1.5 rounded-lg text-xs font-medium transition" style="background:rgba(255,255,255,0.08);color:#9ca3af;" onmouseover="this.style.background='rgba(220,38,38,0.2)';this.style.color='#fca5a5'" onmouseout="this.style.background='rgba(255,255,255,0.08)';this.style.color='#9ca3af'">
                    → <?= t('logout') ?>
                </a>
            </div>
        </div>
    </aside>

    <!-- ====== MAIN CONTENT ====== -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top bar -->
        <header class="sticky top-0 z-30 no-print" style="background:rgba(248,250,252,0.92); backdrop-filter:blur(12px); border-bottom:1px solid #e2e8f0;">
            <div class="flex items-center gap-4 px-6 py-3">
                <!-- Mobile menu btn -->
                <button onclick="toggleSidebar(true)" class="lg:hidden pvn-btn pvn-btn-secondary pvn-btn-sm">☰</button>
                <div class="flex-1">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-800"><?= e($pageTitle) ?></h2>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Clock -->
                    <div class="hidden sm:block text-right">
                        <div class="text-xs text-slate-400"><?= t('cambodia_time') ?></div>
                        <div class="text-sm font-semibold text-slate-700" id="live-clock"><?= date('d M Y H:i') ?></div>
                    </div>
                    <!-- Lang toggle (top bar, desktop) -->
                    <?php if ($langSwitchEnabled): ?>
                    <a href="?lang=<?= $otherLang ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm hidden md:inline-flex">
                        🌐 <?= $langLabel ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Page content -->
        <main class="flex-1 p-5 md:p-8 overflow-x-hidden">
            <?php if ($msg = flash('success')): ?>
                <div class="pvn-alert mb-5 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3.5 text-emerald-800 text-sm font-medium">
                    <span class="text-lg">✓</span> <?= e($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('warning')): ?>
                <div class="pvn-alert mb-5 flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3.5 text-amber-800 text-sm font-medium">
                    <span class="text-lg">⚠</span> <?= e($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="pvn-alert mb-5 flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3.5 text-rose-800 text-sm font-medium">
                    <span class="text-lg">✗</span> <?= e($msg) ?>
                </div>
            <?php endif; ?>

