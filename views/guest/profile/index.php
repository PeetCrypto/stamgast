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
                    $userModel->updatePhoto($userId, $photoUrl, 'validated');
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
$hasFcmToken = !empty($user['fcm_token']);

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

            <?php if ($photoUrl && $photoStatus === 'blocked'): ?>
                <div style="color: var(--error); margin-bottom: var(--space-sm);">❌ Foto is geblokkeerd door beheerder</div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="margin-top: var(--space-md);">
                <input type="hidden" name="action" value="upload_photo">
                <label class="btn btn-primary" style="display: inline-block; cursor: pointer; margin: 0 auto;">
                    📷 Foto kiezen
                    <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" required
                           style="display: none;"
                           onchange="document.getElementById('photoUploadForm').submit();">
                </label>
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

        <hr class="profile-divider">

        <!-- ── App Beveiliging ── -->
        <div class="profile-section">
            <h3>🔒 App Beveiliging</h3>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: var(--space-md);">
                Kies hoe je je app wilt ontgrendelen. Je app wordt altijd vergrendeld als je hem op de achtergrond zet. Beveiliging gaat via je PIN-code of FaceID/Vingerafdruk.
            </p>

            <!-- PIN toggle (onafhankelijk) -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--space-md);border-radius:12px;border:1px solid var(--glass-border);background:var(--glass-bg);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span id="security-pin-icon" style="font-size:24px;">🔢</span>
                    <div>
                        <strong id="security-pin-label">PIN-code</strong>
                        <p id="security-pin-desc" style="color:var(--text-secondary);font-size:13px;margin-top:2px;">Niet ingesteld</p>
                    </div>
                </div>
                <button id="security-pin-toggle" type="button" class="btn btn-sm" style="font-size:13px;padding:6px 16px;">Inschakelen</button>
            </div>

            <!-- FaceID toggle (onafhankelijk, altijd zichtbaar als WebAuthn beschikbaar) -->
            <div id="security-webauthn-row" style="margin-top:var(--space-md);padding:var(--space-md);border-radius:12px;border:1px solid var(--glass-border);background:var(--glass-bg);">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span id="security-webauthn-icon" style="font-size:24px;">👤</span>
                        <div>
                            <strong id="security-webauthn-label">FaceID / Vingerafdruk</strong>
                            <p id="security-webauthn-desc" style="color:var(--text-secondary);font-size:13px;margin-top:2px;">Niet ingeschakeld</p>
                        </div>
                    </div>
                    <button id="security-webauthn-toggle" type="button" class="btn btn-sm" style="font-size:13px;padding:6px 16px;">Inschakelen</button>
                </div>
                <p id="security-webauthn-error" style="display:none;color:#F44336;font-size:13px;margin-top:var(--space-sm);"></p>
                <!-- PWA hint: alleen zichtbaar als NIET in standalone mode -->
                <p id="security-webauthn-pwa-hint" style="display:none;color:#FFC107;font-size:12px;margin-top:var(--space-sm);line-height:1.4;">💡 FaceID werkt het beste als deze app op je thuisscherm staat. Zonder PWA kan een wachtwoordmanager verschijnen i.p.v. FaceID.</p>
            </div>

            <!-- Inline PIN setup (hidden by default) -->
            <div id="security-pin-setup" style="display:none;margin-top:var(--space-lg);text-align:center;">
                <div id="security-setup-step-label" style="font-size:13px;color:var(--accent-primary);margin-bottom:var(--space-sm);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Stap 1 — Kies je PIN</div>

                <!-- PIN dots -->
                <div class="security-pin-dots" id="security-pin-dots" style="display:flex;justify-content:center;gap:16px;margin-bottom:var(--space-lg);">
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                </div>

                <div id="security-pin-error" style="color:#ef4444;font-size:14px;min-height:20px;margin-bottom:var(--space-sm);"></div>

                <!-- Numeric keypad -->
                <div id="security-pin-keypad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:280px;margin:0 auto;">
                    <button type="button" class="security-pin-key" data-digit="1" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">1</button>
                    <button type="button" class="security-pin-key" data-digit="2" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">2</button>
                    <button type="button" class="security-pin-key" data-digit="3" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">3</button>
                    <button type="button" class="security-pin-key" data-digit="4" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">4</button>
                    <button type="button" class="security-pin-key" data-digit="5" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">5</button>
                    <button type="button" class="security-pin-key" data-digit="6" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">6</button>
                    <button type="button" class="security-pin-key" data-digit="7" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">7</button>
                    <button type="button" class="security-pin-key" data-digit="8" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">8</button>
                    <button type="button" class="security-pin-key" data-digit="9" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">9</button>
                    <div style="width:72px;height:72px;"></div>
                    <button type="button" class="security-pin-key" data-digit="0" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">0</button>
                    <button type="button" class="security-pin-key" data-digit="backspace" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-secondary);font-size:20px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">⌫</button>
                </div>

                <button type="button" id="security-pin-cancel" class="btn btn-secondary" style="margin-top:var(--space-md);">Annuleren</button>
            </div>

            <!-- Inline PIN disable (hidden by default) -->
            <div id="security-pin-disable" style="display:none;margin-top:var(--space-lg);text-align:center;">
                <p style="font-size:14px;color:var(--text-secondary);margin-bottom:var(--space-md);">Voer je huidige PIN in om de PIN-code uit te schakelen.</p>

                <div class="security-disable-dots" id="security-disable-dots" style="display:flex;justify-content:center;gap:16px;margin-bottom:var(--space-lg);">
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid var(--text-secondary);background:transparent;transition:all 0.2s ease;"></div>
                </div>

                <div id="security-disable-error" style="color:#ef4444;font-size:14px;min-height:20px;margin-bottom:var(--space-sm);"></div>

                <!-- Numeric keypad -->
                <div id="security-disable-keypad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:280px;margin:0 auto;">
                    <button type="button" class="security-disable-key" data-digit="1" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">1</button>
                    <button type="button" class="security-disable-key" data-digit="2" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">2</button>
                    <button type="button" class="security-disable-key" data-digit="3" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">3</button>
                    <button type="button" class="security-disable-key" data-digit="4" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">4</button>
                    <button type="button" class="security-disable-key" data-digit="5" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">5</button>
                    <button type="button" class="security-disable-key" data-digit="6" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">6</button>
                    <button type="button" class="security-disable-key" data-digit="7" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">7</button>
                    <button type="button" class="security-disable-key" data-digit="8" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">8</button>
                    <button type="button" class="security-disable-key" data-digit="9" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">9</button>
                    <div style="width:72px;height:72px;"></div>
                    <button type="button" class="security-disable-key" data-digit="0" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-primary);font-size:28px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">0</button>
                    <button type="button" class="security-disable-key" data-digit="backspace" style="width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:var(--text-secondary);font-size:20px;font-weight:500;display:flex;align-items:center;justify-content:center;cursor:pointer;justify-self:center;-webkit-tap-highlight-color:transparent;user-select:none;">⌫</button>
                </div>

                <button type="button" id="security-disable-cancel" class="btn btn-secondary" style="margin-top:var(--space-md);">Annuleren</button>
            </div>
        </div>

        <hr class="profile-divider">

        <!-- ── Notifications (VERPLICHT — altijd aan) ── -->
        <div class="profile-section">
            <h3>🔔 Notificaties</h3>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: var(--space-md);">
                Notificaties staan altijd aan zodat je nooit een bericht mist van <?= sanitize($tenant['name'] ?? APP_NAME) ?>.
            </p>
            <div style="display:flex;align-items:center;gap:12px;padding:var(--space-md);border-radius:12px;border:1px solid rgba(76,175,80,0.3);background:rgba(76,175,80,0.06);">
                <span style="font-size:24px;">🔔</span>
                <div>
                    <strong style="color:#4CAF50;">Altijd ingeschakeld</strong>
                    <p style="color:var(--text-secondary);font-size:13px;margin-top:2px;">Je ontvangt push berichten over saldo, betalingen en aanbiedingen</p>
                </div>
            </div>
            <p id="push-denied-hint" style="display:none;color:#F44336;font-size:13px;margin-top:var(--space-sm);">
                Notificaties zijn geblokkeerd in je apparaatinstellingen. Open je browser- of apparaatinstellingen om notificaties toe te staan.
            </p>
        </div>
    </div>

    <a href="<?= BASE_URL ?>/dashboard" class="btn btn-secondary">Terug naar Dashboard</a>
