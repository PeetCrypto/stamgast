<?php
declare(strict_types=1);
/**
 * Guest Profile Page
 * REGULR.vip Loyalty Platform
 */

require_once __DIR__ . '/../../../models/User.php';

$db = Database::getInstance()->getConnection();
$userId = currentUserId();
$userModel = new User($db);

// ── Auto-migrate: ensure new columns exist ──
$migrations = [
    ['phone',        "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL"],
    ['address',      "ALTER TABLE users ADD COLUMN address TEXT NULL DEFAULT NULL"],
    ['street',       "ALTER TABLE users ADD COLUMN street VARCHAR(100) NULL DEFAULT NULL"],
    ['house_number', "ALTER TABLE users ADD COLUMN house_number VARCHAR(10) NULL DEFAULT NULL"],
    ['postal_code',  "ALTER TABLE users ADD COLUMN postal_code VARCHAR(10) NULL DEFAULT NULL"],
    ['city',         "ALTER TABLE users ADD COLUMN city VARCHAR(50) NULL DEFAULT NULL"],
];
foreach ($migrations as [$col, $sql]) {
    $exists = $db->query("SHOW COLUMNS FROM users LIKE '{$col}'")->fetch();
    if (!$exists) {
        $db->exec($sql);
    }
}

$user = $userModel->findById($userId);

// Handle form submissions
$successMessage = '';
$errorMessage = '';
$showPasswordForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                $firstName   = trim($_POST['first_name'] ?? '');
                $lastName    = trim($_POST['last_name'] ?? '');
                $email       = trim($_POST['email'] ?? '');
                $phone       = trim($_POST['phone'] ?? '');
                $street      = trim($_POST['street'] ?? '');
                $houseNumber = trim($_POST['house_number'] ?? '');
                $postalCode  = trim($_POST['postal_code'] ?? '');
                $city        = trim($_POST['city'] ?? '');

                if ($firstName === '' || $lastName === '') {
                    $errorMessage = 'Voornaam en achternaam zijn verplicht.';
                    break;
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = 'Voer een geldig e-mailadres in.';
                    break;
                }

                $stmt = $db->prepare(
                    "UPDATE users SET
                        first_name   = :first_name,
                        last_name    = :last_name,
                        email        = :email,
                        phone        = :phone,
                        street       = :street,
                        house_number = :house_number,
                        postal_code  = :postal_code,
                        city         = :city
                     WHERE id = :id"
                );
                $result = $stmt->execute([
                    ':id'           => $userId,
                    ':first_name'   => $firstName,
                    ':last_name'    => $lastName,
                    ':email'        => $email,
                    ':phone'        => $phone !== '' ? $phone : null,
                    ':street'       => $street !== '' ? $street : null,
                    ':house_number' => $houseNumber !== '' ? $houseNumber : null,
                    ':postal_code'  => $postalCode !== '' ? $postalCode : null,
                    ':city'         => $city !== '' ? $city : null,
                ]);

                if ($result) {
                    $successMessage = 'Profiel succesvol bijgewerkt!';
                    $_SESSION['first_name'] = $firstName;
                    $_SESSION['last_name']  = $lastName;
                    $user = $userModel->findById($userId);
                } else {
                    $errorMessage = 'Er is iets misgegaan bij het bijwerken van het profiel.';
                }
                break;

            case 'change_password':
                $showPasswordForm = true;
                $currentPassword  = $_POST['current_password'] ?? '';
                $newPassword      = $_POST['new_password'] ?? '';
                $confirmPassword  = $_POST['confirm_password'] ?? '';

                if (!password_verify($currentPassword, $user['password_hash'])) {
                    $errorMessage = 'Huidig wachtwoord is onjuist.';
                    break;
                }
                if ($newPassword !== $confirmPassword) {
                    $errorMessage = 'Nieuwe wachtwoorden komen niet overeen.';
                    break;
                }
                if (strlen($newPassword) < 8) {
                    $errorMessage = 'Wachtwoord moet minimaal 8 karakters bevatten.';
                    break;
                }

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $userModel->updatePassword($userId, $passwordHash);
                $successMessage = 'Wachtwoord succesvol gewijzigd!';
                $showPasswordForm = false;
                break;

            case 'upload_photo':
                if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                    $errorCode = $_FILES['profile_photo']['error'] ?? 'none';
                    $errorMessage = 'Fout bij het uploaden van de foto (code: ' . $errorCode . ').';
                    break;
                }

                $file = $_FILES['profile_photo'];

                // Validate MIME type (not just extension)
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

                if (!in_array($mimeType, $allowedTypes, true)) {
                    $errorMessage = 'Alleen JPG, PNG, GIF en WebP bestanden zijn toegestaan.';
                    break;
                }

                if ($file['size'] > 5 * 1024 * 1024) {
                    $errorMessage = 'Foto mag maximaal 5 MB zijn.';
                    break;
                }

                // Upload to public/uploads/profiles/ (consistent with logo upload pattern)
                $extensionMap = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                $ext = $extensionMap[$mimeType] ?? 'jpg';
                $newFilename = "user_{$userId}_" . time() . '.' . $ext;

                $uploadDir = PUBLIC_PATH . 'uploads' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $uploadPath = $uploadDir . $newFilename;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    // Build URL exactly like logo upload does: BASE_URL . '/public/uploads/profiles/filename'
                    $photoUrl = BASE_URL . '/public/uploads/profiles/' . $newFilename;
                    $userModel->updatePhoto($userId, $photoUrl);
                    $successMessage = 'Profielfoto succesvol geüpload!';
                    $user = $userModel->findById($userId);
                } else {
                    $errorMessage = 'Kon foto niet opslaan. Controleer schrijfrechten.';
                }
                break;
        }
    }
}

