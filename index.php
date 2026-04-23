<?php
require __DIR__ . '/includes/bootstrap.php';
if (auth_user()) {
    redirect_to('dashboard.php');
}
if (is_post()) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $user = db_one($pdo, 'SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
    } catch (Throwable $e) {
        $user = null;
    }
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = ['id' => $user['id'], 'full_name' => $user['full_name'], 'email' => $user['email'], 'role' => $user['role']];
        redirect_to('dashboard.php');
    }
    flash('error', 'Invalid email or password.');
    redirect_to('index.php');
}
$logo = app_config('default_logo');
$faviconPath = isset($pdo) ? app_favicon($pdo) : '';
$lang = get_lang();
?>
<!doctype html>
<html lang="<?= $lang === 'km' ? 'km' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PALVIN Login</title>
<?php if ($faviconPath): ?><link rel="icon" href="<?= e($faviconPath) ?>"><?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Khmer:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body { font-family: <?= $lang === 'km' ? "'Noto Sans Khmer'," : '' ?> 'Inter', sans-serif; }
  .gradient-bg { background: linear-gradient(135deg, #0f0f13 0%, #1e1b4b 50%, #0f0f13 100%); }
  .login-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(20px); }
  .pvn-input { width:100%; padding:12px 16px; border:1.5px solid rgba(255,255,255,0.1); border-radius:14px; font-size:14px; background:rgba(255,255,255,0.06); color:#fff; outline:none; transition:border-color 0.15s, box-shadow 0.15s; }
  .pvn-input::placeholder { color:rgba(255,255,255,0.3); }
  .pvn-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.2); }
  .pvn-btn { width:100%; padding:13px; border-radius:14px; font-weight:600; font-size:15px; background:#6366f1; color:#fff; border:none; cursor:pointer; transition:all 0.15s; }
  .pvn-btn:hover { background:#4f46e5; transform:translateY(-1px); box-shadow:0 8px 24px rgba(99,102,241,0.4); }
</style>
</head>
<body class="min-h-screen gradient-bg flex items-center justify-center p-6">

<!-- Decorative background shapes -->
<div style="position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:0;">
  <div style="position:absolute;top:-200px;right:-200px;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,0.15) 0%,transparent 70%);"></div>
  <div style="position:absolute;bottom:-200px;left:-200px;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(139,92,246,0.1) 0%,transparent 70%);"></div>
</div>

<div style="position:relative;z-index:1;width:100%;max-width:400px;">
    <!-- Lang switch -->
    <?php $otherLang = $lang === 'km' ? 'en' : 'km'; $langLabel = $lang === 'km' ? 'English' : 'ខ្មែរ'; ?>
    <div class="text-center mb-6">
        <a href="?lang=<?= $otherLang ?>" style="color:rgba(255,255,255,0.4);font-size:13px;text-decoration:none;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.4)'">🌐 <?= $langLabel ?></a>
    </div>

    <div class="login-card rounded-3xl p-8">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="inline-flex w-16 h-16 rounded-2xl bg-indigo-600/20 border border-indigo-500/30 items-center justify-center mb-4 overflow-hidden">
                <img src="<?= e($logo) ?>" alt="PALVIN" class="w-full h-full object-contain" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=\'color:#818cf8;font-weight:700;font-size:24px\'>P</span>'">
            </div>
            <h1 style="font-size:24px;font-weight:700;color:#fff;margin:0;">PALVIN</h1>
            <p style="color:rgba(255,255,255,0.4);font-size:13px;margin-top:4px;">Retail &amp; Consignment System</p>
        </div>

        <?php if ($msg = flash('error')): ?>
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:12px;padding:12px 16px;color:#fca5a5;font-size:13px;margin-bottom:20px;">
            ✗ <?= e($msg) ?>
        </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div>
                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,0.6);margin-bottom:8px;"><?= t('email') ?></label>
                <input name="email" type="email" class="pvn-input" placeholder="you@example.com" required autofocus>
            </div>
            <div>
                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,0.6);margin-bottom:8px;"><?= t('password') ?></label>
                <input name="password" type="password" class="pvn-input" placeholder="••••••••" required>
            </div>
            <div style="padding-top:8px;">
                <button type="submit" class="pvn-btn">Login →</button>
            </div>
        </form>
    </div>
    <p style="text-align:center;color:rgba(255,255,255,0.2);font-size:12px;margin-top:24px;">PALVIN Premium · Phnom Penh</p>
</div>
</body>
</html>
