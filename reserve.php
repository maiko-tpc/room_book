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

function find_room(array $rooms, string $roomId): ?array
{
    foreach ($rooms as $room) {
        if ($room['id'] === $roomId) {
            return $room;
        }
    }

    return null;
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

function is_valid_time(string $time): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    $timeObject = DateTime::createFromFormat(
        'H:i',
        $time
    );

    return $timeObject !== false
        && $timeObject->format('H:i') === $time;
}

function create_time_options(
    string $startTime,
    string $endTime,
    int $intervalMinutes
): array {
    $options = [];

    $current = DateTime::createFromFormat(
        'H:i',
        $startTime
    );

    $end = DateTime::createFromFormat(
        'H:i',
        $endTime
    );

    if ($current === false || $end === false) {
        return $options;
    }

    while ($current <= $end) {
        $options[] = $current->format('H:i');

        $current->modify(
            '+' . $intervalMinutes . ' minutes'
        );
    }

    return $options;
}

function create_weekly_dates(
    string $startDate,
    string $repeatUntil
): array {
    $dates = [];

    $current = new DateTime($startDate);
    $end = new DateTime($repeatUntil);

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');

        $current->modify('+7 days');
    }

    return $dates;
}

function create_recurrence_group_id(): string
{
    return bin2hex(random_bytes(16));
}

/*
 * 初期日付
 */
$date = $_GET['date'] ?? date('Y-m-d');

if (!is_valid_date($date)) {
    $date = date('Y-m-d');
}

/*
 * URLで会議室IDが指定された場合は初期選択する
 */
$roomId = trim($_GET['room_id'] ?? '');

if (find_room($rooms, $roomId) === null) {
    $roomId = '';
}

$title = '';
$userName = '';
$startTime = DISPLAY_START_TIME;

$endTimeObject = DateTime::createFromFormat(
    'H:i',
    DISPLAY_START_TIME
);

if ($endTimeObject !== false) {
    $endTimeObject->modify(
        '+' . TIME_INTERVAL_MINUTES . ' minutes'
    );

    $endTime = $endTimeObject->format('H:i');
} else {
    $endTime = DISPLAY_START_TIME;
}

$note = '';
$isWeekly = false;
$repeatUntil = $date;
$errorMessages = [];

$timeOptions = create_time_options(
    DISPLAY_START_TIME,
    DISPLAY_END_TIME,
    TIME_INTERVAL_MINUTES
);

