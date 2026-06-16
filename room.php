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

function find_room(
    array $rooms,
    string $roomId
): ?array {
    foreach ($rooms as $room) {
        if ($room['id'] === $roomId) {
            return $room;
        }
    }

    return null;
}

function is_valid_capacity(string $capacity): bool
{
    if ($capacity === '') {
        return true;
    }

    if (!ctype_digit($capacity)) {
        return false;
    }

    $value = (int) $capacity;

    return $value >= 1 && $value <= 10000;
}

/*
 * 会議室ID
 */
$roomId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = trim($_POST['room_id'] ?? '');
} else {
    $roomId = trim($_GET['room_id'] ?? '');
}

$room = find_room(
    $rooms,
    $roomId
);

if ($room === null) {
    http_response_code(404);

    exit('指定された会議室が見つかりません。');
}

/*
 * 編集モード
 *
 * room.php?room_id=...&edit=1
 * の場合だけ編集フォームを表示します。
 */
$isEditMode =
    isset($_GET['edit'])
    && $_GET['edit'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isEditMode = true;
}

$db = get_db();

/*
 * 会議室詳細を取得
 */
$stmt = $db->prepare(
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
    WHERE room_id = :room_id
    '
);

$stmt->execute([
    ':room_id' => $roomId,
]);

$roomDetails = $stmt->fetch();

/*
 * 初期値
 */
$capacity = '';

$hasScreen = false;
$hasProjector = false;
$hasWhiteboard = false;
$hasWebConference = false;

$equipment = '';
$note = '';
$updatedAt = '';

if ($roomDetails) {
    if ($roomDetails['capacity'] !== null) {
        $capacity =
            (string) $roomDetails['capacity'];
    }

    $hasScreen =
        (int) $roomDetails['has_screen'] === 1;

    $hasProjector =
        (int) $roomDetails['has_projector'] === 1;

    $hasWhiteboard =
        (int) $roomDetails['has_whiteboard'] === 1;

    $hasWebConference =
        (int) $roomDetails['has_web_conference'] === 1;

    $equipment =
        (string) $roomDetails['equipment'];

    $note =
        (string) $roomDetails['note'];

    $updatedAt =
        (string) $roomDetails['updated_at'];
}

$errorMessages = [];

/*
 * 保存処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $capacity =
        trim($_POST['capacity'] ?? '');

    $hasScreen =
        isset($_POST['has_screen'])
        && $_POST['has_screen'] === '1';

    $hasProjector =
        isset($_POST['has_projector'])
        && $_POST['has_projector'] === '1';

    $hasWhiteboard =
        isset($_POST['has_whiteboard'])
        && $_POST['has_whiteboard'] === '1';

    $hasWebConference =
        isset($_POST['has_web_conference'])
        && $_POST['has_web_conference'] === '1';

    $equipment =
        trim($_POST['equipment'] ?? '');

    $note =
        trim($_POST['note'] ?? '');

    /*
     * 入力チェック
     */
    if (!is_valid_capacity($capacity)) {
        $errorMessages[] =
            '定員は1以上10000以下の整数で入力してください。';
    }

    if (mb_strlen($equipment) > 2000) {
        $errorMessages[] =
            'その他の設備は2000文字以内で入力してください。';
    }

    if (mb_strlen($note) > 4000) {
        $errorMessages[] =
            '注意事項・補足説明は4000文字以内で入力してください。';
    }

    if (empty($errorMessages)) {
        try {
            $capacityValue = null;

            if ($capacity !== '') {
                $capacityValue =
                    (int) $capacity;
            }

            $now =
                date('Y-m-d H:i:s');

            /*
             * 会議室情報を新規登録または更新
             */
            $stmt = $db->prepare(
                '
                INSERT INTO room_details (
                    room_id,
                    capacity,
                    has_screen,
                    has_projector,
                    has_whiteboard,
                    has_web_conference,
                    equipment,
                    note,
                    updated_at
                ) VALUES (
                    :room_id,
                    :capacity,
                    :has_screen,
                    :has_projector,
                    :has_whiteboard,
                    :has_web_conference,
                    :equipment,
                    :note,
                    :updated_at
                )
                ON CONFLICT(room_id)
                DO UPDATE SET
                    capacity = excluded.capacity,
                    has_screen = excluded.has_screen,
                    has_projector =
                        excluded.has_projector,
                    has_whiteboard =
                        excluded.has_whiteboard,
                    has_web_conference =
                        excluded.has_web_conference,
                    equipment = excluded.equipment,
                    note = excluded.note,
                    updated_at = excluded.updated_at
                '
            );

            $stmt->bindValue(
                ':room_id',
                $roomId,
                PDO::PARAM_STR
            );

            if ($capacityValue === null) {
                $stmt->bindValue(
                    ':capacity',
                    null,
                    PDO::PARAM_NULL
                );
            } else {
                $stmt->bindValue(
                    ':capacity',
                    $capacityValue,
                    PDO::PARAM_INT
                );
            }

            $stmt->bindValue(
                ':has_screen',
                $hasScreen ? 1 : 0,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':has_projector',
                $hasProjector ? 1 : 0,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':has_whiteboard',
                $hasWhiteboard ? 1 : 0,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':has_web_conference',
                $hasWebConference ? 1 : 0,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':equipment',
                $equipment,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ':note',
                $note,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ':updated_at',
                $now,
                PDO::PARAM_STR
            );

            $stmt->execute();

            /*
             * 二重送信を防ぎ、
             * 保存後は詳細表示へ戻る
             */
            header(
                'Location: room.php?room_id='
                . rawurlencode($roomId)
                . '&saved=1'
            );

            exit;
        } catch (Throwable $e) {
            error_log(
                'Room details save error: '
                . $e->getMessage()
            );

            $errorMessages[] =
                '会議室情報の保存中にエラーが発生しました。';
        }
    }
}

