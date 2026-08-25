<?php
/**
 * Прокси приёма кастомной формы → создание Контакта + Сделки в воронку HelpDesk (Bitrix24).
 *
 * Логика:
 *   1) валидация входных данных;
 *   2) поиск дубля контакта по EMAIL и PHONE (crm.duplicate.findbycomm);
 *   3) если дубль найден  → создаём только сделку с CONTACT_ID найденного контакта;
 *      если не найден      → batch(halt): contact.add → deal.add($result[contact]);
 *      если в batch сделка упала, а контакт создался → удаляем контакт (компенсация,
 *      т.к. batch НЕ транзакционный и сам откат не делает).
 *
 * Разместите файл на своём сайте и настройте константу WEBHOOK ниже.
 */

// ─────────────────────────────  НАСТРОЙКИ  ─────────────────────────────
// Секрет (URL вебхука) лежит в config.local.php — он в .gitignore и НЕ коммитится.
// Скопируйте config.local.php.example → config.local.php и впишите свой вебхук.
$cfg = __DIR__ . '/config.local.php';
if (!is_file($cfg)) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Нет config.local.php — скопируйте из config.local.php.example и впишите вебхук'], JSON_UNESCAPED_UNICODE));
}
require $cfg; // определяет константу WEBHOOK

const HELPDESK_CATEGORY_ID = 2;        // воронка HelpDesk
const HELPDESK_STAGE_NEW   = 'C2:NEW'; // стартовая стадия «Новая»
const DEAL_SOURCE_ID       = 'WEB';    // источник

// CORS: укажите домены ваших сайтов (или '*' только на время теста).
const ALLOWED_ORIGIN = '*';

// На время отладки true — в ответ добавляется сырой ответ Битрикс. На проде — false.
const DEBUG = true;
// ───────────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('Только POST', 405); }

// ── Приём данных: поддерживаем и JSON, и обычный form-urlencoded ──
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { $in = $_POST; }

// ── Валидация ──
$name      = trim((string)($in['name']       ?? ''));
$lastName  = trim((string)($in['last_name']  ?? ''));
$email     = trim((string)($in['email']      ?? ''));
$phone     = trim((string)($in['phone']      ?? ''));
$siteUser  = trim((string)($in['site_user_id'] ?? '')); // UF контакта: ID пользователя на сайте
$site      = trim((string)($in['site']       ?? ''));   // домен сайта-источника
$message   = trim((string)($in['message']    ?? ''));

// ── Источник обращения ──
$pageUrl   = trim((string)($in['page_url']  ?? '')); // точный URL страницы с формой
$referrer  = trim((string)($in['referrer']  ?? '')); // откуда пользователь пришёл
$utm = [];
foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $k) {
    $v = trim((string)($in[$k] ?? ''));
    if ($v !== '') { $utm[strtoupper($k)] = $v; } // UTM_SOURCE, UTM_MEDIUM, ... — штатные поля сделки
}

// Поля сделки HelpDesk (значения enum — числовые ID, см. справочник)
$category  = $in['category']       ?? null; // UF_CRM_1786685648580
$priority  = $in['priority']       ?? null; // UF_CRM_1786685952807
$payMethod = $in['payment_method'] ?? null; // UF_CRM_1786686105228
$orderId   = trim((string)($in['order_id']       ?? ''));
$txId      = trim((string)($in['transaction_id'] ?? ''));
$clientId  = trim((string)($in['client_id']      ?? ''));

$errors = [];
if ($name === '' && $lastName === '') { $errors[] = 'Укажите имя или фамилию'; }
if ($email === '' && $phone === '')   { $errors[] = 'Укажите email или телефон'; }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Некорректный email'; }
if ($errors) { fail(implode('; ', $errors), 422); }

// ── Шаг 1. Поиск дубля контакта (по email и телефону сразу, одним пакетом) ──
$existingContactId = findDuplicateContact($email, $phone);