</div>

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script src="<?= BASE_URL ?>/public/js/push.js"></script>
<script>
(function() {
    // Push notificaties zijn VERPLICHT — geen toggle, alleen denied-hint
    setTimeout(function() {
        var deniedHint = document.getElementById('push-denied-hint');

        // Als browser notificaties heeft geblokkeerd → toon hint
        if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
            if (deniedHint) deniedHint.style.display = 'block';
        }
    }, 500);
})();

// ============================================
// APP BEVEILIGING (PIN en FaceID onafhankelijk)
// ============================================
(function() {
    var BASE = window.__BASE_URL || '';

    // Elements
    var pinToggle = document.getElementById('security-pin-toggle');
    var pinIcon = document.getElementById('security-pin-icon');
    var pinDesc = document.getElementById('security-pin-desc');
    var pinSetup = document.getElementById('security-pin-setup');
    var pinDisable = document.getElementById('security-pin-disable');
    var pinCancel = document.getElementById('security-pin-cancel');
    var disableCancel = document.getElementById('security-disable-cancel');
    var webauthnRow = document.getElementById('security-webauthn-row');
    var webauthnToggle = document.getElementById('security-webauthn-toggle');
    var webauthnIcon = document.getElementById('security-webauthn-icon');
    var webauthnDesc = document.getElementById('security-webauthn-desc');
    var webauthnError = document.getElementById('security-webauthn-error');

    // State
    var pinEnabled = false;
    var setupStep = 1;
    var firstPin = '';
    var currentEntry = '';
    var disableEntry = '';

    try { pinEnabled = !!localStorage.getItem('regulr_pin_hash'); } catch(_) {}

    // --- Toast Notification Helper ---
    function showSuccessToast(message) {
        var existing = document.getElementById('profile-success-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'profile-success-toast';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;'
            + 'background:rgba(76,175,80,0.95);border:1px solid rgba(76,175,80,0.8);'
            + 'border-radius:8px;color:#fff;font-size:14px;font-weight:500;'
            + 'min-width:280px;max-width:380px;padding:16px 20px;'
            + 'box-shadow:0 8px 24px rgba(0,0,0,0.3);'
            + 'backdrop-filter:blur(10px);'
            + 'transform:translateX(120%);transition:transform 0.3s ease;';

        toast.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
            + '<span style="font-size:20px;">✅</span>'
            + '<span>' + message + '</span>'
            + '</div>';

        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.style.transform = 'translateX(0)';
            });
        });

        setTimeout(function() {
            toast.style.transform = 'translateX(120%)';
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
        }, 3000);
    }


    // --- PIN Hash (SHA-256) ---
    async function hashPin(pin) {
        if (window.crypto && crypto.subtle) {
            try {
                var encoder = new TextEncoder();
                var data = encoder.encode(pin + '__regulr_salt__');
                var hashBuffer = await crypto.subtle.digest('SHA-256', data);
                return Array.from(new Uint8Array(hashBuffer))
                    .map(function(b) { return b.toString(16).padStart(2, '0'); })
                    .join('');
            } catch (e) {}
        }
        var hash = 0;
        var str = pin + '__regulr_salt__';
        for (var i = 0; i < str.length; i++) {
            hash = ((hash << 5) - hash) + str.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash).toString(16).padStart(8, '0') +
               Math.abs(hash * 31).toString(16).padStart(8, '0');
    }

    // --- Update PIN UI (onafhankelijk van FaceID) ---
    function updatePinState() {
    try { pinEnabled = !!localStorage.getItem('regulr_pin_hash'); } catch(_) {}

    // PWA hint: toon FaceID waarschuwing als NIET in standalone/PWA mode
    (function() {
        var isStandalone = false;
        try { isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; } catch(_) {}
        if (!isStandalone) {
            var pwaHint = document.getElementById('security-webauthn-pwa-hint');
            if (pwaHint) pwaHint.style.display = 'block';
        }
    })();
        if (pinEnabled) {
            pinIcon.textContent = '✅';
            pinDesc.textContent = 'Ingeschakeld';
            pinToggle.textContent = 'Uitschakelen';
            pinToggle.className = 'btn btn-sm btn-secondary';
        } else {
            pinIcon.textContent = '🔢';
            pinDesc.textContent = 'Niet ingesteld';
            pinToggle.textContent = 'Inschakelen';
            pinToggle.className = 'btn btn-sm btn-primary';
        }
    }

    // --- Update FaceID UI (onafhankelijk van PIN) ---
    function updateWebauthnState() {
        var enabled = false;
        try { enabled = localStorage.getItem('regulr_webauthn_enabled') === '1'; } catch(_) {}
        if (enabled) {
            webauthnIcon.textContent = '✅';
            webauthnDesc.textContent = 'Ingeschakeld';
            webauthnToggle.textContent = 'Uitschakelen';
            webauthnToggle.className = 'btn btn-sm btn-secondary';
        } else {
            webauthnIcon.textContent = '👤';
            webauthnDesc.textContent = 'Niet ingeschakeld';
            webauthnToggle.textContent = 'Inschakelen';
            webauthnToggle.className = 'btn btn-sm btn-primary';
        }
    }

    // --- Update dots ---
    function updateDots(containerId, value) {
        var dots = document.querySelectorAll('#' + containerId + ' > div');
        dots.forEach(function(dot, i) {
            dot.style.background = 'transparent';
            dot.style.borderColor = 'var(--text-secondary)';
            dot.style.transform = 'scale(1)';
            if (i < value.length) {
                dot.style.background = 'var(--accent-primary)';
                dot.style.borderColor = 'var(--accent-primary)';
                dot.style.transform = 'scale(1.15)';
            }
        });
    }

    // --- PIN Toggle ---
    if (pinToggle) {
        pinToggle.addEventListener('click', function() {
            if (pinEnabled) {
                // Show disable panel
                pinSetup.style.display = 'none';
                pinDisable.style.display = 'block';
                disableEntry = '';
                updateDots('security-disable-dots', '');
                document.getElementById('security-disable-error').textContent = '';
            } else {
                // Show setup panel
                pinDisable.style.display = 'none';
                pinSetup.style.display = 'block';
                setupStep = 1;
                firstPin = '';
                currentEntry = '';
                updateDots('security-pin-dots', '');
                document.getElementById('security-pin-error').textContent = '';
                document.getElementById('security-setup-step-label').textContent = 'Stap 1 — Kies je PIN';
            }
        });
    }

    // --- Cancel buttons ---
    if (pinCancel) pinCancel.addEventListener('click', function() { pinSetup.style.display = 'none'; });
    if (disableCancel) disableCancel.addEventListener('click', function() { pinDisable.style.display = 'none'; });

    // --- Setup keypad ---
    var setupKeypad = document.getElementById('security-pin-keypad');
    if (setupKeypad) {
        setupKeypad.addEventListener('click', function(e) {
            var btn = e.target.closest('.security-pin-key');
            if (!btn) return;
            var digit = btn.dataset.digit;

            if (digit === 'backspace') {
                currentEntry = currentEntry.slice(0, -1);
                updateDots('security-pin-dots', currentEntry);
                return;
            }
            if (currentEntry.length >= 4) return;

            currentEntry += digit;
            updateDots('security-pin-dots', currentEntry);

            if (currentEntry.length === 4) {
                processSetupPin();
            }
        });
    }

    async function processSetupPin() {
        if (setupStep === 1) {
            firstPin = currentEntry;
            currentEntry = '';
            setupStep = 2;
            document.getElementById('security-setup-step-label').textContent = 'Stap 2 — Bevestig je PIN';
            updateDots('security-pin-dots', '');
        } else {
            if (currentEntry === firstPin) {
                var hash = await hashPin(currentEntry);
                try {
                    localStorage.setItem('regulr_pin_hash', hash);
                    localStorage.setItem('regulr_pin_set_at', new Date().toISOString());
                } catch (_) {
                    document.getElementById('security-pin-error').textContent = 'Kon PIN niet opslaan';
                    return;
                }
                // Success
                pinSetup.style.display = 'none';
                updatePinState();
                showSuccessToast('PIN-code succesvol ingeschakeld!');
            } else {
                document.getElementById('security-pin-error').textContent = 'PIN komt niet overeen. Opnieuw.';
                setupStep = 1;
                firstPin = '';
                currentEntry = '';
                setTimeout(function() {
                    updateDots('security-pin-dots', '');
                    document.getElementById('security-setup-step-label').textContent = 'Stap 1 — Kies je PIN';
                }, 600);
            }
        }
    }

    // --- Disable keypad ---
    var disableKeypad = document.getElementById('security-disable-keypad');
    if (disableKeypad) {
        disableKeypad.addEventListener('click', function(e) {
            var btn = e.target.closest('.security-disable-key');
            if (!btn) return;
            var digit = btn.dataset.digit;

            if (digit === 'backspace') {
                disableEntry = disableEntry.slice(0, -1);
                updateDots('security-disable-dots', disableEntry);
                return;
            }
            if (disableEntry.length >= 4) return;

            disableEntry += digit;
            updateDots('security-disable-dots', disableEntry);

            if (disableEntry.length === 4) {
                processDisablePin();
            }
        });
    }

    async function processDisablePin() {
        var hash = await hashPin(disableEntry);
        var stored = null;
        try { stored = localStorage.getItem('regulr_pin_hash'); } catch(_) {}

        if (hash === stored) {
            // Alleen PIN verwijderen — FaceID blijft ongemoeid
            try {
                localStorage.removeItem('regulr_pin_hash');
                localStorage.removeItem('regulr_pin_set_at');
                sessionStorage.removeItem('regulr_pin_hash');  // Also clear sessionStorage backup
            } catch(_) {}
            pinDisable.style.display = 'none';
            updatePinState();
            showSuccessToast('PIN-code uitgeschakeld');
            
            // Check if Face ID is also disabled — if so, reload to deactivate lock
            var hasWebAuthn = false;
            try { hasWebAuthn = localStorage.getItem('regulr_webauthn_enabled') === '1'; } catch(_) {}
            if (!hasWebAuthn) {
                setTimeout(function() { window.location.reload(); }, 500);
            }
        } else {
            document.getElementById('security-disable-error').textContent = 'Onjuiste PIN';
            disableEntry = '';
            setTimeout(function() { updateDots('security-disable-dots', ''); }, 400);
        }
    }

    // --- WebAuthn toggle (onafhankelijk van PIN) ---
    if (webauthnToggle) {
        webauthnToggle.addEventListener('click', async function() {
            var enabled = false;
            try { enabled = localStorage.getItem('regulr_webauthn_enabled') === '1'; } catch(_) {}

            if (enabled) {
                try { 
                    localStorage.removeItem('regulr_webauthn_enabled');
                    sessionStorage.removeItem('regulr_webauthn_enabled');  // Also clear sessionStorage backup
                } catch(_) {}
                updateWebauthnState();
                showSuccessToast('Face ID uitgeschakeld');
                
                // Check if PIN is also disabled — if so, reload to deactivate lock
                var hasPin = false;
                try { hasPin = !!localStorage.getItem('regulr_pin_hash'); } catch(_) {}
                if (!hasPin) {
                    setTimeout(function() { window.location.reload(); }, 500);
                }
                return;
            }

            // Enable — WebAuthn registration
            webauthnToggle.disabled = true;
            webauthnToggle.textContent = '⏳ Bezig...';
            webauthnError.style.display = 'none';

            try {
                var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                var csrfToken = csrfMeta ? csrfMeta.content : '';

                function b64urlToAB(b64url) {
                    var b64 = b64url.replace(/-/g,'+').replace(/_/g,'/');
                    while (b64.length % 4 !== 0) b64 += '=';
                    var bin = atob(b64);
                    var bytes = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                    return bytes.buffer;
                }
                function abToB64url(buf) {
                    var bytes = new Uint8Array(buf);
                    var bin = '';
                    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
                    return btoa(bin).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
                }

                var optionsResp = await fetch(BASE + '/api/auth/webauthn/register-options', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
                });
                var optionsData = await optionsResp.json();
                if (!optionsData.success) throw new Error(optionsData.error || 'Failed');

                // Als al geregistreerd, verifieer via authenticate flow (toont FaceID prompt)
                if (optionsData.data && optionsData.data.already_registered) {
                    webauthnToggle.textContent = '⏳ Verifiëren met FaceID...';
                    webauthnError.style.display = 'none';
                    try {
                        var authResp = await fetch(BASE + '/api/auth/webauthn/authenticate-options', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
                        });
                        var authData = await authResp.json();
                        console.log('[Profile] auth-options response:', authData);
                        if (!authData.success) throw new Error(authData.error || 'Kon verificatie niet starten');
                        
                        var pubKey = {
                            challenge: b64urlToAB(authData.data.challenge),
                            rpId: authData.data.rpId,
                            allowCredentials: (authData.data.allowCredentials || []).map(function(c) {
                                return { type: c.type, id: b64urlToAB(c.id), transports: c.transports || ['internal'] };
                            }),
                            timeout: authData.data.timeout || 60000,
                            userVerification: authData.data.userVerification || 'required'
                        };
                        console.log('[Profile] Starting biometric prompt...');
                        var credential = await navigator.credentials.get({ publicKey: pubKey });
                        console.log('[Profile] Biometric success, credential:', credential ? 'OK' : 'null');
                        
                        // FaceID werkte! — zet flag aan
                        try { localStorage.setItem('regulr_webauthn_enabled', '1'); } catch(_) {}
                        updateWebauthnState();
                        showSuccessToast('FaceID succesvol ingeschakeld!');
                        // Reload zodat app-lock module activeert
                        setTimeout(function() { window.location.reload(); }, 500);
                    } catch (e) {
                        console.warn('FaceID verification cancelled/failed:', e.message);
                        webauthnError.textContent = e.message || 'FaceID verificatie mislukt of geannuleerd';
                        webauthnError.style.display = 'block';
                        updateWebauthnState();
                    }
                    return;
                }

                var options = optionsData.data;
                var publicKey = {
                    challenge: b64urlToAB(options.challenge),
                    rp: options.rp,
                    user: { name: options.user.name, displayName: options.user.displayName, id: b64urlToAB(options.user.id) },
                    pubKeyCredParams: options.pubKeyCredParams,
                    timeout: options.timeout || 60000,
                    excludeCredentials: (options.excludeCredentials || []).map(function(c) {
                        return { type: c.type, id: b64urlToAB(c.id), transports: c.transports || ['internal'] };
                    }),
                    authenticatorSelection: options.authenticatorSelection || { authenticatorAttachment: 'platform', userVerification: 'required' }
                };

                var credential = await navigator.credentials.create({ publicKey: publicKey });

                var responseBody = {
                    id: credential.id,
                    rawId: abToB64url(credential.rawId),
                    response: {
                        clientDataJSON: abToB64url(credential.response.clientDataJSON),
                        attestationObject: abToB64url(credential.response.attestationObject)
                    },
                    type: credential.type
                };

                var verifyResp = await fetch(BASE + '/api/auth/webauthn/register', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(responseBody)
                });
                var result = await verifyResp.json();
                console.log('[Profile] register response:', result);
                if (!result.success) throw new Error(result.error || 'Registration failed');

                try { localStorage.setItem('regulr_webauthn_enabled', '1'); } catch(_) {}
                updateWebauthnState();
                showSuccessToast('FaceID succesvol ingeschakeld!');
                // Reload zodat app-lock module activeert
                setTimeout(function() { window.location.reload(); }, 500);
            } catch (e) {
                console.warn('WebAuthn registration failed:', e.message);
                webauthnError.textContent = e.message || 'FaceID kon niet worden ingeschakeld';
                webauthnError.style.display = 'block';
                updateWebauthnState();
            } finally {
                webauthnToggle.disabled = false;
            }
        });
    }

    // --- Initial state: beide onafhankelijk initialiseren ---
    updatePinState();

    // FaceID rij altijd tonen als WebAuthn beschikbaar (onafhankelijk van PIN)
    if (webauthnRow) {
        if (window.isSecureContext && window.PublicKeyCredential) {
            webauthnRow.style.display = 'block';
        } else {
            webauthnRow.style.display = 'none';
        }
    }
    updateWebauthnState();
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
