<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
security_headers();

/* One-time setup: creates the tables, the starting categories and the first administrator.
   Once an administrator exists, this page does nothing. Delete it from the server after setup. */

$errors = [];
$done = false;

try {
    $pdo = db();
} catch (Throwable $e) {
    $errors[] = 'Няма връзка с базата. Проверете db_host, db_name, db_user и db_pass в config.php. (' . $e->getMessage() . ')';
}

$hasAdmin = false;
if (!$errors) {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/lib/schema.sql')))) as $sql) {
        $pdo->exec($sql);
    }
    $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
}

if (!$errors && !$hasAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if ($name === '') $errors[] = 'Попълнете име.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Попълнете валиден имейл.';
    if (mb_strlen($pass) < 8) $errors[] = 'Паролата трябва да е поне 8 знака.';
    if (!$errors) {
        $pdo->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
            $seed = [
                ['Опаковъчни машини', 'machines'], ['Стреч обвиване и палетизиране', 'machines'],
                ['Термосвиваеми / фолиращи', 'machines'], ['Етикетиращи машини', 'machines'],
                ['Пълначни и дозиращи', 'machines'], ['Транспортьори', 'machines'],
                ['Компресори и пневматика', 'machines'], ['Автомобили и камиони', 'machines'],
                ['Фолио (стреч, термо)', 'consumables'], ['Кашони и опаковки', 'consumables'],
                ['Етикети и ленти за печат', 'consumables'], ['Тиксо и стрепинг', 'consumables'],
                ['Резервни части', 'consumables'], ['Масла и смазки', 'consumables'],
                ['Гуми', 'consumables'], ['Горива', 'consumables'],
            ];
            $st = $pdo->prepare('INSERT INTO categories (name, grp, sort_order) VALUES (?, ?, ?)');
            foreach ($seed as $i => [$n, $g]) $st->execute([$n, $g, $i + 1]);
        }
        $done = true;
        $hasAdmin = true;
    }
}

$uploadsOk = is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR);
?><!doctype html>
<html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Инсталация · Доставчици</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#eef1ef;color:#16201b;margin:0;padding:24px 16px}
.box{max-width:480px;margin:0 auto;background:#fff;border:1px solid #d5dbd7;border-radius:16px;padding:24px;display:flex;flex-direction:column;gap:14px}
h1{margin:0;font-size:22px}label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:#5b6861}
input{font:inherit;padding:10px 12px;border:1px solid #d5dbd7;border-radius:9px}
button,.btn{font:inherit;background:#1f8a5b;color:#fff;border:0;border-radius:10px;padding:11px 16px;cursor:pointer;text-decoration:none;text-align:center}
.err{background:#fde8e6;color:#8a1c14;border-radius:9px;padding:10px 12px}.ok{background:#d8efe3;border-radius:9px;padding:10px 12px}
.warn{background:#f8e8d3;border-radius:9px;padding:10px 12px}
</style></head><body><div class="box">
<h1>Инсталация на картотеката</h1>
<?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!$uploadsOk): ?><div class="warn">Папката <b>uploads</b> липсва или не може да се записва в нея. Създайте я и ѝ дайте права 755.</div><?php endif; ?>
<?php if ($done): ?>
  <div class="ok">Готово. Таблиците, началните категории и администраторът са създадени.</div>
  <div class="warn"><b>Изтрийте install.php от сървъра</b> (File Manager в cPanel).</div>
  <a class="btn" href="login.php">Към входа</a>
<?php elseif ($hasAdmin): ?>
  <div class="ok">Картотеката вече е инсталирана.</div>
  <div class="warn">Изтрийте install.php от сървъра.</div>
  <a class="btn" href="login.php">Към входа</a>
<?php elseif (isset($pdo)): ?>
  <p style="margin:0">Базата е свързана. Създайте първия администратор:</p>
  <form method="post" style="display:flex;flex-direction:column;gap:12px">
    <label for="name">Име<input id="name" name="name" required value="<?= h($_POST['name'] ?? '') ?>"></label>
    <label for="email">Имейл<input id="email" name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label for="password">Парола (поне 8 знака)<input id="password" name="password" type="password" minlength="8" required></label>
    <button type="submit">Създай и инсталирай</button>
  </form>
<?php endif; ?>
</div></body></html>
