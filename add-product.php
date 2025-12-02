<?php
require_once 'db.php';
requireLogin();

$roleId = (int)($_SESSION['user_type_id'] ?? 0);
$allowedRoles = [2, 3]; // admin, merchant

if (!in_array($roleId, $allowedRoles, true)) {
    http_response_code(403);
    exit('คุณไม่ได้รับสิทธิ์ในการเพิ่มสินค้า');
}

$categories = [
    'Food', 'Clothing', 'Electronics', 'Cosmetics', 'Beverage',
    'Beauty', 'Books', 'Sports', 'Home & Living', 'Toys', 'Automotive',
    'Health', 'Pet Supplies', 'Stationery', 'Others'
];

$errors = [];
$errorFields = [];
$success = false;
$uid = currentUserId();

// ร้านค้าที่ user นี้เป็นคนสร้าง
$stores = [];
$stmtStores = $conn->prepare("
    SELECT store_id, store_name
    FROM Store
    WHERE user_id = ?
    ORDER BY store_name ASC
");
$stmtStores->bind_param('i', $uid);
$stmtStores->execute();
$resultStores = $stmtStores->get_result();
while ($row = $resultStores->fetch_assoc()) {
    $stores[(int)$row['store_id']] = $row['store_name'];
}
$stmtStores->close();
$hasStores = !empty($stores);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storeId      = (int)($_POST['store_id'] ?? 0);
    $productName  = trim($_POST['product_name'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $category     = $_POST['category'] ?? '';

    if (!$hasStores || !array_key_exists($storeId, $stores)) {
        $errors[] = 'กรุณาเลือกร้านค้าของคุณ';
        $errorFields['store_id'] = true;
    }

    if ($productName === '') {
        $errors[] = 'กรุณากรอกชื่อสินค้า';
        $errorFields['product_name'] = true;
    }

    if ($description === '') {
        $errors[] = 'กรุณากรอกคำอธิบายสินค้า';
        $errorFields['description'] = true;
    }

    if (!in_array($category, $categories, true)) {
        $errors[] = 'กรุณาเลือกหมวดสินค้า';
        $errorFields['category'] = true;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO Product (store_id, product_name, description, category)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('isss', $storeId, $productName, $description, $category);
        $stmt->execute();
        $stmt->close();

        $success = true;
    }
}

include 'header.php';
?>

<section class="section">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <h1 class="page-title">เพิ่มสินค้าในร้านของคุณ</h1>
        <a href="index.php" class="btn" style="background:#1f2937; color:#f9fafb;">← กลับหน้าแรก</a>
    </div>
    <?php if ($hasStores): ?>
        <div style="margin-top:0.4rem; display:flex; justify-content:flex-end;">
            <a href="add-store.php" class="btn-add-store" style="text-decoration:none;">
                <span style="font-size:1.1rem;">＋</span> เพิ่มร้านค้า
            </a>
        </div>
    <?php endif; ?>

    <?php if (!$hasStores && !$success): ?>
        <div class="alert alert-error" style="margin-top:0.8rem;">
            คุณยังไม่มีร้านค้า กรุณาสร้างร้านค้าก่อนที่จะเพิ่มสินค้า
        </div>
        <a href="add-store.php" class="btn-primary" style="margin-top:0.8rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <span style="font-size:1.1rem; line-height:1;">＋</span> สร้างร้านค้าแรกของคุณ
        </a>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($success): ?>
        <div>
            <p style="opacity:0;">กำลังพากลับหน้าแรก...</p>
        </div>
    <?php else: ?>
        <div class="form-card" style="max-width:540px;">
            <form id="addProductForm" method="post" novalidate>
                <div class="form-group">
                    <label>เลือกร้านค้าของคุณ</label>
                    <select name="store_id"
                            class="<?php echo isset($errorFields['store_id']) ? 'input-error' : ''; ?>"
                            <?php echo $hasStores ? '' : 'disabled'; ?>>
                        <option value="">-- เลือกร้านค้า --</option>
                        <?php foreach ($stores as $id => $name): ?>
                            <option value="<?php echo (int)$id; ?>"
                                <?php echo ((int)($_POST['store_id'] ?? 0) === (int)$id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>ชื่อสินค้า</label>
                    <input type="text" name="product_name" required
                           class="<?php echo isset($errorFields['product_name']) ? 'input-error' : ''; ?>"
                           value="<?php echo htmlspecialchars($_POST['product_name'] ?? ''); ?>"
                           <?php echo $hasStores ? '' : 'disabled'; ?>>
                </div>

                <div class="form-group">
                    <label>คำอธิบายสินค้า</label>
                    <textarea name="description" required rows="5"
                              class="<?php echo isset($errorFields['description']) ? 'input-error' : ''; ?>"
                              <?php echo $hasStores ? '' : 'disabled'; ?>><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label>หมวดสินค้า</label>
                    <select name="category" required
                            class="<?php echo isset($errorFields['category']) ? 'input-error' : ''; ?>"
                            <?php echo $hasStores ? '' : 'disabled'; ?>>
                        <option value="">-- เลือกหมวดสินค้า --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"
                                <?php echo (($_POST['category'] ?? '') === $c) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color:#f9fafb; font-weight:700; width:100%; justify-content:center; gap:0.35rem;"
                        <?php echo $hasStores ? '' : 'disabled'; ?>>
                    <span style="font-size:1.1rem; line-height:1;">＋</span> เพิ่มข้อมูลสินค้า
                </button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php if ($success): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({
            icon: 'success',
            title: 'เพิ่มข้อมูลสินค้าแล้ว',
            text: 'กำลังพากลับหน้าแรก',
            timer: 800,
            timerProgressBar: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            },
            willClose: () => {
                window.location.href = 'index.php';
            }
        });
    });
    </script>
<?php else: ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('addProductForm');
        if (!form || typeof Swal === 'undefined') return;

        form.addEventListener('submit', (e) => {
            if (form.dataset.submitting === 'true') {
                return;
            }

            const required = ['store_id', 'product_name', 'description', 'category'];
            const missing = [];

            required.forEach((name) => {
                const field = form.elements[name];
                if (!field || field.disabled) {
                    return;
                }
                const value = (field.value || '').trim();
                const empty = value === '';
                field.classList.toggle('input-error', empty);
                if (empty) {
                    missing.push(field);
                }
            });

            if (missing.length > 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'กรุณากรอกข้อมูลให้ครบ',
                    timer: 800,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });
                return;
            }

            form.dataset.submitting = 'true';
        });
    });
    </script>
<?php endif; ?>

<?php include 'footer.php'; ?>
