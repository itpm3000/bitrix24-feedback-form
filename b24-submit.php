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
// Входящий вебхук с правом scope=crm. Пример:
//   https://your-portal.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/
const WEBHOOK = 'https://your-portal.bitrix24.ru/rest/1/PUT_YOUR_TOKEN_HERE/';

const HELPDESK_CATEGORY_ID = 2;        // воронка HelpDesk
const HELPDESK_STAGE_NEW   = 'C2:NEW'; // стартовая стадия «Новая»
const DEAL_SOURCE_ID       = 'WEB';    // источник

// CORS: укажите домены ваших сайтов (или '*' только на время теста).
const ALLOWED_ORIGIN = '*';
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

// ── Поля сделки ──
$title = 'Обращение' . ($site ? " с сайта $site" : '')
       . (($name || $lastName) ? ' — ' . trim("$name $lastName") : '');
$dealFields = array_filter([
    'TITLE'              => $title,
    'CATEGORY_ID'        => HELPDESK_CATEGORY_ID,
    'STAGE_ID'           => HELPDESK_STAGE_NEW,
    'SOURCE_ID'          => DEAL_SOURCE_ID,
    'SOURCE_DESCRIPTION' => $site,
    'COMMENTS'           => $message,
    'UF_CRM_1786685648580' => $category,   // Категория
    'UF_CRM_1786685952807' => $priority,   // Приоритет
    'UF_CRM_1786686105228' => $payMethod,  // Payment Method
    'UF_CRM_1786686034932' => $clientId,   // Client/User ID
    'UF_CRM_1786686054889' => $orderId,    // Order ID
    'UF_CRM_1786686076193' => $txId,       // Transaction ID
    'UF_CRM_1786700225371' => $message,    // Описание проблемы
], fn($v) => $v !== '' && $v !== null);

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
$batch = b24('batch', [
    'halt' => 1,
    'cmd'  => [
        'contact' => ['method' => 'crm.contact.add', 'params' => ['fields' => $contactFields]],
        'deal'    => ['method' => 'crm.deal.add',    'params' => ['fields' => $dealFields + ['CONTACT_ID' => '$result[contact]']]],
    ],
]);

$res    = $batch['result']['result']       ?? [];
$resErr = $batch['result']['result_error']  ?? [];
$newContactId = $res['contact'] ?? null;
$newDealId    = $res['deal']    ?? null;

// Компенсация: контакт создан, но сделка упала → удаляем «висячий» контакт.
if ($newContactId && !$newDealId) {
    b24('crm.contact.delete', ['id' => $newContactId]);
    $why = $resErr['deal']['error_description'] ?? ($resErr['deal']['error'] ?? 'неизвестная ошибка');
    fail('Сделка не создана, контакт откачен. Причина: ' . $why, 502);
}
if (!$newDealId) {
    fail('Не удалось создать сделку: ' . json_encode($resErr, JSON_UNESCAPED_UNICODE), 502);
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
        $cmd['byEmail'] = ['method' => 'crm.duplicate.findbycomm',
            'params' => ['entity_type' => 'CONTACT', 'type' => 'EMAIL', 'values' => [$email]]];
    }
    if ($phone !== '') {
        $cmd['byPhone'] = ['method' => 'crm.duplicate.findbycomm',
            'params' => ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => [$phone]]];
    }
    if (!$cmd) { return null; }

    $r   = b24('batch', ['halt' => 0, 'cmd' => $cmd]);
    $res = $r['result']['result'] ?? [];
    foreach (['byEmail', 'byPhone'] as $k) {
        $ids = $res[$k]['CONTACT'] ?? [];
        if (!empty($ids)) { return (int)$ids[0]; }
    }
    return null;
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
function fail(string $msg, int $c): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }
