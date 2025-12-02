<?php
require_once 'db.php';
requireLogin();

$uid = currentUserId();
$tabParam = $_GET['tab'] ?? 'profile';
$allowedTabs = ['profile', 'reviews', 'comments'];
$activeTab = in_array($tabParam, $allowedTabs, true) ? $tabParam : 'profile';
$msgParam = $_GET['msg'] ?? '';

function redirectTab($tab, $msg = null)
{
    $url = 'user-profile.php?tab=' . urlencode($tab);
    if ($msg !== null) {
        $url .= '&msg=' . urlencode($msg);
    }
    header("Location: {$url}");
    exit;
}

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
    if (isset($_POST['delete_review_id'])) {
        $rid = (int) $_POST['delete_review_id'];
        $chk = $conn->prepare("SELECT review_id FROM Review WHERE review_id = ? AND user_id = ?");
        $chk->bind_param('ii', $rid, $uid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $conn->begin_transaction();
            $delC = $conn->prepare("DELETE FROM Comment WHERE review_id = ?");
            $delC->bind_param('i', $rid);
            $delC->execute();
            $delC->close();

            $delR = $conn->prepare("DELETE FROM Review WHERE review_id = ? AND user_id = ?");
            $delR->bind_param('ii', $rid, $uid);
            $delR->execute();
            $delR->close();

            $conn->commit();
            redirectTab('reviews', 'deleted_review');
        }
        $chk->close();
        redirectTab('reviews');
    }

    if (isset($_POST['delete_comment_id'])) {
        $cid = (int) $_POST['delete_comment_id'];
        $del = $conn->prepare("DELETE FROM Comment WHERE comment_id = ? AND user_id = ?");
        $del->bind_param('ii', $cid, $uid);
        $del->execute();
        $del->close();
        redirectTab('comments', 'deleted_comment');
    }

    if (isset($_POST['delete_comment_group_id'])) {
        $rid = (int) $_POST['delete_comment_group_id'];
        $del = $conn->prepare("DELETE FROM Comment WHERE review_id = ? AND user_id = ?");
        $del->bind_param('ii', $rid, $uid);
        $del->execute();
        $del->close();
        redirectTab('comments', 'deleted_comment');
    }

    $newUsername = trim($_POST['username'] ?? $username);
    $newEmail = trim($_POST['email'] ?? $email);
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

if ($msgParam === 'deleted_review') {
    $messages[] = 'ลบรีวิวเรียบร้อยแล้ว';
} elseif ($msgParam === 'deleted_comment') {
    $messages[] = 'ลบคอมเมนต์เรียบร้อยแล้ว';
}

