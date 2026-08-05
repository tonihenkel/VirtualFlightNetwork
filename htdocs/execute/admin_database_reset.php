<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';

try {
    $pdo = createAdminPdo();
    $admin = requireAdminUser($pdo, 5);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
        exit;
    }

    if (
        empty($_POST['csrf'])
        || empty($_SESSION['admin_csrf'])
        || !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'invalid_csrf']);
        exit;
    }

    if ((string)($_POST['confirmation'] ?? '') !== 'RESET VFN') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'invalid_confirmation']);
        exit;
    }

    $password = (string)($_POST['password'] ?? '');
    $passwordStmt = $pdo->prepare(
        "SELECT password_hash
         FROM users
         WHERE id = :user_id
           AND op_permission >= 5
         LIMIT 1"
    );
    $passwordStmt->execute(['user_id' => (int)$admin['id']]);
    $passwordHash = (string)$passwordStmt->fetchColumn();

    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'invalid_password']);
        exit;
    }

    $tableStmt = $pdo->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    $allTables = array_map(
        static function (array $row): string {
            return (string)$row['TABLE_NAME'];
        },
        $tableStmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $preservedTables = [
        'airports',
        'divisions',
        'division_staff',
        'division_content_revisions',
        'chat_filter_words',
        'users'
    ];
    $resetTables = array_values(array_filter(
        $allTables,
        static function (string $table) use ($preservedTables): bool {
            return !in_array($table, $preservedTables, true);
        }
    ));

    $quoteIdentifier = static function (string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    };

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();

    try {
        foreach ($resetTables as $table) {
            $pdo->exec('DELETE FROM ' . $quoteIdentifier($table));
        }

        $pdo->exec('DELETE FROM users');

        $bootstrapPasswordHash =
            password_hash('saturn', PASSWORD_DEFAULT);

        $bootstrapStmt = $pdo->prepare(
            "INSERT INTO users
                (
                    id,
                    username,
                    email,
                    password_hash,
                    real_name,
                    country_code,
                    division_code,
                    op_permission,
                    email_verified,
                    is_active
                )
             VALUES
                (
                    1,
                    'admin',
                    'admin@virtualflightnetwork.local',
                    :password_hash,
                    'Administrator',
                    'DE',
                    'DE',
                    5,
                    1,
                    1
                )"
        );
        $bootstrapStmt->execute([
            'password_hash' => $bootstrapPasswordHash
        ]);

        $pdo->commit();
    } catch (Throwable $resetError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $resetError;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    foreach (array_merge($resetTables, ['users']) as $table) {
        $pdo->exec(
            'ALTER TABLE ' . $quoteIdentifier($table) . ' AUTO_INCREMENT = 1'
        );
    }

    $activityStmt = $pdo->prepare(
        "INSERT INTO user_activity_log
            (user_id, actor_user_id, activity_type, activity_key, activity_value, is_read)
         VALUES
            (:user_id, :actor_user_id, 'database_reset', 'activity_database_reset', :value, 1)"
    );
    $activityStmt->execute([
        'user_id' => 1,
        'actor_user_id' => 1,
        'value' => 'activity_database_reset_details'
    ]);

    $avatarDirectory =
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'avatars';
    if (is_dir($avatarDirectory)) {
        foreach (glob($avatarDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $avatarFile) {
            if (
                is_file($avatarFile)
                && preg_match('/\.(?:jpe?g|png|webp)$/i', basename($avatarFile))
                && !unlink($avatarFile)
            ) {
                throw new RuntimeException(
                    'Avatar could not be removed during database reset: '
                    . basename($avatarFile)
                );
            }
        }
    }

    // Voice test audio lives in voice-service/test-audio and is deliberately
    // outside the database-reset scope. Only profile avatars are reset here.

    clearVfnWebSession();
    session_regenerate_id(true);

    echo json_encode([
        'success' => true,
        'message' => 'database_reset_complete',
        'reset_tables' => count($resetTables) + 1,
        'bootstrap_user' => 'admin'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
