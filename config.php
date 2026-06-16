<?php

/*
 * 会議室予約システム設定
 */

/*
 * システム名
 */
define('APP_NAME', '会議室予約');

/*
 * タイムゾーン
 */
date_default_timezone_set('Asia/Tokyo');

/*
 * 会議室一覧
 *
 * id:
 *   システム内部で使用する識別子です。
 *   一度予約データを登録した後は変更しないでください。
 *
 * name:
 *   画面に表示する会議室名です。
 *
 * description:
 *   会議室についての補足説明です。
 *
 * 会議室の色は、index.php 側で
 * 登録順に自動的に割り当てます。
 */
$rooms = [
    [
        'id' => 'meeting_room_1',
        'name' => '会議室1',
        'description' => '本館2階',
    ],
    [
        'id' => 'meeting_room_2',
        'name' => '会議室2',
        'description' => '本館3階',
    ],
    [
        'id' => 'seminar_room',
        'name' => 'セミナー室',
        'description' => '研究棟1階',
    ],
];

/*
 * 予約表に表示する時刻範囲
 */
define('DISPLAY_START_TIME', '08:00');
define('DISPLAY_END_TIME', '21:00');

/*
 * 予約時間の入力単位（分）
 */
define('TIME_INTERVAL_MINUTES', 15);