/*
 * 登録処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date'] ?? '');
    $roomId = trim($_POST['room_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $isWeekly = isset($_POST['is_weekly'])
        && $_POST['is_weekly'] === '1';

    $repeatUntil = trim(
        $_POST['repeat_until'] ?? ''
    );

    /*
     * 基本入力チェック
     */
    if (!is_valid_date($date)) {
        $errorMessages[] =
            '日付が正しくありません。';
    }

    if (find_room($rooms, $roomId) === null) {
        $errorMessages[] =
            '会議室を選択してください。';
    }

    if ($title === '') {
        $errorMessages[] =
            '予定を入力してください。';
    }

    if ($userName === '') {
        $errorMessages[] =
            '予約者名を入力してください。';
    }

    if (!is_valid_time($startTime)) {
        $errorMessages[] =
            '開始時刻が正しくありません。';
    }

    if (!is_valid_time($endTime)) {
        $errorMessages[] =
            '終了時刻が正しくありません。';
    }

    if (
        is_valid_time($startTime)
        && !in_array(
            $startTime,
            $timeOptions,
            true
        )
    ) {
        $errorMessages[] =
            '開始時刻が選択可能範囲外です。';
    }

    if (
        is_valid_time($endTime)
        && !in_array(
            $endTime,
            $timeOptions,
            true
        )
    ) {
        $errorMessages[] =
            '終了時刻が選択可能範囲外です。';
    }

    if (
        is_valid_time($startTime)
        && is_valid_time($endTime)
        && $startTime >= $endTime
    ) {
        $errorMessages[] =
            '終了時刻は開始時刻より後にしてください。';
    }

    /*
     * 毎週予約の入力チェック
     */
    if ($isWeekly) {
        if (!is_valid_date($repeatUntil)) {
            $errorMessages[] =
                '繰り返し終了日が正しくありません。';
        }

        if (
            is_valid_date($date)
            && is_valid_date($repeatUntil)
            && $repeatUntil < $date
        ) {
            $errorMessages[] =
                '繰り返し終了日は開始日以降にしてください。';
        }

        if (
            is_valid_date($date)
            && is_valid_date($repeatUntil)
        ) {
            $startDateObject = new DateTime($date);

            $repeatUntilObject = new DateTime(
                $repeatUntil
            );

            $maximumRepeatDate = clone $startDateObject;
            $maximumRepeatDate->modify('+1 year');

            if (
                $repeatUntilObject
                > $maximumRepeatDate
            ) {
                $errorMessages[] =
                    '毎週予約の期間は最大1年間です。';
            }
        }
    }

    /*
     * 登録対象日を作成
     */
    $reservationDates = [];

    if (empty($errorMessages)) {
        if ($isWeekly) {
            $reservationDates = create_weekly_dates(
                $date,
                $repeatUntil
            );
        } else {
            $reservationDates = [$date];
        }

        if (count($reservationDates) > 53) {
            $errorMessages[] =
                '毎週予約は最大53回までです。';
        }
    }

    /*
     * 重複確認と登録
     */
    if (empty($errorMessages)) {
        try {
            $db = get_db();

            $db->beginTransaction();

            $conflictStmt = $db->prepare(
                '
                SELECT
                    id,
                    start_at,
                    end_at
                FROM reservations
                WHERE room_id = :room_id
                  AND start_at < :end_at
                  AND end_at > :start_at
                LIMIT 1
                '
            );

            $conflictDates = [];

            foreach (
                $reservationDates
                as $reservationDate
            ) {
                $startAt = $reservationDate
                    . ' '
                    . $startTime
                    . ':00';

                $endAt = $reservationDate
                    . ' '
                    . $endTime
                    . ':00';

                $conflictStmt->execute([
                    ':room_id' => $roomId,
                    ':start_at' => $startAt,
                    ':end_at' => $endAt,
                ]);

                $conflict = $conflictStmt->fetch();

                if ($conflict) {
                    $conflictDates[] =
                        $reservationDate;
                }
            }

            if (!empty($conflictDates)) {
                $db->rollBack();

                foreach (
                    $conflictDates
                    as $conflictDate
                ) {
                    $errorMessages[] =
                        $conflictDate
                        . ' は、指定した時間帯と'
                        . '重なる予約があります。';
                }
            } else {
                /*
                 * 毎週予約の場合だけ共通IDを生成
                 */
                $recurrenceGroupId = null;

                if ($isWeekly) {
                    $recurrenceGroupId =
                        create_recurrence_group_id();
                }

                $insertStmt = $db->prepare(
                    '
                    INSERT INTO reservations (
                        room_id,
                        title,
                        user_name,
                        start_at,
                        end_at,
                        note,
                        recurrence_group_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :room_id,
                        :title,
                        :user_name,
                        :start_at,
                        :end_at,
                        :note,
                        :recurrence_group_id,
                        :created_at,
                        :updated_at
                    )
                    '
                );

                $now = date('Y-m-d H:i:s');

                foreach (
                    $reservationDates
                    as $reservationDate
                ) {
                    $startAt = $reservationDate
                        . ' '
                        . $startTime
                        . ':00';

                    $endAt = $reservationDate
                        . ' '
                        . $endTime
                        . ':00';

                    $insertStmt->bindValue(
                        ':room_id',
                        $roomId,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':title',
                        $title,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':user_name',
                        $userName,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':start_at',
                        $startAt,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':end_at',
                        $endAt,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':note',
                        $note,
                        PDO::PARAM_STR
                    );

                    if ($recurrenceGroupId === null) {
                        $insertStmt->bindValue(
                            ':recurrence_group_id',
                            null,
                            PDO::PARAM_NULL
                        );
                    } else {
                        $insertStmt->bindValue(
                            ':recurrence_group_id',
                            $recurrenceGroupId,
                            PDO::PARAM_STR
                        );
                    }

                    $insertStmt->bindValue(
                        ':created_at',
                        $now,
                        PDO::PARAM_STR
                    );

                    $insertStmt->bindValue(
                        ':updated_at',
                        $now,
                        PDO::PARAM_STR
                    );

                    $insertStmt->execute();
                }

                $db->commit();

                header(
                    'Location: index.php?date='
                    . rawurlencode($date)
                    . '&registered=1'
                );

                exit;
            }
        } catch (Throwable $e) {
            if (
                isset($db)
                && $db instanceof PDO
                && $db->inTransaction()
            ) {
                $db->rollBack();
            }

            $errorMessages[] =
                '予約の登録中にエラーが発生しました。'
                . $e->getMessage();
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
        新しい予約 - <?php echo h(APP_NAME); ?>
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

        .form-box {
            padding: 24px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 9px 10px;
            color: #222222;
            background: #ffffff;
            border: 1px solid #aaaaaa;
            border-radius: 2px;
            font: inherit;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .time-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 12px;
        }

        .required {
            margin-left: 5px;
            color: #b42318;
            font-size: 13px;
        }

        .error-box {
            margin-bottom: 20px;
            padding: 14px 18px;
            color: #8b1a10;
            background: #fff1f0;
            border: 1px solid #e0aaa5;
        }

        .error-box ul {
            margin: 0;
            padding-left: 22px;
        }

        .repeat-box {
            margin-bottom: 20px;
            padding: 16px;
            background: #f7f7f7;
            border: 1px solid #d8d8d8;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            cursor: pointer;
        }

        .checkbox-label input {
            flex: 0 0 auto;
            width: auto;
            margin: 0;
        }

        .repeat-period {
            margin-top: 16px;
        }

        .repeat-help {
            margin: 7px 0 0;
            color: #666666;
            font-size: 13px;
        }

        .button-row {
            display: flex;
            gap: 12px;
            margin-top: 28px;
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

        [hidden] {
            display: none !important;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .form-box {
                padding: 18px;
            }

            .time-row {
                grid-template-columns: 1fr;
            }

            .time-separator {
                display: none;
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

    <h1>新しい予約</h1>

    <?php if (!empty($errorMessages)): ?>

        <div class="error-box">
            <ul>
                <?php foreach ($errorMessages as $message): ?>
                    <li><?php echo h($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <div class="form-box">

        <form method="post" action="reserve.php">

            <div class="form-group">
                <label for="date">
                    日付
                    <span class="required">必須</span>
                </label>

                <input
                    type="date"
                    id="date"
                    name="date"
                    value="<?php echo h($date); ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="room_id">
                    会議室
                    <span class="required">必須</span>
                </label>

                <select
                    id="room_id"
                    name="room_id"
                    required
                >
                    <option value="">
                        選択してください
                    </option>

                    <?php foreach ($rooms as $room): ?>
                        <option
                            value="<?php echo h($room['id']); ?>"
                            <?php
                            if ($roomId === $room['id']) {
                                echo 'selected';
                            }
                            ?>
                        >
                            <?php echo h($room['name']); ?>

                            <?php
                            if ($room['description'] !== ''):
                            ?>
                                （<?php
                                echo h(
                                    $room['description']
                                );
                                ?>）
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>
                    時間
                    <span class="required">必須</span>
                </label>

                <div class="time-row">

                    <select
                        id="start_time"
                        name="start_time"
                        aria-label="開始時刻"
                        required
                    >
                        <?php foreach ($timeOptions as $time): ?>
                            <option
                                value="<?php echo h($time); ?>"
                                <?php
                                if ($startTime === $time) {
                                    echo 'selected';
                                }
                                ?>
                            >
                                <?php echo h($time); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="time-separator">
                        ～
                    </span>

                    <select
                        id="end_time"
                        name="end_time"
                        aria-label="終了時刻"
                        required
                    >
                        <?php foreach ($timeOptions as $time): ?>
                            <option
                                value="<?php echo h($time); ?>"
                                <?php
                                if ($endTime === $time) {
                                    echo 'selected';
                                }
                                ?>
                            >
                                <?php echo h($time); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>
            </div>

            <div class="repeat-box">

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        id="is_weekly"
                        name="is_weekly"
                        value="1"
                        <?php
                        if ($isWeekly) {
                            echo 'checked';
                        }
                        ?>
                    >

                    <span>毎週予約する</span>
                </label>

                <div
                    id="repeat_period"
                    class="repeat-period"
                    <?php
                    if (!$isWeekly) {
                        echo 'hidden';
                    }
                    ?>
                >
                    <label for="repeat_until">
                        繰り返し終了日
                        <span class="required">必須</span>
                    </label>

                    <input
                        type="date"
                        id="repeat_until"
                        name="repeat_until"
                        value="<?php echo h($repeatUntil); ?>"
                        min="<?php echo h($date); ?>"
                    >

                    <p class="repeat-help">
                        開始日を含め、指定した終了日まで
                        7日ごとに予約します。
                    </p>
                </div>

            </div>

            <div class="form-group">
                <label for="title">
                    予定
                    <span class="required">必須</span>
                </label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    value="<?php echo h($title); ?>"
                    maxlength="200"
                    required
                >
            </div>

            <div class="form-group">
                <label for="user_name">
                    予約者
                    <span class="required">必須</span>
                </label>

                <input
                    type="text"
                    id="user_name"
                    name="user_name"
                    value="<?php echo h($userName); ?>"
                    maxlength="100"
                    required
                >
            </div>

            <div class="form-group">
                <label for="note">備考</label>

                <textarea
                    id="note"
                    name="note"
                    maxlength="2000"
                ><?php echo h($note); ?></textarea>
            </div>

            <div class="button-row">

                <button
                    type="submit"
                    class="button button-primary"
                >
                    予約を登録
                </button>

                <a
                    class="button"
                    href="index.php?date=<?php
                    echo rawurlencode($date);
                    ?>"
                >
                    キャンセル
                </a>

            </div>

        </form>

    </div>

</div>

<script>
(function () {
    'use strict';

    const weeklyCheckbox =
        document.getElementById('is_weekly');

    const repeatPeriod =
        document.getElementById('repeat_period');

    const repeatUntil =
        document.getElementById('repeat_until');

    const startDate =
        document.getElementById('date');

    function updateRepeatArea() {
        const enabled = weeklyCheckbox.checked;

        repeatPeriod.hidden = !enabled;
        repeatUntil.required = enabled;
    }

    function updateMinimumDate() {
        repeatUntil.min = startDate.value;

        if (
            repeatUntil.value === ''
            || repeatUntil.value < startDate.value
        ) {
            repeatUntil.value = startDate.value;
        }
    }

    weeklyCheckbox.addEventListener(
        'change',
        updateRepeatArea
    );

    startDate.addEventListener(
        'change',
        updateMinimumDate
    );

    updateRepeatArea();
    updateMinimumDate();
})();
</script>

</body>
</html>
