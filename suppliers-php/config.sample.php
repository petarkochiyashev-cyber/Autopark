<?php
/*
 * Настройки на „Картотека доставчици“.
 * Копирайте този файл като config.php и попълнете стойностите.
 * config.php НЕ се качва в GitHub (вижте .gitignore).
 */
return [
    // MySQL база от cPanel → MySQL® Databases (Superhosting)
    'db_host' => 'localhost',
    'db_name' => 'потребител_dostavchici',
    'db_user' => 'потребител_dostavchici',
    'db_pass' => 'ПАРОЛА_ЗА_БАЗАТА',

    // Ключ за Anthropic API (console.anthropic.com → API Keys).
    // Оставете празно, ако не искате автоматично разчитане на визитки.
    'anthropic_api_key' => '',

    // Модел за разчитане на визитки.
    'anthropic_model' => 'claude-opus-5-5',

    // Име, което се показва в заглавието на страницата.
    'app_name' => 'Доставчици',

    // Часова зона за датите.
    'timezone' => 'Europe/Sofia',
];
