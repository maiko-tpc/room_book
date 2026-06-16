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

function is_valid_date(string $date): bool
{
    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $dateObject !== false
        && $dateObject->format('Y-m-d') === $date;
}

function format_time(string $dateTime): string
{
    $timestamp = strtotime($dateTime);

    if ($timestamp === false) {
        return '';
    }

    return date('H:i', $timestamp);
}

/*
 * 会議室の自動配色
 *
 * 最初の3色は、これまで使用していた
 * 青、緑、黄色をそのまま使用します。
 *
 * 11部屋目以降は先頭の色から繰り返します。
 */
$roomColors = [
    '#dbeafe',
    '#dcfce7',
    '#fef3c7',
    '#fee2e2',
    '#ffedd5',
    '#fce7f3',
    '#ede9fe',
    '#cffafe',
    '#ccfbf1',
    '#f3e8ff',
];

/*
 * 表示日
 */
$date = $_GET['date'] ?? date('Y-m-d');

if (!is_valid_date($date)) {
    $date = date('Y-m-d');
}

$dateObject = new DateTime($date);

/*
 * 前日・翌日
 */
$previousDate = clone $dateObject;
$previousDate->modify('-1 day');

$nextDate = clone $dateObject;
$nextDate->modify('+1 day');

/*
 * 前週・翌週
 */
$previousWeek = clone $dateObject;
$previousWeek->modify('-7 days');

$nextWeek = clone $dateObject;
$nextWeek->modify('+7 days');

/*
 * 表示日の開始・終了
 */
$dayStart = $date . ' 00:00:00';

$dayEndObject = clone $dateObject;
$dayEndObject->modify('+1 day');

$dayEnd = $dayEndObject->format(
    'Y-m-d 00:00:00'
);

/*
 * 予約取得
 */
$db = get_db();

$stmt = $db->prepare(
    '
    SELECT
        id,
        room_id,
        title,
        user_name,
        start_at,
        end_at,
        note
    FROM reservations
    WHERE start_at < :day_end
      AND end_at > :day_start
    ORDER BY room_id, start_at, end_at
    '
);

$stmt->execute([
    ':day_start' => $dayStart,
    ':day_end' => $dayEnd,
]);

$reservations = $stmt->fetchAll();

/*
 * 会議室ごとに予約を整理
 */
$reservationsByRoom = [];

foreach ($rooms as $room) {
    $reservationsByRoom[$room['id']] = [];
}

foreach ($reservations as $reservation) {
    $roomId = $reservation['room_id'];

    if (!array_key_exists(
        $roomId,
        $reservationsByRoom
    )) {
        continue;
    }

    $reservationsByRoom[$roomId][] =
        $reservation;
}

/*
 * 日付表示
 */
$weekdays = [
    '日',
    '月',
    '火',
    '水',
    '木',
    '金',
    '土',
];

$displayDate = $dateObject->format(
    'Y年n月j日'
);

$weekday = $weekdays[
    (int) $dateObject->format('w')
];

/*
 * 完了メッセージ
 */
$message = '';

if (
    isset($_GET['registered'])
    && $_GET['registered'] === '1'
) {
    $message = '予約を登録しました。';
}

if (
    isset($_GET['updated'])
    && $_GET['updated'] === '1'
) {
    $message = '予約を更新しました。';
}

