<?php
declare(strict_types=1);

/* Core helpers: config, database, session, login, CSRF and JSON responses. */

const APP_ROOT = __DIR__ . '/..';
const UPLOAD_DIR = APP_ROOT . '/uploads';
const ROLES = ['admin' => 'Администратор', 'editor' => 'Редактор', 'viewer' => 'Само преглед'];

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Липсва config.php. Копирайте config.sample.php като config.php и попълнете данните за базата.');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Sofia');
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Keep MySQL's NOW() in the same time zone as PHP's date functions.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('dostavchici');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    session_start();
}

function current_user(): ?array
{
    start_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    static $user = false;
    if ($user === false) {
        $st = db()->prepare('SELECT id, email, name, role FROM users WHERE id = ?');
        $st->execute([$id]);
        $user = $st->fetch() ?: null;
        if (!$user) {
            unset($_SESSION['uid']);
        }
    }
    return $user;
}

function require_login_page(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): void
{
    if (!$token || !hash_equals(csrf_token(), $token)) {
        json_error('Сесията е изтекла. Презаредете страницата.', 419);
    }
}

function can_edit(array $u): bool
{
    return in_array($u['role'], ['admin', 'editor'], true);
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): never
{
    json_out(['error' => $msg], $code);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Login throttling: at most 8 failed attempts per IP in 15 minutes. */
function login_blocked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= 8;
}

function record_failed_login(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())')->execute([client_ip()]);
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_failed_logins(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}
