# Hostinger Deployment Checklist

**Project:** REGULR.vip Loyalty Platform  
**Doel:** Deploy naar `app.regulr.vip` op Hostinger Shared Hosting

---

## Fase 1: Hostinger Server Setup (hPanel)

- [ ] **PHP instellen** → hPanel → PHP → Selecteer PHP 8.1 of 8.2
- [ ] **Database aanmaken** → hPanel → Databases → MySQL Databases
  - Database naam: `u1234567_stamgast` (voorbeeld)
  - User aanmaken met sterk wachtwoord
  - User koppelen aan database met ALL PRIVILEGES
- [ ] **Subdomain aanmaken** → hPanel → Domains → Subdomains
  - Subdomain: `app`
  - Document root: `public_html/app`
- [ ] **SSL activeren** → hPanel → SSL → Selecteer `app.regulr.vip` → Activeer gratis SSL

---

## Fase 2: Database Migraties (phpMyAdmin)

Open phpMyAdmin via hPanel → Databases → phpMyAdmin

Voer **in deze volgorde** uit:

1. `sql/schema.sql` — Basis schema (core tabellen)
2. `sql/email_system_migration.sql` — Email tabellen
3. `sql/platform_settings_migration.sql` — Platform settings
4. `sql/platform_fee_migration.sql` — Platform fees + facturatie
5. `sql/package_tiers_migration.sql` — Package tiers
6. `sql/gated_onboarding_migration.sql` — account_status + verificatie
7. `sql/notifications_migration.sql` — Notifications
8. `sql/verification_toggle_migration.sql` — verification_required toggle
9. `sql/add_created_at_column.sql` — Transactions created_at

---

## Fase 3: Bestanden Uploaden

**Upload naar:** `public_html/app/`

**DOELIJST:**
```
├── index.php
├── .htaccess
├── config/
│   ├── load_env.php
│   ├── app.php
│   ├── database.php
│   ├── cors.php
│   └── email.php
├── models/
├── services/
│   └── Email/
├── middleware/
├── utils/
├── views/
├── api/
└── public/
    ├── css/
    ├── js/
    ├── icons/
    └── uploads/
        ├── logos/
        └── profiles/
```

**NIET uploaden:**
- `.git/` folder
- `.kilo/` folder
- `sql/` folder
- `*.md` bestanden
- `.env` (maak apart op server)

---

## Fase 4: .env Configuratie

Maak via File Manager een nieuw `.env` bestand in `public_html/app/`:

```env
# ===================================================
# REGULR.vip — PRODUCTIE CONFIGURATIE
# ===================================================

APP_ENV=production

# --- Database (Hostinger credentials) ---
DB_HOST=localhost
DB_PORT=3306
DB_NAME=<JOUW_DB_NAAM>
DB_USER=<JOUW_DB_USER>
DB_PASS=<JOUW_DB_WACHTWOORD>

# --- Security ---
# Genereer met: php -r "echo bin2hex(random_bytes(32));"
APP_PEPPER=<32_CHAR_RANDOM_STRING>

# --- Mollie ---
MOLLIE_MODE_DEFAULT=mock
MOLLIE_CONNECT_API_KEY=
MOLLIE_CONNECT_CLIENT_ID=
MOLLIE_CONNECT_CLIENT_SECRET=

# --- Email (Brevo) ---
BREVO_API_KEY=
ENCRYPTION_KEY=<32_CHAR_RANDOM_STRING>

# --- Platform Fee ---
PLATFORM_FEE_DEFAULT_PERCENTAGE=1.00
PLATFORM_FEE_DEFAULT_MIN_CENTS=25
PLATFORM_FEE_BTW_PERCENTAGE=21.00
```

---

## Fase 5: .htaccess Aanpassen

Op de server: open `.htaccess` en wijzig:

```apache
# VAN:
RewriteBase /

# NAAR:
RewriteBase /app/
```

> **BELANGRIJK:** Deze aanpassing ALLEEN op de server. Lokaal blijft `/`

---

## Fase 6: Superadmin Aanmaken

1. Maak tijdelijk `setup_admin.php` (zie deployment_plan.md §8.1)
2. Upload naar `public_html/app/setup_admin.php`
3. Open: `https://app.regulr.vip/setup_admin.php`
4. **VERWIJDER** direct na gebruik!

---

## Fase 7: Deploy Script Draaien

Open in browser: `https://app.regulr.vip/deploy.php`

Het script controleert:
- PHP versie en extensions
- .env bestand
- Database connectie
- SQL migraties
- Superadmin account
- Beveiligingschecks
- App functionaliteit

---

## Fase 8: Post-Deploy Verificatie

- [ ] `https://app.regulr.vip` opent login pagina
- [ ] SSL groen slotje zichtbaar
- [ ] Nieuw account kunnen registreren
- [ ] Dashboard laadt
- [ ] `.env` niet bereikbaar (403 Forbidden)
- [ ] `config/` niet bereikbaar (403 Forbidden)
- [ ] `sql/` directory verwijderd van server

---

## Fase 9: Cleanup

- [ ] `sql/` directory verwijderen van server
- [ ] `setup_admin.php` verwijderen van server
- [ ] `deploy.php` verwijderen (wordt automatisch verwijderd na succes)
- [ ] Eerste tenant aanmaken via superadmin dashboard
- [ ] Brevo API key configureren in `.env`
- [ ] Mollie configureren wanneer klaar voor betalingen

---

## Troubleshooting

| Probleem | Oplossing |
|----------|-----------|
| 500 Error | Check `.env` credentials, bekijk PHP error log in hPanel |
| Blank page | Tijdelijk `APP_ENV=development` in `.env` zetten |
| CSS/JS 404 | `RewriteBase` controleren (moet `/app/` zijn) |
| CORS errors | `APP_ENV=production` in `.env` |
| Wachtwoorden werken niet | Nieuwe accounts aanmaken op productie (pepper is anders) |