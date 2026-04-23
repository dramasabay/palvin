<?php
/**
 * PALVIN Premium — install.php
 * Run once to import the database and verify the setup.
 * DELETE this file after installation is complete.
 */

// ─── Config ──────────────────────────────────────────────────────────────────
$host    = '127.0.0.1';
$port    = '';
$db      = 'palvin_premium';
$user    = 'palvinpavilion';
$pass    = 'h2SsD1TkzfYvG3n';
$charset = 'utf8mb4';
$sqlFile = __DIR__ . '/sql/palvin_premium.sql';

// ─── Style ────────────────────────────────────────────────────────────────────
function box(string $title, string $body, string $type = 'info'): void {
    $colors = ['info' => '#0d6efd', 'success' => '#198754', 'error' => '#dc3545', 'warn' => '#ffc107'];
    $bg     = ['info' => '#cfe2ff', 'success' => '#d1e7dd', 'error'  => '#f8d7da',  'warn' => '#fff3cd'];
    $c = $colors[$type] ?? $colors['info'];
    $b = $bg[$type]     ?? $bg['info'];
    echo "<div style='margin:12px 0;padding:14px 18px;border-left:5px solid $c;background:$b;border-radius:4px;font-family:monospace'>";
    echo "<strong style='color:$c'>$title</strong><br><span style='white-space:pre-wrap'>$body</span></div>";
}

