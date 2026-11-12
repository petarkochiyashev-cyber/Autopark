<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/claude.php';
security_headers();

/* JSON API for the page. Reads need a signed-in user; writes need the editor or admin role and a CSRF token. */

$user = current_user();
if (!$user) {
    json_error('Не сте влезли в профила си. Презаредете страницата.', 401);
}
$action = (string)($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}
$writes = ['parse_text', 'import_rows', 'supplier_save', 'supplier_fav', 'supplier_delete', 'category_save', 'category_delete', 'read_card', 'user_save', 'user_delete'];
if (in_array($action, $writes, true) && !$isPost) {
    json_error('Това действие изисква POST.', 405);
}
$in = $isPost ? ($_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: [])) : $_GET;

function need_editor(array $u): void
{
    if (!can_edit($u)) json_error('Нямате права за промени. Помолете администратора.', 403);
}
function need_admin(array $u): void
{
    if ($u['role'] !== 'admin') json_error('Само администратор може да прави това.', 403);
}
function str_in(array $in, string $k, int $max = 255): string
{
    return mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
}
function ms(?string $dt): ?int
{
    return $dt ? (int)(strtotime($dt) * 1000) : null;
}

function load_suppliers(?int $id = null): array
{
    $sql = 'SELECT s.*, GROUP_CONCAT(sc.category_id) AS cats FROM suppliers s
            LEFT JOIN supplier_categories sc ON sc.supplier_id = s.id'
        . ($id ? ' WHERE s.id = ?' : '') . ' GROUP BY s.id';
    $st = db()->prepare($sql);
    $st->execute($id ? [$id] : []);
    return array_map(fn($r) => [
        'id' => (string)$r['id'],
        'company' => $r['company'], 'person' => $r['person'], 'position' => $r['position'],
        'phones' => (string)$r['phones'], 'emails' => (string)$r['emails'], 'website' => $r['website'],
        'address' => $r['address'], 'city' => $r['city'], 'eik' => $r['eik'],
        'products' => (string)$r['products'], 'notes' => (string)$r['notes'],
        'rating' => (int)$r['rating'], 'fav' => (bool)$r['fav'],
        'front' => $r['front'], 'back' => $r['back'],
        'categories' => $r['cats'] ? explode(',', $r['cats']) : [],
        'createdAt' => ms($r['created_at']), 'updatedAt' => ms($r['updated_at']),
    ], $st->fetchAll());
}

function load_categories(): array
{
    return array_map(fn($r) => ['id' => (string)$r['id'], 'name' => $r['name'], 'group' => $r['grp'], 'order' => (int)$r['sort_order']],
        db()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll());
}

/** Validates an uploaded image and stores it under uploads/ with a random name. */
function store_upload(array $f): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_error('Снимката не се качи. Опитайте отново.');
    if ($f['size'] > 12 * 1024 * 1024) json_error('Снимката е твърде голяма (максимум 12 MB).');
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) json_error('Поддържат се само снимки JPG, PNG и WEBP.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) json_error('Сървърът не можа да запише снимката. Проверете правата на папка uploads.', 500);
    return $name;
}
function delete_upload(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name)) @unlink(UPLOAD_DIR . '/' . $name);
}