// Populate form variables
$firstName   = $user['first_name'] ?? '';
$lastName    = $user['last_name'] ?? '';
$email       = $user['email'] ?? '';
$phone       = $user['phone'] ?? '';
$street      = $user['street'] ?? '';
$houseNumber = $user['house_number'] ?? '';
$postalCode  = $user['postal_code'] ?? '';
$city        = $user['city'] ?? '';
$photoUrl    = $user['photo_url'] ?? '';
$photoStatus = $user['photo_status'] ?? 'unvalidated';

// Determine photo img src (handle both old relative paths and new full URLs)
$photoImgSrc = '';
if ($photoUrl) {
    // If it's already a full URL (starts with / or http), use as-is
    if (str_starts_with($photoUrl, '/') || str_starts_with($photoUrl, 'http')) {
        $photoImgSrc = $photoUrl;
    } else {
        // Legacy relative path: prepend BASE_URL
        $photoImgSrc = BASE_URL . '/' . $photoUrl;
    }
}
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
    .profile-section { margin-bottom: var(--space-lg); }
    .profile-section h3 { margin-bottom: var(--space-md); font-size: 18px; }
    .profile-label {
        display: block;
        margin-bottom: 6px;
        font-size: 14px;
        font-weight: 500;
        color: var(--text-secondary);
    }
    .profile-input {
        width: 100%;
        padding: 12px 16px;
        border-radius: var(--radius-sm);
        border: 1px solid var(--glass-border);
        background: var(--glass-bg);
        color: var(--text-primary);
        font-size: 15px;
        font-family: var(--font-stack);
        outline: none;
        transition: var(--transition-fast);
        box-sizing: border-box;
    }
    .profile-input:focus {
        border-color: var(--accent-primary);
        box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.15);
    }
    .profile-input::placeholder {
        color: var(--text-muted);
    }
    .profile-row {
        display: grid;
        gap: var(--space-md);
        margin-bottom: var(--space-md);
    }
    .profile-row--2 { grid-template-columns: 1fr 1fr; }
    .profile-row--3-1 { grid-template-columns: 3fr 1fr; }
    .profile-row--1-2 { grid-template-columns: 1fr 2fr; }
    .profile-divider {
        border: none;
        border-top: 1px solid var(--glass-border);
        margin: var(--space-xl) 0;
    }
    .alert-success {
        padding: var(--space-md);
        margin-bottom: var(--space-md);
        border-radius: var(--radius-sm);
        background: rgba(76,175,80,0.15);
        border: 1px solid rgba(76,175,80,0.3);
        color: #4CAF50;
        font-size: 15px;
    }
    .alert-error {
        padding: var(--space-md);
        margin-bottom: var(--space-md);
        border-radius: var(--radius-sm);
        background: rgba(244,67,54,0.15);
        border: 1px solid rgba(244,67,54,0.3);
        color: #F44336;
        font-size: 15px;
    }
