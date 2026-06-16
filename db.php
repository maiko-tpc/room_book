<?php

require_once __DIR__ . '/config.php';

/*
 * SQLiteデータベースファイル
 */
define('DB_PATH', __DIR__ . '/data/reservation.sqlite');

/*
 * データベース接続を返す
 */
function get_db(): PDO
{
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $db = new PDO('sqlite:' . DB_PATH);

    $db->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $db->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    /*
     * 外部キー制約を有効にする
     */
    $db->exec('PRAGMA foreign_keys = ON');

    /*
     * SQLiteのロック待ち時間
     */
    $db->exec('PRAGMA busy_timeout = 5000');

    return $db;
}
