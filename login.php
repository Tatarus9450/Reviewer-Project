<?php
// จัดการ login ก่อน ยังไม่ include header.php
require_once 'db.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['username'] ?? ''); // ใส่ username หรือ email ก็ได้
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $sql = "SELECT user_id, username, email, password, user_type_id
                FROM `User`
                WHERE username = ? OR email = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $stmt->bind_result($uid, $uname, $uemail, $dbPassword, $utype);

        if ($stmt->fetch()) {
            if ($password === trim($dbPassword)) {   // compare ตรง ๆ
                $_SESSION['user_id']      = $uid;
                $_SESSION['username']     = $uname;
                $_SESSION['user_type_id'] = $utype;

                header('Location: index.php');
                exit;
            } else {
                $errors[] = 'รหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $errors[] = 'ไม่พบผู้ใช้คนนี้';
        }

        $stmt->close();
    }
}

// ถึงตรงนี้ไม่มี redirect → ค่อยแสดงหน้า HTML
include 'header.php';
?>

<section class="section">
    <h1 class="page-title">เข้าสู่ระบบ</h1>

    <div class="form-card">
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>

        <form method="post">
            <div class="form-group">
                <label>Username หรือ Email</label>
                <input name="username" type="text"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input name="password" type="password">
            </div>
            <button class="btn-primary" type="submit">เข้าสู่ระบบ</button>
        </form>
    </div>
</section>

<?php include 'footer.php'; ?>