/*
 * 設備一覧
 */
$facilityLabels = [];

if ($hasScreen) {
    $facilityLabels[] = 'スクリーン';
}

if ($hasProjector) {
    $facilityLabels[] = 'プロジェクター';
}

if ($hasWhiteboard) {
    $facilityLabels[] = 'ホワイトボード';
}

if ($hasWebConference) {
    $facilityLabels[] = 'Web会議設備';
}

$saved =
    isset($_GET['saved'])
    && $_GET['saved'] === '1';

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
        <?php echo h($room['name']); ?>
        の詳細 - <?php echo h(APP_NAME); ?>
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
            max-width: 760px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-title {
            min-width: 0;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 600;
        }

        .room-description {
            margin: 0;
            color: #666666;
        }

        .success-box {
            margin-bottom: 20px;
            padding: 14px 18px;
            color: #176b32;
            background: #edf8f0;
            border: 1px solid #9fcbaa;
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

        .details-box,
        .form-box {
            margin-bottom: 20px;
            padding: 22px;
            background: #ffffff;
            border: 1px solid #d8d8d8;
        }

        .details-box h2,
        .form-box h2 {
            margin: 0 0 18px;
            font-size: 20px;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table th,
        .details-table td {
            padding: 12px;
            border-bottom: 1px solid #dddddd;
            text-align: left;
            vertical-align: top;
        }

        .details-table th {
            width: 170px;
            background: #f7f7f7;
        }

        .details-table tr:last-child th,
        .details-table tr:last-child td {
            border-bottom: none;
        }

        .pre-wrap {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .empty-value {
            color: #888888;
        }

        .form-group {
            margin-bottom: 22px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input[type="number"],
        textarea {
            width: 100%;
            padding: 10px 11px;
            color: #222222;
            background: #ffffff;
            border: 1px solid #aaaaaa;
            border-radius: 2px;
            font: inherit;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .checkbox-list {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            padding: 12px 14px;
            font-weight: 400;
            background: #f7f7f7;
            border: 1px solid #d8d8d8;
            cursor: pointer;
        }

        .checkbox-label input {
            flex: 0 0 auto;
            width: auto;
            margin: 0;
        }

        .help-text {
            margin: 7px 0 0;
            color: #666666;
            font-size: 13px;
        }

        .updated-at {
            margin: 18px 0 0;
            color: #777777;
            font-size: 13px;
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

        .button-edit {
            flex: 0 0 auto;
            color: #ffffff;
            background: #333333;
            border-color: #333333;
        }

        .button:hover {
            opacity: 0.85;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .page-header {
                align-items: stretch;
                flex-direction: column;
            }

            .details-box,
            .form-box {
                padding: 18px;
            }

            .details-table th {
                width: 110px;
            }

            .checkbox-list {
                grid-template-columns: 1fr;
            }

            .button-row {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">

        <div class="page-title">

            <h1>
                <?php echo h($room['name']); ?>
            </h1>

            <?php if ($room['description'] !== ''): ?>
                <p class="room-description">
                    <?php echo h($room['description']); ?>
                </p>
            <?php endif; ?>

        </div>

        <?php if (!$isEditMode): ?>

            <a
                class="button button-edit"
                href="room.php?room_id=<?php
                echo rawurlencode($roomId);
                ?>&amp;edit=1"
            >
                編集
            </a>

        <?php endif; ?>

    </div>

    <?php if ($saved): ?>

        <div class="success-box">
            会議室情報を保存しました。
        </div>

    <?php endif; ?>

    <?php if (!empty($errorMessages)): ?>

        <div class="error-box">
            <ul>
                <?php foreach ($errorMessages as $message): ?>
                    <li>
                        <?php echo h($message); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <?php if (!$isEditMode): ?>

        <section class="details-box">

            <h2>会議室情報</h2>

            <table class="details-table">
                <tbody>

                <tr>
                    <th>定員</th>
                    <td>
                        <?php if ($capacity !== ''): ?>

                            <?php echo (int) $capacity; ?>名

                        <?php else: ?>

                            <span class="empty-value">
                                未登録
                            </span>

                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>設備</th>
                    <td>
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

                            <span class="empty-value">
                                未登録
                            </span>

                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>その他の設備</th>
                    <td class="pre-wrap">
                        <?php if ($equipment !== ''): ?>

                            <?php echo h($equipment); ?>

                        <?php else: ?>

                            <span class="empty-value">
                                未登録
                            </span>

                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>注意事項</th>
                    <td class="pre-wrap">
                        <?php if ($note !== ''): ?>

                            <?php echo h($note); ?>

                        <?php else: ?>

                            <span class="empty-value">
                                未登録
                            </span>

                        <?php endif; ?>
                    </td>
                </tr>

                </tbody>
            </table>

            <?php if ($updatedAt !== ''): ?>

                <p class="updated-at">
                    最終更新：
                    <?php echo h($updatedAt); ?>
                </p>

            <?php endif; ?>

            <div class="button-row">

                <a
                    class="button"
                    href="index.php"
                >
                    予約一覧へ戻る
                </a>

                <a
                    class="button button-primary"
                    href="reserve.php?date=<?php
                    echo rawurlencode(date('Y-m-d'));
                    ?>&amp;room_id=<?php
                    echo rawurlencode($roomId);
                    ?>"
                >
                    この部屋を予約
                </a>

            </div>

        </section>

    <?php else: ?>

        <section class="form-box">

            <h2>会議室情報を編集</h2>

            <form
                method="post"
                action="room.php"
            >
                <input
                    type="hidden"
                    name="room_id"
                    value="<?php echo h($roomId); ?>"
                >

                <div class="form-group">

                    <label for="capacity">
                        定員
                    </label>

                    <input
                        type="number"
                        id="capacity"
                        name="capacity"
                        value="<?php echo h($capacity); ?>"
                        min="1"
                        max="10000"
                        step="1"
                        inputmode="numeric"
                    >

                    <p class="help-text">
                        不明な場合は空欄にできます。
                    </p>

                </div>

                <div class="form-group">

                    <label>設備</label>

                    <div class="checkbox-list">

                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                name="has_screen"
                                value="1"
                                <?php
                                if ($hasScreen) {
                                    echo 'checked';
                                }
                                ?>
                            >

                            <span>スクリーン</span>
                        </label>

                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                name="has_projector"
                                value="1"
                                <?php
                                if ($hasProjector) {
                                    echo 'checked';
                                }
                                ?>
                            >

                            <span>プロジェクター</span>
                        </label>

                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                name="has_whiteboard"
                                value="1"
                                <?php
                                if ($hasWhiteboard) {
                                    echo 'checked';
                                }
                                ?>
                            >

                            <span>ホワイトボード</span>
                        </label>

                        <label class="checkbox-label">
                            <input
                                type="checkbox"
                                name="has_web_conference"
                                value="1"
                                <?php
                                if ($hasWebConference) {
                                    echo 'checked';
                                }
                                ?>
                            >

                            <span>Web会議設備</span>
                        </label>

                    </div>

                </div>

                <div class="form-group">

                    <label for="equipment">
                        その他の設備
                    </label>

                    <textarea
                        id="equipment"
                        name="equipment"
                        maxlength="2000"
                        placeholder="例：大型モニター、HDMIケーブル、演台、マイク"
                    ><?php echo h($equipment); ?></textarea>

                </div>

                <div class="form-group">

                    <label for="note">
                        注意事項・補足説明
                    </label>

                    <textarea
                        id="note"
                        name="note"
                        maxlength="4000"
                        placeholder="例：利用後は机と椅子を元の位置に戻してください。"
                    ><?php echo h($note); ?></textarea>

                </div>

                <div class="button-row">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        保存
                    </button>

                    <a
                        class="button"
                        href="room.php?room_id=<?php
                        echo rawurlencode($roomId);
                        ?>"
                    >
                        キャンセル
                    </a>

                </div>

            </form>

        </section>

    <?php endif; ?>

</div>
</body>
</html>
