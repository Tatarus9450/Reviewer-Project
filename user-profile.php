<?php
require_once 'db.php';
requireLogin();

$uid = currentUserId();
$tabParam = $_GET['tab'] ?? 'profile';
$allowedTabs = ['profile', 'reviews', 'comments', 'stores'];
$activeTab = in_array($tabParam, $allowedTabs, true) ? $tabParam : 'profile';
$msgParam = $_GET['msg'] ?? '';

function redirectTab($tab, $msg = null, $isError = false)
{
    $url = 'user-profile.php?tab=' . urlencode($tab);
    if ($msg !== null) {
        $url .= '&msg=' . urlencode($msg);
        if ($isError) {
            $url .= '&error=1';
        }
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
    $isAjax = isset($_POST['ajax_action']);
    $response = ['success' => false, 'message' => ''];

    if (isset($_POST['delete_review_id'])) {
        $rid = (int) $_POST['delete_review_id'];
        $chk = $conn->prepare("SELECT review_id FROM Review WHERE review_id = ? AND user_id = ?");
        $chk->bind_param('ii', $rid, $uid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $conn->begin_transaction();
            try {
                $delC = $conn->prepare("DELETE FROM Comment WHERE review_id = ?");
                $delC->bind_param('i', $rid);
                $delC->execute();
                $delC->close();

                $delR = $conn->prepare("DELETE FROM Review WHERE review_id = ? AND user_id = ?");
                $delR->bind_param('ii', $rid, $uid);
                $delR->execute();
                $delR->close();

                $conn->commit();
                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => 'ลบรีวิวเรียบร้อยแล้ว']);
                    exit;
                }
                redirectTab('reviews', 'deleted_review');
            } catch (Exception $e) {
                $conn->rollback();
                if ($isAjax) {
                    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในการลบ']);
                    exit;
                }
            }
        }
        $chk->close();
        if ($isAjax) {
            echo json_encode(['success' => false, 'error' => 'ไม่พบรีวิวหรือคุณไม่มีสิทธิ์ลบ']);
            exit;
        }
        redirectTab('reviews');
    }

    if (isset($_POST['delete_comment_id'])) {
        $cid = (int) $_POST['delete_comment_id'];
        $del = $conn->prepare("DELETE FROM Comment WHERE comment_id = ? AND user_id = ?");
        $del->bind_param('ii', $cid, $uid);
        $del->execute();
        $del->close();
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'ลบคอมเมนต์เรียบร้อยแล้ว']);
            exit;
        }
        redirectTab('comments', 'deleted_comment');
    }

    if (isset($_POST['delete_comment_group_id'])) {
        $rid = (int) $_POST['delete_comment_group_id'];
        $del = $conn->prepare("DELETE FROM Comment WHERE review_id = ? AND user_id = ?");
        $del->bind_param('ii', $rid, $uid);
        $del->execute();
        $del->close();
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'ลบคอมเมนต์ทั้งหมดเรียบร้อยแล้ว']);
            exit;
        }
        redirectTab('comments', 'deleted_comment');
    }

    // Profile Update
    if (isset($_POST['update_profile'])) {
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

            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => 'อัปเดตโปรไฟล์สำเร็จ']);
                exit;
            }

            $messages[] = 'อัปเดตโปรไฟล์สำเร็จ';

            // refresh values shown
            $username = $newUsername;
            $email = $newEmail;
            $password = $newPassword;
        } else {
            if ($isAjax) {
                echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
                exit;
            }
        }
    }
}

