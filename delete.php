<?php

require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=UTF-8');

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function format_datetime_japanese(string $dateTime): string
{
    $timestamp = strtotime($dateTime);

    if ($timestamp === false) {
        return '';
    }

    $weekdays = [
        '日',
        '月',
        '火',
        '水',
        '木',
        '金',
        '土',
    ];

    $weekday = $weekdays[
        (int) date('w', $timestamp)
    ];

    return date('Y年n月j日', $timestamp)
        . '（'
        . $weekday
        . '） '
        . date('H:i', $timestamp);
}

function find_room_name(
    array $rooms,
    string $roomId
): string {
    foreach ($rooms as $room) {
        if ($room['id'] === $roomId) {
            return $room['name'];
        }
    }

    return '不明な会議室';
}

/*
 * 予約IDを取得
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(
        INPUT_POST,
        'id',
        FILTER_VALIDATE_INT
    );
} else {
    $id = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );
}

if (
    $id === false
    || $id === null
    || $id < 1
) {
    http_response_code(400);
    exit('予約IDが正しくありません。');
}

$db = get_db();

/*
 * 対象予約を取得
 */
$stmt = $db->prepare(
    '
    SELECT
        id,
        room_id,
        title,
        user_name,
        start_at,
        end_at,
        note,
        recurrence_group_id
    FROM reservations
    WHERE id = :id
    '
);

$stmt->execute([
    ':id' => $id,
]);

$reservation = $stmt->fetch();

if (!$reservation) {
    http_response_code(404);
    exit('予約が見つかりません。');
}

$recurrenceGroupId =
    $reservation['recurrence_group_id'];

$isRecurring =
    $recurrenceGroupId !== null
    && $recurrenceGroupId !== '';

$futureReservationCount = 0;

/*
 * この予約を含め、それ以降に何件あるか確認
 */
if ($isRecurring) {
    $stmt = $db->prepare(
        '
        SELECT COUNT(*)
        FROM reservations
        WHERE recurrence_group_id
            = :recurrence_group_id
          AND start_at >= :start_at
        '
    );

    $stmt->execute([
        ':recurrence_group_id' =>
            $recurrenceGroupId,
        ':start_at' =>
            $reservation['start_at'],
    ]);

    $futureReservationCount =
        (int) $stmt->fetchColumn();
}

/*
 * 削除処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteScope = $_POST['delete_scope'] ?? '';

    if (
        $deleteScope !== 'single'
        && $deleteScope !== 'future'
    ) {
        http_response_code(400);
        exit('削除範囲が正しくありません。');
    }

    /*
     * 通常予約で「以降を削除」が送られた場合は拒否
     */
    if (
        $deleteScope === 'future'
        && !$isRecurring
    ) {
        http_response_code(400);
        exit('この予約は毎週予約ではありません。');
    }

    $displayDate = date(
        'Y-m-d',
        strtotime($reservation['start_at'])
    );

    try {
        $db->beginTransaction();

        if ($deleteScope === 'future') {
            /*
             * 同じ毎週予約の、この回以降を削除
             */
            $stmt = $db->prepare(
                '
                DELETE FROM reservations
                WHERE recurrence_group_id
                    = :recurrence_group_id
                  AND start_at >= :start_at
                '
            );

            $stmt->execute([
                ':recurrence_group_id' =>
                    $recurrenceGroupId,
                ':start_at' =>
                    $reservation['start_at'],
            ]);

            $deletedCount = $stmt->rowCount();
            $messageType = 'future';
        } else {
            /*
             * この予約だけ削除
             */
            $stmt = $db->prepare(
                '
                DELETE FROM reservations
                WHERE id = :id
                '
            );

            $stmt->execute([
                ':id' => $id,
            ]);

            $deletedCount = $stmt->rowCount();
            $messageType = 'single';
        }

        if ($deletedCount < 1) {
            throw new RuntimeException(
                '削除対象の予約が見つかりませんでした。'
            );
        }

        $db->commit();

        header(
            'Location: index.php?date='
            . rawurlencode($displayDate)
            . '&deleted=1'
            . '&delete_scope='
            . rawurlencode($messageType)
            . '&deleted_count='
            . rawurlencode((string) $deletedCount)
        );

        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        http_response_code(500);

        exit(
            '予約の削除中にエラーが発生しました。'
            . h($e->getMessage())
        );
    }
}

$roomName = find_room_name(
    $rooms,
    $reservation['room_id']
);

