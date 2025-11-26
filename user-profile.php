<?php
require_once 'db.php';
requireLogin();

$uid = currentUserId();

$sql = "SELECT user_id, username, email, password, registration_date, user_type_id FROM `User` WHERE user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->bind_result($userId, $username, $email, $password, $registrationDate, $userTypeId);
if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    exit('ไม่พบผู้ใช้');
}
$stmt->close();

$messages = [];
$errors = [];
$errorFields = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newUsername = trim($_POST['username'] ?? $username);
    $newEmail    = trim($_POST['email'] ?? $email);
    $newPassword = trim($_POST['password'] ?? $password);

    if ($newUsername === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้';
        $errorFields['username'] = true;
    }
    if ($newEmail === '') {
        $errors[] = 'กรุณากรอกอีเมล';
        $errorFields['email'] = true;
    }
    if ($newPassword === '') {
        $errors[] = 'กรุณากรอกรหัสผ่าน';
        $errorFields['password'] = true;
    }

    if (empty($errors)) {
        $update = $conn->prepare("UPDATE `User` SET username = ?, email = ?, password = ? WHERE user_id = ?");
        $update->bind_param('sssi', $newUsername, $newEmail, $newPassword, $uid);
        $update->execute();
        $update->close();

        $_SESSION['username'] = $newUsername;
        $_SESSION['user_type_id'] = $userTypeId;

        $messages[] = 'อัปเดตโปรไฟล์สำเร็จ';

        // refresh values shown
        $username = $newUsername;
        $email = $newEmail;
        $password = $newPassword;
    }
}

include 'header.php';
?>

<section class="section">
    <h1 class="page-title">โปรไฟล์ของฉัน</h1>

    <div class="profile-card">
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($m); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>

        <form id="profile-form" method="post" novalidate>
            <div class="profile-row">
                <label>ชื่อผู้ใช้</label>
                <div class="profile-field">
                    <input type="text" name="username" id="profile-username"
                           value="<?php echo htmlspecialchars($username); ?>"
                           class="<?php echo isset($errorFields['username']) ? 'input-error' : ''; ?>"
                           disabled>
                    <button type="button" class="btn-edit" data-target="profile-username">Edit</button>
                </div>
            </div>

            <div class="profile-row">
                <label>อีเมล</label>
                <div class="profile-field">
                    <input type="email" name="email" id="profile-email"
                           value="<?php echo htmlspecialchars($email); ?>"
                           class="<?php echo isset($errorFields['email']) ? 'input-error' : ''; ?>"
                           disabled>
                    <button type="button" class="btn-edit" data-target="profile-email">Edit</button>
                </div>
            </div>

            <div class="profile-row">
                <label>รหัสผ่าน</label>
                <div class="profile-field">
                    <input type="text" name="password" id="profile-password"
                           value="<?php echo htmlspecialchars($password); ?>"
                           class="<?php echo isset($errorFields['password']) ? 'input-error' : ''; ?>"
                           disabled>
                    <button type="button" class="btn-edit" data-target="profile-password">Edit</button>
                </div>
            </div>

            <div class="profile-row">
                <label>สถานะบัญชี</label>
                <div class="profile-field">
                    <span class="<?php echo userRoleClass($userTypeId); ?>">
                        <?php
                        echo ($userTypeId === 2) ? 'Admin'
                            : (($userTypeId === 3) ? 'Merchant' : 'Customer');
                        ?>
                    </span>
                </div>
            </div>

            <div class="profile-row">
                <label>วันที่ลงทะเบียน</label>
                <div class="profile-field">
                    <span><?php echo htmlspecialchars($registrationDate); ?></span>
                </div>
            </div>
        </form>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('profile-form');

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.dataset.mode = 'view';
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const input = document.getElementById(targetId);

            if (btn.dataset.mode === 'view') {
                input.disabled = false;
                input.focus();
                btn.textContent = 'Submit';
                btn.classList.add('save');
                btn.dataset.mode = 'save';
            } else {
                input.disabled = false;
                form.submit();
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
