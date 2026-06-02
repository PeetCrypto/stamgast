-- ============================================================
-- Guest Already Registered Email Template + ENUM Update
-- Date: 2026-06-02
-- Description: Adds 'guest_already_registered' to the email_templates
--   type ENUM and inserts the default email template.
--   This template is sent when someone tries to register with an
--   email that already exists for this tenant.
-- ============================================================

-- 1. Add new type to the ENUM (idempotent — safe to rerun)
ALTER TABLE `email_templates`
    MODIFY COLUMN `type` ENUM(
        'tenant_welcome',
        'admin_invite',
        'bartender_invite',
        'guest_confirmation',
        'guest_password_reset',
        'guest_already_registered',
        'marketing'
    ) NOT NULL;

-- 2. Insert default template (UPSERT pattern)
--    Global default (tenant_id IS NULL, language = nl)
INSERT INTO `email_templates`
    (`type`, `subject`, `content`, `text_content`, `tenant_id`, `language_code`, `is_default`)
VALUES (
    'guest_already_registered',
    '{{tenant_name}} — Je hebt al een account',
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:20px;background:#0f0f1a;color:#e0e0e0;font-family:Arial,Helvetica,sans-serif;"><div style="max-width:600px;margin:0 auto;background:#1a1a2e;border-radius:12px;overflow:hidden;"><div style="background:#FFC107;padding:20px;text-align:center;"><h1 style="margin:0;color:#000;font-size:24px;">{{tenant_name}}</h1></div><div style="padding:30px;"><h2 style="color:#FFC107;margin-top:0;">Je hebt al een account</h2><p>Hallo {{guest_name}},</p><p>Je probeerde je opnieuw te registreren bij <strong>{{tenant_name}}</strong>, maar je hebt al een account.</p><p>Geen zorgen — je account bestaat nog steeds. Log gewoon in met je bestaande gegevens.</p><div style="text-align:center;margin:30px 0;"><a href="{{login_url}}" style="background:#FFC107;color:#000;padding:12px 30px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;display:inline-block;">Inloggen</a></div><p style="color:#888;font-size:14px;">Wachtwoord vergeten? <a href="{{forgot_password_url}}" style="color:#FFC107;text-decoration:underline;">Klik hier om je wachtwoord te herstellen</a>.</p><hr style="border:none;border-top:1px solid #2a2a4a;margin:25px 0;"><p style="color:#888;font-size:13px;margin:0;">Met vriendelijke groet,<br><strong style="color:#e0e0e0;">{{tenant_name}}</strong></p></div></div></body></html>',
    "Je hebt al een account — {{tenant_name}}\n\nHallo {{guest_name}},\n\nJe probeerde je opnieuw te registreren bij {{tenant_name}}, maar je hebt al een account.\n\nGeen zorgen — je account bestaat nog steeds. Log gewoon in met je bestaande gegevens.\n\nInloggen: {{login_url}}\n\nWachtwoord vergeten? Klik hier om je wachtwoord te herstellen:\n{{forgot_password_url}}\n\nMet vriendelijke groet,\n{{tenant_name}}",
    NULL,
    'nl',
    1
)
ON DUPLICATE KEY UPDATE
    `subject`      = VALUES(`subject`),
    `content`      = VALUES(`content`),
    `text_content` = VALUES(`text_content`);
