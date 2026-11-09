<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
security_headers();
start_session();

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'Сесията е изтекла. Опитайте отново.';
    } elseif (login_blocked()) {
        $error = 'Твърде много неуспешни опити. Опитайте отново след 15 минути.';
    } else {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch();
        if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
            clear_failed_logins();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            unset($_SESSION['csrf']);
            header('Location: index.php');
            exit;
        }
        record_failed_login();
        usleep(400000);
        $error = 'Грешен имейл или парола.';
    }
}
$app = config()['app_name'] ?? 'Доставчици';
?><!doctype html>
<html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0e1814">
<title>Вход · <?= h($app) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=Unbounded:wght@600;700&display=swap">
<style>
:root{--hero:#0e1814;--hero-2:#13241d;--fg:#eaf2ed;--muted:#93a99d;--glow:#2fd58a;--line:rgba(160,220,190,.10);--err:#ff9b8f}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg);display:grid;place-items:center;
  padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));
  background:radial-gradient(700px 300px at 80% 0%,rgba(47,213,138,.18),transparent 70%),
  linear-gradient(var(--line) 1px,transparent 1px) 0 0/28px 28px,linear-gradient(90deg,var(--line) 1px,transparent 1px) 0 0/28px 28px,
  linear-gradient(160deg,var(--hero-2),var(--hero))}
form{width:min(380px,100%);display:flex;flex-direction:column;gap:14px;background:rgba(8,16,12,.6);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:26px;backdrop-filter:blur(6px)}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.mark{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--glow),#1b8f62);display:grid;place-items:center;color:#062015;font-family:Unbounded,system-ui;font-weight:700}
h1{font-family:Unbounded,system-ui,sans-serif;font-size:20px;margin:0}
label{display:flex;flex-direction:column;gap:5px;font-size:13px;color:var(--muted)}
input{font:inherit;font-size:16px;color:var(--fg);background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.16);border-radius:11px;padding:12px 13px;outline:none}
input:focus{border-color:var(--glow)}
button{font:inherit;font-weight:600;background:var(--glow);color:#062015;border:0;border-radius:11px;padding:12px;cursor:pointer;margin-top:4px}
button:focus-visible,input:focus-visible{outline:2px solid var(--glow);outline-offset:2px}
.err{color:var(--err);font-size:14px}
</style></head><body>
<form method="post" autocomplete="on">
  <div class="logo"><span class="mark" aria-hidden="true">Д</span><h1><?= h($app) ?></h1></div>
  <?php if ($error): ?><div class="err" role="alert"><?= h($error) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label for="email">Имейл<input id="email" name="email" type="email" autocomplete="username" required value="<?= h($_POST['email'] ?? '') ?>"></label>
  <label for="password">Парола<input id="password" name="password" type="password" autocomplete="current-password" required></label>
  <button type="submit">Вход</button>
</form>
</body></html>