// Handle Store Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_store_id'])) {
    $isAjax = isset($_POST['ajax_action']);
    $storeId = (int) $_POST['delete_store_id'];
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Verify password
    if ($confirmPass !== $password) { // $password is from DB fetch at top
        if ($isAjax) {
            echo json_encode(['success' => false, 'error' => 'รหัสผ่านไม่ถูกต้อง! การลบล้มเหลว']);
            exit;
        }
        redirectTab('stores', 'error_password', true);
    } else {
        // Check ownership
        $chk = $conn->prepare("SELECT store_id FROM Store WHERE store_id = ? AND user_id = ?");
        $chk->bind_param('ii', $storeId, $uid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $conn->begin_transaction();
            try {
                // 1. Get all products in this store
                $pStmt = $conn->prepare("SELECT product_id FROM Product WHERE store_id = ?");
                $pStmt->bind_param('i', $storeId);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                while ($pRow = $pRes->fetch_assoc()) {
                    $pid = (int) $pRow['product_id'];

                    // 2. Get all reviews for this product
                    $rStmt = $conn->prepare("SELECT review_id FROM Review WHERE product_id = ?");
                    $rStmt->bind_param('i', $pid);
                    $rStmt->execute();
                    $rRes = $rStmt->get_result();
                    while ($rRow = $rRes->fetch_assoc()) {
                        $rid = (int) $rRow['review_id'];
                        // 3. Delete comments for this review
                        $conn->query("DELETE FROM Comment WHERE review_id = $rid");
                    }
                    $rStmt->close();

                    // 4. Delete reviews for this product
                    $conn->query("DELETE FROM Review WHERE product_id = $pid");
                }
                $pStmt->close();

                // 5. Delete products
                $conn->query("DELETE FROM Product WHERE store_id = $storeId");

                // 6. Delete store
                $delS = $conn->prepare("DELETE FROM Store WHERE store_id = ?");
                $delS->bind_param('i', $storeId);
                $delS->execute();
                $delS->close();

                $conn->commit();
                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => 'ลบร้านค้าเรียบร้อยแล้ว']);
                    exit;
                }
                redirectTab('stores', 'deleted_store');
            } catch (Exception $e) {
                $conn->rollback();
                if ($isAjax) {
                    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในการลบร้านค้า: ' . $e->getMessage()]);
                    exit;
                }
                $errors[] = 'เกิดข้อผิดพลาดในการลบร้านค้า: ' . $e->getMessage();
            }
        } else {
            if ($isAjax) {
                echo json_encode(['success' => false, 'error' => 'ไม่พบร้านค้าหรือคุณไม่มีสิทธิ์ลบ']);
                exit;
            }
            $errors[] = 'ไม่พบร้านค้าหรือคุณไม่มีสิทธิ์ลบ';
        }
        $chk->close();
    }
}

// Handle Product Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product_id'])) {
    $isAjax = isset($_POST['ajax_action']);
    $productId = (int) $_POST['delete_product_id'];
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Verify password
    if ($confirmPass !== $password) {
        if ($isAjax) {
            echo json_encode(['success' => false, 'error' => 'รหัสผ่านไม่ถูกต้อง! การลบล้มเหลว']);
            exit;
        }
        redirectTab('stores', 'error_password', true);
    } else {
        // Check ownership (via Store -> User)
        $chk = $conn->prepare("
            SELECT p.product_id 
            FROM Product p
            JOIN Store s ON p.store_id = s.store_id
            WHERE p.product_id = ? AND s.user_id = ?
        ");
        $chk->bind_param('ii', $productId, $uid);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $conn->begin_transaction();
            try {
                // 1. Get all reviews for this product
                $rStmt = $conn->prepare("SELECT review_id FROM Review WHERE product_id = ?");
                $rStmt->bind_param('i', $productId);
                $rStmt->execute();
                $rRes = $rStmt->get_result();
                while ($rRow = $rRes->fetch_assoc()) {
                    $rid = (int) $rRow['review_id'];
                    // 2. Delete comments for this review
                    $conn->query("DELETE FROM Comment WHERE review_id = $rid");
                }
                $rStmt->close();

                // 3. Delete reviews
                $conn->query("DELETE FROM Review WHERE product_id = $productId");

                // 4. Delete product
                $delP = $conn->prepare("DELETE FROM Product WHERE product_id = ?");
                $delP->bind_param('i', $productId);
                $delP->execute();
                $delP->close();

                $conn->commit();
                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => 'ลบสินค้าเรียบร้อยแล้ว']);
                    exit;
                }
                redirectTab('stores', 'deleted_product');
            } catch (Exception $e) {
                $conn->rollback();
                if ($isAjax) {
                    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในการลบสินค้า: ' . $e->getMessage()]);
                    exit;
                }
                $errors[] = 'เกิดข้อผิดพลาดในการลบสินค้า: ' . $e->getMessage();
            }
        } else {
            if ($isAjax) {
                echo json_encode(['success' => false, 'error' => 'ไม่พบสินค้าหรือคุณไม่มีสิทธิ์ลบ']);
                exit;
            }
            $errors[] = 'ไม่พบสินค้าหรือคุณไม่มีสิทธิ์ลบ';
        }
        $chk->close();
    }
}

