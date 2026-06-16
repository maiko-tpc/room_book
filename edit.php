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

/*
 * YYYY-MM-DD の差を日数で返す
 */
function calculate_date_difference(
    string $originalDate,
    string $newDate
): int {
    $original = new DateTime($originalDate);
    $new = new DateTime($newDate);

    $seconds = $new->getTimestamp()
        - $original->getTimestamp();

    return (int) round($seconds / 86400);
}

/*
 * 日付を指定日数移動する
 */
function shift_date(
    string $date,
    int $differenceDays
): string {
    $dateObject = new DateTime($date);

    if ($differenceDays !== 0) {
        $dateObject->modify(
            ($differenceDays > 0 ? '+' : '')
            . $differenceDays
            . ' days'
        );
    }

    return $dateObject->format('Y-m-d');
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
 * 編集対象の予約を取得
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

/*
 * 元の値
 */
$originalStartAt = $reservation['start_at'];

$originalDate = date(
    'Y-m-d',
    strtotime($originalStartAt)
);

$recurrenceGroupId =
    $reservation['recurrence_group_id'];

$isRecurring =
    $recurrenceGroupId !== null
    && $recurrenceGroupId !== '';

/*
 * この回以降の件数
 */
$futureReservationCount = 0;

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
            $originalStartAt,
    ]);

    $futureReservationCount =
        (int) $stmt->fetchColumn();
}

/*
 * フォーム初期値
 */
$date = $originalDate;
$roomId = $reservation['room_id'];
$title = $reservation['title'];
$userName = $reservation['user_name'];

$startTime = date(
    'H:i',
    strtotime($reservation['start_at'])
);

$endTime = date(
    'H:i',
    strtotime($reservation['end_at'])
);

$note = $reservation['note'];
$editScope = 'single';

$errorMessages = [];

$timeOptions = create_time_options(
    DISPLAY_START_TIME,
    DISPLAY_END_TIME,
    TIME_INTERVAL_MINUTES
);

