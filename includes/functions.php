<?php
if (!function_exists('app_config')) {
function app_config(string $key, $default = null) {
    global $config;
    return $config[$key] ?? $default;
}}
if (!function_exists('e')) {
function e($value = null): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}
if (!function_exists('is_post')) {
function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}}
if (!function_exists('redirect_to')) {
function redirect_to(string $path): void {
    if (!headers_sent()) { header('Location: ' . $path); exit; }
    echo '<script>window.location.href=' . json_encode($path) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . e($path) . '"></noscript>';
    exit;
}}
if (!function_exists('flash')) {
function flash(string $key, ?string $message = null) {
    if ($message !== null) { $_SESSION['_flash'][$key] = $message; return null; }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}}
if (!function_exists('auth_user')) {
function auth_user(): ?array { return $_SESSION['user'] ?? null; }}
if (!function_exists('require_login')) {
function require_login(): void { if (!auth_user()) redirect_to('index.php'); }}
if (!function_exists('require_admin')) {
function require_admin(): void {
    require_login();
    if ((auth_user()['role'] ?? '') !== 'admin') { http_response_code(403); exit('Access denied'); }
}}
if (!function_exists('db_one')) {
function db_one(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetch();
}}
if (!function_exists('db_all')) {
function db_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}}
if (!function_exists('db_value')) {
function db_value(PDO $pdo, string $sql, array $params = [], $default = 0) {
    $row = db_one($pdo, $sql, $params);
    if (!$row) return $default;
    return array_values($row)[0] ?? $default;
}}
if (!function_exists('setting')) {
function setting(PDO $pdo, string $key, ?string $default = ''): string {
    try {
        $row = db_one($pdo, 'SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        return $row['setting_value'] ?? (string)$default;
    } catch (Throwable $e) {
        return (string)$default;
    }
}}
if (!function_exists('save_setting')) {
function save_setting(PDO $pdo, string $key, string $value): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {}
}}
if (!function_exists('runtime_table_exists')) {
function runtime_table_exists(PDO $pdo, string $table): bool {
    try {
        return (int)db_value($pdo,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',[$table],0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}}
if (!function_exists('runtime_index_exists')) {
function runtime_index_exists(PDO $pdo, string $table, string $index): bool {
    return (int)db_value($pdo,'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',[$table,$index],0) > 0;
}}
if (!function_exists('ensure_runtime_index')) {
function ensure_runtime_index(PDO $pdo, string $table, string $index, string $definition): void {
    if (!runtime_table_exists($pdo, $table) || runtime_index_exists($pdo, $table, $index)) return;
    try { $pdo->exec('CREATE INDEX `' . $index . '` ON `' . $table . '` (' . $definition . ')'); } catch (Throwable $e) {}
}}
if (!function_exists('ensure_setting_defaults')) {
function ensure_setting_defaults(PDO $pdo, array $defaults): void {
    foreach ($defaults as $key => $value) {
        if (!db_one($pdo, 'SELECT setting_key FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])) {
            save_setting($pdo, $key, (string)$value);
        }
    }
}}
if (!function_exists('ensure_runtime_schema')) {
function ensure_runtime_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!runtime_table_exists($pdo, 'system_settings')) return;
    $schemaVersion = '2026-04-18-palvin-v3b';
    $currentVersion = db_value($pdo,'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1',['app_schema_version'],'');
    if ((string)$currentVersion === $schemaVersion) return;

    // Auto-migrate: add updated_at to consignment_payouts if missing (fixes HTTP 500 on older installs)
    try {
        $hasCols = db_all($pdo, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consignment_payouts'");
        $existingCols = array_column($hasCols, 'COLUMN_NAME');
        if (!in_array('updated_at', $existingCols, true)) {
            $pdo->exec("ALTER TABLE consignment_payouts ADD COLUMN updated_at DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('created_at', $existingCols, true)) {
            $pdo->exec("ALTER TABLE consignment_payouts ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
        }
    } catch (Throwable $e) {}

    ensure_setting_defaults($pdo, [
        'telegram_enabled'=>'0','telegram_bot_token'=>'','telegram_chat_id'=>'',
        'telegram_message_thread_id'=>'','telegram_retail_alerts'=>'1','telegram_consignment_alerts'=>'1',
        'exchange_rate'=>'4100','currency_display'=>'both','app_language'=>'en',
        'invoice_size'=>'A4','pos_display_mode'=>'grid','language_switch_enabled'=>'1','custom_css'=>'',
    ]);
    $indexes = [
        'retail_orders'=>['idx_retail_orders_order_date'=>'`order_date`','idx_retail_orders_contact_number'=>'`contact_number`','idx_retail_orders_payment_order_date'=>'`payment_type`, `order_date`'],
        'retail_order_items'=>['idx_retail_order_items_item_name'=>'`item_name`'],
        'retail_inventory'=>['idx_retail_inventory_item_name'=>'`item_name`','idx_retail_inventory_item_code'=>'`item_code`'],
        'consignment_assignments'=>['idx_consignment_assignments_delivery_no'=>'`delivery_no`','idx_consignment_assignments_consignor_delivery'=>'`consignor_id`, `delivery_no`'],
        'consignment_inventory'=>['idx_consignment_inventory_main_balance'=>'`main_inventory_id`, `stock_balance`','idx_consignment_inventory_consignor_assignment'=>'`consignor_id`, `assignment_id`'],
        'consignment_sales'=>['idx_consignment_sales_sold_at'=>'`sold_at`','idx_consignment_sales_invoice_no'=>'`invoice_no`','idx_consignment_sales_assignment_sold_at'=>'`assignment_id`, `sold_at`','idx_consignment_sales_consignor_sold_at'=>'`consignor_id`, `sold_at`'],
        'consignment_payouts'=>['idx_consignment_payouts_claim_month_status'=>'`claim_month`, `status`','idx_consignment_payouts_consignor_claim_month'=>'`consignor_id`, `claim_month`'],
        'media_files'=>['idx_media_files_uploaded_at'=>'`uploaded_at`'],
    ];
    foreach ($indexes as $table => $tableIndexes) {
        foreach ($tableIndexes as $index => $definition) ensure_runtime_index($pdo, $table, $index, $definition);
    }
    save_setting($pdo, 'app_schema_version', $schemaVersion);
}}
if (!function_exists('money')) {
function money($value): string { return '$' . number_format((float)$value, 2); }}
// Dual currency display - USD + KHR
if (!function_exists('money_dual')) {
function money_dual(PDO $pdo, $value, bool $inline = false): string {
    $usd = (float)$value;
    $rate = (float)setting($pdo, 'exchange_rate', '4100');
    if ($rate <= 0) $rate = 4100;
    $khr = $usd * $rate;
    $display = setting($pdo, 'currency_display', 'both');
    $usdStr = '$' . number_format($usd, 2);
    $khrStr = '&#x17DB;' . number_format($khr, 0);
    if ($display === 'usd') return $usdStr;
    if ($display === 'khr') return $khrStr;
    if ($inline) return $usdStr . ' <span class="text-neutral-400 text-xs font-normal">(' . $khrStr . ')</span>';
    return $usdStr . '<br><span class="text-xs text-neutral-400 font-normal">' . $khrStr . '</span>';
}}
if (!function_exists('money_khr')) {
function money_khr(PDO $pdo, $value): string {
    $usd = (float)$value;
    $rate = (float)setting($pdo, 'exchange_rate', '4100');
    if ($rate <= 0) $rate = 4100;
    return '&#x17DB;' . number_format($usd * $rate, 0);
}}
if (!function_exists('upload_file')) {
function upload_file(array $file, string $subDir, array $allowed = ['jpg','jpeg','png','webp','gif','svg']): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    $name = uniqid('', true) . '.' . $ext;
    $targetDir = rtrim(app_config('upload_dir'), '/\\') . '/' . trim($subDir, '/\\');
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $target = $targetDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) return null;
    return 'uploads/' . trim($subDir, '/\\') . '/' . $name;
}}
if (!function_exists('csv_headers')) {
function csv_headers(string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}}
if (!function_exists('excel_headers')) {
function excel_headers(string $filename): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}}
if (!function_exists('invoice_logo')) {
function invoice_logo(PDO $pdo): string {
    $logo = setting($pdo, 'invoice_logo', '');
    return $logo !== '' ? $logo : app_config('default_logo');
}}
if (!function_exists('app_favicon')) {
function app_favicon(PDO $pdo): string {
    return setting($pdo, 'app_favicon', '');
}}
if (!function_exists('customer_type_label')) {
function customer_type_label(PDO $pdo, string $phone): string {
    if ($phone === '') return 'New';
    $count = (int)db_value($pdo, 'SELECT COUNT(*) FROM retail_orders WHERE contact_number = ?', [$phone], 0);
    return $count > 0 ? 'Old' : 'New';
}}
if (!function_exists('consignor_unclaimed_status')) {
function consignor_unclaimed_status(PDO $pdo, int $consignorId): array {
    return db_all($pdo, "SELECT invoice_no, payout_due, status, claim_month FROM consignment_payouts WHERE consignor_id = ? AND status IN ('pending','overdue') ORDER BY claim_month ASC", [$consignorId]);
}}
if (!function_exists('claim_cutoff_date')) {
function claim_cutoff_date(PDO $pdo): string {
    $mode = setting($pdo, 'claim_alert_mode', 'auto');
    if ($mode === 'manual') {
        $manual = setting($pdo, 'manual_claim_cutoff', '');
        if ($manual !== '') return date('Y-m-01', strtotime($manual));
    }
    return date('Y-m-01');
}}
if (!function_exists('update_overdue_payouts')) {
function update_overdue_payouts(PDO $pdo): void {
    try {
        $cutoff = claim_cutoff_date($pdo);
        // Note: updated_at omitted here for compatibility with older DB schemas
        $pdo->prepare("UPDATE consignment_payouts SET status='overdue' WHERE status='pending' AND claim_month < ?")->execute([$cutoff]);
    } catch (Throwable $e) {
        // Never crash the page if payout table is missing or has schema differences
    }
}}
if (!function_exists('paginate_meta')) {
function paginate_meta(int $total, int $defaultPerPage = 10, int $maxPerPage = 50): array {
    $raw = $_GET['per_page'] ?? $defaultPerPage;
    if ($raw === 'all') { $perPage = max(1, $total); return ['page'=>1,'per_page'=>$perPage,'pages'=>1,'offset'=>0,'total'=>$total,'show_all'=>true]; }
    $perPage = (int)$raw;
    if ($perPage < 1) $perPage = $defaultPerPage;
    if ($perPage > $maxPerPage) $perPage = $maxPerPage;
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($page > $pages) $page = $pages;
    return ['page'=>$page,'per_page'=>$perPage,'pages'=>$pages,'offset'=>($page-1)*$perPage,'total'=>$total,'show_all'=>false];
}}
if (!function_exists('pagination_links')) {
function pagination_links(array $meta): array {
    if (($meta['show_all'] ?? false) || $meta['pages'] <= 1) return [];
    $links = [];
    for ($i = 1; $i <= $meta['pages']; $i++) {
        $params = $_GET; $params['page'] = $i; $params['per_page'] = $meta['per_page'];
        $links[] = ['label'=>(string)$i,'href'=>'?'.http_build_query($params),'active'=>$i===$meta['page']];
    }
    return $links;
}}
if (!function_exists('page_size_options')) {
function page_size_options(array $custom = [6,8,10,12,'all']): array { return $custom; }}
if (!function_exists('normalize_report_date')) {
function normalize_report_date(string $value, string $fallback): string {
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $fallback;
    return $value;
}}
if (!function_exists('report_range')) {
function report_range(): array {
    $today = new DateTimeImmutable('today');
    $defaultEnd = $today->format('Y-m-d');
    $defaultStart = $today->sub(new DateInterval('P1M'))->format('Y-m-d');
    $end = normalize_report_date((string)($_GET['end_date'] ?? ''), $defaultEnd);
    $start = normalize_report_date((string)($_GET['start_date'] ?? ''), $defaultStart);
    if ($start > $end) [$start, $end] = [$end, $start];
    return [$start, $end];
}}
if (!function_exists('report_datetime_bounds')) {
function report_datetime_bounds(?string $startDate = null, ?string $endDate = null): array {
    if ($startDate === null || $endDate === null) [$startDate, $endDate] = report_range();
    $start = new DateTimeImmutable($startDate . ' 00:00:00');
    $endExclusive = (new DateTimeImmutable($endDate . ' 00:00:00'))->modify('+1 day');
    return [$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')];
}}
if (!function_exists('nav_active')) {
function nav_active(array $files, string $current): bool { return in_array($current, $files, true); }}
if (!function_exists('post_action')) {
function post_action(): string { return trim((string)($_POST['action'] ?? '')); }}
if (!function_exists('discount_types')) {
function discount_types(): array { return ['amount' => 'Amount', 'percent' => 'Percent', 'fixed' => 'Fixed']; }}
if (!function_exists('generate_document_number')) {
function generate_document_number(PDO $pdo, string $table, string $column, string $prefix): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column))
        throw new InvalidArgumentException('Invalid table or column name.');
    for ($i = 0; $i < 8; $i++) {
        try { $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)); }
        catch (Throwable $e) { $suffix = strtoupper(str_pad(dechex((int)mt_rand(0, 65535)), 4, '0', STR_PAD_LEFT)); }
        $candidate = strtoupper($prefix) . '-' . date('YmdHis') . '-' . $suffix;
        if ((int)db_value($pdo, 'SELECT COUNT(*) FROM `'.$table.'` WHERE `'.$column.'` = ?', [$candidate], 0) === 0) return $candidate;
        usleep(50000);
    }
    return strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper((string)mt_rand(10000, 99999));
}}
if (!function_exists('telegram_message_from_lines')) {
function telegram_message_from_lines(array $lines): string {
    $clean = [];
    foreach ($lines as $line) { $text = trim((string)$line); if ($text !== '') $clean[] = $text; }
    return implode("\n", $clean);
}}
if (!function_exists('telegram_setting_on')) {
function telegram_setting_on(PDO $pdo, string $key, string $default = '0'): bool { return setting($pdo, $key, $default) === '1'; }}
if (!function_exists('telegram_config')) {
function telegram_config(PDO $pdo): array {
    return ['enabled'=>telegram_setting_on($pdo,'telegram_enabled','0'),'bot_token'=>trim(setting($pdo,'telegram_bot_token','')),'chat_id'=>trim(setting($pdo,'telegram_chat_id','')),'thread_id'=>trim(setting($pdo,'telegram_message_thread_id','')),'retail_enabled'=>telegram_setting_on($pdo,'telegram_retail_alerts','1'),'consignment_enabled'=>telegram_setting_on($pdo,'telegram_consignment_alerts','1')];
}}
if (!function_exists('telegram_enabled_for_channel')) {
function telegram_enabled_for_channel(PDO $pdo, string $channel): bool {
    $config = telegram_config($pdo);
    if (!$config['enabled']) return false;
    return match ($channel) { 'retail' => $config['retail_enabled'], 'consignment' => $config['consignment_enabled'], default => true };
}}
if (!function_exists('send_telegram_message')) {
function send_telegram_message(PDO $pdo, string $message, array $override = []): array {
    $config = telegram_config($pdo);
    if (empty($override['ignore_enabled']) && !$config['enabled']) return ['ok'=>false,'error'=>'Telegram alerts are disabled.'];
    $token = trim((string)($override['bot_token'] ?? $config['bot_token']));
    $chatId = trim((string)($override['chat_id'] ?? $config['chat_id']));
    $threadId = trim((string)($override['thread_id'] ?? $config['thread_id']));
    $message = trim($message);
    if ($message === '') return ['ok'=>false,'error'=>'Telegram message is empty.'];
    if ($token === '' || $chatId === '') return ['ok'=>false,'error'=>'Telegram bot token or chat ID is missing.'];
    $payload = ['chat_id'=>$chatId,'text'=>$message,'disable_web_page_preview'=>'true'];
    if ($threadId !== '' && preg_match('/^-?\d+$/', $threadId)) $payload['message_thread_id'] = (int)$threadId;
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = http_build_query($payload);
    $responseBody = ''; $httpStatus = 0; $transportError = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $responseBody = (string)curl_exec($ch);
        if ($responseBody === '' && curl_errno($ch)) $transportError = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>12,'ignore_errors'=>true]]);
        $responseBody = (string)@file_get_contents($url, false, $context);
        if ($responseBody === '' || $responseBody === false) $transportError = 'Unable to reach Telegram API.';
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) $httpStatus = (int)$match[1];
    }
    if ($transportError !== '') return ['ok'=>false,'error'=>$transportError];
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded) || (($decoded['ok'] ?? false) !== true)) {
        $description = is_array($decoded) ? (string)($decoded['description'] ?? '') : '';
        if ($description === '') $description = $httpStatus > 0 ? 'Telegram API error (HTTP '.$httpStatus.').' : 'Telegram API error.';
        return ['ok'=>false,'error'=>$description];
    }
    return ['ok'=>true,'error'=>'','response'=>$decoded];
}}
// Database import helper
if (!function_exists('import_sql_file')) {
function import_sql_file(PDO $pdo, string $content): void {
    $statements = preg_split('/;\s*[\r\n]+/', $content);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || preg_match('/^--/', $stmt)) continue;
        try { $pdo->exec($stmt); } catch (PDOException $e) {}
    }
}}