</style>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">Mijn Profiel</h1>

    <?php if ($successMessage): ?>
        <div class="alert-success"><?= sanitize($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert-error"><?= sanitize($errorMessage) ?></div>
    <?php endif; ?>

    <div class="glass-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg);">

        <!-- ── Profile Photo ── -->
        <div class="profile-section" style="text-align: center;">
            <h3>Profielfoto</h3>
            <div style="margin: var(--space-md) 0;">
                <?php if ($photoImgSrc): ?>
                    <img src="<?= sanitize($photoImgSrc) ?>" alt="Profielfoto"
                         style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-primary);">
                <?php else: ?>
                    <div style="width: 120px; height: 120px; border-radius: 50%; background: var(--glass-bg); border: 2px solid var(--glass-border); margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                        <span style="font-size: 36px; font-weight: 600; color: var(--accent-primary);">
                            <?= strtoupper(substr($firstName, 0, 1)) ?><?= strtoupper(substr($lastName, 0, 1)) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($photoUrl): ?>
                <?php if ($photoStatus === 'validated'): ?>
                    <div style="color: var(--success); margin-bottom: var(--space-sm);">✅ Foto goedgekeurd</div>
                <?php elseif ($photoStatus === 'blocked'): ?>
                    <div style="color: var(--error); margin-bottom: var(--space-sm);">❌ Foto geweigerd</div>
                <?php else: ?>
                    <div style="color: var(--warning); margin-bottom: var(--space-sm);">⏳ Foto wacht op goedkeuring</div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" style="margin-top: var(--space-md);">
                <input type="hidden" name="action" value="upload_photo">
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" required
                       style="margin-bottom: var(--space-sm); max-width: 300px; margin-left: auto; margin-right: auto; display: block;">
                <button type="submit" class="btn btn-primary">Foto Uploaden</button>
            </form>
        </div>

        <hr class="profile-divider">

        <!-- ── Personal Details ── -->
        <div class="profile-section">
            <h3>Persoonlijke Gegevens</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">

                <div class="profile-row profile-row--2">
                    <div>
                        <label class="profile-label" for="first_name">Voornaam *</label>
                        <input type="text" id="first_name" name="first_name" value="<?= sanitize($firstName) ?>" required class="profile-input">
                    </div>
                    <div>
                        <label class="profile-label" for="last_name">Achternaam *</label>
                        <input type="text" id="last_name" name="last_name" value="<?= sanitize($lastName) ?>" required class="profile-input">
                    </div>
                </div>

                <div class="profile-row">
                    <div>
                        <label class="profile-label" for="email">E-mailadres *</label>
                        <input type="email" id="email" name="email" value="<?= sanitize($email) ?>" required class="profile-input">
                    </div>
                </div>

                <div class="profile-row">
                    <div>
                        <label class="profile-label" for="phone">Telefoonnummer</label>
                        <input type="tel" id="phone" name="phone" value="<?= sanitize($phone) ?>" placeholder="+31 6 12345678" class="profile-input">
                    </div>
                </div>

                <div class="profile-row profile-row--3-1">
                    <div>
                        <label class="profile-label" for="street">Straat</label>
                        <input type="text" id="street" name="street" value="<?= sanitize($street) ?>" placeholder="Kalverstraat" class="profile-input">
                    </div>
                    <div>
                        <label class="profile-label" for="house_number">Huisnr.</label>
                        <input type="text" id="house_number" name="house_number" value="<?= sanitize($houseNumber) ?>" placeholder="123" class="profile-input">
                    </div>
                </div>

                <div class="profile-row profile-row--1-2">
                    <div>
                        <label class="profile-label" for="postal_code">Postcode</label>
                        <input type="text" id="postal_code" name="postal_code" value="<?= sanitize($postalCode) ?>" placeholder="1012 NX" class="profile-input">
                    </div>
                    <div>
                        <label class="profile-label" for="city">Plaats</label>
                        <input type="text" id="city" name="city" value="<?= sanitize($city) ?>" placeholder="Amsterdam" class="profile-input">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Gegevens Opslaan</button>
            </form>
        </div>

        <hr class="profile-divider">

        <!-- ── Password Change ── -->
        <div class="profile-section">
            <h3>Wachtwoord Wijzigen</h3>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('passwordForm').style.display = document.getElementById('passwordForm').style.display === 'none' ? 'block' : 'none';">
                Wachtwoord Wijzigen
            </button>

            <div id="passwordForm" style="display: <?= $showPasswordForm ? 'block' : 'none' ?>; margin-top: var(--space-md);">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">

                    <div style="margin-bottom: var(--space-md);">
                        <label class="profile-label" for="current_password">Huidig Wachtwoord</label>
                        <input type="password" id="current_password" name="current_password" required class="profile-input">
                    </div>
                    <div style="margin-bottom: var(--space-md);">
                        <label class="profile-label" for="new_password">Nieuw Wachtwoord</label>
                        <input type="password" id="new_password" name="new_password" required class="profile-input">
                        <small style="display: block; margin-top: 6px; color: var(--text-muted);">Minimaal 8 karakters</small>
                    </div>
                    <div style="margin-bottom: var(--space-md);">
                        <label class="profile-label" for="confirm_password">Bevestig Nieuw Wachtwoord</label>
                        <input type="password" id="confirm_password" name="confirm_password" required class="profile-input">
                    </div>

                    <button type="submit" class="btn btn-primary">Wachtwoord Wijzigen</button>
                </form>
            </div>
        </div>
    </div>

    <a href="<?= BASE_URL ?>/dashboard" class="btn btn-secondary">Terug naar Dashboard</a>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
