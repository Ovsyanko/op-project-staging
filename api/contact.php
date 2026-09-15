<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://op-co.ru','https://www.op-co.ru',
    'https://op-project.ru','https://www.op-project.ru',
    'https://ovsyanko.github.io'
];
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    exit;
}

function fail(string $message, int $code=400): never {
    http_response_code($code);
    echo json_encode(['ok'=>false,'message'=>$message], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function ok(): never {
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function post(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function yes(string $key): bool { return in_array(strtolower(post($key)), ['1','on','yes','true'], true); }
function cleanHeader(string $s): string { return preg_replace('/[\r\n]+/u',' ',trim($s)) ?? ''; }
function encHeader(string $s): string { return '=?UTF-8?B?'.base64_encode($s).'?='; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Метод не поддерживается', 405);

// Honeypot: bots usually fill this hidden field. Return success without sending.
if (post('company_website') !== '') ok();

$name = post('name');
$phone = post('phone');
$email = post('email');
$message = post('message');
$pageUrl = post('page_url');
$pageTitle = post('page_title');
$newsletter = yes('newsletter');
$consent = yes('consent');

if ($name === '' || mb_strlen($name) > 100) fail('Укажите имя');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) fail('Укажите корректный E-mail');
if ($message === '' || mb_strlen($message) > 1000) fail('Опишите задачу (до 1000 знаков)');
if (!$consent) fail('Необходимо согласие на обработку персональных данных');
if ($phone !== '') {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) !== 11 || !in_array($digits[0] ?? '', ['7','8'], true)) fail('Укажите корректный номер телефона');
}
if (mb_strlen($pageUrl) > 500) $pageUrl = mb_substr($pageUrl,0,500);
if (mb_strlen($pageTitle) > 200) $pageTitle = mb_substr($pageTitle,0,200);

// Simple per-IP rate limiting: max 1 request per 20 sec and 10 per hour.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$key = hash('sha256', $ip . '|opproject-contact');
$rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'opform_' . $key . '.json';
$now = time();
$rate = ['last'=>0,'hits'=>[]];
if (is_file($rateFile)) {
    $decoded = json_decode((string)@file_get_contents($rateFile), true);
    if (is_array($decoded)) $rate = array_merge($rate,$decoded);
}
$hits = array_values(array_filter((array)$rate['hits'], fn($t)=>is_numeric($t) && (int)$t > $now-3600));
if (($now-(int)$rate['last']) < 20 || count($hits) >= 10) fail('Слишком много отправок. Попробуйте немного позже.', 429);
$hits[]=$now;
@file_put_contents($rateFile, json_encode(['last'=>$now,'hits'=>$hits]), LOCK_EX);

$configPath = dirname(__DIR__, 2) . '/opproject-contact-config.php';
if (!is_file($configPath)) fail('Форма временно не настроена. Напишите на sales@op-project.ru', 503);
$config = require $configPath;
if (!is_array($config)) fail('Ошибка конфигурации формы', 500);
foreach (['smtp_host','smtp_port','smtp_user','smtp_password','from_email','to_email'] as $k) {
    if (empty($config[$k]) || $config[$k] === 'PASTE_PASSWORD_HERE') fail('Форма временно не настроена. Напишите на sales@op-project.ru', 503);
}

function smtpRead($fp): array {
    $lines=[]; $code=0;
    while (!feof($fp)) {
        $line=fgets($fp, 8192);
        if ($line===false) break;
        $lines[]=$line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code=(int)$m[1];
            if ($m[2]===' ') break;
        }
    }
    return [$code, implode('', $lines)];
}
function smtpCmd($fp, string $cmd, array $okCodes): string {
    if ($cmd !== '') fwrite($fp, $cmd."\r\n");
    [$code,$text]=smtpRead($fp);
    if (!in_array($code,$okCodes,true)) throw new RuntimeException('SMTP '.$code.' '.$text);
    return $text;
}

$host=(string)$config['smtp_host'];
$port=(int)$config['smtp_port'];
$context=stream_context_create(['ssl'=>[
    'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false
]]);
$errno=0; $errstr='';
$fp=@stream_socket_client('ssl://'.$host.':'.$port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
if (!$fp) fail('Не удалось соединиться с почтовым сервером. Попробуйте позже.', 502);
stream_set_timeout($fp, 20);

try {
    smtpCmd($fp,'',[220]);
    smtpCmd($fp,'EHLO op-project.ru',[250]);
    smtpCmd($fp,'AUTH LOGIN',[334]);
    smtpCmd($fp,base64_encode((string)$config['smtp_user']),[334]);
    smtpCmd($fp,base64_encode((string)$config['smtp_password']),[235]);
    smtpCmd($fp,'MAIL FROM:<'.cleanHeader((string)$config['from_email']).'>',[250]);
    smtpCmd($fp,'RCPT TO:<'.cleanHeader((string)$config['to_email']).'>',[250,251]);
    smtpCmd($fp,'DATA',[354]);

    $subject='Новая заявка с сайта';
    if ($pageTitle!=='') $subject.=' — '.$pageTitle;
    $body = "Новая заявка с сайта\n\n".
            "Страница: ".($pageUrl ?: ($_SERVER['HTTP_REFERER'] ?? ''))."\n".
            "Заголовок страницы: ".$pageTitle."\n".
            "Имя: ".$name."\n".
            "Телефон: ".($phone ?: 'не указан')."\n".
            "E-mail: ".$email."\n".
            "Согласие на рассылку: ".($newsletter?'Да':'Нет')."\n".
            "Согласие на обработку ПД: Да\n".
            "Дата/время сервера: ".date('Y-m-d H:i:s T')."\n\n".
            "Задача:\n".$message."\n";

    $headers=[
        'Date: '.date(DATE_RFC2822),
        'From: '.encHeader((string)($config['from_name'] ?? 'OP Project website')).' <'.cleanHeader((string)$config['from_email']).'>',
        'To: '.encHeader((string)($config['to_name'] ?? 'OP Project Sales')).' <'.cleanHeader((string)$config['to_email']).'>',
        'Reply-To: '.encHeader($name).' <'.cleanHeader($email).'>',
        'Subject: '.encHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: OPProjectWebForm/1.0'
    ];
    $data=implode("\r\n",$headers)."\r\n\r\n".str_replace(["\r\n","\r"],"\n",$body);
    $data=str_replace("\n","\r\n",$data);
    $data=preg_replace('/\r\n\./','\r\n..',$data) ?? $data;
    fwrite($fp,$data."\r\n.\r\n");
    [$code,$text]=smtpRead($fp);
    if ($code!==250) throw new RuntimeException('SMTP '.$code.' '.$text);
    @fwrite($fp,"QUIT\r\n");
    fclose($fp);
    ok();
} catch (Throwable $e) {
    @fclose($fp);
    error_log('OP contact form SMTP error: '.$e->getMessage());
    fail('Не удалось отправить заявку. Попробуйте позже или напишите на sales@op-project.ru', 502);
}
