<?php

declare(strict_types=1);

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

function format_month_day(DateTime $dateObject): string
{
    return $dateObject->format('n/j');
}

function format_full_date(DateTime $dateObject): string
{
    return $dateObject->format('Y年n月j日');
}

function get_room_color(
    array $room,
    int $roomIndex,
    array $roomColors
): string {
    if (
        isset($room['color'])
        && is_string($room['color'])
        && $room['color'] !== ''
    ) {
        return $room['color'];
    }

    return $roomColors[
        $roomIndex % count($roomColors)
    ];
}

function get_room_facility_labels(array $roomDetails): array
{
    $facilityLabels = [];

    if ((int) $roomDetails['has_screen'] === 1) {
        $facilityLabels[] = 'スクリーン';
    }

    if ((int) $roomDetails['has_projector'] === 1) {
        $facilityLabels[] = 'プロジェクター';
    }

    if ((int) $roomDetails['has_whiteboard'] === 1) {
        $facilityLabels[] = 'ホワイトボード';
    }

    if ((int) $roomDetails['has_web_conference'] === 1) {
        $facilityLabels[] = 'Web会議設備';
    }

    return $facilityLabels;
}

/*
 * 会議室の自動配色
 *
 * config.php 側で color を指定している場合は、
 * そちらを優先します。
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
 * 表示日を含む週の月曜日を求める
 */
$weekStartObject = clone $dateObject;

$weekdayNumber =
    (int) $weekStartObject->format('w');

if ($weekdayNumber === 0) {
    $weekStartObject->modify('-6 days');
} else {
    $weekStartObject->modify(
        '-' . ($weekdayNumber - 1) . ' days'
    );
}

$weekEndObject = clone $weekStartObject;
$weekEndObject->modify('+7 days');

$previousWeekObject = clone $weekStartObject;
$previousWeekObject->modify('-7 days');

$nextWeekObject = clone $weekStartObject;
$nextWeekObject->modify('+7 days');

/*
 * 前日・翌日
 */
$previousDate = clone $dateObject;
$previousDate->modify('-1 day');

$nextDate = clone $dateObject;
$nextDate->modify('+1 day');

/*
 * 週内の日付
 */
$weekDates = [];

for ($i = 0; $i < 7; $i++) {
    $dayObject = clone $weekStartObject;
    $dayObject->modify('+' . $i . ' days');

    $weekDates[] = $dayObject;
}

/*
 * 期間文字列
 */
$weekStart =
    $weekStartObject->format('Y-m-d') . ' 00:00:00';

$weekEnd =
    $weekEndObject->format('Y-m-d') . ' 00:00:00';

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
    WHERE start_at < :week_end
      AND end_at > :week_start
    ORDER BY room_id, start_at, end_at
    '
);

$stmt->execute([
    ':week_start' => $weekStart,
    ':week_end' => $weekEnd,
]);

$weekReservations = $stmt->fetchAll();

/*
 * 会議室詳細情報を取得
 */
$roomDetailsByRoom = [];

foreach ($rooms as $room) {
    $roomDetailsByRoom[$room['id']] = [
        'capacity' => null,
        'has_screen' => 0,
        'has_projector' => 0,
        'has_whiteboard' => 0,
        'has_web_conference' => 0,
        'equipment' => '',
        'note' => '',
        'updated_at' => '',
    ];
}

try {
    $roomDetailsStmt = $db->query(
        '
        SELECT
            room_id,
            capacity,
            has_screen,
            has_projector,
            has_whiteboard,
            has_web_conference,
            equipment,
            note,
            updated_at
        FROM room_details
        '
    );

    $roomDetailsRows =
        $roomDetailsStmt->fetchAll();

    foreach ($roomDetailsRows as $roomDetails) {
        $detailsRoomId =
            (string) $roomDetails['room_id'];

        if (!array_key_exists(
            $detailsRoomId,
            $roomDetailsByRoom
        )) {
            continue;
        }

        $roomDetailsByRoom[$detailsRoomId] =
            $roomDetails;
    }
} catch (Throwable $e) {
    /*
     * room_detailsテーブルがまだない古い環境でも、
     * 予約一覧は表示できるようにする。
     */
    error_log(
        'Room details load error: '
        . $e->getMessage()
    );
}

