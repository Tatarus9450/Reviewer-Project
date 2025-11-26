<?php
require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ReviewHub – Product Review</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<header class="topbar">
    <div class="container">
        <div class="logo">ReviewHub</div>
        <nav class="nav">
            <a href="index.php">หน้าแรก</a>
            <?php if (currentUserId()): ?>
                <?php $roleClass = userRoleClass($_SESSION['user_type_id'] ?? 1); ?>
                <a href="user-profile.php" class="nav-user <?php echo $roleClass; ?>" style="text-decoration:none;">
                    👤 <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
                <a href="logout.php">ออกจากระบบ</a>
            <?php else: ?>
                <a href="login.php">เข้าสู่ระบบ</a>
                <a href="register.php" class="btn-primary">สมัครสมาชิก</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container main-content">
