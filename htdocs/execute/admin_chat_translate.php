<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';

try {
    $pdo = createAdminPdo();
    requireAdminUser($pdo, 2);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('method_not_allowed');
    }
    if (empty($_POST['csrf']) || empty($_SESSION['admin_csrf'])
        || !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])) {
        http_response_code(403);
        throw new RuntimeException('invalid_csrf');
    }
    $text = trim(mb_substr((string)($_POST['text'] ?? ''), 0, 255));
    $target = strtolower(trim((string)($_POST['target'] ?? '')));
    if ($text === '' || !in_array($target, ['de', 'en'], true)) {
        http_response_code(422);
        throw new RuntimeException('invalid_translation');
    }
    $now = time();
    $requests = array_values(array_filter(
        (array)($_SESSION['chat_translation_requests'] ?? []),
        static fn($timestamp): bool => (int)$timestamp > $now - 60
    ));
    if (count($requests) >= 30) {
        http_response_code(429);
        throw new RuntimeException('translation_rate_limited');
    }
    $requests[] = $now;
    $_SESSION['chat_translation_requests'] = $requests;

    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_chat_translation_' . hash('sha256', $target . "\0" . $text) . '.json';
    $result = null;
    if (is_file($cachePath) && time() - (int)filemtime($cachePath) < 604800) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && !empty($cached['translated_text'])) $result = $cached;
    }
    if ($result === null) {
        $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
            'q' => $text, 'langpair' => 'Autodetect|' . $target, 'mt' => 1,
        ]);
        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        $payload = $body !== false ? json_decode($body, true) : null;
        $response = is_array($payload) ? (array)($payload['responseData'] ?? []) : [];
        $translated = trim(html_entity_decode(
            (string)($response['translatedText'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'
        ));
        if ($translated === '' || (int)($payload['responseStatus'] ?? 0) !== 200) {
            http_response_code(502);
            throw new RuntimeException('translation_unavailable');
        }
        $result = [
            'translated_text' => mb_substr($translated, 0, 1000),
            'detected_language' => strtolower((string)($response['detectedLanguage'] ?? '')),
            'target_language' => $target,
        ];
        @file_put_contents($cachePath, json_encode(
            $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), LOCK_EX);
    }
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