// ─── Security gate ────────────────────────────────────────────────────────────
$token = 'PALVIN_INSTALL_2026';
$supplied = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PALVIN Install</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 780px; margin: 40px auto; padding: 0 20px; background: #f8f9fa; color: #212529; }
  h1   { color: #343a40; }
  .step{ background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; }
  code { background:#e9ecef; padding:2px 6px; border-radius:3px; font-size:.9em; }
  .btn { display:inline-block; padding:10px 22px; background:#0d6efd; color:#fff; border:none; border-radius:4px; font-size:1em; cursor:pointer; text-decoration:none; }
  .btn:hover { background:#0b5ed7; }
  .warn-box { background:#fff3cd; border:1px solid #ffc107; padding:14px; border-radius:4px; margin-top:20px; }
</style>
</head>
<body>
<h1>🔧 PALVIN Premium — Installer</h1>

<?php if ($supplied !== $token): ?>
<div class="step">
  <h2>Access Token Required</h2>
  <p>Add <code>?token=<?= htmlspecialchars($token) ?></code> to the URL to proceed.</p>
  <p>Example: <code>https://app.palvinpavilion.com/install.php?token=<?= htmlspecialchars($token) ?></code></p>
</div>
</body></html>
<?php exit; endif; ?>

<?php
// ─── Step 1 — PHP version ─────────────────────────────────────────────────────
echo '<div class="step"><h2>Step 1 — PHP Version</h2>';
if (PHP_MAJOR_VERSION >= 7 && PHP_MINOR_VERSION >= 4) {
    box('✅ PHP ' . PHP_VERSION, 'PHP is acceptable (7.4+ required).', 'success');
} else {
    box('❌ PHP ' . PHP_VERSION, 'PHP 7.4 or higher is required.', 'error');
}
echo '</div>';

// ─── Step 2 — PDO / MySQL extension ──────────────────────────────────────────
echo '<div class="step"><h2>Step 2 — PDO MySQL Extension</h2>';
if (extension_loaded('pdo_mysql')) {
    box('✅ pdo_mysql loaded', 'PDO MySQL extension is present.', 'success');
} else {
    box('❌ pdo_mysql missing', "Run: sudo apt install php-mysql\nThen restart Apache/Nginx.", 'error');
    echo '</div></body></html>'; exit;
}
echo '</div>';

// ─── Step 3 — SQL file present ────────────────────────────────────────────────
echo '<div class="step"><h2>Step 3 — SQL File</h2>';
if (!file_exists($sqlFile)) {
    box('❌ sql/palvin_premium.sql not found', 'Make sure the sql/ folder is uploaded.', 'error');
    echo '</div></body></html>'; exit;
}
box('✅ SQL file found', realpath($sqlFile), 'success');
echo '</div>';

// ─── Step 4 — DB connection ───────────────────────────────────────────────────
echo '<div class="step"><h2>Step 4 — Database Connection</h2>';
$dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';charset=' . $charset;
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    box('✅ Connected', "Host: $host\nUser: $user\nDB:   $db", 'success');
} catch (Throwable $e) {
    box('❌ Connection failed', $e->getMessage(), 'error');
    echo '</div></body></html>'; exit;
}
echo '</div>';

// ─── Step 5 — Create DB if missing ───────────────────────────────────────────
echo '<div class="step"><h2>Step 5 — Database Existence</h2>';
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");
    box('✅ Database ready', "Database `$db` exists (or was just created).", 'success');
} catch (Throwable $e) {
    box('❌ Could not create/select database', $e->getMessage(), 'error');
    echo '</div></body></html>'; exit;
}
echo '</div>';

// ─── Step 6 — Import SQL ──────────────────────────────────────────────────────
echo '<div class="step"><h2>Step 6 — Import SQL Schema</h2>';

// Only skip import if ALL 12 required tables already exist
$expectedTables = ['users','system_settings','retail_inventory','retail_orders','retail_order_items',
    'consignors','consignment_main_inventory','consignment_inventory',
    'consignment_assignments','consignment_sales','consignment_payouts','media_files'];
$existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$missingTables  = array_diff($expectedTables, $existingTables);
$allPresent     = empty($missingTables);

if ($allPresent && !isset($_GET['force'])) {
    box('✅ All tables already exist', implode(', ', $existingTables) . "\n\nSkipping import. Append &force=1 to the URL to re-import (WARNING: drops all data).", 'success');
} else {
    if (!empty($missingTables) && !empty($existingTables)) {
        box('⚠️ Partial install detected', 'Missing: ' . implode(', ', $missingTables) . "\n\nRe-importing all tables...", 'warn');
    }
    $sql = file_get_contents($sqlFile);
    // Robust SQL splitter: handles multi-line statements, strings, comments
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $ok = 0; $errors = [];
    $statements = [];
    $current = '';
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $inLineComment = false;
    $inBlockComment = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $sql[$i + 1] ?? '';
        if ($inLineComment) {
            if ($c === "\n") $inLineComment = false;
            continue;
        }
        if ($inBlockComment) {
            if ($c === '*' && $next === '/') { $inBlockComment = false; $i++; }
            continue;
        }
        if (!$inSingleQuote && !$inDoubleQuote && $c === '-' && $next === '-') {
            $inLineComment = true; continue;
        }
        if (!$inSingleQuote && !$inDoubleQuote && $c === '/' && $next === '*') {
            $inBlockComment = true; $i++; continue;
        }
        if ($c === "'" && !$inDoubleQuote) {
            $inSingleQuote = !$inSingleQuote;
        } elseif ($c === '"' && !$inSingleQuote) {
            $inDoubleQuote = !$inDoubleQuote;
        }
        if ($c === ';' && !$inSingleQuote && !$inDoubleQuote) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
        } else {
            $current .= $c;
        }
    }
    if (trim($current) !== '') $statements[] = trim($current);

    foreach ($statements as $stmt) {
        // Skip USE and CREATE DATABASE — already connected to correct DB
        if (preg_match('/^\s*(USE|CREATE\s+DATABASE)/i', $stmt)) continue;
        try { $pdo->exec($stmt); $ok++; }
        catch (Throwable $e) { $errors[] = substr($stmt, 0, 100) . ' → ' . $e->getMessage(); }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    if (empty($errors)) {
        box("✅ Imported $ok statements", "All SQL executed successfully.", 'success');
    } else {
        $errCount = count($errors);
        box("⚠️ $ok statements OK, $errCount errors", implode("\n", $errors), 'warn');
    }
}
echo '</div>';

// ─── Step 7 — Verify key tables ───────────────────────────────────────────────
echo '<div class="step"><h2>Step 7 — Table Check</h2>';
$expected = ['users','system_settings','retail_inventory','retail_orders','retail_order_items',
             'consignors','consignment_main_inventory','consignment_inventory',
             'consignment_assignments','consignment_sales','consignment_payouts','media_files'];
$existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$missing  = array_diff($expected, $existing);
if (empty($missing)) {
    box('✅ All tables present', implode(', ', $existing), 'success');
} else {
    box('⚠️ Missing tables', implode(', ', $missing), 'warn');
}
echo '</div>';

// ─── Step 8 — Upload folder writable ─────────────────────────────────────────
echo '<div class="step"><h2>Step 8 — Upload Directory Permissions</h2>';
$dirs = [__DIR__.'/uploads', __DIR__.'/uploads/products', __DIR__.'/uploads/branding'];
foreach ($dirs as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_writable($d)) {
        box('✅ Writable: ' . str_replace(__DIR__, '.', $d), '', 'success');
    } else {
        box('❌ Not writable: ' . str_replace(__DIR__, '.', $d), "Run: chmod 755 $d", 'error');
    }
}
echo '</div>';

// ─── Step 9 — Default admin ───────────────────────────────────────────────────
echo '<div class="step"><h2>Step 9 — Default Admin Account</h2>';
$admin = $pdo->query("SELECT email FROM users WHERE role='admin' LIMIT 1")->fetch();
if ($admin) {
    box('✅ Admin exists', 'Email: ' . $admin['email'] . "\nPassword: admin123\n\n⚠️ Change password after first login!", 'success');
} else {
    box('⚠️ No admin found', 'The SQL import may have failed. Check Step 6.', 'warn');
}
echo '</div>';

// ─── Summary ──────────────────────────────────────────────────────────────────
?>
<div class="step">
  <h2>🎉 Installation Summary</h2>
  <p><strong>App URL:</strong> <a href="https://app.palvinpavilion.com">https://app.palvinpavilion.com</a></p>
  <p><strong>Login:</strong> <code>admin@palvin.local</code> / <code>admin123</code></p>
  <p><strong>Database:</strong> <code>palvin_premium</code> @ <code>127.0.0.1</code> (user: <code>palvinpavilion</code>)</p>
  <div class="warn-box">
    <strong>⚠️ SECURITY: Delete install.php after setup!</strong><br>
    Run on the server: <code>rm /home/palvinpavilion/public_html/install.php</code><br>
    Or delete via Webmin File Manager.
  </div>
  <br>
  <a class="btn" href="https://app.palvinpavilion.com/index.php">→ Go to Login Page</a>
</div>
</body>
</html>
