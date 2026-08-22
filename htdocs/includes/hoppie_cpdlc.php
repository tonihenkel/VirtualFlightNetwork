<?php
declare(strict_types=1);

require_once __DIR__ . '/cpdlc_schema.php';

function vfnHoppieEnabled(): bool
{
    return !empty($GLOBALS['hoppieCpdlcEnabled'])
        && trim((string)($GLOBALS['hoppieCpdlcLogonCode'] ?? '')) !== '';
}

function vfnHoppieStation(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9-]/', '', str_replace('_', '-', $value));
    return substr((string)$value, 0, 16);
}

/**
 * Override a broken global PHP curl.cainfo setting for VFN's Hoppie calls.
 * The first entry also permits a future project-local CA bundle without
 * making it mandatory for the current Windows/IIS installation.
 */
function vfnHoppieCaBundle(): string
{
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
        'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt'
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function vfnHoppieAtcStation(string $value): string
{
    $value = strtoupper(trim($value));
    if (preg_match('/[A-Z0-9]{4}/', $value, $match)) {
        return $match[0];
    }
    return substr(preg_replace('/[^A-Z0-9]/', '', $value), 0, 4);
}

function vfnHoppieRequest(string $from, string $to, string $type, string $packet = ''): string
{
    if (!vfnHoppieEnabled()) {
        throw new RuntimeException('hoppie_not_configured');
    }
    $url = (string)($GLOBALS['hoppieCpdlcConnectUrl'] ?? 'https://www.hoppie.nl/acars/system/connect.html');
    $body = http_build_query([
        'logon' => (string)$GLOBALS['hoppieCpdlcLogonCode'],
        'from' => vfnHoppieStation($from),
        'to' => vfnHoppieStation($to),
        'type' => $type,
        'packet' => $packet
    ], '', '&');
    $caBundle = vfnHoppieCaBundle();
    $sslOptions = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'SNI_enabled' => true
    ];
    if ($caBundle !== '') {
        $sslOptions['cafile'] = $caBundle;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true
        ],
        'ssl' => $sslOptions
    ]);
    $result = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\\s(\\d{3})\\s/', (string)$responseHeaders[0], $match)) {
        $status = (int)$match[1];
    }
    // Hoppie can return a protocol error payload with a non-2xx HTTP status.
    // It is still a valid gateway response and must not be replaced by the
    // legacy cURL fallback (which has the obsolete global CA configuration).
    if ($result !== false) {
        return (string)$result;
    }

    // Fallback for PHP installations without HTTPS stream support. The VFN
    // server normally uses the verified OpenSSL stream above because the
    // installed legacy cURL build can retain an obsolete global CA path.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']];
        if ($caBundle !== '') {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? $error : 'hoppie_http_' . $status);
        }
        return (string)$result;
    }
    throw new RuntimeException('hoppie_connection_failed');
}

function vfnHoppiePollMessages(string $response): array
{
    if (strncmp($response, 'ok', 2) !== 0) {
        throw new RuntimeException(trim($response) ?: 'hoppie_invalid_response');
    }
    $messages = [];
    $text = substr($response, 2);
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        if ($text[$i] !== '{') continue;
        $start = $i; $depth = 0;
        for (; $i < $length; $i++) {
            if ($text[$i] === '{') $depth++;
            elseif ($text[$i] === '}' && --$depth === 0) break;
        }
        $block = substr($text, $start + 1, $i - $start - 1);
        if (preg_match('/^(?:(\d+)\s+)?([^\s]+)\s+([^\s]+)\s+\{(.*)\}$/s', $block, $m)) {
            $messages[] = [
                'gateway_id' => isset($m[1]) ? trim((string)$m[1]) : '',
                'from' => strtoupper($m[2]),
                'type' => strtolower($m[3]),
                'packet' => $m[4],
            ];
        }
    }
    return $messages;
}

function vfnHoppieDecodePacket(string $packet): array
{
    if (preg_match('#^/data2/(\d*)/(\d*)/([^/]*)/(.*)$#s', trim($packet), $m)) {
        return ['min' => $m[1] === '' ? null : (int)$m[1], 'mrn' => $m[2] === '' ? null : (int)$m[2],
            'response' => strtoupper($m[3]), 'text' => trim(str_replace(['@_@', '@'], ["\n", "\n"], $m[4]))];
    }
    return ['min' => null, 'mrn' => null, 'response' => '', 'text' => trim($packet)];
}

