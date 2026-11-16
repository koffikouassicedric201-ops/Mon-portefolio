<?php

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function respond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(array(
        'success' => $success,
        'message' => $message
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

function postValue($key)
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

function contactRateLimitExceeded()
{
    if (empty($_SERVER['REMOTE_ADDR'])) {
        error_log('Contact form rate limit could not identify the client.');
        return null;
    }

    $filePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'portfolio-contact-' . hash('sha256', $_SERVER['REMOTE_ADDR']) . '.json';
    $file = @fopen($filePath, 'c+');

    if ($file === false) {
        error_log('Contact form rate limit storage is unavailable.');
        return null;
    }

    if (!flock($file, LOCK_EX)) {
        fclose($file);
        error_log('Contact form rate limit storage could not be locked.');
        return null;
    }

    $storedAttempts = stream_get_contents($file);
    $attempts = json_decode($storedAttempts, true);
    if (!is_array($attempts)) {
        $attempts = array();
    }

    $cutoff = time() - 600;
    $attempts = array_values(array_filter($attempts, function ($timestamp) use ($cutoff) {
        return is_int($timestamp) && $timestamp > $cutoff;
    }));

    if (count($attempts) >= 5) {
        flock($file, LOCK_UN);
        fclose($file);
        return true;
    }

    $attempts[] = time();
    $encodedAttempts = json_encode($attempts);
    rewind($file);
    $written = ftruncate($file, 0)
        && fwrite($file, $encodedAttempts) === strlen($encodedAttempts)
        && fflush($file);

    flock($file, LOCK_UN);
    fclose($file);

    if (!$written) {
        error_log('Contact form rate limit storage could not be updated.');
        return null;
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Méthode de requête non autorisée.');
}

if (postValue('website') !== '') {
    respond(422, false, 'La soumission n’a pas pu être validée.');
}

$fullName = postValue('full_name');
$email = postValue('email');
$subject = postValue('subject');
$message = postValue('message');

if ($fullName === '' || strlen($fullName) > 480
    || preg_match('/[\r\n\x00]/', $fullName)
    || $email === '' || strlen($email) > 254
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || preg_match('/[\r\n]/', $email)
    || $subject === '' || strlen($subject) > 600
    || preg_match('/[\r\n\x00]/', $subject)
    || $message === '' || strlen($message) > 20000
    || strpos($message, "\0") !== false) {
    respond(422, false, 'Vérifiez les champs et réessayez.');
}

foreach (array($fullName, $email, $subject, $message) as $value) {
    if (preg_match('//u', $value) !== 1) {
        respond(422, false, 'Certains caractères du message ne sont pas valides.');
    }
}

$mailFrom = getenv('PORTFOLIO_MAIL_FROM');
if (!$mailFrom || !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
    respond(503, false, 'Le serveur doit être configuré avec une adresse d’expédition valide.');
}

if (!function_exists('mail')) {
    respond(503, false, 'Le service de messagerie du serveur n’est pas disponible.');
}

$rateLimit = contactRateLimitExceeded();
if ($rateLimit === null) {
    respond(503, false, 'La protection du formulaire est momentanément indisponible.');
}
if ($rateLimit) {
    respond(429, false, 'Trop de tentatives. Veuillez réessayer dans quelques minutes.');
}

$recipient = 'exellanoservice@gmail.com';
$mailSubject = '=?UTF-8?B?' . base64_encode('Message portfolio : ' . $subject) . '?=';
$body = "Nom complet : " . $fullName . "\n"
    . "Adresse e-mail : " . $email . "\n"
    . "Objet : " . $subject . "\n\n"
    . "Message :\n" . $message . "\n";
$headers = "From: " . $mailFrom . "\r\n"
    . "Reply-To: " . $email . "\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($recipient, $mailSubject, $body, $headers);
if (!$sent) {
    error_log('Portfolio contact mail transport failed.');
    respond(503, false, 'Le service de messagerie n’a pas accepté le message. Réessayez plus tard.');
}

respond(200, true, 'Votre message a été transmis au service de messagerie.');
