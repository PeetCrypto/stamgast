<?php
/**
 * RESET ALL USER PASSWORDS
 * 
 * Dit script zet alle gebruikerswachtwoorden naar bekende test-waarden.
 * Gebruik ARGON2ID met pepper (zoals de app verwacht).
 * 
 * Run: http://localhost/stamgast/reset_all_passwords.php
 * 
 * NA GEBRUIK: VERWIJDER DIT BESTAND VAN DE SERVER!
 */

define('APP_PEPPER', 'change-this-to-a-random-string-in-production-32chars-min');

$host = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbname = 'stamgast_db';

echo "<pre style='background:#111;color:#0f0;padding:24px;font-family:monospace;font-size:14px;line-height:1.6'>";
echo "=== STAMGAST - RESET ALL PASSWORDS ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[OK] Database verbinding: $dbname\n\n";
} catch (Exception $e) {
    die("[FOUT] Kan niet verbinden: " . $e->getMessage() . "\n</pre>");
}

// Definieer alle logins
$logins = [
    ['email' => 'admin@stamgast.nl',    'password' => 'Admin123!',   'role' => 'superadmin',  'label' => 'Super-Admin'],
    ['email' => 'manager@test.nl',       'password' => 'Manager123!', 'role' => 'admin',       'label' => 'Admin (Manager)'],
    ['email' => 'bartender@test.nl',     'password' => 'Bartend3r!',  'role' => 'bartender',   'label' => 'Bartender'],
    ['email' => 'guest@test.nl',         'password' => 'Guest123!',   'role' => 'guest',        'label' => 'Gast'],
];

echo "--- Wachtwoorden resetten ---\n\n";

foreach ($logins as $login) {
    $pepperedPassword = $login['password'] . APP_PEPPER;
    $hash = password_hash($pepperedPassword, PASSWORD_ARGON2ID);

    if ($hash === false) {
        echo "[FOUT] Hash mislukt voor " . $login['email'] . "\n";
        continue;
    }

    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE email = :email");
    $stmt->execute([':hash' => $hash, ':email' => $login['email']]);
    $rows = $stmt->rowCount();

    if ($rows > 0) {
        echo "[OK] " . sprintf("%-20s", $login['label']) . " | " . sprintf("%-25s", $login['email']) . " | wachtwoord bijgewerkt\n";
    } else {
        echo "[??] " . sprintf("%-20s", $login['label']) . " | " . sprintf("%-25s", $login['email']) . " | GEBRUIKER NIET GEVONDEN\n";
    }
}

// Verificatie
echo "\n--- Verificatie ---\n\n";

foreach ($logins as $login) {
    $stmt = $pdo->prepare("SELECT password_hash, role, first_name, last_name FROM users WHERE email = :email");
    $stmt->execute([':email' => $login['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "[FOUT] " . $login['email'] . " niet gevonden in database!\n";
        continue;
    }

    $pepperedPassword = $login['password'] . APP_PEPPER;
    $verified = password_verify($pepperedPassword, $user['password_hash']);

    if ($verified) {
        echo "[OK] GEVERIFIEERD  | " . sprintf("%-25s", $login['email']) . " | " . sprintf("%-12s", $user['role']) . " | " . $user['first_name'] . " " . $user['last_name'] . "\n";
    } else {
        echo "[XX] MISLUKT       | " . sprintf("%-25s", $login['email']) . "\n";
    }
}

echo "\n";
echo "=== LOGIN OVERZICHT ===\n";
echo str_repeat("-", 72) . "\n";
echo sprintf("%-15s %-25s %-15s\n", "ROL", "E-MAIL", "WACHTWOORD");
echo str_repeat("-", 72) . "\n";

foreach ($logins as $login) {
    echo sprintf("%-15s %-25s %-15s\n", $login['label'], $login['email'], $login['password']);
}

echo str_repeat("-", 72) . "\n";
echo "\nLogin pagina: http://localhost/stamgast/login\n";
echo "\nLET OP: VERWIJDER reset_all_passwords.php NA GEBRUIK!\n";

echo "</pre>";