if ($msgParam === 'deleted_review') {
    $messages[] = 'ลบรีวิวเรียบร้อยแล้ว';
} elseif ($msgParam === 'deleted_comment') {
    $messages[] = 'ลบคอมเมนต์เรียบร้อยแล้ว';
} elseif ($msgParam === 'deleted_store') {
    $messages[] = 'ลบร้านค้าและข้อมูลที่เกี่ยวข้องเรียบร้อยแล้ว';
} elseif ($msgParam === 'deleted_product') {
    $messages[] = 'ลบสินค้าและข้อมูลที่เกี่ยวข้องเรียบร้อยแล้ว';
} elseif ($msgParam === 'error_password') {
    $errors[] = 'รหัสผ่านไม่ถูกต้อง! การลบล้มเหลว';
}

// ดึงรีวิวทั้งหมดของผู้ใช้
$userReviews = [];
$stmtRevList = $conn->prepare("
    SELECT r.review_id, r.rating, r.review_text, r.review_date, r.product_id,
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
           r.review_id, r.review_text, r.rating, r.product_id,
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
            'product_id' => $row['product_id'],
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

// Fetch Stores and Products for Merchant/Admin
$myStores = [];
if (in_array($userTypeId, [2, 3], true)) {
    $stmtS = $conn->prepare("SELECT store_id, store_name, country, city, contact FROM Store WHERE user_id = ? ORDER BY store_name ASC");
    $stmtS->bind_param('i', $uid);
    $stmtS->execute();
    $resS = $stmtS->get_result();
    while ($row = $resS->fetch_assoc()) {
        $sid = (int) $row['store_id'];
        $row['products'] = [];
        $myStores[$sid] = $row;
    }
    $stmtS->close();

    if (!empty($myStores)) {
        // Fetch products for these stores
        $storeIds = implode(',', array_keys($myStores));
        $sqlP = "SELECT product_id, store_id, product_name, category, description FROM Product WHERE store_id IN ($storeIds) ORDER BY product_name ASC";
        $resP = $conn->query($sqlP);
        while ($row = $resP->fetch_assoc()) {
            $sid = (int) $row['store_id'];
            if (isset($myStores[$sid])) {
                $myStores[$sid]['products'][] = $row;
            }
        }
    }
}

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
        <?php if (in_array($userTypeId, [2, 3], true)): ?>
            <button type="button" class="tab-btn <?php echo $activeTab === 'stores' ? 'active' : ''; ?>"
                data-tab-target="stores">ร้านค้าและสินค้า</button>
        <?php else: ?>
            <button type="button" class="tab-btn" style="opacity:0.5; cursor:not-allowed;"
                title="สำหรับร้านค้าเท่านั้น">ร้านค้าและสินค้า</button>
        <?php endif; ?>
    </div>

    <div id="searchContainer"
        style="margin-bottom: 1rem; display: <?php echo $activeTab === 'profile' ? 'none' : 'block'; ?>;">
        <input type="text" id="tabSearchInput" placeholder="🔍 ค้นหา (รีวิว, คอมเมนต์, ร้านค้า)..."
            style="width:100%; padding:0.6rem; border-radius:0.5rem; border:1px solid #374151; background:#1f2937; color:#f9fafb;">
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <div id="tab-profile" class="tab-content <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
        <div class="profile-card">
            <?php foreach ($errors as $e): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>

            <form id="profile-form" method="post" novalidate>
                <input type="hidden" name="update_profile" value="1">
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
                <div class="card clickable-card"
                    data-url="product.php?id=<?php echo $r['product_id']; ?>#review-<?php echo $r['review_id']; ?>"
                    style="margin-bottom:0.9rem; cursor: pointer;">
                    <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:center;">
                        <span style="font-weight:600;"><?php echo htmlspecialchars($r['store_name']); ?></span>
                        <button type="button" class="danger-btn btn-delete-ajax" data-type="review"
                            data-id="<?php echo (int) $r['review_id']; ?>" title="ลบรีวิวนี้"
                            onclick="event.stopPropagation();">🗑</button>
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
                <div class="card clickable-card"
                    data-url="product.php?id=<?php echo $group['product_id']; ?>#review-<?php echo $group['review_id']; ?>"
                    style="margin-bottom:0.9rem; position:relative; cursor: pointer;">
                    <button type="button" class="danger-btn btn-delete-ajax" data-type="comment_group"
                        data-id="<?php echo (int) $group['review_id']; ?>" title="ลบคอมเมนต์ทั้งหมดของคุณในรีวิวนี้"
                        style="position:absolute; top:0.5rem; right:0.5rem;" onclick="event.stopPropagation();">🗑</button>
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
                            <button type="button" class="danger-btn btn-delete-ajax" data-type="comment"
                                data-id="<?php echo (int) $c['comment_id']; ?>" style="padding:0.15rem 0.5rem;"
                                onclick="event.stopPropagation();">ลบ</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="tab-stores" class="tab-content <?php echo $activeTab === 'stores' ? 'active' : ''; ?>">
        <?php if (empty($myStores)): ?>
            <p style="opacity:0.85;">คุณยังไม่มีร้านค้า</p>
            <div style="margin-top:1rem;">
                <a href="add-store.php" class="btn-primary" style="text-decoration:none;">+ สร้างร้านค้าใหม่</a>
            </div>
        <?php else: ?>
            <?php foreach ($myStores as $store): ?>
                <div class="card" style="margin-bottom:1rem; position:relative;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="font-weight:700; font-size:1.4rem;">
                                <?php echo htmlspecialchars($store['store_name']); ?>
                            </div>
                            <div style="opacity:0.8; font-size:0.9rem; margin-top:0.2rem;">
                                📍 <?php echo htmlspecialchars($store['city'] . ', ' . $store['country']); ?>
                            </div>
                            <div style="opacity:0.8; font-size:0.9rem;">
                                📞 <?php echo htmlspecialchars($store['contact']); ?>
                            </div>
                        </div>
                        <button type="button" class="danger-btn btn-delete-confirm" data-type="store"
                            data-id="<?php echo $store['store_id']; ?>"
                            data-name="<?php echo htmlspecialchars($store['store_name']); ?>" title="ลบร้านค้านี้">
                            🗑
                        </button>
                    </div>

                    <div style="margin-top:1rem;">
                        <button type="button" class="btn-toggle-products"
                            style="background:none; border:none; color:#3b82f6; cursor:pointer; padding:0; font-size:1rem; display:flex; align-items:center; gap:0.3rem;">
                            <span>▶</span> ดูรายการสินค้า (<?php echo count($store['products']); ?>)
                        </button>
                        <div class="products-list"
                            style="display:none; margin-top:0.5rem; padding-left:0.5rem; border-left:2px solid #374151;">
                            <?php if (empty($store['products'])): ?>
                                <p style="opacity:0.6; font-style:italic; margin:0.5rem 0;">ไม่มีสินค้าในร้านนี้</p>
                            <?php else: ?>
                                <?php foreach ($store['products'] as $prod): ?>
                                    <div
                                        style="display:flex; justify-content:space-between; align-items:center; padding:0.4rem 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <div>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($prod['product_name']); ?></div>
                                            <div style="font-size:0.85rem; opacity:0.7;">
                                                <?php echo htmlspecialchars($prod['category']); ?>
                                            </div>
                                        </div>
                                        <button type="button" class="danger-btn btn-delete-confirm" data-type="product"
                                            data-id="<?php echo $prod['product_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($prod['product_name']); ?>"
                                            style="padding:0.15rem 0.5rem;" title="ลบสินค้านี้">
                                            🗑
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div style="margin-top:0.5rem;">
                                <a href="add-product.php" style="font-size:0.9rem; color:#10b981; text-decoration:none;">+
                                    เพิ่มสินค้าใหม่</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('profile-form');

        // Profile Update AJAX
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
                    // AJAX Submit
                    const formData = new FormData(form);
                    formData.append('ajax_action', '1');

                    fetch('user-profile.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'สำเร็จ',
                                    text: data.message,
                                    timer: 700,
                                    showConfirmButton: false
                                });
                                input.disabled = true;
                                btn.textContent = 'Edit';
                                btn.classList.remove('save');
                                btn.dataset.mode = 'view';
                                // Update displayed value if needed (input value is already changed by user)
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาด',
                                    text: data.error
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
                }
            });
        });

        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.style.cursor === 'not-allowed') return;

                const target = btn.dataset.tabTarget;
                tabButtons.forEach(b => b.classList.remove('active'));
                tabContents.forEach(sec => sec.classList.remove('active'));
                btn.classList.add('active');
                const targetEl = document.getElementById(`tab-${target}`);
                if (targetEl) targetEl.classList.add('active');

                const url = new URL(window.location.href);
                url.searchParams.set('tab', target);
                window.history.replaceState({}, '', url);

                // Toggle Search Bar
                const searchContainer = document.getElementById('searchContainer');
                if (searchContainer) {
                    searchContainer.style.display = (target === 'profile') ? 'none' : 'block';
                }
            });
        });

        // Toggle Products Dropdown
        document.querySelectorAll('.btn-toggle-products').forEach(btn => {
            btn.addEventListener('click', () => {
                const list = btn.nextElementSibling;
                const icon = btn.querySelector('span');
                if (list.style.display === 'none') {
                    list.style.display = 'block';
                    icon.textContent = '▼';
                } else {
                    list.style.display = 'none';
                    icon.textContent = '▶';
                }
            });
        });

        // Delete Confirmation Popup (Store/Product)
        document.querySelectorAll('.btn-delete-confirm').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent card click
                const type = btn.dataset.type; // 'store' or 'product'
                const id = btn.dataset.id;
                const name = btn.dataset.name;

                let confirmBtn;
                let timerInterval;

                Swal.fire({
                    title: `คุณแน่ใจหรอว่าจะลบสิ่งนี้?\n"${name}"`,
                    html: `
                        <p style="font-size:0.9rem; opacity:0.8; margin-bottom:1rem;">
                            การลบนี้จะลบข้อมูลที่เกี่ยวข้องทั้งหมด (รีวิว, คอมเมนต์) และไม่สามารถกู้คืนได้
                        </p>
                        <input type="password" id="swal-password" class="swal2-input" placeholder="ใส่รหัสผ่านเพื่อยืนยัน" style="max-width: 80%; margin: 0 auto;">
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'ยืนยัน (10)',
                    cancelButtonText: 'ยกเลิก',
                    didOpen: () => {
                        confirmBtn = Swal.getConfirmButton();
                        confirmBtn.disabled = true;
                        confirmBtn.style.opacity = '0.5';
                        let timeLeft = 10;

                        timerInterval = setInterval(() => {
                            timeLeft--;
                            confirmBtn.textContent = `ยืนยัน (${timeLeft})`;
                            if (timeLeft <= 0) {
                                clearInterval(timerInterval);
                                confirmBtn.textContent = 'ยืนยัน';
                                confirmBtn.disabled = false;
                                confirmBtn.style.opacity = '1';
                                confirmBtn.classList.remove('swal2-confirm'); // remove default style if needed
                                confirmBtn.style.backgroundColor = '#10b981'; // Green
                            }
                        }, 1000);
                    },
                    willClose: () => {
                        clearInterval(timerInterval);
                    },
                    preConfirm: () => {
                        const password = Swal.getPopup().querySelector('#swal-password').value;
                        if (!password) {
                            Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                        }
                        return { password: password };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // AJAX Delete
                        const formData = new FormData();
                        formData.append(type === 'store' ? 'delete_store_id' : 'delete_product_id', id);
                        formData.append('confirm_password', result.value.password);
                        formData.append('ajax_action', '1');

                        fetch('user-profile.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'ลบสำเร็จ',
                                        text: data.message,
                                        timer: 700,
                                        showConfirmButton: false
                                    });
                                    // Remove element from DOM
                                    if (type === 'store') {
                                        btn.closest('.card').remove();
                                    } else {
                                        btn.closest('div').remove(); // Remove product row
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'ลบล้มเหลว',
                                        text: data.error
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
                    }
                });
            });
        });

        // AJAX Delete for Review/Comment (Simple Confirmation)
        document.querySelectorAll('.btn-delete-ajax').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const type = btn.dataset.type;
                const id = btn.dataset.id;

                let confirmTitle = 'คุณแน่ใจหรือไม่?';
                if (type === 'review') confirmTitle = 'ลบรีวิวนี้?';
                if (type === 'comment') confirmTitle = 'ลบคอมเมนต์นี้?';
                if (type === 'comment_group') confirmTitle = 'ลบคอมเมนต์ทั้งหมดในรีวิวนี้?';

                Swal.fire({
                    title: confirmTitle,
                    text: "การกระทำนี้ไม่สามารถย้อนกลับได้",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'ลบเลย',
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        if (type === 'review') formData.append('delete_review_id', id);
                        if (type === 'comment') formData.append('delete_comment_id', id);
                        if (type === 'comment_group') formData.append('delete_comment_group_id', id);
                        formData.append('ajax_action', '1');

                        fetch('user-profile.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'ลบสำเร็จ',
                                        text: data.message,
                                        timer: 700,
                                        showConfirmButton: false
                                    });
                                    // Remove element
                                    if (type === 'review' || type === 'comment_group') {
                                        btn.closest('.card').remove();
                                    } else if (type === 'comment') {
                                        btn.closest('div').remove(); // Remove comment row
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'เกิดข้อผิดพลาด',
                                        text: data.error || 'ไม่สามารถลบได้'
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
                    }
                });
            });
        });

        // Clickable Cards
        document.querySelectorAll('.clickable-card').forEach(card => {
            card.addEventListener('click', (e) => {
                // Prevent redirection if clicking on buttons or inputs
                if (e.target.closest('button') || e.target.closest('input')) return;

                const url = card.dataset.url;
                if (url) {
                    window.location.href = url;
                }
            });
        });

        // Search Functionality
        const searchInput = document.getElementById('tabSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const filter = searchInput.value.toLowerCase();
                const activeTab = document.querySelector('.tab-content.active');
                if (!activeTab) return;

                const cards = activeTab.querySelectorAll('.card');
                cards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    if (text.includes(filter)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });

            // Re-filter when tab changes
            tabButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    // Trigger input event to re-filter the newly active tab
                    searchInput.dispatchEvent(new Event('input'));
                });
            });
        }
    });
</script>

<?php include 'footer.php'; ?>