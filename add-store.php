<?php
require_once 'db.php';
requireLogin();

$roleId = (int)($_SESSION['user_type_id'] ?? 0);
$allowedRoles = [2, 3]; // admin, merchant

if (!in_array($roleId, $allowedRoles, true)) {
    http_response_code(403);
    exit('คุณไม่ได้รับสิทธิ์ในการเพิ่มร้านค้า');
}

$countries = [
    'Thailand',
    'Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan',
    'Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi',
    'Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo (Congo-Brazzaville)','Costa Rica','Cote d\'Ivoire','Croatia','Cuba','Cyprus','Czech Republic',
    'Democratic Republic of the Congo','Denmark','Djibouti','Dominica','Dominican Republic',
    'Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia',
    'Fiji','Finland','France',
    'Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana',
    'Haiti','Honduras','Hungary',
    'Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy',
    'Jamaica','Japan','Jordan',
    'Kazakhstan','Kenya','Kiribati','Kuwait','Kyrgyzstan',
    'Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg',
    'Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar',
    'Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Korea','North Macedonia','Norway',
    'Oman',
    'Pakistan','Palau','Palestine State','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal',
    'Qatar',
    'Romania','Russia','Rwanda',
    'Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria',
    'Tajikistan','Tanzania','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu',
    'Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan',
    'Vanuatu','Vatican City','Venezuela','Vietnam',
    'Yemen',
    'Zambia','Zimbabwe'
];

$errors = [];
$errorFields = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storeName = trim($_POST['store_name'] ?? '');
    $country   = $_POST['country'] ?? '';
    $city      = trim($_POST['city'] ?? '');
    $contact   = trim($_POST['contact'] ?? '');

    if ($storeName === '') {
        $errors[] = 'กรุณากรอกชื่อร้านค้า';
        $errorFields['store_name'] = true;
    }
    if (!in_array($country, $countries, true)) {
        $errors[] = 'กรุณาเลือกประเทศ';
        $errorFields['country'] = true;
    }
    if ($city === '') {
        $errors[] = 'กรุณากรอกเมือง';
        $errorFields['city'] = true;
    }
    if ($contact === '') {
        $errors[] = 'กรุณากรอกช่องทางติดต่อ';
        $errorFields['contact'] = true;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO Store (user_id, store_name, country, city, contact, register_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $uid = currentUserId();
        $stmt->bind_param('issss', $uid, $storeName, $country, $city, $contact);
        $stmt->execute();
        $stmt->close();

        header('Location: index.php');
        exit;
    }
}

include 'header.php';
?>

<section class="section">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <h1 class="page-title">เพิ่มข้อมูลร้านค้า</h1>
        <a href="add-product.php" class="btn" style="background:#1f2937; color:#f9fafb;">← กลับหน้าก่อน</a>
    </div>

    <div class="form-card" style="max-width:540px;">
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <div class="form-group">
                <label>ชื่อร้านค้า</label>
                <input type="text" name="store_name" required
                       class="<?php echo isset($errorFields['store_name']) ? 'input-error' : ''; ?>"
                       value="<?php echo htmlspecialchars($_POST['store_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>ประเทศ</label>
                <select name="country" required class="<?php echo isset($errorFields['country']) ? 'input-error' : ''; ?>">
                    <option value="">-- เลือกประเทศ --</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"
                            <?php echo (($_POST['country'] ?? '') === $c) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>เมือง</label>
                <input type="text" name="city" required
                       class="<?php echo isset($errorFields['city']) ? 'input-error' : ''; ?>"
                       value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>ช่องทางติดต่อ</label>
                <input type="text" name="contact" required
                       class="<?php echo isset($errorFields['contact']) ? 'input-error' : ''; ?>"
                       value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>">
            </div>

            <button class="btn-primary" type="submit">บันทึกข้อมูลร้าน</button>
        </form>
    </div>
</section>

<?php include 'footer.php'; ?>
