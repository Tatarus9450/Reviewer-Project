<?php
include 'header.php';

// รับค่ากรอง/ค้นหา
$search = trim($_GET['q'] ?? '');
$categoryInput = $_GET['category'] ?? 'all';
$categoryFilter = $categoryInput === '' ? 'all' : $categoryInput;
$ratingOrder = $_GET['rating_order'] ?? 'desc';
$ratingOrder = $ratingOrder === 'asc' ? 'asc' : 'desc'; // default มากไปน้อย
$ratingDir = $ratingOrder === 'asc' ? 'ASC' : 'DESC';

$categoryOptions = [
    'all' => 'ทั้งหมด',
    'Food' => 'Food',
    'Clothing' => 'Clothing',
    'Electronics' => 'Electronics',
    'Cosmetics' => 'Cosmetics',
    'Beverage' => 'Beverage',
    'Beauty' => 'Beauty',
    'Books' => 'Books',
    'Sports' => 'Sports',
    'Home & Living' => 'Home & Living',
    'Toys' => 'Toys',
    'Automotive' => 'Automotive',
    'Health' => 'Health',
    'Pet Supplies' => 'Pet Supplies',
    'Stationery' => 'Stationery',
    'Others' => 'Others',
];

// ดึงสินค้าพร้อมค่าเฉลี่ยเรตติ้ง
$sql = "
    SELECT p.product_id, p.product_name, p.category,
           s.store_name,
           ROUND(AVG(r.rating), 1) AS avg_rating,
           COUNT(r.review_id) AS review_count
    FROM Product p
    JOIN Store s ON p.store_id = s.store_id
    LEFT JOIN Review r ON r.product_id = p.product_id
    WHERE 1=1
";

$hasSearch = $search !== '';
$hasCategory = ($categoryFilter !== 'all');

if ($hasSearch) {
    $sql .= " AND (p.product_name LIKE ? OR s.store_name LIKE ?)";
    $like = '%' . $search . '%';
}

if ($hasCategory) {
    $sql .= " AND p.category = ?";
}

$sql .= " GROUP BY p.product_id
          ORDER BY COALESCE(avg_rating, 0) {$ratingDir}, p.product_name ASC
          LIMIT 50";

$stmt = $conn->prepare($sql);
if ($hasSearch && $hasCategory) {
    $stmt->bind_param('sss', $like, $like, $categoryFilter);
} elseif ($hasSearch) {
    $stmt->bind_param('ss', $like, $like);
} elseif ($hasCategory) {
    $stmt->bind_param('s', $categoryFilter);
}
$stmt->execute();
$result = $stmt->get_result();
$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

$currentRole = $_SESSION['user_type_id'] ?? null;
$canAddProduct = in_array((int) $currentRole, [2, 3], true);

