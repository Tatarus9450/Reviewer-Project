<?php
require_once 'db.php';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $errors[] = 'กรุณากรอกข้อมูลให้ครบ';
    }
    if ($password !== $confirm) {
        $errors[] = 'รหัสผ่านไม่ตรงกัน';
    }

    if (!$errors) {
        // ตรวจว่าซ้ำไหม
        $stmt = $conn->prepare("SELECT user_id FROM `User` WHERE username = ? OR email = ?");
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = 'มี username หรือ email นี้แล้ว';
        } else {
            // งานเดโม: เก็บรหัสผ่านแบบ plain text (ของจริงไม่ควรทำ)
            $userTypeId = 1;
            $stmt = $conn->prepare("
                INSERT INTO `User` (user_type_id, username, email, password)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param('isss', $userTypeId, $username, $email, $password);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $errors[] = 'บันทึกข้อมูลไม่สำเร็จ: ' . $conn->error;
            }
        }
        $stmt->close();
    }
}

include 'header.php';
?>

<section class="section">
    <h1 class="page-title">สมัครสมาชิก</h1>

    <div class="form-card">
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>

        <form method="post">
            <div class="form-group">
                <label>Username</label>
                <input name="username" type="text"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input name="email" type="email"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input name="password" type="password">
            </div>
            <div class="form-group">
                <label>ยืนยันรหัสผ่าน</label>
                <input name="confirm" type="password">
            </div>
            <button class="btn-primary" type="submit">สมัครสมาชิก</button>
        </form>
    </div>
</section>

<?php if ($success): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
        icon: 'success',
        title: 'สมัครสมาชิกสำเร็จ',
        text: 'กำลังพาไปหน้าเข้าสู่ระบบ',
        timer: 800,
        showConfirmButton: false
    }).then(() => {
        window.location.href = 'login.php';
    });
});
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
