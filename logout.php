<?php
// logout.php

require_once 'db.php';

// ล้างข้อมูล session
$_SESSION = [];
session_destroy();

// กลับหน้าแรก
header('Location: index.php');
exit;