// ร้านค้าที่ยังไม่มีสินค้า แสดงเฉพาะเมื่อ filter หมวดเป็น "all"
$storesNoProduct = [];
if ($categoryFilter === 'all') {
    $sqlStoreOnly = "
        SELECT s.store_id, s.store_name
        FROM Store s
        WHERE NOT EXISTS (
            SELECT 1 FROM Product p WHERE p.store_id = s.store_id
        )
    ";
    $storeHasSearch = $search !== '';

    if ($storeHasSearch) {
        $sqlStoreOnly .= " AND s.store_name LIKE ?";
        $storeLike = '%' . $search . '%';
    }

    $sqlStoreOnly .= " ORDER BY s.store_name ASC LIMIT 50";

    $stmtStore = $conn->prepare($sqlStoreOnly);
    if ($storeHasSearch) {
        $stmtStore->bind_param('s', $storeLike);
    }
    $stmtStore->execute();
    $storeResult = $stmtStore->get_result();
    while ($row = $storeResult->fetch_assoc()) {
        $storesNoProduct[] = $row;
    }
    $stmtStore->close();
}
?>
<section class="section">
    <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
        <h1 class="page-title" style="margin-bottom:0;">ระบบรีวิวสินค้า</h1>
        <form id="filterForm" method="get" style="display:flex; gap:0.35rem; flex-wrap:wrap; align-items:center;">
            <input type="text" name="q" placeholder="🔎สินค้า/ร้าน" value="<?php echo htmlspecialchars($search); ?>"
                style="padding:0.45rem 0.6rem; border-radius:0.65rem; border:1px solid #374151; background:#0b1222; color:#f9fafb; min-width:100px; max-width:170px;">
            <span style="opacity:0.85;">📁ประเภท:</span>
            <select name="category" id="categorySelect"
                style="padding:0.45rem 0.6rem; border-radius:0.65rem; border:1px solid #374151; background:#0b1222; color:#f9fafb; min-width:100px; max-width:140px;">
                <?php foreach ($categoryOptions as $val => $label): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($categoryFilter === $val) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span style="opacity:0.85;">⭐เรียงคะแนน:</span>
            <input type="hidden" name="rating_order" id="ratingOrderInput"
                value="<?php echo htmlspecialchars($ratingOrder); ?>">
            <button type="button" id="ratingOrderBtn"
                style="display:flex; align-items:center; gap:0.25rem; padding:0.45rem 0.65rem; border-radius:0.65rem; border:1px solid #374151; background:#0b1222; color:#f9fafb; cursor:pointer;">
                <span id="ratingOrderDown"
                    style="color:<?php echo $ratingOrder === 'asc' ? '#3b82f6' : '#64748b'; ?>">↓</span>
                <span id="ratingOrderUp"
                    style="color:<?php echo $ratingOrder === 'desc' ? '#3b82f6' : '#64748b'; ?>">↑</span>
            </button>
            <noscript><button class="btn-primary" type="submit">ค้นหา</button></noscript>
        </form>
        <?php if (currentUserId()): ?>
            <?php if ($canAddProduct): ?>
                <a class="btn-add-store" href="add-product.php" style="margin-left:auto;">
                    <span style="font-size:1.1rem;">＋</span> เพิ่มข้อมูล
                </a>
            <?php else: ?>
                <button type="button" class="btn-add-store locked" id="addProductLockedBtn" style="margin-left:auto;">
                    <span style="font-size:1.1rem;">＋</span> เพิ่มข้อมูล
                </button>
            <?php endif; ?>
        <?php else: ?>
            <a class="btn-add-store locked" id="addProductGuestLink" href="login.php" style="margin-left:auto;">＋
                เพิ่มข้อมูลสินค้า</a>
        <?php endif; ?>
    </div>
    <p class="page-subtitle">รายการสินค้าทั้งหมด</p>

    <div class="card-grid">
        <?php foreach ($products as $row): ?>
            <div class="card" style="position:relative; padding:0; overflow:hidden;">
                <a href="product.php?id=<?php echo $row['product_id']; ?>"
                    style="display:block; padding:1.2rem; text-decoration:none; color:inherit; height:100%;">
                    <div class="card-title">
                        <?php echo htmlspecialchars($row['product_name']); ?>
                    </div>
                    <div class="card-sub">
                        ร้าน: <?php echo htmlspecialchars($row['store_name']); ?>
                    </div>
                    <div>
                        <?php if ($row['category']): ?>
                            <span class="badge">หมวด: <?php echo htmlspecialchars($row['category']); ?></span>
                        <?php endif; ?>
                        <span class="badge">
                            ⭐ <?php echo $row['avg_rating'] ? $row['avg_rating'] : '-'; ?>
                        </span>
                        <span class="badge">
                            💬 <?php echo $row['review_count']; ?> รีวิว
                        </span>
                    </div>
                </a>
                <?php if ((int) ($currentRole ?? 0) === 2): ?>
                    <button class="btn-delete-product" data-id="<?php echo $row['product_id']; ?>"
                        style="position:absolute; top:10px; right:10px; background:#ef4444; color:white; border:none; border-radius:50%; width:30px; height:30px; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:10;">
                        🗑️
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($storesNoProduct as $store): ?>
            <div class="card card-empty">
                <div class="card-title">
                    <?php echo htmlspecialchars($store['store_name']); ?>
                </div>
                <div class="card-sub">
                    ยังไม่มีสินค้าที่เชื่อมกับร้านนี้
                </div>
                <div>
                    <span class="badge">⭐ -</span>
                    <span class="badge">💬 0 รีวิว</span>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($products) && empty($storesNoProduct)): ?>
            <p style="opacity:0.85; margin-top:0.6rem;">ยังไม่มีข้อมูลสินค้า/ร้าน</p>
        <?php endif; ?>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const lockedBtn = document.getElementById('addProductLockedBtn');
        const guestLink = document.getElementById('addProductGuestLink');
        const filterForm = document.getElementById('filterForm');
        const categorySelect = document.getElementById('categorySelect');
        const ratingOrderBtn = document.getElementById('ratingOrderBtn');
        const ratingOrderInput = document.getElementById('ratingOrderInput');
        const ratingOrderUp = document.getElementById('ratingOrderUp');
        const ratingOrderDown = document.getElementById('ratingOrderDown');

        if (categorySelect && filterForm) {
            categorySelect.addEventListener('change', () => filterForm.submit());
        }

        if (ratingOrderBtn && ratingOrderInput && filterForm) {
            ratingOrderBtn.addEventListener('click', () => {
                const next = ratingOrderInput.value === 'asc' ? 'desc' : 'asc';
                ratingOrderInput.value = next;
                if (ratingOrderUp && ratingOrderDown) {
                    ratingOrderUp.style.color = next === 'asc' ? '#3b82f6' : '#64748b';
                    ratingOrderDown.style.color = next === 'desc' ? '#3b82f6' : '#64748b';
                }
                filterForm.submit();
            });
        }

        if (lockedBtn) {
            lockedBtn.addEventListener('click', (e) => {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'คุณไม่ได้อยู่ในสถานะบัญชีร้านค้า',
                    text: 'เฉพาะผู้ดูแลระบบหรือเจ้าของร้านค้าถึงจะเพิ่มข้อมูลได้',
                    confirmButtonColor: '#10b981'
                });
            });
        }

        if (guestLink && typeof Swal !== 'undefined') {
            guestLink.addEventListener('click', (e) => {
                e.preventDefault();
                if (guestLink.dataset.submitting === 'true') return;
                guestLink.dataset.submitting = 'true';

                Swal.fire({
                    title: 'กำลังนำไปหน้าเข้าสู่ระบบ...',
                    text: 'กรุณารอสักครู่',
                    timer: 800,
                    timerProgressBar: true,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    },
                    willClose: () => {
                        window.location.href = guestLink.href;
                    }
                });
            });
        }

        // Admin Delete Product
        document.querySelectorAll('.btn-delete-product').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault(); // Prevent card link
                e.stopPropagation();
                const productId = btn.dataset.id;

                Swal.fire({
                    title: 'ยืนยันการลบสินค้า?',
                    text: "การกระทำนี้จะลบสินค้า รีวิว และคอมเมนต์ทั้งหมดที่เกี่ยวข้อง ไม่สามารถกู้คืนได้!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#374151',
                    confirmButtonText: 'ยืนยัน (3)',
                    cancelButtonText: 'ยกเลิก',
                    didOpen: () => {
                        const confirmBtn = Swal.getConfirmButton();
                        confirmBtn.disabled = true;
                        let timer = 3;
                        const interval = setInterval(() => {
                            timer--;
                            confirmBtn.textContent = `ยืนยัน (${timer})`;
                            if (timer <= 0) {
                                clearInterval(interval);
                                confirmBtn.textContent = 'ใช่, ลบเลย!';
                                confirmBtn.disabled = false;
                            }
                        }, 1000);
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('action', 'delete_product');
                        formData.append('product_id', productId);

                        fetch('product.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    Swal.fire('ลบสำเร็จ!', data.message, 'success')
                                        .then(() => location.reload());
                                } else {
                                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                                }
                            })
                            .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
                    }
                });
            });
        });
    });
</script>
<?php include 'footer.php'; ?>