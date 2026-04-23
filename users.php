<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pageTitle = t('users');
$currentUser = auth_user();

// Handle actions
if (is_post()) {
    $action = post_action();

    // Staff cannot create users
    if ($action === 'create' && ($currentUser['role'] ?? '') !== 'admin') {
        flash('error', t('staff_no_create'));
        redirect_to('users.php');
    }

    if ($action === 'create') {
        $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([
                trim($_POST['full_name'] ?? ''),
                trim($_POST['email'] ?? ''),
                password_hash($_POST['password'] ?? '123456', PASSWORD_DEFAULT),
                trim($_POST['role'] ?? 'staff'),
            ]);
        flash('success', t('user_created'));
        redirect_to('users.php');
    }

    if ($action === 'update') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $fields = [trim($_POST['full_name'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['role'] ?? 'staff'), $uid];
        if (!empty(trim($_POST['password'] ?? ''))) {
            $pdo->prepare('UPDATE users SET full_name=?, email=?, role=?, password_hash=? WHERE id=?')
                ->execute([$fields[0], $fields[1], $fields[2], password_hash($_POST['password'], PASSWORD_DEFAULT), $uid]);
        } else {
            $pdo->prepare('UPDATE users SET full_name=?, email=?, role=? WHERE id=?')->execute($fields);
        }
        flash('success', t('user_updated'));
        redirect_to('users.php');
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)($currentUser['id'] ?? 0)) {
            flash('error', t('cannot_delete_self'));
        } else {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            flash('success', t('user_deleted'));
        }
        redirect_to('users.php');
    }
}

$users = db_all($pdo, 'SELECT * FROM users ORDER BY id DESC');
$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = db_one($pdo, 'SELECT * FROM users WHERE id=?', [(int)$_GET['edit']]);
}

require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-6 xl:grid-cols-[380px,1fr]">

    <!-- Create / Edit form — admin only -->
    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
    <div class="pvn-card p-6">
        <h3 class="font-semibold text-base mb-5 text-slate-800">
            <?= $editUser ? t('update_user') : t('create_user') ?>
        </h3>
        <form method="post" class="space-y-4">
            <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
            <?php if ($editUser): ?>
            <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('full_name') ?></label>
                <input name="full_name" class="pvn-input" placeholder="<?= t('full_name') ?>" value="<?= e($editUser['full_name'] ?? '') ?>" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('email') ?></label>
                <input name="email" type="email" class="pvn-input" placeholder="<?= t('email') ?>" value="<?= e($editUser['email'] ?? '') ?>" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('password') ?> <?= $editUser ? '<span class="text-slate-400 font-normal">(leave blank to keep)</span>' : '' ?></label>
                <input name="password" type="password" class="pvn-input" placeholder="<?= t('password') ?>" <?= $editUser ? '' : 'required' ?>>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5"><?= t('role') ?></label>
                <select name="role" class="pvn-input pvn-select">
                    <option value="staff" <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>><?= t('staff') ?></option>
                    <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>><?= t('admin') ?></option>
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="pvn-btn pvn-btn-primary flex-1 justify-center">
                    <?= $editUser ? t('update_user') : t('create_user') ?>
                </button>
                <?php if ($editUser): ?>
                <a href="users.php" class="pvn-btn pvn-btn-secondary"><?= t('cancel') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Users table -->
    <div class="pvn-card overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h3 class="font-semibold text-base text-slate-800"><?= t('users') ?></h3>
        </div>
        <div class="overflow-x-auto">
            <table class="pvn-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= t('full_name') ?></th>
                        <th><?= t('email') ?></th>
                        <th><?= t('role') ?></th>
                        <th><?= t('created_at') ?></th>
                        <?php if (($currentUser['role'] ?? '') === 'admin'): ?><th><?= t('actions') ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="text-slate-400 text-xs"><?= (int)$u['id'] ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-xs flex-shrink-0">
                                    <?= e(strtoupper(substr($u['full_name'], 0, 1))) ?>
                                </div>
                                <span class="font-medium"><?= e($u['full_name']) ?></span>
                                <?php if ((int)$u['id'] === (int)($currentUser['id'] ?? 0)): ?>
                                <span class="pvn-badge pvn-badge-blue text-xs">You</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-slate-500"><?= e($u['email']) ?></td>
                        <td>
                            <span class="pvn-badge <?= $u['role'] === 'admin' ? 'pvn-badge-purple' : 'pvn-badge-blue' ?>">
                                <?= e(ucfirst($u['role'])) ?>
                            </span>
                        </td>
                        <td class="text-slate-400 text-xs"><?= $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '—' ?></td>
                        <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                        <td>
                            <div class="flex items-center gap-2">
                                <a href="users.php?edit=<?= (int)$u['id'] ?>" class="pvn-btn pvn-btn-secondary pvn-btn-sm"><?= t('edit') ?></a>
                                <?php if ((int)$u['id'] !== (int)($currentUser['id'] ?? 0)): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="pvn-btn pvn-btn-danger pvn-btn-sm" data-confirm="<?= e(t('confirm_delete')) ?>"><?= t('delete') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                    <tr><td colspan="6" class="text-center py-10 text-slate-400"><?= t('no_data') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