function vfnHoppieNextMin(PDO $pdo, string $station): int
{
    $pdo->prepare("INSERT IGNORE INTO cpdlc_gateway_state(station_code,next_min) VALUES(:s,1)")->execute(['s' => $station]);
    $q = $pdo->prepare("SELECT next_min FROM cpdlc_gateway_state WHERE station_code=:s FOR UPDATE");
    $q->execute(['s' => $station]);
    $min = ((int)$q->fetchColumn()) % 64;
    $pdo->prepare("UPDATE cpdlc_gateway_state SET next_min=:n WHERE station_code=:s")->execute(['n' => ($min + 1) % 64, 's' => $station]);
    return $min;
}

function vfnHoppieReplyMrn(PDO $pdo, int $connectionId): string
{
    $q = $pdo->prepare("SELECT external_packet FROM cpdlc_messages WHERE connection_id=:id AND sender_role='pilot' AND external_packet IS NOT NULL ORDER BY id DESC LIMIT 1");
    $q->execute(['id' => $connectionId]);
    $packet = $q->fetchColumn();
    if (!$packet) return '';
    $decoded = vfnHoppieDecodePacket((string)$packet);
    return $decoded['min'] === null ? '' : (string)$decoded['min'];
}

function vfnHoppieConnection(PDO $pdo, string $pilot, string $station, array $decoded): array
{
    $q = $pdo->prepare("SELECT * FROM cpdlc_connections WHERE transport='hoppie' AND pilot_callsign=:p AND station_code=:s AND state IN ('requested','connected') ORDER BY id DESC LIMIT 1");
    $q->execute(['p' => $pilot, 's' => $station]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $key = 'hoppie:' . $station . ':' . $pilot;
    $pdo->prepare("INSERT INTO cpdlc_connections(pilot_user_id,pilot_session_token,pilot_callsign,station_code,state,transport,external_connection_key) VALUES(NULL,NULL,:p,:s,'requested','hoppie',:k)")
        ->execute(['p' => $pilot, 's' => $station, 'k' => $key]);
    $q = $pdo->prepare("SELECT * FROM cpdlc_connections WHERE id=:id");
    $q->execute(['id' => (int)$pdo->lastInsertId()]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

function vfnHoppieFreshLogonConnection(PDO $pdo, string $pilot, string $station, string $packetKey): array
{
    // A simulator restart creates a new CPDLC logon even if the old aircraft
    // instance never sent LOGOFF. Never attach that request to the stale,
    // already connected session: the aircraft and ATC would then disagree
    // about the connection state.
    $q = $pdo->prepare("SELECT id FROM cpdlc_connections WHERE transport='hoppie' AND pilot_callsign=:p AND station_code=:s AND state IN ('requested','connected')");
    $q->execute(['p' => $pilot, 's' => $station]);
    $oldIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if ($oldIds) {
        $marks = implode(',', array_fill(0, count($oldIds), '?'));
        $pdo->prepare("UPDATE cpdlc_connections SET state='closed',closed_at=NOW(),last_activity_at=NOW() WHERE id IN ({$marks})")
            ->execute($oldIds);
        // Do not deliver queued uplinks from the previous cockpit instance to
        // the newly logged-on aircraft.
        $pdo->prepare("UPDATE cpdlc_messages SET gateway_error=IF(gateway_sent_at IS NULL,'superseded_by_new_logon',gateway_error),gateway_sent_at=COALESCE(gateway_sent_at,NOW()) WHERE connection_id IN ({$marks}) AND sender_role IN ('atc','system')")
            ->execute($oldIds);
    }
    $key = 'hoppie:' . $station . ':' . $pilot . ':' . substr($packetKey, 0, 20);
    $pdo->prepare("INSERT INTO cpdlc_connections(pilot_user_id,pilot_session_token,pilot_callsign,station_code,state,transport,external_connection_key) VALUES(NULL,NULL,:p,:s,'requested','hoppie',:k)")
        ->execute(['p' => $pilot, 's' => $station, 'k' => $key]);
    $q = $pdo->prepare("SELECT * FROM cpdlc_connections WHERE id=:id");
    $q->execute(['id' => (int)$pdo->lastInsertId()]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

function vfnHoppieStoreAcarsMessage(PDO $pdo, string $station, array $remote, string $messageType = ''): void
{
    $station = vfnHoppieStation($station);
    $remoteCallsign = vfnHoppieStation((string)($remote['from'] ?? ''));
    $messageType = strtolower(trim($messageType !== '' ? $messageType : (string)($remote['type'] ?? 'unknown')));
    $messageType = (string)preg_replace('/[^a-z0-9_-]/', '', $messageType);
    if ($messageType === '') $messageType = 'unknown';
    $packet = (string)($remote['packet'] ?? '');
    $gatewayId = trim((string)($remote['gateway_id'] ?? ''));
    $delivery = $gatewayId !== '' ? 'gateway:' . $gatewayId : 'packet:' . $packet;
    $key = hash('sha256', $station . '|' . $remoteCallsign . '|' . $messageType . '|' . $delivery);
    try {
        $pdo->prepare("INSERT INTO acars_gateway_messages(station_code,remote_callsign,direction,message_type,message_text,external_packet,external_message_key) VALUES(:s,:r,'inbound',:t,:m,:p,:k)")
            ->execute(['s' => $station, 'r' => $remoteCallsign, 't' => $messageType, 'm' => $packet, 'p' => $packet, 'k' => $key]);
    } catch (PDOException $e) {
        // Multiple open ATC clients may poll the same Hoppie station concurrently.
        if ((string)$e->getCode() === '23000') return;
        throw $e;
    }
}

function vfnHoppieIngest(PDO $pdo, string $station, array $remote): void
{
    $remoteType = strtolower(trim((string)($remote['type'] ?? '')));
    if ($remoteType !== 'cpdlc') {
        vfnHoppieStoreAcarsMessage($pdo, $station, $remote, $remoteType);
        return;
    }
    $pilot = vfnHoppieStation((string)$remote['from']);
    $packet = (string)$remote['packet'];
    $decoded = vfnHoppieDecodePacket($packet);
    $text = (string)$decoded['text'];
    $upper = strtoupper($text);
    $gatewayId = trim((string)($remote['gateway_id'] ?? ''));
    $delivery = $gatewayId !== '' ? 'gateway:' . $gatewayId : 'packet:' . $packet;
    $baseKey = hash('sha256', $station . '|' . $pilot . '|' . $delivery);
    $key = $baseKey;
    $type = 'free_text';
    if (strpos($upper, 'REQUEST LOGON') !== false) $type = 'logon_request';
    elseif ($upper === 'LOGOFF') $type = 'logoff';
    elseif (in_array($upper, ['WILCO','UNABLE','STANDBY','ROGER','AFFIRM','NEGATIVE'], true)) $type = 'response';
    // Check before resolving/creating a connection. Hoppie's poll response
    // prefixes every delivery with an id. Keeping that id distinguishes a new
    // identical cockpit LOGON from the same delivery returned to concurrent
    // ATC browser polls. The packet hash remains the legacy fallback.
    $q = $pdo->prepare("SELECT m.id,m.created_at,c.state FROM cpdlc_messages m JOIN cpdlc_connections c ON c.id=m.connection_id WHERE m.external_message_key=:k LIMIT 1");
    $q->execute(['k' => $key]);
    $duplicate = $q->fetch(PDO::FETCH_ASSOC);
    if ($duplicate) {
        if ($type !== 'logon_request') return;
        $age = max(0, time() - (int)strtotime((string)$duplicate['created_at']));
        if ($age < 10) return;
        // Retain the base hash for ordinary packet deduplication, but give a
        // genuine later relogon its own database key.
        $key = hash('sha256', $baseKey . '|relogon|' . sprintf('%.6F', microtime(true)));
    }
    $connection = $type === 'logon_request'
        ? vfnHoppieFreshLogonConnection($pdo, $pilot, $station, $key)
        : vfnHoppieConnection($pdo, $pilot, $station, $decoded);
    try {
        $pdo->prepare("INSERT INTO cpdlc_messages(connection_id,sender_role,message_type,message_text,response_options,transport,external_message_key,external_packet) VALUES(:c,'pilot',:t,:m,'','hoppie',:k,:p)")
            ->execute(['c' => $connection['id'], 't' => $type, 'm' => $text, 'k' => $key, 'p' => $packet]);
    } catch (PDOException $e) {
        // A concurrent ATC poll may already have stored this Hoppie packet.
        if ((string)$e->getCode() === '23000') return;
        throw $e;
    }
    if ($type === 'logoff') {
        $pdo->prepare("UPDATE cpdlc_connections SET state='closed',closed_at=NOW(),last_activity_at=NOW() WHERE id=:id")->execute(['id' => $connection['id']]);
    } else {
        $pdo->prepare("UPDATE cpdlc_connections SET last_activity_at=NOW() WHERE id=:id")->execute(['id' => $connection['id']]);
    }
}

function vfnHoppieSendPending(PDO $pdo, string $station): void
{
    $q = $pdo->prepare("SELECT m.*,c.pilot_callsign,c.state connection_state FROM cpdlc_messages m JOIN cpdlc_connections c ON c.id=m.connection_id WHERE c.transport='hoppie' AND c.station_code=:s AND m.sender_role IN ('atc','system') AND m.gateway_sent_at IS NULL ORDER BY m.id ASC LIMIT 25");
    $q->execute(['s' => $station]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $message) {
        try {
            $pdo->beginTransaction();
            $min = vfnHoppieNextMin($pdo, $station);
            $text = strtoupper(trim((string)$message['message_text']));
            $response = trim((string)$message['response_options']) !== '' ? 'WU' : 'NE';
            $mrn = in_array($message['message_type'], ['logon_accepted','logon_rejected','response'], true)
                ? vfnHoppieReplyMrn($pdo, (int)$message['connection_id']) : '';
            if ($message['message_type'] === 'logon_accepted') $text = 'LOGON ACCEPTED';
            elseif ($message['message_type'] === 'logon_rejected') $text = 'LOGON REJECTED';
            elseif ($message['message_type'] === 'logoff') $text = 'LOGOFF';
            $packet = '/data2/' . $min . '/' . $mrn . '/' . $response . '/' . str_replace("\n", '@_@', $text);
            $pdo->commit();
            $result = vfnHoppieRequest($station, (string)$message['pilot_callsign'], 'cpdlc', $packet);
            if (strncmp($result, 'ok', 2) !== 0) throw new RuntimeException(trim($result));
            $pdo->prepare("UPDATE cpdlc_messages SET gateway_sent_at=NOW(),external_packet=:p,gateway_error=NULL WHERE id=:id")
                ->execute(['p' => $packet, 'id' => $message['id']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare("UPDATE cpdlc_messages SET gateway_error=:e WHERE id=:id")->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $message['id']]);
        }
    }
}

function vfnHoppieSyncStation(PDO $pdo, string $station, bool $allowPoll = true): void
{
    if (!vfnHoppieEnabled()) return;
    $station = vfnHoppieStation($station);
    if ($station === '') return;
    ensureCpdlcSchema($pdo);
    vfnHoppieSendPending($pdo, $station);
    if (!$allowPoll) return;
    $pdo->prepare("INSERT IGNORE INTO cpdlc_gateway_state(station_code,next_poll_at) VALUES(:s,NOW())")->execute(['s' => $station]);
    // Compare using the database clock. PHP may run in UTC while MySQL uses
    // the server's local timezone; parsing next_poll_at in PHP could then
    // postpone every poll by the timezone offset (two hours in summer).
    $q = $pdo->prepare("SELECT (next_poll_at IS NULL OR next_poll_at <= NOW()) FROM cpdlc_gateway_state WHERE station_code=:s");
    $q->execute(['s' => $station]);
    if (!(bool)$q->fetchColumn()) return;
    $seconds = max(45, min(75, (int)($GLOBALS['hoppieCpdlcPollSeconds'] ?? 60)));
    $pdo->prepare("UPDATE cpdlc_gateway_state SET last_poll_at=NOW(),next_poll_at=DATE_ADD(NOW(),INTERVAL {$seconds} SECOND) WHERE station_code=:s")
        ->execute(['s' => $station]);
    try {
        $response = vfnHoppieRequest($station, 'SERVER', 'poll', '');
        foreach (vfnHoppiePollMessages($response) as $remote) vfnHoppieIngest($pdo, $station, $remote);
        $pdo->prepare("UPDATE cpdlc_gateway_state SET last_success_at=NOW(),last_error=NULL WHERE station_code=:s")->execute(['s' => $station]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE cpdlc_gateway_state SET last_error=:e WHERE station_code=:s")->execute(['e' => substr($e->getMessage(), 0, 500), 's' => $station]);
    }
}
