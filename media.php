<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pageTitle = 'Media';
if (is_post()) {
    $path = upload_file($_FILES['media'] ?? [], 'media', ['jpg','jpeg','png','webp','gif','svg','pdf']);
    if ($path) {
        $pdo->prepare('INSERT INTO media_files (file_name, file_path, file_type, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, NOW())')->execute([basename($path), $path, pathinfo($path, PATHINFO_EXTENSION), auth_user()['full_name'] ?? '']);
        flash('success', 'Media uploaded.');
    } else flash('error', 'Upload failed.');
    redirect_to('media.php');
}
if (isset($_GET['delete'])) {
    $row = db_one($pdo, 'SELECT * FROM media_files WHERE id=?', [(int)$_GET['delete']]);
    if ($row) { @unlink(__DIR__ . '/' . $row['file_path']); $pdo->prepare('DELETE FROM media_files WHERE id=?')->execute([$row['id']]); flash('success', 'Media deleted.'); }
    redirect_to('media.php');
}
$total=(int)db_value($pdo,'SELECT COUNT(*) FROM media_files');
$meta=paginate_meta($total,8,100);
$rows=db_all($pdo,'SELECT * FROM media_files ORDER BY id DESC LIMIT '.(int)$meta['per_page'].' OFFSET '.(int)$meta['offset']);
require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-6 xl:grid-cols-[360px,1fr]">
<form method="post" enctype="multipart/form-data" class="pvn-card p-6 space-y-4 no-print"><h3 class="font-semibold text-lg">Upload media</h3><input type="file" name="media" class="pvn-input" required><button class="pvn-btn pvn-btn-primary">Upload</button></form>
<div class="pvn-card p-6"><div class="flex items-center justify-between mb-4 no-print"><h3 class="font-semibold text-lg">Media library</h3><form class="flex items-center gap-2"><select name="per_page" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?php foreach (page_size_options([6,8,10,12,'all']) as $opt): ?><option value="<?= e((string)$opt) ?>" <?= ((string)($meta['show_all'] ? 'all' : $meta['per_page'])) === (string)$opt ? 'selected' : '' ?>><?= e(is_string($opt)?strtoupper($opt):(string)$opt) ?></option><?php endforeach; ?></select><button class="pvn-btn pvn-btn-secondary pvn-btn-sm">Apply</button></form></div><div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4"><?php foreach($rows as $row): ?><div class="rounded-2xl border p-4 bg-neutral-50"><?php if (in_array(strtolower($row['file_type']), ['jpg','jpeg','png','webp','gif','svg'], true)): ?><img src="<?= e($row['file_path']) ?>" class="w-full h-40 object-cover rounded-xl mb-3"><?php else: ?><div class="h-40 rounded-xl bg-white border flex items-center justify-center mb-3">PDF</div><?php endif; ?><div class="text-sm font-medium break-all"><?= e($row['file_name']) ?></div><div class="text-xs text-neutral-500"><?= e($row['uploaded_by']) ?> · <?= e($row['uploaded_at']) ?></div><div class="mt-3 flex gap-2"><a class="rounded-xl border px-3 py-2 text-sm" target="_blank" href="<?= e($row['file_path']) ?>">Open</a><a class="rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-700" href="?delete=<?= (int)$row['id'] ?>" data-confirm="Delete this file?">Delete</a></div></div><?php endforeach; ?></div><div class="mt-4 flex flex-wrap gap-2 no-print"><?php foreach (pagination_links($meta) as $link): ?><a href="<?= e($link['href']) ?>" class="rounded-xl px-3 py-2 border <?= $link['active'] ? 'bg-neutral-950 text-white border-neutral-950' : 'bg-white' ?>"><?= e($link['label']) ?></a><?php endforeach; ?></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>