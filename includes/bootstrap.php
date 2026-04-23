<?php
// ── Global exception handler ─────────────────────────────────────────────────
set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8');
    $line = (int)$e->getLine();
    // If it's a missing-table error, show the installer redirect instead
    if (strpos($e->getMessage(), '42S02') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
        palvin_show_installer_redirect();
        exit;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>PALVIN Error</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.box{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px 40px;max-width:520px;width:100%;box-shadow:0 2px 8px rgba(0,0,0,0.06)}'
       . 'h2{color:#dc2626;margin:0 0 12px}p{color:#475569;margin:6px 0}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px}'
       . 'a{color:#6366f1;text-decoration:none}</style></head>'
       . '<body><div class="box"><h2>⚠ Application Error</h2>'
       . '<p>' . $msg . '</p>'
       . '<p style="font-size:13px;color:#94a3b8">in <code>' . $file . '</code> on line <code>' . $line . '</code></p>'
       . '<p style="margin-top:20px"><a href="javascript:history.back()">← Go back</a></p>'
       . '</div></body></html>';
    exit;
});

function palvin_show_installer_redirect(): void {
    http_response_code(503);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Setup Required</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.box{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px 40px;max-width:480px;width:100%;text-align:center}'
       . 'h2{color:#0f172a;margin:0 0 10px}p{color:#64748b;margin:6px 0}'
       . '.btn{display:inline-block;margin-top:20px;padding:12px 28px;background:#6366f1;color:#fff;border-radius:10px;text-decoration:none;font-weight:500;font-size:15px}'
       . '</style></head><body><div class="box">'
       . '<div style="font-size:48px;margin-bottom:12px">⚙️</div>'
       . '<h2>Database Setup Required</h2>'
       . '<p>The database tables have not been created yet.</p>'
       . '<p style="margin-top:8px">Run the installer to set up your database.</p>'
       . '<a class="btn" href="install.php?token=PALVIN_INSTALL_2026">Run Installer →</a>'
       . '</div></body></html>';
}

// ── Session & config ─────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$config = require __DIR__ . '/../config/app.php';
$timezone = (string)($config['timezone'] ?? 'Asia/Phnom_Penh');
if (!@date_default_timezone_set($timezone)) {
    date_default_timezone_set('Asia/Phnom_Penh');
}

// ── Database connection ──────────────────────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// ── Guard: check core tables exist before ANY page code runs ─────────────────
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        // Check the two most fundamental tables
        $tableCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','system_settings')")->fetchColumn();
        if ((int)$tableCheck < 2) {
            // Tables missing — show installer redirect immediately
            palvin_show_installer_redirect();
            exit;
        }
    } catch (Throwable $e) {
        palvin_show_installer_redirect();
        exit;
    }

    // Tables exist — run schema migrations
    try {
        ensure_runtime_schema($pdo);
    } catch (Throwable $e) {
        // Non-fatal migration errors: log and continue
    }
}
