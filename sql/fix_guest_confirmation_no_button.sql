-- ============================================================
-- Fix: Remove "E-mail bevestiging" button from guest_confirmation
-- Date: 2026-06-29
-- Description: Forces ALL guest_confirmation templates (global + tenant-
--   specific) to the version WITHOUT the "E-mail bevestigation" button.
--   The button links to a verification page that does not work in the PWA
--   (PWA cannot handle the deep link properly). Guests enter the code
--   manually at the bar instead.
-- ============================================================

UPDATE `email_templates`
SET
    `subject` = 'Welkom bij {{tenant_name}} — Verifieer je e-mail',
    `content` = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:20px;background:#0f0f1a;color:#e0e0e0;font-family:Arial,Helvetica,sans-serif;"><div style="max-width:600px;margin:0 auto;background:#1a1a2e;border-radius:12px;overflow:hidden;"><div style="background:#FFC107;padding:20px;text-align:center;"><h1 style="margin:0;color:#000;font-size:24px;">{{tenant_name}}</h1></div><div style="padding:30px;"><h2 style="color:#FFC107;margin-top:0;">Welkom, {{guest_name}}!</h2><p>Bedankt voor je registratie bij <strong>{{tenant_name}}</strong>.</p><p>Om je account te activeren, moet je je e-mailadres verifiëren. Daarvoor heb je de volgende code nodig:</p><div style="background:#16213e;border:2px dashed #FFC107;border-radius:8px;padding:20px;text-align:center;margin:20px 0;"><span style="font-size:32px;font-weight:bold;color:#FFC107;letter-spacing:4px;">{{verification_code}}</span></div><p style="color:#888;font-size:14px;margin-top:20px;">Deze code is 10 minuten geldig.</p><p style="color:#888;font-size:14px;">Deze code is persoonlijk. Deel hem niet met anderen.</p><hr style="border:none;border-top:1px solid #2a2a4a;margin:25px 0;"><p style="color:#888;font-size:13px;margin:0;">Met vriendelijke groet,<br><strong style="color:#e0e0e0;">{{tenant_name}}</strong></p></div></div></body></html>',
    `text_content` = "Welkom bij {{tenant_name}}!\n\nBeste {{guest_name}},\n\nBedankt voor je registratie.\n\nOm je account te activeren, moet je je e-mailadres verifiëren. Daarvoor heb je de volgende code nodig:\n\n{{verification_code}}\n\nDeze code is 10 minuten geldig.\n\nDeze code is persoonlijk. Deel hem niet met anderen.\n\nMet vriendelijke groet,\n{{tenant_name}}"
WHERE `type` = 'guest_confirmation';
