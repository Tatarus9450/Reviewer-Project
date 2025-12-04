<?php
// product.php

require_once 'db.php';

$currentRole = $_SESSION['user_type_id'] ?? 0;

// --- Admin Actions (Merged from admin_action.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (int) $currentRole === 2) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'delete_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0)
                throw new Exception('Invalid Product ID');

            // 1. Delete Comments linked to Reviews of this Product
            $conn->query("DELETE c FROM Comment c JOIN Review r ON c.review_id = r.review_id WHERE r.product_id = $productId");

            // 2. Delete Reviews of this Product
            $conn->query("DELETE FROM Review WHERE product_id = $productId");

            // 3. Delete Product
            $conn->query("DELETE FROM Product WHERE product_id = $productId");

            echo json_encode(['success' => true, 'message' => 'ลบสินค้าเรียบร้อยแล้ว']);
            exit;

        } elseif ($action === 'delete_store') {
            $storeId = (int) ($_POST['store_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            if ($storeId <= 0)
                throw new Exception('Invalid Store ID');

            // Verify Admin Password
            $adminId = $_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT password FROM User WHERE user_id = ?");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->bind_result($adminPass);
            $stmt->fetch();
            $stmt->close();

            if ($password !== $adminPass) {
                throw new Exception('รหัสผ่านไม่ถูกต้อง');
            }

            // 1. Get all Product IDs of this store
            $res = $conn->query("SELECT product_id FROM Product WHERE store_id = $storeId");
            $pIds = [];
            while ($row = $res->fetch_assoc())
                $pIds[] = $row['product_id'];

            if (!empty($pIds)) {
                $pIdsStr = implode(',', $pIds);
                // 2. Delete Comments linked to Reviews of these Products
                $conn->query("DELETE c FROM Comment c JOIN Review r ON c.review_id = r.review_id WHERE r.product_id IN ($pIdsStr)");
                // 3. Delete Reviews of these Products
                $conn->query("DELETE FROM Review WHERE product_id IN ($pIdsStr)");
                // 4. Delete Products
                $conn->query("DELETE FROM Product WHERE store_id = $storeId");
            }

            // 5. Delete Store
            $conn->query("DELETE FROM Store WHERE store_id = $storeId");

            echo json_encode(['success' => true, 'message' => 'ลบร้านค้าเรียบร้อยแล้ว']);
            exit;

        } elseif ($action === 'delete_review') {
            $reviewId = (int) ($_POST['review_id'] ?? 0);
            if ($reviewId <= 0)
                throw new Exception('Invalid Review ID');

            // 1. Delete Comments of this Review
            $conn->query("DELETE FROM Comment WHERE review_id = $reviewId");
            // 2. Delete Review
            $conn->query("DELETE FROM Review WHERE review_id = $reviewId");

            echo json_encode(['success' => true, 'message' => 'ลบรีวิวเรียบร้อยแล้ว']);
            exit;

        } elseif ($action === 'delete_comment') {
            $commentId = (int) ($_POST['comment_id'] ?? 0);
            if ($commentId <= 0)
                throw new Exception('Invalid Comment ID');

            $conn->query("DELETE FROM Comment WHERE comment_id = $commentId");

            echo json_encode(['success' => true, 'message' => 'ลบคอมเมนต์เรียบร้อยแล้ว']);
            exit;

        } elseif ($action === 'ban_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            if ($userId <= 0)
                throw new Exception('Invalid User ID');

            // Verify Admin Password
            $adminId = $_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT password FROM User WHERE user_id = ?");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->bind_result($adminPass);
            $stmt->fetch();
            $stmt->close();

            if ($password !== $adminPass) {
                throw new Exception('รหัสผ่านไม่ถูกต้อง');
            }

            // 1. Delete Comments made by User
            $conn->query("DELETE FROM Comment WHERE user_id = $userId");

            // 2. Delete Reviews made by User (and their comments)
            $conn->query("DELETE c FROM Comment c JOIN Review r ON c.review_id = r.review_id WHERE r.user_id = $userId");
            $conn->query("DELETE FROM Review WHERE user_id = $userId");

            // 3. Delete Store (and products/reviews/comments linked to store)
            $res = $conn->query("SELECT store_id FROM Store WHERE user_id = $userId");
            if ($row = $res->fetch_assoc()) {
                $storeId = $row['store_id'];
                $resP = $conn->query("SELECT product_id FROM Product WHERE store_id = $storeId");
                $pIds = [];
                while ($rP = $resP->fetch_assoc())
                    $pIds[] = $rP['product_id'];

                if (!empty($pIds)) {
                    $pIdsStr = implode(',', $pIds);
                    $conn->query("DELETE c FROM Comment c JOIN Review r ON c.review_id = r.review_id WHERE r.product_id IN ($pIdsStr)");
                    $conn->query("DELETE FROM Review WHERE product_id IN ($pIdsStr)");
                    $conn->query("DELETE FROM Product WHERE store_id = $storeId");
                }
                $conn->query("DELETE FROM Store WHERE store_id = $storeId");
            }

            // 4. Delete User
            $conn->query("DELETE FROM User WHERE user_id = $userId");

            echo json_encode(['success' => true, 'message' => 'แบนผู้ใช้และลบข้อมูลทั้งหมดเรียบร้อยแล้ว']);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($productId <= 0) {
    // Allow if it's just a script access? No, product.php expects ID for display.
    // But if we are here, it means it's NOT an admin action (which exits).
    die('ไม่พบสินค้า');
}

// --- จัดการฟอร์ม (รีวิว + คอมเมนต์) ก่อนส่ง HTML ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && currentUserId()) {

    // เพิ่มรีวิว
    if (isset($_POST['add_review'])) {
        $rating = (int) ($_POST['rating'] ?? 0);
        $text = trim($_POST['review_text'] ?? '');
        $uid = currentUserId();
        $isAjax = isset($_POST['ajax_action']);

        if ($rating < 1)
            $rating = 1;
        if ($rating > 5)
            $rating = 5;

        $stmt = $conn->prepare("
            INSERT INTO Review (product_id, user_id, rating, review_text)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('iiis', $productId, $uid, $rating, $text);
        $stmt->execute();
        $newReviewId = $stmt->insert_id;
        $stmt->close();

        if ($isAjax) {
            // Fetch user info for display
            $uStmt = $conn->prepare("SELECT username, user_type_id FROM User WHERE user_id = ?");
            $uStmt->bind_param('i', $uid);
            $uStmt->execute();
            $uStmt->bind_result($username, $userTypeId);
            $uStmt->fetch();
            $uStmt->close();

            $roleClass = userRoleClass($userTypeId);
            // Note: In this context, we don't easily know if they are store owner without fetching store info again, 
            // but for simplicity/performance we can check if we have storeOwnerId available. 
            // However, storeOwnerId is fetched later in the script. 
            // Let's just do a quick check or omit for now, or move the store fetch up.
            // Actually, let's move the store fetch UP before POST handling if possible, OR just fetch it here.

            // Re-fetch store owner for this product to be accurate
            $sStmt = $conn->prepare("SELECT s.user_id FROM Store s JOIN Product p ON s.store_id = p.store_id WHERE p.product_id = ?");
            $sStmt->bind_param('i', $productId);
            $sStmt->execute();
            $sStmt->bind_result($realStoreOwnerId);
            $sStmt->fetch();
            $sStmt->close();

            $isStoreOwner = ($uid === $realStoreOwnerId);
            $isAdmin = ($userTypeId === 2);
            $dateStr = date('Y-m-d H:i:s'); // Current time

            // Generate HTML
            ob_start();
            ?>
            <div class="review" id="review-<?php echo $newReviewId; ?>">
                <div class="review-header">
                    <span class="<?php echo $roleClass; ?>">
                        👤 <?php echo htmlspecialchars($username); ?>
                        <?php if ($isAdmin): ?>
                            <span style="opacity:0.85;">(ผู้ดูแลระบบ)</span>
                        <?php endif; ?>
                        <?php if ($isStoreOwner): ?>
                            <span style="opacity:0.85;">(เจ้าของร้านค้า)</span>
                        <?php endif; ?>
                    </span>
                    <span>⭐ <?php echo $rating; ?> · <?php echo $dateStr; ?></span>
                </div>
                <div class="review-body">
                    <?php echo nl2br(htmlspecialchars($text)); ?>
                </div>
                <div class="comments">
                    <!-- New review has no comments yet -->
                    <form method="post" style="margin-top:0.4rem;" class="comment-form">
                        <input type="hidden" name="review_id" value="<?php echo $newReviewId; ?>">
                        <input type="hidden" name="add_comment" value="1">
                        <textarea name="comment_text" placeholder="เขียนคอมเมนต์สั้น ๆ"></textarea>
                        <button class="btn-primary" type="submit" style="margin-top:0.35rem;">ส่งคอมเมนต์</button>
                    </form>
                </div>
            </div>
            <?php
            $html = ob_get_clean();
            echo json_encode(['success' => true, 'message' => 'บันทึกรีวิวสำเร็จ', 'html' => $html]);
            exit;
        }

        header("Location: product.php?id=" . $productId);
        exit;
    }

    // เพิ่มคอมเมนต์
    if (isset($_POST['add_comment'])) {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $text = trim($_POST['comment_text'] ?? '');
        $uid = currentUserId();
        $isAjax = isset($_POST['ajax_action']);

        if ($reviewId > 0 && $text !== '') {
            $stmt = $conn->prepare("
                INSERT INTO Comment (user_id, review_id, comment_text)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iis', $uid, $reviewId, $text);
            $stmt->execute();
            $stmt->close();

            if ($isAjax) {
                // Fetch user info
                $uStmt = $conn->prepare("SELECT username, user_type_id FROM User WHERE user_id = ?");
                $uStmt->bind_param('i', $uid);
                $uStmt->execute();
                $uStmt->bind_result($username, $userTypeId);
                $uStmt->fetch();
                $uStmt->close();

                // Fetch store owner (need to join Review -> Product -> Store)
                $sStmt = $conn->prepare("
                    SELECT s.user_id 
                    FROM Store s 
                    JOIN Product p ON s.store_id = p.store_id 
                    JOIN Review r ON p.product_id = r.product_id 
                    WHERE r.review_id = ?
                ");
                $sStmt->bind_param('i', $reviewId);
                $sStmt->execute();
                $sStmt->bind_result($realStoreOwnerId);
                $sStmt->fetch();
                $sStmt->close();

                $roleClass = userRoleClass($userTypeId);
                $isStoreOwner = ($uid === $realStoreOwnerId);
                $isAdmin = ($userTypeId === 2);

                ob_start();
                ?>
                <div class="comment">
                    <strong class="<?php echo $roleClass; ?>">
                        <?php echo htmlspecialchars($username); ?>
                        <?php if ($isAdmin): ?>
                            <span style="opacity:0.85;">(ผู้ดูแลระบบ)</span>
                        <?php endif; ?>
                        <?php if ($isStoreOwner): ?>
                            <span style="opacity:0.85;">(เจ้าของร้านค้า)</span>
                        <?php endif; ?>:
                    </strong>
                    <?php echo nl2br(htmlspecialchars($text)); ?>
                </div>
                <?php
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'message' => 'ส่งคอมเมนต์สำเร็จ', 'html' => $html]);
                exit;
            }
        }

        header("Location: product.php?id=" . $productId);
        exit;
    }
}

// --- ดึงข้อมูลสินค้า + ร้าน ---
$stmt = $conn->prepare("
    SELECT p.product_name, p.description, p.category,
           s.store_id, s.user_id, s.store_name, s.city, s.country, s.contact
    FROM Product p
    JOIN Store s ON p.store_id = s.store_id
    WHERE p.product_id = ?
");
$stmt->bind_param('i', $productId);
$stmt->execute();
$stmt->bind_result($pname, $pdesc, $pcat, $storeId, $storeOwnerId, $sname, $scity, $scountry, $scontact);
if (!$stmt->fetch()) {
    $stmt->close();
    die('ไม่พบสินค้า');
}
$stmt->close();

// ตัวเลือกกรองรีวิว: จำนวนดาว และเรียงลำดับวันที่
$ratingFilter = $_GET['rating'] ?? 'all';
$allowedRatings = ['all', '5', '4', '3', '2', '1'];
if (!in_array($ratingFilter, $allowedRatings, true)) {
    $ratingFilter = 'all';
}

$orderParam = $_GET['order'] ?? 'new';
$orderParam = ($orderParam === 'old') ? 'old' : 'new'; // default ใหม่ไปเก่า
$orderDir = $orderParam === 'old' ? 'ASC' : 'DESC';

// --- ดึงรีวิวทั้งหมดของสินค้านี้ รวม user_type_id ด้วย ---
$sqlReviews = "
    SELECT r.review_id, r.rating, r.review_text, r.review_date,
           u.username, u.user_type_id, u.user_id
    FROM Review r
    JOIN `User` u ON r.user_id = u.user_id
    WHERE r.product_id = ?
";
if ($ratingFilter !== 'all') {
    $sqlReviews .= " AND r.rating = ?";
}
$sqlReviews .= " ORDER BY r.review_date {$orderDir}";

$stmtRev = $conn->prepare($sqlReviews);
if ($ratingFilter !== 'all') {
    $ratingValue = (int) $ratingFilter;
    $stmtRev->bind_param('ii', $productId, $ratingValue);
} else {
    $stmtRev->bind_param('i', $productId);
}
$stmtRev->execute();
$resultRev = $stmtRev->get_result();

$reviews = [];
while ($row = $resultRev->fetch_assoc()) {
    $reviews[] = $row;
}
$stmtRev->close();

// --- ดึงคอมเมนต์ของแต่ละรีวิว (รวม user_type_id) ---
$commentsByReview = [];
if ($reviews) {
    $stmtC = $conn->prepare("
        SELECT c.comment_id, c.comment_text, c.comment_date, u.username, u.user_type_id, u.user_id
        FROM Comment c
        JOIN `User` u ON c.user_id = u.user_id
        WHERE c.review_id = ?
        ORDER BY c.comment_date ASC
    ");

    foreach ($reviews as $r) {
        $rid = (int) $r['review_id'];
        $stmtC->bind_param('i', $rid);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($c = $resC->fetch_assoc()) {
            $commentsByReview[$rid][] = $c;
        }
    }
    $stmtC->close();
}

// หลังจัดการ logic ทั้งหมดแล้ว ค่อยแสดงหน้า HTML
include 'header.php';
?>

<section class="section">
    <h1 class="page-title">
        <?php if ((int) ($currentRole ?? 0) === 2): ?>
            <button class="btn-admin-action" data-action="delete_product" data-id="<?php echo $productId; ?>"
                style="background:#ef4444; color:white; border:none; border-radius:50%; width:32px; height:32px; cursor:pointer; margin-right:0.5rem;"
                title="ลบสินค้า">
                🗑️
            </button>
        <?php endif; ?>
        <?php echo htmlspecialchars($pname); ?>
        <?php if ((int) ($currentRole ?? 0) === 2): ?>
            <button class="btn-admin-action" data-action="ban_user" data-id="<?php echo $storeOwnerId; ?>"
                style="background:#eab308; color:white; border:none; border-radius:8px; padding:0.2rem 0.6rem; font-size:0.9rem; cursor:pointer; margin-left:0.5rem;"
                title="แบนเจ้าของร้าน">
                ลบUser(BANถาวร)
            </button>
        <?php endif; ?>
    </h1>
    <p class="page-subtitle">
    <p class="page-subtitle">
        <?php if ((int) ($currentRole ?? 0) === 2): ?>
            <button class="btn-admin-action" data-action="delete_store" data-id="<?php echo $storeId; ?>"
                style="background:#eab308; color:white; border:none; border-radius:8px; padding:0.1rem 0.5rem; font-size:0.8rem; cursor:pointer; margin-right:0.3rem;"
                title="ลบร้านค้า">
                ลบข้อมูลร้าน
            </button>
        <?php endif; ?>
        ร้าน: <?php echo htmlspecialchars($sname); ?>
        <?php if ($scity || $scountry): ?>
            · ที่อยู่: <?php echo htmlspecialchars(trim($scity . ' ' . $scountry)); ?>
        <?php endif; ?>
        <?php if ($pcat): ?>
            · หมวด: <?php echo htmlspecialchars($pcat); ?>
        <?php endif; ?>
        <?php if ($scontact): ?>
            · ช่องทางติดต่อ: <?php echo htmlspecialchars($scontact); ?>
        <?php endif; ?>
    </p>

    <?php if ($pdesc): ?>
        <div class="section">
            <div class="card">
                <div class="card-title">รายละเอียดสินค้า</div>
                <p class="body-text">
                    <?php echo nl2br(htmlspecialchars($pdesc)); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2 class="page-title" style="font-size:1.2rem;">เขียนรีวิวสินค้า</h2>

        <?php if (!currentUserId()): ?>
            <div class="alert alert-error">
                ต้องเข้าสู่ระบบก่อนถึงจะเขียนรีวิวได้
                <a href="login.php" style="text-decoration: underline; font-weight: bold; color: #3b82f6;">เข้าสู่ระบบ</a>
            </div>
        </div>
    <?php else: ?>
        <form method="post" class="form-card" id="review-form">
            <input type="hidden" name="add_review" value="1">
            <div class="form-group">
                <label>คะแนน (1–5 ดาว ⭐)</label>
                <select name="rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <?php $stars = str_repeat("⭐", $i); ?>
                        <option value="<?php echo $i; ?>">
                            <?php echo $i . " ดาว " . $stars; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label>รายละเอียดรีวิว</label>
                <textarea name="review_text"></textarea>
            </div>
            <button class="btn-primary" type="submit">บันทึกรีวิว</button>
        </form>
    <?php endif; ?>
    </div>

    <div class="section">
        <h2 class="page-title" style="font-size:1.2rem;">รีวิวทั้งหมด</h2>

        <form method="get"
            style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; margin:0.6rem 0 0.4rem;">
            <input type="hidden" name="id" value="<?php echo (int) $productId; ?>">
            <label style="display:flex; gap:0.35rem; align-items:center;">
                Filter:
                <select name="rating" onchange="this.form.submit()">
                    <option value="all" <?php echo $ratingFilter === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo (string) $ratingFilter === (string) $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?> ⭐
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <span>เรียงตามวันที่:</span>
                <button type="submit" name="order" value="<?php echo $orderParam === 'new' ? 'old' : 'new'; ?>"
                    style="display:flex; align-items:center; gap:0.35rem; padding:0.25rem 0.65rem; border-radius:8px; border:1px solid #f8f9fb; background:#020617; color:#111; cursor:pointer;">
                    <span
                        style="color:<?php echo $orderParam === 'new' ? '#1b7cff' : '#888'; ?>; font-size:1rem;">↓</span>
                    <span
                        style="color:<?php echo $orderParam === 'old' ? '#1b7cff' : '#888'; ?>; font-size:1rem;">↑</span>
                </button>
            </div>
            <noscript><button class="btn-primary" type="submit">กรอง</button></noscript>
        </form>

        <div id="reviews-list">
            <?php if (empty($reviews)): ?>
                <p style="opacity:0.85; margin-top:0.6rem;">ยังไม่มีรีวิว ลองเขียนอันแรกเลย!</p>
            <?php else: ?>
                <?php foreach ($reviews as $r): ?>
                    <?php
                    $roleClass = userRoleClass($r['user_type_id']);
                    $isStoreOwner = ((int) ($r['user_id'] ?? 0) === (int) $storeOwnerId);
                    $isAdmin = ((int) ($r['user_type_id'] ?? 0) === 2);
                    ?>
                    <div class="review" id="review-<?php echo $r['review_id']; ?>">
                        <div class="review-header">
                            <span class="<?php echo $roleClass; ?>">
                                👤 <?php echo htmlspecialchars($r['username']); ?>
                                <?php if ($isAdmin): ?>
                                    <span style="opacity:0.85;">(ผู้ดูแลระบบ)</span>
                                <?php endif; ?>
                                <?php if ($isStoreOwner): ?>
                                    <span style="opacity:0.85;">(เจ้าของร้านค้า)</span>
                                <?php endif; ?>
                            </span>
                            <span>⭐ <?php echo (int) $r['rating']; ?> ·
                                <?php echo htmlspecialchars($r['review_date']); ?>
                            </span>
                        </div>
                        <div class="review-body">
                            <?php echo nl2br(htmlspecialchars($r['review_text'])); ?>
                            <?php if ((int) ($currentRole ?? 0) === 2): ?>
                                <div style="margin-top:0.5rem; display:flex; gap:0.5rem;">
                                    <button class="btn-admin-action" data-action="delete_review"
                                        data-id="<?php echo $r['review_id']; ?>"
                                        style="background:#ef4444; color:white; border:none; border-radius:6px; padding:0.15rem 0.5rem; font-size:0.8rem; cursor:pointer;">
                                        ลบ
                                    </button>
                                    <button class="btn-admin-action" data-action="ban_user" data-id="<?php echo $r['user_id']; ?>"
                                        style="background:#eab308; color:white; border:none; border-radius:6px; padding:0.15rem 0.5rem; font-size:0.8rem; cursor:pointer;">
                                        BAN
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="comments">
                            <?php foreach ($commentsByReview[$r['review_id']] ?? [] as $c): ?>
                                <?php
                                $cRoleClass = userRoleClass($c['user_type_id']);
                                $cIsStoreOwner = ((int) ($c['user_id'] ?? 0) === (int) $storeOwnerId);
                                $cIsAdmin = ((int) ($c['user_type_id'] ?? 0) === 2);
                                ?>
                                <div class="comment">
                                    <strong class="<?php echo $cRoleClass; ?>">
                                        <?php echo htmlspecialchars($c['username']); ?>
                                        <?php if ($cIsAdmin): ?>
                                            <span style="opacity:0.85;">(ผู้ดูแลระบบ)</span>
                                        <?php endif; ?>
                                        <?php if ($cIsStoreOwner): ?>
                                            <span style="opacity:0.85;">(เจ้าของร้านค้า)</span>
                                        <?php endif; ?>:
                                    </strong>
                                    <?php echo nl2br(htmlspecialchars($c['comment_text'])); ?>
                                    <?php if ((int) ($currentRole ?? 0) === 2): ?>
                                        <div style="margin-top:0.3rem; display:flex; gap:0.5rem;">
                                            <button class="btn-admin-action" data-action="delete_comment"
                                                data-id="<?php echo $c['comment_id']; ?>"
                                                style="background:#ef4444; color:white; border:none; border-radius:6px; padding:0.15rem 0.5rem; font-size:0.75rem; cursor:pointer;">
                                                ลบ
                                            </button>
                                            <button class="btn-admin-action" data-action="ban_user"
                                                data-id="<?php echo $c['user_id']; ?>"
                                                style="background:#eab308; color:white; border:none; border-radius:6px; padding:0.15rem 0.5rem; font-size:0.75rem; cursor:pointer;">
                                                BAN
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if (currentUserId()): ?>
                                <form method="post" style="margin-top:0.4rem;" class="comment-form">
                                    <input type="hidden" name="review_id" value="<?php echo $r['review_id']; ?>">
                                    <input type="hidden" name="add_comment" value="1">
                                    <textarea name="comment_text" placeholder="เขียนคอมเมนต์สั้น ๆ"></textarea>
                                    <button class="btn-primary" type="submit" style="margin-top:0.35rem;">ส่งคอมเมนต์</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

</section>

<?php include 'footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Handle Review Submission
        const reviewForm = document.getElementById('review-form');
        if (reviewForm) {
            reviewForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax_action', '1');

                fetch('product.php?id=<?php echo $productId; ?>', {
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
                                timer: 500,
                                showConfirmButton: false
                            });

                            // Append new review
                            const reviewsList = document.getElementById('reviews-list');
                            // Remove "no reviews" message if it exists
                            const noReviewMsg = reviewsList.querySelector('p');
                            if (noReviewMsg && noReviewMsg.textContent.includes('ยังไม่มีรีวิว')) {
                                noReviewMsg.remove();
                            }

                            // Create a temporary container to parse HTML string
                            const temp = document.createElement('div');
                            temp.innerHTML = data.html;
                            reviewsList.appendChild(temp.firstElementChild);

                            // Clear form
                            reviewForm.reset();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: data.error || 'ไม่สามารถบันทึกรีวิวได้'
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
        }

        // Handle Comment Submission (Event Delegation)
        document.addEventListener('submit', function (e) {
            if (e.target && e.target.classList.contains('comment-form')) {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);
                formData.append('ajax_action', '1');

                fetch('product.php?id=<?php echo $productId; ?>', {
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
                                timer: 500,
                                showConfirmButton: false
                            });

                            // Insert new comment before the form
                            const temp = document.createElement('div');
                            temp.innerHTML = data.html;
                            form.parentNode.insertBefore(temp.firstElementChild, form);

                            // Clear form
                            form.reset();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: data.error || 'ไม่สามารถส่งคอมเมนต์ได้'
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

    // Admin Actions
    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('btn-admin-action')) {
            e.preventDefault();
            const btn = e.target;
            const action = btn.dataset.action;
            const id = btn.dataset.id;

            let title = 'ยืนยันการทำรายการ?';
            let text = 'คุณต้องการดำเนินการนี้ใช่หรือไม่?';
            let confirmColor = '#ef4444';
            let requirePassword = false;
            let timerDuration = 3;

            if (action === 'delete_product') {
                title = 'ลบสินค้า?';
                text = 'สินค้า รีวิว และคอมเมนต์ทั้งหมดจะถูกลบ!';
            } else if (action === 'delete_store') {
                title = 'ลบร้านค้า?';
                text = 'ร้านค้า สินค้า รีวิว และคอมเมนต์ทั้งหมดจะถูกลบ!';
                confirmColor = '#eab308';
                requirePassword = true;
                timerDuration = 7;
            } else if (action === 'delete_review') {
                title = 'ลบรีวิว?';
                text = 'รีวิวและคอมเมนต์ที่เกี่ยวข้องจะถูกลบ!';
            } else if (action === 'delete_comment') {
                title = 'ลบคอมเมนต์?';
                text = 'คอมเมนต์นี้จะถูกลบ!';
            } else if (action === 'ban_user') {
                title = 'BAN ผู้ใช้ถาวร?';
                text = 'ผู้ใช้ ร้านค้า สินค้า รีวิว และคอมเมนต์ทั้งหมดของเขาจะถูกลบถาวร!';
                confirmColor = '#eab308';
                requirePassword = true;
                timerDuration = 7;
            }

            Swal.fire({
                title: title,
                text: text,
                icon: 'warning',
                input: requirePassword ? 'password' : undefined,
                inputPlaceholder: requirePassword ? 'กรุณาใส่รหัสผ่านแอดมินเพื่อยืนยัน' : undefined,
                inputAttributes: {
                    autocapitalize: 'off',
                    autocorrect: 'off'
                },
                customClass: {
                    input: 'swal-password-input'
                },
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#374151',
                confirmButtonText: `ยืนยัน (${timerDuration})`,
                cancelButtonText: 'ยกเลิก',
                didOpen: () => {
                    const confirmBtn = Swal.getConfirmButton();
                    confirmBtn.disabled = true;
                    let timer = timerDuration;
                    const interval = setInterval(() => {
                        timer--;
                        confirmBtn.textContent = `ยืนยัน (${timer})`;
                        if (timer <= 0) {
                            clearInterval(interval);
                            confirmBtn.textContent = 'ยืนยัน';
                            confirmBtn.disabled = false;
                        }
                    }, 1000);

                    // Fix input width
                    const input = Swal.getInput();
                    if (input) {
                        input.style.width = '85%';
                        input.style.maxWidth = '400px';
                        input.style.margin = '1em auto';
                    }
                },
                preConfirm: (password) => {
                    if (requirePassword && !password) {
                        Swal.showValidationMessage('กรุณาใส่รหัสผ่าน');
                    }
                    return password;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', action);
                    if (requirePassword) formData.append('password', result.value);

                    if (action === 'delete_product') formData.append('product_id', id);
                    if (action === 'delete_store') formData.append('store_id', id);
                    if (action === 'delete_review') formData.append('review_id', id);
                    if (action === 'delete_comment') formData.append('comment_id', id);
                    if (action === 'ban_user') formData.append('user_id', id);

                    fetch('product.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('สำเร็จ!', data.message, 'success')
                                    .then(() => {
                                        if (action === 'delete_product' || action === 'delete_store' || action === 'ban_user') {
                                            window.location.href = 'index.php';
                                        } else {
                                            location.reload();
                                        }
                                    });
                            } else {
                                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                            }
                        })
                        .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
                }
            });
        }
    });

</script>