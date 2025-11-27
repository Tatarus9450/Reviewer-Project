<?php
// product.php

require_once 'db.php';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) {
    die('ไม่พบสินค้า');
}

// --- จัดการฟอร์ม (รีวิว + คอมเมนต์) ก่อนส่ง HTML ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && currentUserId()) {

    // เพิ่มรีวิว
    if (isset($_POST['add_review'])) {
        $rating = (int)($_POST['rating'] ?? 0);
        $text   = trim($_POST['review_text'] ?? '');
        $uid    = currentUserId();

        if ($rating < 1) $rating = 1;
        if ($rating > 5) $rating = 5;

        $stmt = $conn->prepare("
            INSERT INTO Review (product_id, user_id, rating, review_text)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('iiis', $productId, $uid, $rating, $text);
        $stmt->execute();
        $stmt->close();

        header("Location: product.php?id=" . $productId);
        exit;
    }

    // เพิ่มคอมเมนต์
    if (isset($_POST['add_comment'])) {
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $text     = trim($_POST['comment_text'] ?? '');
        $uid      = currentUserId();

        if ($reviewId > 0 && $text !== '') {
            $stmt = $conn->prepare("
                INSERT INTO Comment (user_id, review_id, comment_text)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iis', $uid, $reviewId, $text);
            $stmt->execute();
            $stmt->close();
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
    $ratingValue = (int)$ratingFilter;
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
        SELECT c.comment_text, c.comment_date, u.username, u.user_type_id, u.user_id
        FROM Comment c
        JOIN `User` u ON c.user_id = u.user_id
        WHERE c.review_id = ?
        ORDER BY c.comment_date ASC
    ");

    foreach ($reviews as $r) {
        $rid = (int)$r['review_id'];
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
    <h1 class="page-title"><?php echo htmlspecialchars($pname); ?></h1>
    <p class="page-subtitle">
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
                <a href="login.php">เข้าสู่ระบบ</a>
            </div>
        <?php else: ?>
            <form method="post" class="form-card">
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

        <form method="get" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; margin:0.6rem 0 0.4rem;">
            <input type="hidden" name="id" value="<?php echo (int)$productId; ?>">
            <label style="display:flex; gap:0.35rem; align-items:center;">
                Filter:
                <select name="rating" onchange="this.form.submit()">
                    <option value="all" <?php echo $ratingFilter === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo (string)$ratingFilter === (string)$i ? 'selected' : ''; ?>>
                            <?php echo $i; ?> ⭐
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <span>เรียงตามวันที่:</span>
                <button type="submit" name="order" value="<?php echo $orderParam === 'new' ? 'old' : 'new'; ?>"
                        style="display:flex; align-items:center; gap:0.35rem; padding:0.25rem 0.65rem; border-radius:8px; border:1px solid #f8f9fb; background:#020617; color:#111; cursor:pointer;">
                    <span style="color:<?php echo $orderParam === 'new' ? '#1b7cff' : '#888'; ?>; font-size:1rem;">↓</span>
                    <span style="color:<?php echo $orderParam === 'old' ? '#1b7cff' : '#888'; ?>; font-size:1rem;">↑</span>
                </button>
            </div>
            <noscript><button class="btn-primary" type="submit">กรอง</button></noscript>
        </form>

        <?php if (empty($reviews)): ?>
            <p style="opacity:0.85; margin-top:0.6rem;">ยังไม่มีรีวิว ลองเขียนอันแรกเลย!</p>
        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
                <?php
                $roleClass = userRoleClass($r['user_type_id']);
                $isStoreOwner = ((int)($r['user_id'] ?? 0) === (int)$storeOwnerId);
                $isAdmin = ((int)($r['user_type_id'] ?? 0) === 2);
                ?>
                <div class="review">
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
                        <span>⭐ <?php echo (int)$r['rating']; ?> ·
                            <?php echo htmlspecialchars($r['review_date']); ?>
                        </span>
                    </div>
                    <div class="review-body">
                        <?php echo nl2br(htmlspecialchars($r['review_text'])); ?>
                    </div>

                    <div class="comments">
                        <?php foreach ($commentsByReview[$r['review_id']] ?? [] as $c): ?>
                            <?php
                            $cRoleClass = userRoleClass($c['user_type_id']);
                            $cIsStoreOwner = ((int)($c['user_id'] ?? 0) === (int)$storeOwnerId);
                            $cIsAdmin = ((int)($c['user_type_id'] ?? 0) === 2);
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
                            </div>
                        <?php endforeach; ?>

                        <?php if (currentUserId()): ?>
                            <form method="post" style="margin-top:0.4rem;">
                                <input type="hidden" name="review_id"
                                       value="<?php echo $r['review_id']; ?>">
                                <input type="hidden" name="add_comment" value="1">
                                <textarea name="comment_text"
                                          placeholder="เขียนคอมเมนต์สั้น ๆ"></textarea>
                                <button class="btn-primary" type="submit"
                                        style="margin-top:0.35rem;">ส่งคอมเมนต์</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</section>

<?php include 'footer.php'; ?>