$displayDate = date(
    'Y-m-d',
    strtotime($reservation['start_at'])
);

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
        予約の削除 - <?php echo h(APP_NAME); ?>
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

        .warning-box {
            margin-bottom: 20px;
            padding: 16px 18px;
            color: #8b1a10;
            background: #fff1f0;
            border: 1px solid #e0aaa5;
        }

        .recurring-box {
            margin-bottom: 20px;
            padding: 15px 18px;
            color: #4b3a00;
            background: #fff9df;
            border: 1px solid #d8c46c;
        }

        .reservation-box {
            padding: 22px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .reservation-table {
            width: 100%;
            border-collapse: collapse;
        }

        .reservation-table th,
        .reservation-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #dddddd;
            text-align: left;
            vertical-align: top;
        }

        .reservation-table th {
            width: 140px;
            background: #f5f5f5;
            font-weight: 600;
        }

        .reservation-table tr:last-child th,
        .reservation-table tr:last-child td {
            border-bottom: none;
        }

        .note {
            white-space: pre-wrap;
        }

        .delete-options {
            margin-top: 24px;
            padding: 18px;
            background: #f7f7f7;
            border: 1px solid #d8d8d8;
        }

        .delete-options h2 {
            margin: 0 0 16px;
            font-size: 18px;
        }

        .delete-form {
            margin: 0 0 12px;
        }

        .delete-form:last-child {
            margin-bottom: 0;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
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

        .button-danger {
            color: #ffffff;
            background: #a61b1b;
            border-color: #a61b1b;
        }

        .button-danger-outline {
            color: #a61b1b;
            background: #ffffff;
            border-color: #a61b1b;
        }

        .button:hover {
            opacity: 0.85;
        }

        .option-description {
            margin: 6px 0 0;
            color: #666666;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .reservation-table th {
                width: 100px;
            }

            .button-row {
                flex-direction: column;
            }

            .button,
            .delete-form button {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>予約の削除</h1>

    <div class="warning-box">
        削除した予約は元に戻せません。
    </div>

    <?php if ($isRecurring): ?>

        <div class="recurring-box">
            この予約は毎週予約の一部です。

            <?php if ($futureReservationCount > 1): ?>
                この回を含め、同じ予約が以降
                <?php
                echo (int) $futureReservationCount;
                ?>件あります。
            <?php else: ?>
                この予約は毎週予約の最後の回です。
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <div class="reservation-box">

        <table class="reservation-table">
            <tbody>

            <tr>
                <th>会議室</th>
                <td><?php echo h($roomName); ?></td>
            </tr>

            <tr>
                <th>開始</th>
                <td>
                    <?php
                    echo h(
                        format_datetime_japanese(
                            $reservation['start_at']
                        )
                    );
                    ?>
                </td>
            </tr>

            <tr>
                <th>終了</th>
                <td>
                    <?php
                    echo h(
                        format_datetime_japanese(
                            $reservation['end_at']
                        )
                    );
                    ?>
                </td>
            </tr>

            <tr>
                <th>予定</th>
                <td>
                    <?php
                    echo h($reservation['title']);
                    ?>
                </td>
            </tr>

            <tr>
                <th>予約者</th>
                <td>
                    <?php
                    echo h($reservation['user_name']);
                    ?>
                </td>
            </tr>

            <?php if ($reservation['note'] !== ''): ?>
                <tr>
                    <th>備考</th>
                    <td class="note">
                        <?php
                        echo h($reservation['note']);
                        ?>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>

        <div class="delete-options">

            <h2>削除する範囲を選択</h2>

            <form
                method="post"
                action="delete.php"
                class="delete-form"
            >
                <input
                    type="hidden"
                    name="id"
                    value="<?php echo (int) $id; ?>"
                >

                <input
                    type="hidden"
                    name="delete_scope"
                    value="single"
                >

                <button
                    type="submit"
                    class="button button-danger-outline"
                >
                    この予約だけ削除
                </button>

                <?php if ($isRecurring): ?>
                    <p class="option-description">
                        この日だけ削除し、翌週以降の予約は残します。
                    </p>
                <?php endif; ?>
            </form>

            <?php if (
                $isRecurring
                && $futureReservationCount > 1
            ): ?>

                <form
                    method="post"
                    action="delete.php"
                    class="delete-form"
                >
                    <input
                        type="hidden"
                        name="id"
                        value="<?php echo (int) $id; ?>"
                    >

                    <input
                        type="hidden"
                        name="delete_scope"
                        value="future"
                    >

                    <button
                        type="submit"
                        class="button button-danger"
                    >
                        この予約と、それ以降を削除
                    </button>

                    <p class="option-description">
                        この回を含め、同じ毎週予約を
                        <?php
                        echo (int) $futureReservationCount;
                        ?>件削除します。
                    </p>
                </form>

            <?php endif; ?>

        </div>

        <div class="button-row">

            <a
                class="button"
                href="edit.php?id=<?php echo (int) $id; ?>"
            >
                編集画面へ戻る
            </a>

            <a
                class="button"
                href="index.php?date=<?php
                echo rawurlencode($displayDate);
                ?>"
            >
                予約一覧へ戻る
            </a>

        </div>

    </div>

</div>
</body>
</html>
