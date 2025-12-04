<?php
require_once 'db.php';

$errors = [];
$success = false;
$roleKey = 'customer';
$roleOptions = [
    'customer' => ['id' => 1, 'label' => 'ลูกค้า'],
    'merchant' => ['id' => 3, 'label' => 'ร้านค้า'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $roleKey = $_POST['account_role'] ?? 'customer';

    if ($username === '' || $email === '' || $password === '' || $roleKey === '') {
        $errors[] = 'กรุณากรอกข้อมูลให้ครบ';
    }
    if ($password !== $confirm) {
        $errors[] = 'รหัสผ่านไม่ตรงกัน';
    }
    if (!array_key_exists($roleKey, $roleOptions)) {
        $errors[] = 'กรุณาเลือกสถานะบัญชี';
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
            $userTypeId = $roleOptions[$roleKey]['id'];
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
    if (isset($_POST['ajax_register'])) {
        header('Content-Type: application/json');
        if (empty($errors)) {
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'สมัครสมาชิกสำเร็จ']);
            } else {
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        }
        exit;
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
                <input name="username" type="text" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input name="password" type="password">
            </div>
            <div class="form-group">
                <label>ยืนยันรหัสผ่าน</label>
                <input name="confirm" type="password">
            </div>
            <div class="form-group">
                <label>เลือกสถานะบัญชี</label>
                <select name="account_role">
                    <?php foreach ($roleOptions as $key => $opt): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (($roleKey ?? 'customer') === $key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($opt['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-primary" type="submit">สมัครสมาชิก</button>
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
            formData.append('ajax_register', '1');

            Swal.fire({
                title: 'กำลังสมัครสมาชิก...',
                text: 'กรุณารอสักครู่',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('register.php', {
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
                            title: 'สมัครสมาชิกสำเร็จ',
                            text: 'กำลังพาไปหน้าเข้าสู่ระบบ',
                            timer: 1520,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'login.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: data.message || 'สมัครสมาชิกไม่สำเร็จ'
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