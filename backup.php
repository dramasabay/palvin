<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pageTitle = t('backup');

// Export / Download
if (isset($_GET['download'])) {
    $tables = db_all($pdo, 'SHOW TABLES');
    $names = array_map(fn($r) => array_values($r)[0], $tables);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="palvin-backup-' . date('Ymd-His') . '.sql"');
    foreach ($names as $table) {
        $create = db_one($pdo, 'SHOW CREATE TABLE `' . $table . '`');
        echo "DROP TABLE IF EXISTS `$table`;\n" . array_values($create)[1] . ";\n\n";
        $rows = db_all($pdo, 'SELECT * FROM `' . $table . '`');
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
            }
            echo 'INSERT INTO `' . $table . '` VALUES (' . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    exit;
}

// Import
$importMsg = '';
$importError = '';
if (is_post() && isset($_FILES['sql_file'])) {
    $file = $_FILES['sql_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($file['tmp_name']);
        if ($content !== false) {
            try {
                import_sql_file($pdo, $content);
                flash('success', t('import_success'));
                redirect_to('backup.php');
            } catch (Throwable $e) {
                $importError = t('import_error', ['error' => $e->getMessage()]);
            }
        } else {
            $importError = 'Could not read uploaded file.';
        }
    } else {
        $importError = 'File upload error code: ' . $file['error'];
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-6 md:grid-cols-2 max-w-4xl">

    <!-- Export -->
    <div class="pvn-card p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600 text-xl">⬇</div>
            <h3 class="font-semibold text-base text-slate-800"><?= t('backup') ?></h3>
        </div>
        <p class="text-sm text-slate-500 mb-6"><?= t('backup_desc') ?></p>
        <a href="?download=1" class="pvn-btn pvn-btn-primary w-full justify-center">
            ⬇ <?= t('download_backup') ?>
        </a>
    </div>

    <!-- Import -->
    <div class="pvn-card p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 text-xl">⬆</div>
            <h3 class="font-semibold text-base text-slate-800"><?= t('import_db') ?></h3>
        </div>
        <p class="text-sm text-slate-500 mb-4"><?= t('import_desc') ?></p>
        <?php if ($importError): ?>
            <div class="mb-4 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-700"><?= e($importError) ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('import_sql') ?></label>
                <input type="file" name="sql_file" accept=".sql" class="pvn-input" required>
            </div>
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-700">
                ⚠ <?= $lang === 'km' ? 'ការនាំចូលនឹងជំនួសទិន្នន័យដែលមានស្រាប់។ សូមបង្កើតការបម្រុងទុកមុន!' : 'Import will overwrite existing data. Make a backup first!' ?>
            </div>
            <button type="submit" class="pvn-btn pvn-btn-primary w-full justify-center" data-confirm="<?= e($lang === 'km' ? 'ប្រាកដទេ? វានឹងជំនួសទិន្នន័យដែលមានស្រាប់!' : 'Are you sure? This will overwrite existing data!') ?>">
                ⬆ <?= t('import_btn') ?>
            </button>
        </form>
    </div>

</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