/*
 * 会議室 × 日付 ごとに予約を整理
 */
$weekReservationsByRoomDate = [];
$reservationsByRoom = [];

foreach ($rooms as $room) {
    $roomId = $room['id'];

    $reservationsByRoom[$roomId] = [];
    $weekReservationsByRoomDate[$roomId] = [];

    foreach ($weekDates as $dayObject) {
        $weekReservationsByRoomDate[$roomId][
            $dayObject->format('Y-m-d')
        ] = [];
    }
}

foreach ($weekReservations as $reservation) {
    $roomId = $reservation['room_id'];

    if (!array_key_exists(
        $roomId,
        $weekReservationsByRoomDate
    )) {
        continue;
    }

    /*
     * 日別詳細用
     */
    if (
        $reservation['start_at'] < $dayEnd
        && $reservation['end_at'] > $dayStart
    ) {
        $reservationsByRoom[$roomId][] =
            $reservation;
    }

    /*
     * 週表示用
     */
    foreach ($weekDates as $dayObject) {
        $cellDate =
            $dayObject->format('Y-m-d');

        $cellStart =
            $cellDate . ' 00:00:00';

        $cellEndObject = clone $dayObject;
        $cellEndObject->modify('+1 day');

        $cellEnd =
            $cellEndObject->format(
                'Y-m-d 00:00:00'
            );

        if (
            $reservation['start_at'] < $cellEnd
            && $reservation['end_at'] > $cellStart
        ) {
            $weekReservationsByRoomDate[$roomId][$cellDate][] =
                $reservation;
        }
    }
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

$displayDate = format_full_date(
    $dateObject
);

$weekday = $weekdays[
    (int) $dateObject->format('w')
];

$weekDisplay =
    format_full_date($weekStartObject)
    . '（月）〜 '
    . format_full_date(
        (clone $weekEndObject)->modify('-1 day')
    )
    . '（日）';

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
            max-width: 1180px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }

        h2 {
            margin: 0 0 16px;
            font-size: 21px;
            font-weight: 600;
        }

        .button {
            display: inline-block;
            padding: 9px 16px;
            color: #222222;
            text-align: center;
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

        .success-message {
            margin-bottom: 20px;
            padding: 13px 16px;
            color: #176b32;
            background: #edf8f0;
            border: 1px solid #9fcbaa;
        }

        .week-panel {
            margin-bottom: 22px;
            padding: 18px;
            background: #ffffff;
            border: 1px solid #d0d0d0;
        }

        .week-panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .week-title {
            flex: 0 0 auto;
            font-size: 20px;
            font-weight: 600;
        }

        .week-date-tools {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 10px;
        }

        .week-next-button {
            margin-left: auto;
        }

        .week-date-tools .today-link {
            font-size: 13px;
        }


        .week-table-wrap {
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .week-table {
            width: 100%;
            min-width: 860px;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
            border-top: 1px solid #d8d8d8;
            border-left: 1px solid #d8d8d8;
        }

        .week-table th,
        .week-table td {
            border-right: 1px solid #d8d8d8;
            border-bottom: 1px solid #d8d8d8;
        }

        .week-table th {
            background: #f4f4f4;
            font-weight: 600;
        }

        .week-room-header {
            width: 150px;
            padding: 10px;
            text-align: left;
            vertical-align: middle;
        }

        .week-room-header-label {
            text-align: center;
            vertical-align: middle;
        }

        .week-day-header {
            padding: 0;
            text-align: center;
        }

        .week-day-link {
            display: block;
            padding: 10px 6px;
            color: #222222;
            text-decoration: none;
        }

        .week-day-link:hover {
            color: #135ea8;
            text-decoration: underline;
            background: #eeeeee;
        }

        .week-day-link.selected {
            color: #ffffff;
            background: #333333;
        }

        .week-day-name {
            display: block;
            margin-bottom: 2px;
            font-size: 14px;
        }

        .week-day-date {
            display: block;
            font-size: 13px;
            font-weight: 400;
        }

        .week-room-name {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .room-marker {
            flex: 0 0 auto;
            width: 14px;
            height: 14px;
            border: 1px solid #999999;
        }

        .week-room-link {
            color: #222222;
            text-decoration: none;
        }

        .week-room-link:hover {
            color: #135ea8;
            text-decoration: underline;
        }

        .week-room-place {
            margin-top: 4px;
            padding-left: 22px;
            color: #666666;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.35;
        }

        .week-room-info-wrapper {
            position: relative;
        }

        .week-room-info-popup {
            display: none;
            position: absolute;
            z-index: 30;
            left: 8px;
            top: 34px;
            width: 300px;
            padding: 12px;
            color: #222222;
            background: #ffffff;
            border: 1px solid #999999;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18);
            text-align: left;
            font-weight: 400;
        }

        .week-room-info-wrapper:hover .week-room-info-popup,
        .week-room-info-wrapper:focus-within .week-room-info-popup {
            display: block;
        }

        .week-room-info-title {
            margin-bottom: 8px;
            padding-bottom: 7px;
            border-bottom: 1px solid #dddddd;
            font-weight: 600;
        }

        .week-room-info-row {
            margin: 0 0 7px;
            color: #444444;
            font-size: 13px;
            line-height: 1.45;
        }

        .week-room-info-label {
            color: #222222;
            font-weight: 600;
        }

        .week-room-info-empty {
            color: #777777;
        }

        .week-room-info-note {
            margin-top: 8px;
            padding-top: 8px;
            color: #555555;
            border-top: 1px solid #eeeeee;
            font-size: 13px;
            line-height: 1.45;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .week-room-info-link {
            display: block;
            margin-top: 10px;
            padding: 7px 10px;
            color: #ffffff;
            text-align: center;
            text-decoration: none;
            background: #333333;
            border: 1px solid #333333;
            font-size: 13px;
        }

        .week-room-info-link:hover {
            opacity: 0.85;
        }

        .week-cell {
            position: relative;
            height: 82px;
            padding: 0;
            vertical-align: top;
            background: #ffffff;
            cursor: pointer;
        }

        .week-cell:hover {
            background: #f7fbff;
        }

        .week-cell.selected-day {
            background: #fffdf2;
        }

        .week-cell-inner {
            height: 100%;
            padding: 8px;
        }

        .week-cell-count {
            display: inline-block;
            margin-bottom: 6px;
            padding: 2px 7px;
            color: #333333;
            background: #eeeeee;
            border: 1px solid #d0d0d0;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .week-cell-empty {
            color: #777777;
            background: #f8f8f8;
        }

        .week-cell-preview {
            overflow: hidden;
            color: #444444;
            font-size: 12px;
            line-height: 1.35;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .week-cell-popup {
            display: none;
            position: absolute;
            z-index: 20;
            left: 8px;
            top: 58px;
            width: 270px;
            padding: 12px;
            color: #222222;
            background: #ffffff;
            border: 1px solid #999999;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18);
        }

        .week-cell:hover .week-cell-popup,
        .week-cell:focus-within .week-cell-popup {
            display: block;
        }

        .week-cell-popup-title {
            margin-bottom: 8px;
            padding-bottom: 7px;
            border-bottom: 1px solid #dddddd;
            font-weight: 600;
        }

        .week-popup-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .week-popup-list li + li {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #eeeeee;
        }

        .week-popup-time {
            display: block;
            color: #666666;
            font-size: 12px;
        }

        .week-popup-link {
            color: #135ea8;
            font-weight: 600;
            text-decoration: none;
        }

        .week-popup-link:hover {
            text-decoration: underline;
        }

        .week-popup-user {
            margin-top: 2px;
            color: #666666;
            font-size: 12px;
        }

        .week-popup-empty {
            color: #777777;
        }

        .week-popup-add {
            display: block;
            margin-top: 10px;
            padding: 7px 10px;
            color: #ffffff;
            text-align: center;
            text-decoration: none;
            background: #333333;
            border: 1px solid #333333;
        }

        .week-popup-add:hover {
            opacity: 0.85;
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

        .room-name-link {
            color: #222222;
            font-size: 18px;
            font-weight: 600;
            text-decoration: none;
        }

        .room-name-link:hover {
            color: #135ea8;
            text-decoration: underline;
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

            .button {
                width: 100%;
            }

            .week-panel {
                padding: 14px;
            }

            .week-panel-header {
                align-items: stretch;
                flex-direction: column;
            }

            .week-title {
                order: -1;
                font-size: 18px;
            }

            .week-date-tools {
                align-items: stretch;
                justify-content: stretch;
                flex-direction: column;
            }

            .week-next-button {
                margin-left: 0;
            }

            .week-date-tools .today-link {
                text-align: center;
            }

            .week-table {
                min-width: 760px;
            }

            .week-room-header {
                width: 120px;
            }

            .week-cell {
                height: 74px;
            }

            .week-cell-popup {
                display: none;
            }

            .week-room-info-popup {
                display: none;
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

    <section class="week-panel">

        <div class="week-panel-header">

            <a
                class="button"
                href="?date=<?php
                echo rawurlencode(
                    $previousWeekObject->format('Y-m-d')
                );
                ?>"
            >
                前週
            </a>

            <div class="week-title">
                <?php echo h($weekDisplay); ?>
            </div>

            <div class="week-date-tools">

                <form
                    class="date-form"
                    method="get"
                    action="index.php"
                >
                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="<?php echo h($date); ?>"
                        onchange="this.form.submit();"
                        aria-label="日付を選択"
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

            <a
                class="button week-next-button"
                href="?date=<?php
                echo rawurlencode(
                    $nextWeekObject->format('Y-m-d')
                );
                ?>"
            >
                翌週
            </a>

        </div>

        <div class="week-table-wrap">

            <table class="week-table">

                <thead>
                <tr>
                    <th class="week-room-header week-room-header-label">
                        会議室
                    </th>

                    <?php foreach ($weekDates as $dayObject): ?>

                        <?php
                        $cellDate =
                            $dayObject->format('Y-m-d');

                        $isSelectedDay =
                            $cellDate === $date;

                        $dayWeekday =
                            $weekdays[
                                (int) $dayObject->format('w')
                            ];
                        ?>

                        <th class="week-day-header">
                            <a
                                class="week-day-link <?php
                                echo $isSelectedDay
                                    ? 'selected'
                                    : '';
                                ?>"
                                href="?date=<?php
                                echo rawurlencode($cellDate);
                                ?>"
                            >
                                <span class="week-day-name">
                                    <?php echo h($dayWeekday); ?>
                                </span>

                                <span class="week-day-date">
                                    <?php
                                    echo h(
                                        format_month_day(
                                            $dayObject
                                        )
                                    );
                                    ?>
                                </span>
                            </a>
                        </th>

                    <?php endforeach; ?>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($rooms as $roomIndex => $room): ?>

                    <?php
                    $roomId = $room['id'];

                    $roomColor = get_room_color(
                        $room,
                        $roomIndex,
                        $roomColors
                    );
                    ?>

                    <tr>

                        <th class="week-room-header">

                            <?php
                            $roomDetails =
                                $roomDetailsByRoom[$roomId];

                            $facilityLabels =
                                get_room_facility_labels(
                                    $roomDetails
                                );

                            $capacityText = '';

                            if (
                                $roomDetails['capacity'] !== null
                                && $roomDetails['capacity'] !== ''
                            ) {
                                $capacityText =
                                    (string) $roomDetails['capacity']
                                    . '名';
                            }

                            $equipmentText =
                                trim(
                                    (string) $roomDetails['equipment']
                                );

                            $noteText =
                                trim(
                                    (string) $roomDetails['note']
                                );

                            $hasAnyRoomInfo =
                                $capacityText !== ''
                                || !empty($facilityLabels)
                                || $equipmentText !== ''
                                || $noteText !== '';
                            ?>

                            <div class="week-room-info-wrapper">

                                <div class="week-room-name">

                                    <span
                                        class="room-marker"
                                        style="background-color: <?php
                                        echo h($roomColor);
                                        ?>;"
                                    ></span>

                                    <a
                                        class="week-room-link"
                                        href="room.php?room_id=<?php
                                        echo rawurlencode($roomId);
                                        ?>"
                                    >
                                        <?php echo h($room['name']); ?>
                                    </a>

                                </div>

                                <?php if ($room['description'] !== ''): ?>
                                    <div class="week-room-place">
                                        <?php
                                        echo h(
                                            $room['description']
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="week-room-info-popup">

                                    <div class="week-room-info-title">
                                        <?php echo h($room['name']); ?>
                                    </div>

                                    <p class="week-room-info-row">
                                        <span class="week-room-info-label">
                                            定員：
                                        </span>

                                        <?php if ($capacityText !== ''): ?>
                                            <?php echo h($capacityText); ?>
                                        <?php else: ?>
                                            <span class="week-room-info-empty">
                                                未登録
                                            </span>
                                        <?php endif; ?>
                                    </p>

                                    <p class="week-room-info-row">
                                        <span class="week-room-info-label">
                                            設備：
                                        </span>

                                        <?php if (!empty($facilityLabels)): ?>
                                            <?php
                                            echo h(
                                                implode(
                                                    '、',
                                                    $facilityLabels
                                                )
                                            );
                                            ?>
                                        <?php else: ?>
                                            <span class="week-room-info-empty">
                                                未登録
                                            </span>
                                        <?php endif; ?>
                                    </p>

                                    <?php if ($equipmentText !== ''): ?>
                                        <p class="week-room-info-row">
                                            <span class="week-room-info-label">
                                                その他：
                                            </span>

                                            <?php echo h($equipmentText); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($noteText !== ''): ?>
                                        <div class="week-room-info-note">
                                            <?php echo h($noteText); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!$hasAnyRoomInfo): ?>
                                        <p class="week-room-info-row">
                                            <span class="week-room-info-empty">
                                                会議室情報は未登録です。
                                            </span>
                                        </p>
                                    <?php endif; ?>

                                    <a
                                        class="week-room-info-link"
                                        href="room.php?room_id=<?php
                                        echo rawurlencode($roomId);
                                        ?>"
                                    >
                                        設備・詳細を開く
                                    </a>

                                </div>

                            </div>

                        </th>

                        <?php foreach ($weekDates as $dayObject): ?>

                            <?php
                            $cellDate =
                                $dayObject->format('Y-m-d');

                            $cellReservations =
                                $weekReservationsByRoomDate[
                                    $roomId
                                ][$cellDate];

                            $reservationCount =
                                count($cellReservations);

                            $cellUrl =
                                'reserve.php?date='
                                . rawurlencode($cellDate)
                                . '&room_id='
                                . rawurlencode($roomId);

                            $isSelectedDay =
                                $cellDate === $date;
                            ?>

                            <td
                                class="week-cell <?php
                                echo $isSelectedDay
                                    ? 'selected-day'
                                    : '';
                                ?>"
                                tabindex="0"
                                data-href="<?php
                                echo h($cellUrl);
                                ?>"
                                title="<?php
                                echo h(
                                    $room['name']
                                    . ' / '
                                    . $cellDate
                                    . ' に予約を追加'
                                );
                                ?>"
                            >

                                <div class="week-cell-inner">

                                    <?php if ($reservationCount > 0): ?>

                                        <span class="week-cell-count">
                                            <?php
                                            echo $reservationCount;
                                            ?>件
                                        </span>

                                        <?php
                                        $previewReservations =
                                            array_slice(
                                                $cellReservations,
                                                0,
                                                2
                                            );
                                        ?>

                                        <?php foreach ($previewReservations as $reservation): ?>

                                            <div class="week-cell-preview">
                                                <?php
                                                echo h(
                                                    format_time(
                                                        $reservation['start_at']
                                                    )
                                                    . ' '
                                                    . $reservation['title']
                                                );
                                                ?>
                                            </div>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <span
                                            class="
                                                week-cell-count
                                                week-cell-empty
                                            "
                                        >
                                            空き
                                        </span>

                                    <?php endif; ?>

                                    <div class="week-cell-popup">

                                        <div class="week-cell-popup-title">
                                            <?php
                                            echo h(
                                                $room['name']
                                                . ' / '
                                                . format_month_day(
                                                    $dayObject
                                                )
                                                . '（'
                                                . $weekdays[
                                                    (int) $dayObject
                                                        ->format('w')
                                                ]
                                                . '）'
                                            );
                                            ?>
                                        </div>

                                        <?php if ($reservationCount > 0): ?>

                                            <ul class="week-popup-list">

                                                <?php foreach ($cellReservations as $reservation): ?>

                                                    <li>
                                                        <span class="week-popup-time">
                                                            <?php
                                                            echo h(
                                                                format_time(
                                                                    $reservation['start_at']
                                                                )
                                                                . '～'
                                                                . format_time(
                                                                    $reservation['end_at']
                                                                )
                                                            );
                                                            ?>
                                                        </span>

                                                        <a
                                                            class="week-popup-link"
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

                                                        <?php if ($reservation['user_name'] !== ''): ?>

                                                            <div class="week-popup-user">
                                                                <?php
                                                                echo h(
                                                                    $reservation['user_name']
                                                                );
                                                                ?>
                                                            </div>

                                                        <?php endif; ?>
                                                    </li>

                                                <?php endforeach; ?>

                                            </ul>

                                        <?php else: ?>

                                            <div class="week-popup-empty">
                                                この日の予約はありません。
                                            </div>

                                        <?php endif; ?>

                                        <a
                                            class="week-popup-add"
                                            href="<?php echo h($cellUrl); ?>"
                                        >
                                            この枠に予約を追加
                                        </a>

                                    </div>

                                </div>

                            </td>

                        <?php endforeach; ?>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>

    <div class="date-navigation">

        <a
            class="week-button"
            href="?date=<?php
            echo rawurlencode(
                $previousWeekObject->format('Y-m-d')
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
                $nextWeekObject->format('Y-m-d')
            );
            ?>"
        >
            翌週
        </a>

    </div>

    <h2>
        <?php echo h($displayDate); ?>
        （<?php echo h($weekday); ?>）の予約一覧
    </h2>

    <?php foreach ($rooms as $roomIndex => $room): ?>

        <?php
        $roomId = $room['id'];

        $roomReservations =
            $reservationsByRoom[$roomId];

        $roomColor = get_room_color(
            $room,
            $roomIndex,
            $roomColors
        );
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
                    echo rawurlencode($roomId);
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
                    echo rawurlencode($roomId);
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

<script>
(function () {
    const cells = document.querySelectorAll(
        '.week-cell[data-href]'
    );

    cells.forEach(function (cell) {
        cell.addEventListener(
            'click',
            function (event) {
                if (event.target.closest('a')) {
                    return;
                }

                window.location.href =
                    cell.getAttribute('data-href');
            }
        );

        cell.addEventListener(
            'keydown',
            function (event) {
                if (
                    event.key !== 'Enter'
                    && event.key !== ' '
                ) {
                    return;
                }

                if (event.target.closest('a')) {
                    return;
                }

                event.preventDefault();

                window.location.href =
                    cell.getAttribute('data-href');
            }
        );
    });
})();
</script>

</body>
</html>
