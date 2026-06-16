<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

/*
 * このファイルは初期設定完了後に削除してください。
 * setup.lock が存在する場合は再実行されません。
 */

$dataDirectory = __DIR__ . '/data';
$databasePath = $dataDirectory . '/reservation.sqlite';
$lockFilePath = $dataDirectory . '/setup.lock';
$dataHtaccessPath = $dataDirectory . '/.htaccess';

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$errorMessages = [];
$successMessages = [];
$setupCompleted = false;
$setupLocked = file_exists($lockFilePath);


if (!extension_loaded('pdo')) {
    $errorMessages[] =
        'PDO拡張が有効になっていません。';
}

if (!extension_loaded('pdo_sqlite')) {
    $errorMessages[] =
        'PDO SQLite拡張が有効になっていません。';
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !$setupLocked
) {
    if (
        !extension_loaded('pdo')
        || !extension_loaded('pdo_sqlite')
    ) {
        $errorMessages[] =
            '必要なPHP拡張が不足しています。';
    }

    if (empty($errorMessages)) {
        try {
            if (!is_dir($dataDirectory)) {
                if (
                    !mkdir(
                        $dataDirectory,
                        0775,
                        true
                    )
                    && !is_dir($dataDirectory)
                ) {
                    throw new RuntimeException(
                        'dataディレクトリを作成できませんでした。'
                    );
                }

                $successMessages[] =
                    'dataディレクトリを作成しました。';
            } else {
                $successMessages[] =
                    'dataディレクトリを確認しました。';
            }

            if (!is_writable($dataDirectory)) {
                throw new RuntimeException(
                    'dataディレクトリに書き込み権限がありません。'
                );
            }

            /*
             * Apache利用時にdataディレクトリへの
             * Webアクセスを拒否する設定を作成します。
             */
            if (!file_exists($dataHtaccessPath)) {
                $htaccessContents =
                    "Require all denied\n";

                if (
                    file_put_contents(
                        $dataHtaccessPath,
                        $htaccessContents,
                        LOCK_EX
                    ) === false
                ) {
                    throw new RuntimeException(
                        'data/.htaccessを作成できませんでした。'
                    );
                }

                @chmod($dataHtaccessPath, 0644);

                $successMessages[] =
                    'data/.htaccessを作成しました。';
            } else {
                $successMessages[] =
                    'data/.htaccessを確認しました。';
            }

            $databaseAlreadyExists =
                file_exists($databasePath);

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

            $successMessages[] =
                'reservationsテーブルを確認しました。';

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

                $successMessages[] =
                    'recurrence_group_id列を追加しました。';
            } else {
                $successMessages[] =
                    'recurrence_group_id列を確認しました。';
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

            $successMessages[] =
                'room_detailsテーブルを確認しました。';

            $db->exec(
                '
                CREATE TABLE IF NOT EXISTS system_check (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    checked_at TEXT NOT NULL
                )
                '
            );

            $successMessages[] =
                'system_checkテーブルを確認しました。';

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

            $successMessages[] =
                'データベースのインデックスを確認しました。';

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

            $lockContents =
                'Setup completed at: '
                . date('Y-m-d H:i:s')
                . PHP_EOL;

            if (
                file_put_contents(
                    $lockFilePath,
                    $lockContents,
                    LOCK_EX
                ) === false
            ) {
                throw new RuntimeException(
                    'setup.lockを作成できませんでした。'
                );
            }

            @chmod($lockFilePath, 0664);

            $successMessages[] =
                '初期設定済みロックを作成しました。';

            $successMessages[] =
                $databaseAlreadyExists
                ? '既存データベースを更新しました。'
                : '新しいデータベースを作成しました。';

            $setupCompleted = true;
            $setupLocked = true;
        } catch (Throwable $e) {
            if (
                isset($db)
                && $db instanceof PDO
                && $db->inTransaction()
            ) {
                $db->rollBack();
            }

            error_log(
                'Meeting room setup error: '
                . $e->getMessage()
            );

            $errorMessages[] =
                '初期設定に失敗しました。';

            $errorMessages[] =
                $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        初期設定 - <?php echo h(APP_NAME); ?>
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            color: #222222;
            background: #f5f5f5;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Hiragino Kaku Gothic ProN",
                "Hiragino Sans",
                Meiryo,
                sans-serif;
        }

        .container {
            max-width: 720px;
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 24px;
            font-size: 28px;
            font-weight: 600;
        }

        h2 {
            margin: 0 0 16px;
            font-size: 20px;
        }

        .box {
            margin-bottom: 20px;
            padding: 22px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .error-box {
            margin-bottom: 20px;
            padding: 15px 18px;
            color: #8b1a10;
            background: #fff1f0;
            border: 1px solid #e0aaa5;
        }

        .success-box {
            margin-bottom: 20px;
            padding: 15px 18px;
            color: #176b32;
            background: #edf8f0;
            border: 1px solid #9fcbaa;
        }

        .warning-box {
            margin-bottom: 20px;
            padding: 15px 18px;
            color: #5f4600;
            background: #fff9df;
            border: 1px solid #d8c46c;
        }

        ul {
            margin: 0;
            padding-left: 22px;
        }

        li + li {
            margin-top: 6px;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .button {
            display: inline-block;
            padding: 10px 18px;
            color: #222222;
            text-decoration: none;
            background: #eeeeee;
            border: 1px solid #aaaaaa;
            border-radius: 2px;
            cursor: pointer;
            font: inherit;
        }

        .button-primary {
            color: #ffffff;
            background: #333333;
            border-color: #333333;
        }

        .button:hover {
            opacity: 0.85;
        }

        code {
            padding: 2px 5px;
            background: #eeeeee;
        }

        .path {
            overflow-wrap: anywhere;
            font-family:
                Menlo,
                Monaco,
                Consolas,
                monospace;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .button-row {
                flex-direction: column;
            }

            .button {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>会議室予約システム 初期設定</h1>

    <?php if (!empty($errorMessages)): ?>

        <div class="error-box">
            <ul>
                <?php foreach ($errorMessages as $message): ?>
                    <li><?php echo h($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <?php if (!empty($successMessages)): ?>

        <div class="success-box">
            <ul>
                <?php foreach ($successMessages as $message): ?>
                    <li><?php echo h($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <?php if ($setupLocked): ?>

        <div class="box">

            <h2>初期設定は完了しています</h2>

            <p>
                初期設定済みロックが存在するため、
                再実行はできません。
            </p>

            <p class="path">
                <?php echo h($lockFilePath); ?>
            </p>

            <?php if ($setupCompleted): ?>
                <div class="warning-box">
                    セキュリティのため、この画面を閉じた後に
                    <code>setup-web.php</code> を
                    サーバーから削除してください。
                </div>
            <?php endif; ?>

            <div class="button-row">
                <a
                    class="button button-primary"
                    href="index.php"
                >
                    予約システムを開く
                </a>
            </div>

        </div>

    <?php else: ?>

        <div class="box">

            <h2>データベースを初期化</h2>

            <p>
                下のボタンを押して、
                データベースの初期設定を実行してください。
            </p>

            <p>
                既存のデータベースがある場合も、
                予約データと会議室詳細データは削除されません。
            </p>

            <form
                method="post"
                action="setup-web.php"
                autocomplete="off"
            >
                <div class="button-row">
                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        初期設定を実行
                    </button>
                </div>
            </form>

        </div>

    <?php endif; ?>

    <div class="box">

        <h2>PHP環境</h2>

        <ul>
            <li>
                PHP：
                <?php echo h(PHP_VERSION); ?>
            </li>

            <li>
                PDO：
                <?php
                echo extension_loaded('pdo')
                    ? '利用可能'
                    : '利用不可';
                ?>
            </li>

            <li>
                PDO SQLite：
                <?php
                echo extension_loaded('pdo_sqlite')
                    ? '利用可能'
                    : '利用不可';
                ?>
            </li>
        </ul>

    </div>

</div>
</body>
</html>