switch ($action) {
    case 'bootstrap':
        json_out([
            'me' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
            'categories' => load_categories(),
            'suppliers' => load_suppliers(),
            'aiEnabled' => ai_enabled(),
        ]);

    case 'supplier_save':
        need_editor($user);
        $id = (int)($in['id'] ?? 0);
        $old = null;
        if ($id) {
            $st = db()->prepare('SELECT front, back FROM suppliers WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch() ?: json_error('Доставчикът не съществува.', 404);
        }
        $d = [
            'company' => str_in($in, 'company'), 'person' => str_in($in, 'person'), 'position' => str_in($in, 'position'),
            'phones' => str_in($in, 'phones', 2000), 'emails' => str_in($in, 'emails', 2000), 'website' => str_in($in, 'website'),
            'address' => str_in($in, 'address'), 'city' => str_in($in, 'city', 120), 'eik' => str_in($in, 'eik', 40),
            'products' => str_in($in, 'products', 4000), 'notes' => str_in($in, 'notes', 8000),
            'rating' => max(0, min(5, (int)($in['rating'] ?? 0))), 'fav' => !empty($in['fav']) && $in['fav'] !== 'false' ? 1 : 0,
        ];
        if ($d['company'] === '' && $d['person'] === '') json_error('Попълнете поне фирма или лице за контакт.');
        $toDelete = [];
        foreach (['front', 'back'] as $k) {
            $d[$k] = $old[$k] ?? null;
            if (!empty($_FILES[$k]['tmp_name'])) {
                $toDelete[] = $d[$k];
                $d[$k] = store_upload($_FILES[$k]);
            } elseif (!empty($in['remove_' . $k])) {
                $toDelete[] = $d[$k];
                $d[$k] = null;
            }
        }
        $cats = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($in['categories'] ?? '[]'), true)))));
        $pdo = db();
        $pdo->beginTransaction();
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d)));
            $pdo->prepare("UPDATE suppliers SET $sets, updated_at = NOW() WHERE id = :id")->execute($d + ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($d));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
            $pdo->prepare("INSERT INTO suppliers ($cols, created_by) VALUES ($vals, :uid)")->execute($d + ['uid' => $user['id']]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM supplier_categories WHERE supplier_id = ?')->execute([$id]);
        if ($cats) {
            $ins = $pdo->prepare('INSERT IGNORE INTO supplier_categories (supplier_id, category_id) SELECT ?, id FROM categories WHERE id = ?');
            foreach ($cats as $c) $ins->execute([$id, $c]);
        }
        $pdo->commit();
        foreach ($toDelete as $f) delete_upload($f);
        json_out(['supplier' => load_suppliers($id)[0]]);

    case 'supplier_fav':
        need_editor($user);
        db()->prepare('UPDATE suppliers SET fav = ? WHERE id = ?')->execute([!empty($in['fav']) ? 1 : 0, (int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);

    case 'supplier_delete':
        need_editor($user);
        $st = db()->prepare('SELECT front, back FROM suppliers WHERE id = ?');
        $st->execute([(int)($in['id'] ?? 0)]);
        if ($row = $st->fetch()) {
            db()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([(int)$in['id']]);
            delete_upload($row['front']);
            delete_upload($row['back']);
        }
        json_out(['ok' => true]);

    case 'category_save':
        need_editor($user);
        $name = str_in($in, 'name', 190);
        $grp = in_array($in['group'] ?? '', ['machines', 'consumables'], true) ? $in['group'] : null;
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            if ($name !== '') db()->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            if ($grp) db()->prepare('UPDATE categories SET grp = ? WHERE id = ?')->execute([$grp, $id]);
        } else {
            if ($name === '' || !$grp) json_error('Попълнете име и група на категорията.');
            $max = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM categories')->fetchColumn();
            db()->prepare('INSERT INTO categories (name, grp, sort_order) VALUES (?, ?, ?)')->execute([$name, $grp, $max + 1]);
        }
        json_out(['categories' => load_categories()]);

    case 'category_delete':
        need_editor($user);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        json_out(['categories' => load_categories()]);

    case 'read_card':
        need_editor($user);
        if (!ai_enabled()) json_error('Разчитането не е настроено. Добавете anthropic_api_key в config.php.');
        $images = [];
        foreach (['front', 'back'] as $k) {
            if (empty($_FILES[$k]['tmp_name']) || $_FILES[$k]['error'] !== UPLOAD_ERR_OK) continue;
            $info = @getimagesize($_FILES[$k]['tmp_name']);
            if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) json_error('Поддържат се само снимки JPG, PNG и WEBP.');
            if ($_FILES[$k]['size'] > 5 * 1024 * 1024) json_error('Снимката е твърде голяма за разчитане (максимум 5 MB).');
            $images[] = ['data' => file_get_contents($_FILES[$k]['tmp_name']), 'type' => $info['mime']];
        }
        if (!$images) json_error('Първо добавете снимка на визитката.');
        $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'grp' => $c['group']], load_categories());
        try {
            $r = read_business_card($images, $cats);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        $r['categoryIds'] = array_map('strval', array_filter((array)($r['categoryIds'] ?? []), 'is_numeric'));
        json_out(['card' => $r]);

    case 'parse_text':
        need_editor($user);
        if (!ai_enabled()) json_error('Разчитането не е настроено. Добавете anthropic_api_key в config.php.');
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') json_error('Първо поставете текст.');
        $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'grp' => $c['group']], load_categories());
        try {
            $r = read_supplier_text($text, $cats);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        $r['categoryIds'] = array_map('strval', array_filter((array)($r['categoryIds'] ?? []), 'is_numeric'));
        json_out(['card' => $r]);

    case 'import_rows':
        need_editor($user);
        $rows = $in['rows'] ?? null;
        if (!is_array($rows) || count($rows) > 200) json_error('Изпратете до 200 реда наведнъж.');
        $grp = in_array($in['group'] ?? '', ['machines', 'consumables'], true) ? $in['group'] : 'consumables';
        $pdo = db();
        $pdo->beginTransaction();
        $ids = [];
        foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $c) $ids[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM categories')->fetchColumn();
        $insCat = $pdo->prepare('INSERT INTO categories (name, grp, sort_order) VALUES (?, ?, ?)');
        foreach ((array)($in['newCats'] ?? []) as $name) {
            $name = mb_substr(trim((string)$name), 0, 190);
            if ($name === '' || isset($ids[mb_strtolower($name)])) continue;
            $insCat->execute([$name, $grp, ++$order]);
            $ids[mb_strtolower($name)] = (int)$pdo->lastInsertId();
        }
        $insSup = $pdo->prepare('INSERT INTO suppliers (company, person, position, phones, emails, website, address, city, eik, products, notes, rating, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insSc = $pdo->prepare('INSERT IGNORE INTO supplier_categories (supplier_id, category_id) VALUES (?, ?)');
        $added = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $v = fn(string $k, int $max = 255) => str_in($r, $k, $max);
            if ($v('company') === '' && $v('person') === '') continue;
            $insSup->execute([$v('company'), $v('person'), $v('position'), $v('phones', 2000), $v('emails', 2000), $v('website'),
                $v('address'), $v('city', 120), $v('eik', 40), $v('products', 4000), $v('notes', 8000),
                max(0, min(5, (int)($r['rating'] ?? 0))), $user['id']]);
            $sid = (int)$pdo->lastInsertId();
            foreach ((array)($r['catNames'] ?? []) as $n) {
                $cid = $ids[mb_strtolower(trim((string)$n))] ?? null;
                if ($cid) $insSc->execute([$sid, $cid]);
            }
            $added++;
        }
        $pdo->commit();
        json_out(['added' => $added]);

    case 'export_csv':
        $cats = array_column(load_categories(), 'name', 'id');
        $cols = ['company' => 'Фирма', 'person' => 'Лице', 'position' => 'Длъжност', 'phones' => 'Телефони', 'emails' => 'Имейли',
            'website' => 'Сайт', 'address' => 'Адрес', 'city' => 'Град', 'eik' => 'ЕИК', 'products' => 'Предлага',
            'cats' => 'Категории', 'rating' => 'Оценка', 'created' => 'Добавен', 'notes' => 'Бележки'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dostavchici-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($cols), ';', '"', '');
        foreach (load_suppliers() as $s) {
            $s['cats'] = implode('; ', array_filter(array_map(fn($id) => $cats[$id] ?? null, $s['categories'])));
            $s['created'] = $s['createdAt'] ? date('d.m.Y', intdiv($s['createdAt'], 1000)) : '';
            $row = [];
            foreach (array_keys($cols) as $k) {
                $v = (string)($s[$k] ?? '');
                // Keep spreadsheet formulas from running, but leave plain phone numbers like +359 88 123 4567 alone.
                $risky = preg_match('/^[=@\t\r]/', $v) || preg_match('/^[+\-].*[^\d\s().,;\/+\-]/', $v);
                $row[] = $risky ? "'" . $v : $v;
            }
            fputcsv($out, $row, ';', '"', '');
        }
        exit;

    case 'backup_zip':
        need_admin($user);
        if (!class_exists('ZipArchive')) json_error('Сървърът няма ZIP модул. Включете zip в PHP настройките на хостинга.', 500);
        $tmp = tempnam(sys_get_temp_dir(), 'bk');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('data.json', json_encode(['exportedAt' => date('c'), 'categories' => load_categories(), 'suppliers' => load_suppliers()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (glob(UPLOAD_DIR . '/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $f) $zip->addFile($f, 'uploads/' . basename($f));
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="dostavchici-backup-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;

    case 'users':
        need_admin($user);
        json_out(['users' => db()->query('SELECT id, email, name, role FROM users ORDER BY name')->fetchAll()]);

    case 'user_save':
        need_admin($user);
        $id = (int)($in['id'] ?? 0);
        $name = str_in($in, 'name', 190);
        $email = mb_strtolower(str_in($in, 'email', 190));
        $role = array_key_exists($in['role'] ?? '', ROLES) ? $in['role'] : 'editor';
        $pass = (string)($in['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Попълнете име и валиден имейл.');
        if ((!$id || $pass !== '') && mb_strlen($pass) < 8) json_error('Паролата трябва да е поне 8 знака.');
        if ($id === (int)$user['id'] && $role !== 'admin') json_error('Не можете да махнете собствените си администраторски права.');
        try {
            if ($id) {
                db()->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?')->execute([$name, $email, $role, $id]);
                if ($pass !== '') db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            } else {
                db()->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)')
                    ->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_error('Вече има потребител с този имейл.');
            throw $e;
        }
        json_out(['users' => db()->query('SELECT id, email, name, role FROM users ORDER BY name')->fetchAll()]);

    case 'user_delete':
        need_admin($user);
        $id = (int)($in['id'] ?? 0);
        if ($id === (int)$user['id']) json_error('Не можете да изтриете собствения си профил.');
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        json_out(['users' => db()->query('SELECT id, email, name, role FROM users ORDER BY name')->fetchAll()]);

    default:
        json_error('Непознато действие.', 404);
}
