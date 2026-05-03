<?php
/**
 * Migration: Add bartender_invite to email_templates ENUM
 * Also inserts a default bartender_invite template
 */
require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Adding bartender_invite to email_templates ENUM ===" . PHP_EOL;

// 1. Alter the ENUM to include bartender_invite
try {
    $db->exec("ALTER TABLE email_templates 
        MODIFY COLUMN `type` ENUM(
            'tenant_welcome',
            'admin_invite',
            'bartender_invite',
            'guest_confirmation',
            'guest_password_reset',
            'marketing'
        ) NOT NULL");
    echo "[OK] ENUM altered successfully" . PHP_EOL;
} catch (Throwable $e) {
    echo "[ERROR] ENUM alter failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 2. Insert default bartender_invite template (NL)
try {
    $stmt = $db->prepare("SELECT id FROM email_templates WHERE type = 'bartender_invite' AND tenant_id IS NULL AND language_code = 'nl'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo "[SKIP] bartender_invite NL template already exists" . PHP_EOL;
    } else {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0f0f1a;font-family:Arial,sans-serif;color:#e0e0e0;">
<div style="max-width:600px;margin:0 auto;padding:40px 20px;">
  <div style="text-align:center;margin-bottom:30px;">
    <h1 style="color:#FFC107;margin:0;font-size:28px;">REGULR.vip</h1>
    <p style="color:#888;margin:5px 0 0;">Loyalty Platform</p>
  </div>
  <div style="background:#1a1a2e;border-radius:12px;padding:30px;border:1px solid rgba(255,255,255,0.1);">
    <h2 style="color:#FFC107;margin-top:0;">Uitnodiging als Bartender</h2>
    <p>Beste {{user_name}},</p>
    <p>Je bent uitgenodigd om bartender te worden bij <strong>{{tenant_name}}</strong> op het REGULR.vip platform.</p>
    <p>Met je bartender account kun je:</p>
    <ul>
      <li>QR codes scannen van gasten</li>
      <li>Betalingen verwerken aan de bar</li>
      <li>Gasten verificeren (KYC-light)</li>
    </ul>
    <div style="text-align:center;margin:30px 0;">
      <a href="{{invitation_link}}" style="background:#FFC107;color:#000;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">Accepteer Uitnodiging</a>
    </div>
    <p style="font-size:14px;color:#888;">Of kopieer deze link naar je browser:<br>
    <code style="word-break:break-all;color:#FFC107;">{{invitation_link}}</code></p>
    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:25px 0;">
    <p style="font-size:13px;color:#666;">Je inloggegevens:<br>
    E-mail: <strong>{{user_email}}</strong><br>
    Wachtwoord: <strong>{{user_password}}</strong></p>
    <p style="font-size:12px;color:#555;">Verander je wachtwoord na het eerste inloggen.</p>
  </div>
  <p style="text-align:center;font-size:12px;color:#555;margin-top:20px;">
    &copy; REGULR.vip — Dit is een automatische e-mail.
  </p>
</div>
</body>
</html>
HTML;

        $text = "Uitnodiging als Bartender bij {{tenant_name}}\n\n"
              . "Beste {{user_name}},\n\n"
              . "Je bent uitgenodigd om bartender te worden bij {{tenant_name}}.\n\n"
              . "Accepteer je uitnodiging:\n{{invitation_link}}\n\n"
              . "Inloggegevens:\nE-mail: {{user_email}}\nWachtwoord: {{user_password}}\n\n"
              . "Verander je wachtwoord na het eerste inloggen.";

        $stmt = $db->prepare("INSERT INTO email_templates (type, subject, content, text_content, tenant_id, language_code, is_default)
            VALUES ('bartender_invite', :subject, :content, :text_content, NULL, 'nl', 1)");
        $stmt->execute([
            ':subject' => 'Uitnodiging: Bartender toegang voor {{tenant_name}}',
            ':content' => $html,
            ':text_content' => $text,
        ]);
        echo "[OK] Default bartender_invite NL template inserted (ID: " . $db->lastInsertId() . ")" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "[ERROR] Template insert failed: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Migration complete ===" . PHP_EOL;