// ดึงรีวิวทั้งหมดของผู้ใช้
$userReviews = [];
$stmtRevList = $conn->prepare("
    SELECT r.review_id, r.rating, r.review_text, r.review_date,
           p.product_name, s.store_name
    FROM Review r
    JOIN Product p ON r.product_id = p.product_id
    JOIN Store s ON p.store_id = s.store_id
    WHERE r.user_id = ?
    ORDER BY r.review_date DESC
");
$stmtRevList->bind_param('i', $uid);
$stmtRevList->execute();
$resRevList = $stmtRevList->get_result();
while ($row = $resRevList->fetch_assoc()) {
    $userReviews[] = $row;
}
$stmtRevList->close();

// ดึงคอมเมนต์ทั้งหมดของผู้ใช้ พร้อมบริบทรีวิว/ร้าน/สินค้า
$commentsGrouped = [];
$stmtComments = $conn->prepare("
    SELECT c.comment_id, c.comment_text, c.comment_date,
           r.review_id, r.review_text, r.rating,
           ru.username AS reviewer_name,
           p.product_name, s.store_name
    FROM Comment c
    JOIN Review r ON c.review_id = r.review_id
    JOIN `User` ru ON r.user_id = ru.user_id
    JOIN Product p ON r.product_id = p.product_id
    JOIN Store s ON p.store_id = s.store_id
    WHERE c.user_id = ?
    ORDER BY r.review_id, c.comment_date DESC
");
$stmtComments->bind_param('i', $uid);
$stmtComments->execute();
$resComments = $stmtComments->get_result();
while ($row = $resComments->fetch_assoc()) {
    $rid = (int) $row['review_id'];
    if (!isset($commentsGrouped[$rid])) {
        $commentsGrouped[$rid] = [
            'review_id' => $rid,
            'review_text' => $row['review_text'],
            'rating' => $row['rating'],
            'reviewer_name' => $row['reviewer_name'],
            'product_name' => $row['product_name'],
            'store_name' => $row['store_name'],
            'comments' => []
        ];
    }
    $commentsGrouped[$rid]['comments'][] = [
        'comment_id' => $row['comment_id'],
        'comment_text' => $row['comment_text'],
        'comment_date' => $row['comment_date']
    ];
}
$stmtComments->close();

include 'header.php';
?>

<style>
    .tab-row {
        display: flex;
        gap: 0.5rem;
        margin: 0.9rem 0 0.4rem;
    }

    .tab-btn {
        padding: 0.45rem 0.9rem;
        border-radius: 0.85rem;
        border: 1px solid #1f2937;
        background: #0b1222;
        color: #f9fafb;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
    }

    .tab-btn.active {
        background: #1f2937;
        border-color: #3b82f6;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .danger-btn {
        background: #b91c1c;
        color: #f9fafb;
        border: none;
        border-radius: 8px;
        padding: 0.25rem 0.6rem;
        cursor: pointer;
    }
</style>

<section class="section">
    <h1 class="page-title">โปรไฟล์ของฉัน</h1>

    <div class="tab-row">
        <button type="button" class="tab-btn <?php echo $activeTab === 'profile' ? 'active' : ''; ?>"
            data-tab-target="profile">โปรไฟล์</button>
        <button type="button" class="tab-btn <?php echo $activeTab === 'reviews' ? 'active' : ''; ?>"
            data-tab-target="reviews">รีวิว</button>
        <button type="button" class="tab-btn <?php echo $activeTab === 'comments' ? 'active' : ''; ?>"
            data-tab-target="comments">คอมเมนต์</button>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>

    <div id="tab-profile" class="tab-content <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
        <div class="profile-card">
            <?php foreach ($errors as $e): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>

            <form id="profile-form" method="post" novalidate>
                <div class="profile-row">
                    <label>ชื่อผู้ใช้</label>
                    <div class="profile-field">
                        <input type="text" name="username" id="profile-username"
                            value="<?php echo htmlspecialchars($username); ?>"
                            class="<?php echo isset($errorFields['username']) ? 'input-error' : ''; ?>" disabled>
                        <button type="button" class="btn-edit" data-target="profile-username">Edit</button>
                    </div>
                </div>

                <div class="profile-row">
                    <label>อีเมล</label>
                    <div class="profile-field">
                        <input type="email" name="email" id="profile-email"
                            value="<?php echo htmlspecialchars($email); ?>"
                            class="<?php echo isset($errorFields['email']) ? 'input-error' : ''; ?>" disabled>
                        <button type="button" class="btn-edit" data-target="profile-email">Edit</button>
                    </div>
                </div>

                <div class="profile-row">
                    <label>รหัสผ่าน</label>
                    <div class="profile-field">
                        <input type="text" name="password" id="profile-password"
                            value="<?php echo htmlspecialchars($password); ?>"
                            class="<?php echo isset($errorFields['password']) ? 'input-error' : ''; ?>" disabled>
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
    </div>

    <div id="tab-reviews" class="tab-content <?php echo $activeTab === 'reviews' ? 'active' : ''; ?>">
        <?php if (empty($userReviews)): ?>
            <p style="opacity:0.85;">ยังไม่มีรีวิว</p>
        <?php else: ?>
            <?php foreach ($userReviews as $r): ?>
                <div class="card" style="margin-bottom:0.9rem;">
                    <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:center;">
                        <span style="font-weight:600;"><?php echo htmlspecialchars($r['store_name']); ?></span>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="delete_review_id" value="<?php echo (int) $r['review_id']; ?>">
                            <button type="submit" class="danger-btn" title="ลบรีวิวนี้">🗑</button>
                        </form>
                    </div>
                    <div style="font-weight:770; font-size:1.37rem; margin-top:0.25rem;">
                        <?php echo htmlspecialchars($r['product_name']); ?>
                    </div>
                    <div style="opacity:0.9; margin-top:0.15rem;">⭐ <?php echo (int) $r['rating']; ?></div>
                    <p class="body-text" style="margin-top:0.35rem;">
                        <?php echo nl2br(htmlspecialchars($r['review_text'])); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="tab-comments" class="tab-content <?php echo $activeTab === 'comments' ? 'active' : ''; ?>">
        <?php if (empty($commentsGrouped)): ?>
            <p style="opacity:0.85;">ยังไม่มีคอมเมนต์</p>
        <?php else: ?>
            <?php foreach ($commentsGrouped as $group): ?>
                <div class="card" style="margin-bottom:0.9rem; position:relative;">
                    <form method="post" style="margin:0; position:absolute; top:0.5rem; right:0.5rem;">
                        <input type="hidden" name="delete_comment_group_id" value="<?php echo (int) $group['review_id']; ?>">
                        <button type="submit" class="danger-btn" title="ลบคอมเมนต์ทั้งหมดของคุณในรีวิวนี้">🗑</button>
                    </form>
                    <div style="margin-top:0.35rem; color: rgba(204, 204, 204, 1);">
                        ชื่อร้านค้า: <span
                            style="font-weight:650; font-size:1.1em; opacity:0.85;"><?php echo htmlspecialchars($group['store_name']); ?></span>
                    </div>
                    <div style="font-weight:800; font-size:1.8rem; margin-top:0.25rem;">
                        <?php echo htmlspecialchars($group['product_name']); ?>
                    </div>
                    <div style="margin-top:0.35rem; color: rgba(239, 68, 68, 0.9);">
                        ชื่อคนรีวิว: <span
                            style="font-weight:500; font-size:1em; color: rgba(204, 204, 204, 1);"><?php echo htmlspecialchars($group['reviewer_name']); ?>
                    </div>
                    <div style="margin-top:0.04rem; color: rgba(239, 68, 68, 0.9);">
                        ข้อความรีวิว: <span
                            style="font-weight:500; font-size:1em; color: rgba(204, 204, 204, 1);"><?php echo nl2br(htmlspecialchars($group['review_text'])); ?>
                    </div>
                    <div
                        style="font-size:1.15em; margin-top:0.8rem; padding-top:0.5rem; border-top:1px solid rgba(255, 255, 255, 0.35); font-weight:650; color: #f5d46b; letter-spacing:0.09em;">
                        คอมเมนต์ของคุณ:</div>
                    <?php foreach ($group['comments'] as $c): ?>
                        <div style="display:flex; gap:0.5rem; align-items:flex-start; margin-top:0.3rem;">
                            <div class="body-text" style="flex:1; margin:0;">
                                <?php echo nl2br(htmlspecialchars($c['comment_text'])); ?>
                            </div>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="delete_comment_id" value="<?php echo (int) $c['comment_id']; ?>">
                                <button type="submit" class="danger-btn" style="padding:0.15rem 0.5rem;">ลบ</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.tabTarget;
                tabButtons.forEach(b => b.classList.remove('active'));
                tabContents.forEach(sec => sec.classList.remove('active'));
                btn.classList.add('active');
                const targetEl = document.getElementById(`tab-${target}`);
                if (targetEl) targetEl.classList.add('active');

                const url = new URL(window.location.href);
                url.searchParams.set('tab', target);
                window.history.replaceState({}, '', url);
            });
        });
    });
</script>

<?php include 'footer.php'; ?>