<?php
// จัดการ login ก่อน ยังไม่ include header.php
require_once 'db.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['username'] ?? ''); // ใส่ username หรือ email ก็ได้
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
                $_SESSION['user_id'] = $uid;
                $_SESSION['username'] = $uname;
                $_SESSION['user_type_id'] = $utype;

                // header('Location: index.php');
                // exit;
                $success = true;
            } else {
                $errors[] = 'รหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $errors[] = 'ไม่พบผู้ใช้คนนี้';
        }

        $stmt->close();
    }
    if (isset($_POST['ajax_login'])) {
        header('Content-Type: application/json');
        if (empty($errors)) {
            echo json_encode(['success' => true, 'message' => 'เข้าสู่ระบบสำเร็จ']);
        } else {
            echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        }
        exit;
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
                <input name="username" type="text" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input name="password" type="password">
            </div>
            <button class="btn-primary" type="submit">เข้าสู่ระบบ</button>
        </form>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('form');
        if (!form || typeof Swal === 'undefined') return;

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const formData = new FormData(form);
            formData.append('ajax_login', '1');

            Swal.fire({
                title: 'กำลังเข้าสู่ระบบ...',
                text: 'กรุณารอสักครู่',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('login.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        new Audio('assets/notification_sound.mp3').play()
                            .then(() => console.log('Audio playing'))
                            .catch(e => console.log('Audio play failed:', e));

                        Swal.fire({
                            icon: 'success',
                            title: 'เข้าสู่ระบบสำเร็จ',
                            text: 'กำลังพาไปหน้าแรก',
                            timer: 1520,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'index.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: data.message || 'เข้าสู่ระบบไม่สำเร็จ'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้'
                    });
                });
        });
    });
</script>

<?php include 'footer.php'; ?>