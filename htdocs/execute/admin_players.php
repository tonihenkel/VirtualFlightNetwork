<?php

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/ratings.php';

try {
    $pdo = createAdminPdo();
    requireAdminUser($pdo, 2);

    $search = trim((string)($_GET['search'] ?? ''));
    $country = strtoupper(trim((string)($_GET['country'] ?? '')));
    $division = strtoupper(trim((string)($_GET['division'] ?? '')));
    $rank = strtoupper(trim((string)($_GET['rank'] ?? '')));
    $status = trim((string)($_GET['status'] ?? ''));
    $online = trim((string)($_GET['online'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

    $where = ['1 = 1'];
    $params = [];

    if ($search !== '') {
        $where[] =
            '(u.username LIKE :search
              OR u.real_name LIKE :search
              OR u.email LIKE :search)';
        $params['search'] =
            '%' . mb_substr($search, 0, 120) . '%';
    }

    if ($country !== '') {
        $where[] = 'u.country_code = :country';
        $params['country'] = mb_substr($country, 0, 8);
    }

    if ($division !== '') {
        $where[] = 'u.division_code = :division';
        $params['division'] = mb_substr($division, 0, 12);
    }

    if ($status === 'active' || $status === 'inactive') {
        $where[] = 'u.is_active = :is_active';
        $params['is_active'] = $status === 'active' ? 1 : 0;
    }

    if ($online === 'online') {
        $where[] =
            'EXISTS (
                SELECT 1
                FROM user_sessions online_session
                WHERE online_session.user_id = u.id
                  AND online_session.is_active = 1
                  AND online_session.last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
            )';
    } elseif ($online === 'offline') {
        $where[] =
            'NOT EXISTS (
                SELECT 1
                FROM user_sessions online_session
                WHERE online_session.user_id = u.id
                  AND online_session.is_active = 1
                  AND online_session.last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
            )';
    }

    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.username,
            u.email,
            u.real_name,
            u.country_code,
            u.division_code,
            u.rating_pilot,
            u.rating_atc,
            u.rating_special,
            u.op_permission,
            u.email_verified,
            u.is_active,
            u.created_at,
            u.last_login,
            d.name AS division_name,
            EXISTS (
                SELECT 1
                FROM user_sessions online_session
                WHERE online_session.user_id = u.id
                  AND online_session.is_active = 1
                  AND online_session.last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
            ) AS is_online
         FROM users u
         LEFT JOIN divisions d ON d.code = u.division_code
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.username ASC"
    );
    $stmt->execute($params);

    $players = [];
    $countries = [];
    $divisions = $pdo
        ->query(
            "SELECT code, name
             FROM divisions
             ORDER BY name ASC, code ASC"
        )
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pilot = getPilotRating((int)$row['rating_pilot']);
        $atc = getAtcRating((int)$row['rating_atc']);
        $special = getSpecialRating((int)$row['rating_special']);

        $rankValues = [
            strtoupper((string)$pilot['code']),
            strtoupper((string)$pilot['name']),
            strtoupper((string)$atc['code']),
            strtoupper((string)$atc['name'])
        ];
        if ($special) {
            $rankValues[] = strtoupper((string)$special['code']);
            $rankValues[] = strtoupper((string)$special['name']);
        }

        if (
            $rank !== ''
            && count(array_filter(
                $rankValues,
                static function (string $value) use ($rank): bool {
                    return strpos($value, $rank) !== false;
                }
            )) === 0
        ) {
            continue;
        }

        $countryCode = strtoupper((string)($row['country_code'] ?? ''));
        $divisionCode = strtoupper((string)($row['division_code'] ?? ''));
        if ($countryCode !== '') {
            $countries[$countryCode] = true;
        }
        $players[] = [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'real_name' => (string)($row['real_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'country' => $countryCode,
            'division' => $divisionCode,
            'division_name' => (string)($row['division_name'] ?? ''),
            'pilot_rank' => $pilot['code'] . ' – ' . $pilot['name'],
            'atc_rank' => $atc['code'] . ' – ' . $atc['name'],
            'special_rank' => $special
                ? $special['code'] . ' – ' . $special['name']
                : '',
            'op_permission' => (int)$row['op_permission'],
            'email_verified' => (int)$row['email_verified'] === 1,
            'active' => (int)$row['is_active'] === 1,
            'online' => (int)$row['is_online'] === 1,
            'registered' => !empty($row['created_at'])
                ? date('d.m.Y H:i', strtotime((string)$row['created_at']))
                : '-',
            'last_login' => !empty($row['last_login'])
                ? date('d.m.Y H:i', strtotime((string)$row['last_login']))
                : '-'
        ];
    }

    ksort($countries);
    $total = count($players);
    $players = array_slice($players, ($page - 1) * $perPage, $perPage);

    echo json_encode([
        'success' => true,
        'players' => $players,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $perPage))
        ],
        'countries' => array_keys($countries),
        'divisions' => array_map(
            static function (array $division): array {
                return [
                    'code' => strtoupper((string)$division['code']),
                    'name' => (string)$division['name']
                ];
            },
            $divisions
        )
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'server_error'
    ]);
}