// ── Поля контакта ──
$contactFields = array_filter([
    'NAME'       => $name,
    'LAST_NAME'  => $lastName,
    'OPENED'     => 'Y',
    'TYPE_ID'    => 'CLIENT',
    'SOURCE_ID'  => DEAL_SOURCE_ID,
    'UF_CRM_1786546554' => $siteUser, // ID пользователя на сайте обращения
], fn($v) => $v !== '' && $v !== null);
if ($email !== '') { $contactFields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']]; }
if ($phone !== '') { $contactFields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']]; }

// ── Источник: короткая строка для SOURCE_DESCRIPTION и подробный блок в комментарий ──
$sourceShort = trim(implode(' | ', array_filter([$site, $pageUrl]))); // компактно для поля «Источник»

$srcLines = array_filter([
    $site      ? "Сайт: $site"            : '',
    $pageUrl   ? "Страница: $pageUrl"     : '',
    $referrer  ? "Реферер: $referrer"     : '',
    $utm       ? 'UTM: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($utm), $utm)) : '',
]);
$sourceBlock = $srcLines ? "── Источник обращения ──\n" . implode("\n", $srcLines) : '';

// COMMENTS = текст обращения + блок источника (для контекста менеджеру)
$comments = trim($message . ($sourceBlock ? ($message ? "\n\n" : '') . $sourceBlock : ''));

// ── Поля сделки ──
$title = 'Обращение' . ($site ? " с сайта $site" : '')
       . (($name || $lastName) ? ' — ' . trim("$name $lastName") : '');
$dealFields = array_filter([
    'TITLE'              => $title,
    'CATEGORY_ID'        => HELPDESK_CATEGORY_ID,
    'STAGE_ID'           => HELPDESK_STAGE_NEW,
    'SOURCE_ID'          => DEAL_SOURCE_ID,
    'SOURCE_DESCRIPTION' => $sourceShort,
    'COMMENTS'           => $comments,
    'UF_CRM_1786685648580' => $category,   // Категория
    'UF_CRM_1786685952807' => $priority,   // Приоритет
    'UF_CRM_1786686105228' => $payMethod,  // Payment Method
    'UF_CRM_1786686034932' => $clientId,   // Client/User ID
    'UF_CRM_1786686054889' => $orderId,    // Order ID
    'UF_CRM_1786686076193' => $txId,       // Transaction ID
    'UF_CRM_1786700225371' => $message,    // Описание проблемы (только текст обращения)
], fn($v) => $v !== '' && $v !== null) + $utm; // + штатные UTM_SOURCE/MEDIUM/CAMPAIGN/CONTENT/TERM

// ── Шаг 2. Создание ──
if ($existingContactId) {
    // Контакт уже есть — создаём только сделку. Орфанов быть не может.
    $dealFields['CONTACT_ID'] = $existingContactId;
    $deal = b24('crm.deal.add', ['fields' => $dealFields]);
    if (isset($deal['error'])) { fail('Ошибка создания сделки: ' . ($deal['error_description'] ?? $deal['error']), 502); }
    ok([
        'deal_id'          => $deal['result'],
        'contact_id'       => (int)$existingContactId,
        'contact_created'  => false,
    ]);
}

// Контакта нет — создаём контакт + сделку одним пакетом (halt=1).
// Команды batch передаём СТРОКАМИ (сырой endpoint не понимает форму {method,params}).
// Ссылку на результат $result[contact] дописываем литералом, без URL-кодирования.
$batch = b24('batch', [
    'halt' => 1,
    'cmd'  => [
        'contact' => cmdStr('crm.contact.add', ['fields' => $contactFields]),
        'deal'    => cmdStr('crm.deal.add',    ['fields' => $dealFields]) . '&fields[CONTACT_ID]=$result[contact]',
    ],
]);

// Ошибка верхнего уровня самого batch (нет прав, битый токен, неверный запрос и т.п.)
if (isset($batch['error'])) {
    fail('Batch отклонён Битриксом: ' . ($batch['error_description'] ?: $batch['error']), 502, $batch);
}

$res    = $batch['result']['result']       ?? [];
$resErr = $batch['result']['result_error']  ?? [];
$newContactId = $res['contact'] ?? null;
$newDealId    = $res['deal']    ?? null;

// Компенсация: контакт создан, но сделка упала → удаляем «висячий» контакт.
if ($newContactId && !$newDealId) {
    b24('crm.contact.delete', ['id' => $newContactId]);
    $de  = $resErr['deal'] ?? [];
    $why = $de['error_description'] ?: ($de['error'] ?? 'неизвестная ошибка');
    fail('Сделка не создана, контакт откачен. Причина: ' . $why, 502, $batch);
}
if (!$newDealId) {
    // Разбираем, что именно вернул Битрикс по каждой команде.
    $parts = [];
    foreach (['contact' => $resErr['contact'] ?? null, 'deal' => $resErr['deal'] ?? null] as $k => $e) {
        if ($e) { $parts[] = "$k: " . ($e['error_description'] ?: ($e['error'] ?? '?')); }
    }
    $msg = $parts ? implode('; ', $parts) : 'Битрикс вернул пустой результат без ошибки (проверьте права вебхука и STAGE_ID/CATEGORY_ID)';
    fail('Не удалось создать сделку: ' . $msg, 502, $batch);
}

ok([
    'deal_id'         => $newDealId,
    'contact_id'      => $newContactId,
    'contact_created' => true,
]);

// ─────────────────────────────  ФУНКЦИИ  ─────────────────────────────

/** Поиск существующего контакта по email и телефону (одним batch). Возвращает ID или null. */
function findDuplicateContact(string $email, string $phone): ?int {
    $cmd = [];
    if ($email !== '') {
        $cmd['byEmail'] = cmdStr('crm.duplicate.findbycomm',
            ['entity_type' => 'CONTACT', 'type' => 'EMAIL', 'values' => [$email]]);
    }
    if ($phone !== '') {
        $cmd['byPhone'] = cmdStr('crm.duplicate.findbycomm',
            ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => [$phone]]);
    }
    if (!$cmd) { return null; }

    $r = b24('batch', ['halt' => 0, 'cmd' => $cmd]);
    if (isset($r['error'])) { // напр. insufficient_scope — вебхук без права crm
        fail('Проверка дублей отклонена: ' . ($r['error_description'] ?: $r['error']), 502, $r);
    }
    $res = $r['result']['result'] ?? [];
    foreach (['byEmail', 'byPhone'] as $k) {
        $ids = $res[$k]['CONTACT'] ?? [];
        if (!empty($ids)) { return (int)$ids[0]; }
    }
    return null;
}

/** Строковая команда для batch: "method?fields[NAME]=...&fields[EMAIL][0][VALUE]=...". */
function cmdStr(string $method, array $params): string {
    return $method . '?' . http_build_query($params);
}

/** Один REST-вызов с ретраем на лимиты (503 QUERY_LIMIT_EXCEEDED / 429 OPERATION_TIME_LIMIT). */
function b24(string $method, array $params): array {
    $url = rtrim(WEBHOOK, '/') . '/' . $method . '.json';
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($code === 503 || $code === 429) && $attempt < 4) {
            usleep(500000 * $attempt); // 0.5s, 1s, 1.5s
            continue;
        }
        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : ['error' => 'BAD_RESPONSE', 'error_description' => "HTTP $code"];
    }
    return ['error' => 'RATE_LIMIT', 'error_description' => 'Превышен лимит запросов'];
}

function ok(array $payload): void   { echo json_encode(['ok' => true]  + $payload, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $c, ?array $raw = null): void {
    http_response_code($c);
    $out = ['ok' => false, 'error' => $msg];
    if (DEBUG && $raw !== null) { $out['debug'] = $raw; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
