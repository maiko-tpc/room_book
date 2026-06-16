<?php

declare(strict_types=1);

/*
 * 会議室予約システム
 * コマンドライン初期設定
 *
 * 実行方法:
 *
 * php setup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);

    exit(
        "このファイルはコマンドライン専用です。\n"
        . "ターミナルで php setup.php を実行してください。\n"
    );
}

require_once __DIR__ . '/config.php';

function output_message(string $message): void
{
    echo $message . PHP_EOL;
}

function output_success(string $message): void
{
    echo '[OK] ' . $message . PHP_EOL;
}

function output_warning(string $message): void
{
    echo '[注意] ' . $message . PHP_EOL;
}

function output_error(string $message): void
{
    fwrite(
        STDERR,
        '[エラー] ' . $message . PHP_EOL
    );
}

if (!extension_loaded('pdo')) {
    output_error(
        'PDO拡張が有効になっていません。'
    );

    exit(1);
}

if (!extension_loaded('pdo_sqlite')) {
    output_error(
        'PDO SQLite拡張が有効になっていません。'
    );

    output_message(
        'php -m を実行して pdo_sqlite が'
        . '表示されるか確認してください。'
    );

    exit(1);
}

$dataDirectory = __DIR__ . '/data';
$databasePath = $dataDirectory . '/reservation.sqlite';
$dataHtaccessPath = $dataDirectory . '/.htaccess';

if (!is_dir($dataDirectory)) {
    output_message(
        'dataディレクトリを作成します。'
    );

    if (
        !mkdir(
            $dataDirectory,
            0775,
            true
        )
        && !is_dir($dataDirectory)
    ) {
        output_error(
            'dataディレクトリを作成できませんでした。'
        );

        exit(1);
    }

    output_success(
        'dataディレクトリを作成しました。'
    );
} else {
    output_success(
        'dataディレクトリを確認しました。'
    );
}

if (!is_writable($dataDirectory)) {
    output_error(
        'dataディレクトリに書き込めません。'
    );

    output_message(
        'Webサーバーと実行ユーザーが'
        . '書き込める権限を設定してください。'
    );

    exit(1);
}

if (!file_exists($dataHtaccessPath)) {
    if (
        file_put_contents(
            $dataHtaccessPath,
            "Require all denied\n",
            LOCK_EX
        ) === false
    ) {
        output_error(
            'data/.htaccessを作成できませんでした。'
        );

        exit(1);
    }

    @chmod($dataHtaccessPath, 0644);

    output_success(
        'data/.htaccessを作成しました。'
    );
} else {
    output_success(
        'data/.htaccessを確認しました。'
    );
}

$databaseAlreadyExists =
    file_exists($databasePath);

if ($databaseAlreadyExists) {
    output_warning(
        '既存のデータベースを更新します。'
    );

    output_message(
        '既存の予約データと会議室詳細データは削除されません。'
    );
} else {
    output_message(
        '新しいデータベースを作成します。'
    );
}

try {
    $db = new PDO(
        'sqlite:' . $databasePath
    );

    $db->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $db->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    $db->beginTransaction();

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id TEXT NOT NULL,
            title TEXT NOT NULL,
            user_name TEXT NOT NULL,
            start_at TEXT NOT NULL,
            end_at TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT \'\',
            recurrence_group_id TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
        '
    );

    output_success(
        'reservationsテーブルを確認しました。'
    );

    $columns = $db
        ->query(
            'PRAGMA table_info(reservations)'
        )
        ->fetchAll();

    $columnNames = [];

    foreach ($columns as $column) {
        if (
            isset($column['name'])
            && is_string($column['name'])
        ) {
            $columnNames[] =
                $column['name'];
        }
    }

    if (
        !in_array(
            'recurrence_group_id',
            $columnNames,
            true
        )
    ) {
        $db->exec(
            '
            ALTER TABLE reservations
            ADD COLUMN recurrence_group_id TEXT
            '
        );

        output_success(
            'recurrence_group_id列を追加しました。'
        );
    } else {
        output_success(
            'recurrence_group_id列を確認しました。'
        );
    }

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS room_details (
            room_id TEXT PRIMARY KEY,
            capacity INTEGER,
            has_screen INTEGER NOT NULL DEFAULT 0,
            has_projector INTEGER NOT NULL DEFAULT 0,
            has_whiteboard INTEGER NOT NULL DEFAULT 0,
            has_web_conference INTEGER NOT NULL DEFAULT 0,
            equipment TEXT NOT NULL DEFAULT \'\',
            note TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL
        )
        '
    );

    output_success(
        'room_detailsテーブルを確認しました。'
    );

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS system_check (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            checked_at TEXT NOT NULL
        )
        '
    );

    output_success(
        'system_checkテーブルを確認しました。'
    );

    $db->exec(
        '
        CREATE INDEX IF NOT EXISTS
        idx_reservations_room_start
        ON reservations (
            room_id,
            start_at
        )
        '
    );

    $db->exec(
        '
        CREATE INDEX IF NOT EXISTS
        idx_reservations_start_end
        ON reservations (
            start_at,
            end_at
        )
        '
    );

    $db->exec(
        '
        CREATE INDEX IF NOT EXISTS
        idx_reservations_recurrence_group
        ON reservations (
            recurrence_group_id,
            start_at
        )
        '
    );

    $db->exec(
        '
        CREATE INDEX IF NOT EXISTS
        idx_room_details_updated_at
        ON room_details (
            updated_at
        )
        '
    );

    output_success(
        'データベースのインデックスを確認しました。'
    );

    $checkStmt = $db->prepare(
        '
        INSERT INTO system_check (
            checked_at
        ) VALUES (
            :checked_at
        )
        '
    );

    $checkStmt->execute([
        ':checked_at' =>
            date('Y-m-d H:i:s'),
    ]);

    $db->commit();

    if (!$databaseAlreadyExists) {
        @chmod($databasePath, 0664);
    }

    output_message('');
    output_success(
        '初期設定が完了しました。'
    );

    output_message(
        'データベース: '
        . $databasePath
    );

    output_message('');
    output_message(
        'ブラウザで index.php を開いて'
        . '動作を確認してください。'
    );

    exit(0);
} catch (Throwable $e) {
    if (
        isset($db)
        && $db instanceof PDO
        && $db->inTransaction()
    ) {
        $db->rollBack();
    }

    output_error(
        '初期設定に失敗しました。'
    );

    output_error(
        $e->getMessage()
    );

    exit(1);
}