if (
    isset($_GET['deleted'])
    && $_GET['deleted'] === '1'
) {
    $message = '予約を削除しました。';
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

    <title><?php echo h(APP_NAME); ?></title>

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
            max-width: 1100px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 24px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }

        .success-message {
            margin-bottom: 20px;
            padding: 13px 16px;
            color: #176b32;
            background: #edf8f0;
            border: 1px solid #9fcbaa;
        }

        .date-navigation {
            display: grid;
            grid-template-columns:
                auto
                auto
                minmax(200px, 1fr)
                auto
                auto;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid #d0d0d0;
        }

        .date-navigation a {
            display: inline-block;
            padding: 8px 12px;
            color: #222222;
            text-align: center;
            text-decoration: none;
            white-space: nowrap;
            background: #f3f3f3;
            border: 1px solid #cccccc;
        }

        .date-navigation a:hover {
            background: #e8e8e8;
        }

        .week-button {
            color: #444444;
            background: #fafafa;
        }

        .current-date {
            padding: 0 10px;
            font-size: 20px;
            font-weight: 600;
            text-align: center;
        }

        .date-tools {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
            padding: 12px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .date-form {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .date-form label {
            font-weight: 600;
        }

        .date-form input[type="date"] {
            padding: 8px 10px;
            color: #222222;
            background: #ffffff;
            border: 1px solid #aaaaaa;
            border-radius: 2px;
            cursor: pointer;
            font: inherit;
        }

        .today-link {
            color: #135ea8;
            white-space: nowrap;
        }

        .room-section {
            margin-bottom: 20px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .room-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #eeeeee;
            border-bottom: 1px solid #d8d8d8;
        }

        .room-marker {
            flex: 0 0 auto;
            width: 14px;
            height: 14px;
            border: 1px solid #999999;
        }

        .room-name-link {
            color: #135ea8;
            font-size: 18px;
            font-weight: 600;
            text-decoration: underline;
        }

        .room-name-link:hover {
            color: #0b4f91;
        }

        .room-description {
            color: #666666;
            font-size: 14px;
        }

        .room-detail-link {
            margin-left: auto;
            padding: 5px 10px;
            color: #444444;
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
            background: #ffffff;
            border: 1px solid #b8b8b8;
            border-radius: 2px;
        }

        .room-detail-link:hover {
            color: #135ea8;
            background: #f7f7f7;
            border-color: #888888;
        }

        .reservation-list {
            width: 100%;
            border-collapse: collapse;
        }

        .reservation-list th,
        .reservation-list td {
            padding: 12px 14px;
            border-bottom: 1px solid #e1e1e1;
            text-align: left;
            vertical-align: top;
        }

        .reservation-list th {
            background: #fafafa;
            font-weight: 600;
        }

        .reservation-list tr:last-child td {
            border-bottom: none;
        }

        .time-column {
            width: 160px;
            white-space: nowrap;
        }

        .user-column {
            width: 180px;
        }

        .reservation-link {
            color: #135ea8;
            font-weight: 600;
            text-decoration: none;
        }

        .reservation-link:hover {
            text-decoration: underline;
        }

        .note {
            margin-top: 5px;
            color: #666666;
            font-size: 13px;
            white-space: pre-wrap;
        }

        .empty {
            padding: 18px 16px;
            color: #777777;
        }

        @media (max-width: 700px) {
            body {
                padding: 15px;
            }

            .date-navigation {
                grid-template-columns:
                    1fr
                    1fr
                    1fr
                    1fr;
            }

            .current-date {
                grid-column: 1 / -1;
                grid-row: 1;
                padding: 4px 0 10px;
            }

            .date-navigation a {
                grid-row: 2;
                padding: 8px 5px;
                font-size: 13px;
            }

            .date-tools {
                align-items: stretch;
                flex-direction: column;
            }

            .date-form {
                align-items: stretch;
                flex-direction: column;
            }

            .date-form input[type="date"] {
                width: 100%;
            }

            .today-link {
                text-align: center;
            }

            .room-header {
                align-items: flex-start;
                flex-wrap: wrap;
            }

            .room-description {
                flex: 1 1 auto;
            }

            .room-detail-link {
                margin-left: 24px;
            }

            .reservation-list th:nth-child(3),
            .reservation-list td:nth-child(3) {
                display: none;
            }

            .time-column {
                width: 125px;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <h1><?php echo h(APP_NAME); ?></h1>
    </div>

    <?php if ($message !== ''): ?>
        <div class="success-message">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="date-navigation">

        <a
            class="week-button"
            href="?date=<?php
            echo rawurlencode(
                $previousWeek->format('Y-m-d')
            );
            ?>"
        >
            前週
        </a>

        <a href="?date=<?php
        echo rawurlencode(
            $previousDate->format('Y-m-d')
        );
        ?>">
            前日
        </a>

        <div class="current-date">
            <?php echo h($displayDate); ?>
            （<?php echo h($weekday); ?>）
        </div>

        <a href="?date=<?php
        echo rawurlencode(
            $nextDate->format('Y-m-d')
        );
        ?>">
            翌日
        </a>

        <a
            class="week-button"
            href="?date=<?php
            echo rawurlencode(
                $nextWeek->format('Y-m-d')
            );
            ?>"
        >
            翌週
        </a>

    </div>

    <div class="date-tools">

        <form
            class="date-form"
            method="get"
            action="index.php"
        >
            <label for="date">
                日付を選択
            </label>

            <input
                type="date"
                id="date"
                name="date"
                value="<?php echo h($date); ?>"
                onchange="this.form.submit();"
                required
            >
        </form>

        <a
            class="today-link"
            href="?date=<?php
            echo rawurlencode(date('Y-m-d'));
            ?>"
        >
            今日を表示
        </a>

    </div>

    <?php foreach ($rooms as $roomIndex => $room): ?>

        <?php
        $roomId = $room['id'];

        $roomReservations =
            $reservationsByRoom[$roomId];

        $roomColor = $roomColors[
            $roomIndex % count($roomColors)
        ];
        ?>

        <section class="room-section">

            <div class="room-header">

                <span
                    class="room-marker"
                    style="background-color: <?php
                    echo h($roomColor);
                    ?>;"
                ></span>

                <a
                    class="room-name-link"
                    href="reserve.php?date=<?php
                    echo rawurlencode($date);
                    ?>&amp;room_id=<?php
                    echo rawurlencode($room['id']);
                    ?>"
                    title="<?php
                    echo h(
                        $room['name']
                        . 'を予約する'
                    );
                    ?>"
                >
                    <?php echo h($room['name']); ?>
                </a>

                <?php if ($room['description'] !== ''): ?>
                    <span class="room-description">
                        <?php
                        echo h($room['description']);
                        ?>
                    </span>
                <?php endif; ?>

                <a
                    class="room-detail-link"
                    href="room.php?room_id=<?php
                    echo rawurlencode($room['id']);
                    ?>"
                >
                    設備・詳細
                </a>

            </div>

            <?php if (empty($roomReservations)): ?>

                <div class="empty">
                    この日の予約はありません。
                </div>

            <?php else: ?>

                <table class="reservation-list">

                    <thead>
                    <tr>
                        <th class="time-column">
                            時間
                        </th>

                        <th>
                            予定
                        </th>

                        <th class="user-column">
                            予約者
                        </th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($roomReservations as $reservation): ?>

                        <tr>
                            <td class="time-column">
                                <?php
                                echo h(
                                    format_time(
                                        $reservation['start_at']
                                    )
                                );
                                ?>
                                ～
                                <?php
                                echo h(
                                    format_time(
                                        $reservation['end_at']
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <a
                                    class="reservation-link"
                                    href="edit.php?id=<?php
                                    echo (int) $reservation['id'];
                                    ?>"
                                >
                                    <?php
                                    echo h(
                                        $reservation['title']
                                    );
                                    ?>
                                </a>

                                <?php if ($reservation['note'] !== ''): ?>
                                    <div class="note">
                                        <?php
                                        echo h(
                                            $reservation['note']
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="user-column">
                                <?php
                                echo h(
                                    $reservation['user_name']
                                );
                                ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </section>

    <?php endforeach; ?>

</div>
</body>
</html>
