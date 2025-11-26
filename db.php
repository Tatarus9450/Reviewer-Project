<?php
// db.php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();
date_default_timezone_set('Asia/Bangkok');

$DB_HOST = 'fdb1032.awardspace.net';
$DB_USER = '4708671_home';
$DB_PASS = 'litalita2546';
$DB_NAME = '4708671_home';

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+07:00'");
} catch (mysqli_sql_exception $e) {
    die('Database connection failed.');
}

function currentUserId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function requireLogin() {
    if (!currentUserId()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * คืนชื่อ class สำหรับแยกสีตาม user_type_id
 * 1 = Customer (ปกติ), 2 = Admin (แดง), 3 = Merchant (ทอง)
 */
function userRoleClass($typeId) {
    $typeId = (int)$typeId;
    if ($typeId === 2) {
        return 'user-role-admin';
    }
    if ($typeId === 3) {
        return 'user-role-merchant';
    }
    return 'user-role-customer';
}
