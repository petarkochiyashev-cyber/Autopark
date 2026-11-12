<?php
declare(strict_types=1);

/* App settings (general texts, field labels, extra fields, category groups) stored as one JSON document. */

const DEFAULT_GROUPS = [['id' => 'machines', 'name' => 'Машини', 'color' => 'blue'], ['id' => 'consumables', 'name' => 'Консумативи', 'color' => 'amber']];
const STD_FIELDS = ['company', 'person', 'position', 'phones', 'emails', 'website', 'address', 'city', 'eik', 'products', 'notes'];
const STD_LABELS = ['company' => 'Фирма', 'person' => 'Лице', 'position' => 'Длъжност', 'phones' => 'Телефони', 'emails' => 'Имейли',
    'website' => 'Сайт', 'address' => 'Адрес', 'city' => 'Град', 'eik' => 'ЕИК', 'products' => 'Предлага', 'notes' => 'Бележки'];
const COLORS = ['blue', 'amber', 'green', 'red', 'violet', 'teal', 'pink', 'slate'];
const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'yesno'];

/** Brings an older installation up to date: settings table, free-form groups, extra fields. Safe to run often. */
function ensure_schema(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $col = $pdo->query("SHOW COLUMNS FROM categories LIKE 'grp'")->fetch();
    if ($col && stripos((string)$col['Type'], 'enum') === 0) {
        $pdo->exec('ALTER TABLE categories MODIFY grp VARCHAR(40) NOT NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'extra'")->fetch()) {
        $pdo->exec('ALTER TABLE suppliers ADD COLUMN extra TEXT NULL AFTER notes');
    }
}

function s_str(mixed $v, int $max, string $def = ''): string
{
    return is_string($v) ? mb_substr(trim($v), 0, $max) : $def;
}

/** Same rules as cleanSettings() in the page: unknown keys are dropped, every value is bounded. */
function clean_settings(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $g = is_array($raw['general'] ?? null) ? $raw['general'] : [];
    $out = ['general' => [
        'appName' => s_str($g['appName'] ?? null, 60) ?: 'Доставчици',
        'subtitle' => s_str($g['subtitle'] ?? null, 80, 'картотека визитки'),
        'headline' => s_str($g['headline'] ?? null, 160) ?: 'Всеки доставчик. *Една снимка* разстояние.',
        'defaultSort' => in_array($g['defaultSort'] ?? '', ['name', 'new', 'old', 'rating'], true) ? $g['defaultSort'] : 'name',
        'defaultView' => in_array($g['defaultView'] ?? '', ['grid', 'list'], true) ? $g['defaultView'] : 'grid',
        'skipDuplicates' => ($g['skipDuplicates'] ?? true) !== false,
    ], 'groups' => [], 'fields' => new stdClass(), 'custom' => []];
    $seen = [];
    foreach (is_array($raw['groups'] ?? null) ? $raw['groups'] : [] as $x) {
        $id = (string)($x['id'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $id) || isset($seen[$id])) continue;
        $seen[$id] = true;
        $out['groups'][] = ['id' => $id, 'name' => s_str($x['name'] ?? null, 40) ?: $id,
            'color' => in_array($x['color'] ?? '', COLORS, true) ? $x['color'] : 'slate'];
        if (count($out['groups']) >= 12) break;
    }
    if (!$out['groups']) $out['groups'] = DEFAULT_GROUPS;
    $fields = [];
    foreach (STD_FIELDS as $k) {
        $f = $raw['fields'][$k] ?? null;
        if (!is_array($f)) continue;
        $fields[$k] = ['label' => s_str($f['label'] ?? null, 60), 'hidden' => $k !== 'company' && ($f['hidden'] ?? false) === true];
    }
    if ($fields) $out['fields'] = $fields;
    $cs = [];
    foreach (is_array($raw['custom'] ?? null) ? $raw['custom'] : [] as $x) {
        $id = (string)($x['id'] ?? '');
        if (!preg_match('/^x_[a-z0-9]{3,16}$/', $id) || isset($cs[$id])) continue;
        $cs[$id] = true;
        $opts = array_values(array_filter(array_map(fn($o) => s_str($o, 80), is_array($x['options'] ?? null) ? $x['options'] : [])));
        $out['custom'][] = ['id' => $id, 'label' => s_str($x['label'] ?? null, 60) ?: 'Поле',
            'type' => in_array($x['type'] ?? '', FIELD_TYPES, true) ? $x['type'] : 'text', 'options' => array_slice($opts, 0, 50)];
        if (count($out['custom']) >= 40) break;
    }
    return $out;
}

function load_settings(): array
{
    static $s = null;
    if ($s === null) {
        $v = db()->query("SELECT v FROM settings WHERE k = 'app'")->fetchColumn();
        $s = clean_settings($v ? json_decode((string)$v, true) : []);
    }
    return $s;
}

function save_settings(array $s): void
{
    db()->prepare("REPLACE INTO settings (k, v) VALUES ('app', ?)")
        ->execute([json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

/** @return array<string,string> group id => name */
function group_names(): array
{
    return array_column(load_settings()['groups'], 'name', 'id');
}

/** @return array<string,array{label:string,type:string}> custom field id => definition */
function custom_fields(): array
{
    return array_column(load_settings()['custom'], null, 'id');
}

function field_label(string $k): string
{
    $f = load_settings()['fields'];
    $f = is_array($f) ? $f : [];
    return ($f[$k]['label'] ?? '') ?: STD_LABELS[$k];
}

/** Keeps only well-formed extra-field values: ids like x_ab12, string values up to 2000 characters. */
function clean_extra(mixed $raw): array
{
    if (is_string($raw)) $raw = json_decode($raw, true);
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $k => $v) {
        if (!preg_match('/^x_[a-z0-9]{3,16}$/', (string)$k) || !is_scalar($v)) continue;
        $v = mb_substr(trim((string)$v), 0, 2000);
        if ($v !== '') $out[$k] = $v;
        if (count($out) >= 60) break;
    }
    return $out;
}