/*
 * 更新処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date'] ?? '');
    $roomId = trim($_POST['room_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $editScope = trim(
        $_POST['edit_scope'] ?? 'single'
    );

    /*
     * 編集範囲の確認
     */
    if (
        $editScope !== 'single'
        && $editScope !== 'future'
    ) {
        $errorMessages[] =
            '編集範囲が正しくありません。';
    }

    if (
        $editScope === 'future'
        && !$isRecurring
    ) {
        $errorMessages[] =
            'この予約は毎週予約ではありません。';
    }

    /*
     * 入力確認
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
     * 対象予約の取得、重複確認、更新
     */
    if (empty($errorMessages)) {
        try {
            $db->beginTransaction();

            /*
             * 更新対象を取得
             */
            if (
                $editScope === 'future'
                && $isRecurring
            ) {
                $stmt = $db->prepare(
                    '
                    SELECT
                        id,
                        start_at,
                        end_at
                    FROM reservations
                    WHERE recurrence_group_id
                        = :recurrence_group_id
                      AND start_at >= :start_at
                    ORDER BY start_at
                    '
                );

                $stmt->execute([
                    ':recurrence_group_id' =>
                        $recurrenceGroupId,
                    ':start_at' =>
                        $originalStartAt,
                ]);

                $targetReservations =
                    $stmt->fetchAll();
            } else {
                $targetReservations = [
                    [
                        'id' => $reservation['id'],
                        'start_at' =>
                            $reservation['start_at'],
                        'end_at' =>
                            $reservation['end_at'],
                    ],
                ];
            }

            if (empty($targetReservations)) {
                throw new RuntimeException(
                    '更新対象の予約が見つかりません。'
                );
            }

            /*
             * 対象予約IDの一覧
             */
            $targetIds = [];

            foreach (
                $targetReservations
                as $targetReservation
            ) {
                $targetIds[] =
                    (int) $targetReservation['id'];
            }

            /*
             * 日付を変更した場合の移動日数
             */
            $differenceDays =
                calculate_date_difference(
                    $originalDate,
                    $date
                );

            /*
             * 更新後の日時を予約ごとに作成
             */
            $updatedReservations = [];

            foreach (
                $targetReservations
                as $targetReservation
            ) {
                $targetOriginalDate = date(
                    'Y-m-d',
                    strtotime(
                        $targetReservation['start_at']
                    )
                );

                if ($editScope === 'future') {
                    $targetNewDate = shift_date(
                        $targetOriginalDate,
                        $differenceDays
                    );
                } else {
                    $targetNewDate = $date;
                }

                $updatedReservations[] = [
                    'id' =>
                        (int) $targetReservation['id'],

                    'start_at' =>
                        $targetNewDate
                        . ' '
                        . $startTime
                        . ':00',

                    'end_at' =>
                        $targetNewDate
                        . ' '
                        . $endTime
                        . ':00',
                ];
            }

            /*
             * 更新対象自身を重複検索から除外する
             */
            $excludePlaceholders = [];

            foreach (
                $targetIds
                as $index => $targetId
            ) {
                $excludePlaceholders[] =
                    ':exclude_id_' . $index;
            }

            $conflictSql =
                '
                SELECT
                    id,
                    start_at,
                    end_at
                FROM reservations
                WHERE room_id = :room_id
                  AND start_at < :end_at
                  AND end_at > :start_at
                  AND id NOT IN (
                    '
                    . implode(
                        ', ',
                        $excludePlaceholders
                    )
                    . '
                  )
                LIMIT 1
                ';

            $conflictStmt = $db->prepare(
                $conflictSql
            );

            $conflictDates = [];

            foreach (
                $updatedReservations
                as $updatedReservation
            ) {
                $conflictStmt->bindValue(
                    ':room_id',
                    $roomId,
                    PDO::PARAM_STR
                );

                $conflictStmt->bindValue(
                    ':start_at',
                    $updatedReservation['start_at'],
                    PDO::PARAM_STR
                );

                $conflictStmt->bindValue(
                    ':end_at',
                    $updatedReservation['end_at'],
                    PDO::PARAM_STR
                );

                foreach (
                    $targetIds
                    as $index => $targetId
                ) {
                    $conflictStmt->bindValue(
                        ':exclude_id_' . $index,
                        $targetId,
                        PDO::PARAM_INT
                    );
                }

                $conflictStmt->execute();

                $conflict =
                    $conflictStmt->fetch();

                if ($conflict) {
                    $conflictDates[] = date(
                        'Y-m-d',
                        strtotime(
                            $updatedReservation[
                                'start_at'
                            ]
                        )
                    );
                }
            }

            if (!empty($conflictDates)) {
                $db->rollBack();

                $conflictDates = array_unique(
                    $conflictDates
                );

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
                 * 更新実行
                 */
                $updateStmt = $db->prepare(
                    '
                    UPDATE reservations
                    SET
                        room_id = :room_id,
                        title = :title,
                        user_name = :user_name,
                        start_at = :start_at,
                        end_at = :end_at,
                        note = :note,
                        updated_at = :updated_at
                    WHERE id = :id
                    '
                );

                $now = date('Y-m-d H:i:s');

                foreach (
                    $updatedReservations
                    as $updatedReservation
                ) {
                    $updateStmt->execute([
                        ':room_id' => $roomId,
                        ':title' => $title,
                        ':user_name' => $userName,
                        ':start_at' =>
                            $updatedReservation[
                                'start_at'
                            ],
                        ':end_at' =>
                            $updatedReservation[
                                'end_at'
                            ],
                        ':note' => $note,
                        ':updated_at' => $now,
                        ':id' =>
                            $updatedReservation['id'],
                    ]);
                }

                $db->commit();

                $redirectDate = date(
                    'Y-m-d',
                    strtotime(
                        $updatedReservations[0][
                            'start_at'
                        ]
                    )
                );

                header(
                    'Location: index.php?date='
                    . rawurlencode($redirectDate)
                    . '&updated=1'
                    . '&edit_scope='
                    . rawurlencode($editScope)
                    . '&updated_count='
                    . rawurlencode(
                        (string) count(
                            $updatedReservations
                        )
                    )
                );

                exit;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $errorMessages[] =
                '予約の更新中にエラーが発生しました。'
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
        予約の編集 - <?php echo h(APP_NAME); ?>
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

        .recurring-box {
            margin-bottom: 20px;
            padding: 16px;
            background: #fff9df;
            border: 1px solid #d8c46c;
        }

        .recurring-box p {
            margin: 0 0 12px;
        }

        .recurring-box p:last-child {
            margin-bottom: 0;
        }

        .scope-option {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 12px;
            cursor: pointer;
            font-weight: 400;
        }

        .scope-option:last-child {
            margin-bottom: 0;
        }

        .scope-option input {
            flex: 0 0 auto;
            width: auto;
            margin: 3px 0 0;
        }

        .scope-title {
            display: block;
            font-weight: 600;
        }

        .scope-description {
            display: block;
            margin-top: 3px;
            color: #666666;
            font-size: 13px;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
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

        .button-danger {
            color: #a61b1b;
            background: #ffffff;
            border-color: #a61b1b;
        }

        .button:hover {
            opacity: 0.85;
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

    <h1>予約の編集</h1>

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

        <form method="post" action="edit.php">

            <input
                type="hidden"
                name="id"
                value="<?php echo (int) $id; ?>"
            >

            <?php if ($isRecurring): ?>

                <div class="recurring-box">

                    <p>
                        この予約は毎週予約の一部です。
                        この回を含め、以降に
                        <?php
                        echo (int) $futureReservationCount;
                        ?>件あります。
                    </p>

                    <label class="scope-option">
                        <input
                            type="radio"
                            name="edit_scope"
                            value="single"
                            <?php
                            if ($editScope === 'single') {
                                echo 'checked';
                            }
                            ?>
                        >

                        <span>
                            <span class="scope-title">
                                この予約だけ変更
                            </span>

                            <span class="scope-description">
                                この日の予約だけを変更し、
                                翌週以降は変更しません。
                            </span>
                        </span>
                    </label>

                    <?php if ($futureReservationCount > 1): ?>

                        <label class="scope-option">
                            <input
                                type="radio"
                                name="edit_scope"
                                value="future"
                                <?php
                                if ($editScope === 'future') {
                                    echo 'checked';
                                }
                                ?>
                            >

                            <span>
                                <span class="scope-title">
                                    この予約と、それ以降を変更
                                </span>

                                <span class="scope-description">
                                    この回を含む
                                    <?php
                                    echo (int) $futureReservationCount;
                                    ?>件をまとめて変更します。
                                    日付を変えると、後続予約も
                                    同じ日数だけ移動します。
                                </span>
                            </span>
                        </label>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <input
                    type="hidden"
                    name="edit_scope"
                    value="single"
                >

            <?php endif; ?>

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

                            <?php if ($room['description'] !== ''): ?>
                                （<?php
                                echo h($room['description']);
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
                    変更を保存
                </button>

                <a
                    class="button"
                    href="index.php?date=<?php
                    echo rawurlencode($originalDate);
                    ?>"
                >
                    キャンセル
                </a>

                <a
                    class="button button-danger"
                    href="delete.php?id=<?php
                    echo (int) $id;
                    ?>"
                >
                    予約を削除
                </a>

            </div>

        </form>

    </div>

</div>
</body>
</html>
