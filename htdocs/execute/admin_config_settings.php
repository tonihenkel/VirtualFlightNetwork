<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/config_settings.php';

try {
    $pdo = createAdminPdo();
    requireAdminUser($pdo, 5);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            empty($_POST['csrf'])
            || empty($_SESSION['admin_csrf'])
            || !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'invalid_csrf']);
            exit;
        }

        $submitted = json_decode(
            (string)($_POST['settings'] ?? ''),
            true
        );

        if (!is_array($submitted)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'invalid_settings']);
            exit;
        }

        $definitions = vfnConfigDefinitions();
        if (array_diff(array_keys($submitted), array_keys($definitions))) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'unknown_setting']);
            exit;
        }

        $currentValues = vfnReadRuntimeConfig();
        foreach ($definitions as $settingKey => $definition) {
            if (
                ($definition['type'] ?? '') === 'secret'
                && isset($submitted[$settingKey])
                && $submitted[$settingKey] === '********'
            ) {
                $submitted[$settingKey] = (string)($currentValues[$settingKey] ?? '');
            }
        }
        vfnWriteRuntimeConfig($submitted);
        vfnApplyRuntimeConfig(vfnReadRuntimeConfig());

        if (array_key_exists('atcLoginEnabled', $submitted) && !$submitted['atcLoginEnabled']) {
            require_once __DIR__ . '/../includes/atc_schema.php';
            ensureAtcSchema($pdo);
            $pdo->exec(
                "UPDATE user_sessions s
                 INNER JOIN atc_sessions a
                    ON a.user_id = s.user_id AND a.callsign = s.callsign
                 INNER JOIN users u ON u.id = a.user_id
                 SET s.is_active = 0
                 WHERE a.is_active = 1 AND u.op_permission < 5"
            );
            $pdo->exec(
                "UPDATE atc_sessions a
                 INNER JOIN users u ON u.id = a.user_id
                 SET a.is_active = 0, a.disconnected_at = NOW()
                 WHERE a.is_active = 1 AND u.op_permission < 5"
            );
        }
    }

    $values = [];
    foreach (vfnConfigDefinitions() as $key => $definition) {
        $value = $GLOBALS[$key] ?? null;
        $values[$key] = (($definition['type'] ?? '') === 'secret' && (string)$value !== '')
            ? '********'
            : $value;
    }

    echo json_encode([
        'success' => true,
        'definitions' => vfnConfigDefinitions(),
        'values' => $values,
        'timezones' => timezone_identifiers_list()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
