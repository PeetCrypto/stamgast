# STAMGAST LOYALTY PLATFORM - THE MASTER BLUEPRINT (v8)

This document is the definitive, exhaustive Technical Specification and UI/UX Design Guide for the Stamgast Loyalty Platform. It has been expanded to cover every granular detail, ensuring the system is "Hufterproof," multi-tenant, and premium.

---

## 1. STRATEGIC VISION & SCALABILITY
### 1.1 Multi-Tenant MVP Context
The system is built as a white-label platform. While running on a single MySQL instance for the MVP, the architecture uses a "Tenant-Aware" layer on every transaction.
- **Data Isolation**: Every table contains a `tenant_id` (INT) and every query is forced through a global filter.
- **Scalability**: The database logic uses PDO with standardized SQL to ensure that migration to PostgreSQL or a distributed cluster (e.g., separate databases per tenant) requires minimal refactoring.
- **Portability**: Optimized for Hostinger shared hosting (PHP 8.2+, Apache .htaccess).

### 1.2 Ironclad Security ("Hufterproof")
- **Balance Integrity**: No client-side balance manipulation. The client is a view-only terminal. All financial logic occurs inside the server's atomic transactions.
- **Anti-Fraud QR**: Cryptographically signed HMAC-SHA256 codes with a 60-second expiration. Screenshots are rendered useless.
- **IP-Geofencing**: Bartender actions are only allowed from whitelisted IP addresses (The establishment's WiFi).
- **Audit Ledger**: Every cent moved is logged with a mandatory audit trail (Who, When, Where, IP, Fingerprint).

Postgres-Ready Migration Layer: De database-architectuur is ontworpen volgens de "Twelve-Factor App" principes. Dit betekent:
Geen database-specifieke "Stored Procedures" of "Triggers" die migratie bemoeilijken.
Gebruik van UUID naast INT voor publieke identifiers, wat essentieel is voor gedistribueerde systemen zoals PostgreSQL.
Voorbereid op Row Level Security (RLS) door consistente tenant_id implementatie.
---

## 2. EXHAUSTIVE DATABASE DICTIONARY

### 2.1 Establishment Management
#### `tenants`
| Field | Type | Attributes | Description |
| :--- | :--- | :--- | :--- |
| `id` | INT | PK, AI | Unique ID. |
| `uuid` | VARCHAR(36) | UNIQUE | Publicly safe ID. |
| `name` | VARCHAR(255) | | Establishment name. |
| `brand_color` | VARCHAR(7) | | Primary HEX color (e.g., #FFC107). |
| `secondary_color` | VARCHAR(7) | | Secondary HEX color. |
| `logo_path` | VARCHAR(255) | | Local path to the establishment logo. |
| `mollie_api_key` | VARCHAR(255) | | Encrypted API key. |
| `mollie_status` | ENUM | | 'mock', 'test', 'live'. |
| `whitelisted_ips` | TEXT | | Line-separated whitelist of Bar WiFi IPs. |
| `feature_push` | BOOLEAN | DEFAULT 1 | Modular toggle. |
| `feature_marketing` | BOOLEAN | DEFAULT 1 | Modular toggle. |
| `created_at` | TIMESTAMP | | |

### 2.2 Identity & Role Management
#### `users`
- `id` (INT PK AI).
- `tenant_id` (INT FK): Isolation bridge.
- `email` (VARCHAR 255): Unique per tenant.
- `password_hash` (VARCHAR 255): Argon2id or Bcrypt.
- `role` (ENUM): `superadmin`, `admin` (establishment owner), `bartender`, `guest`.
- `first_name`, `last_name` (VARCHAR 100).
- `birthdate` (DATE): For age checks.
- `photo_url` (VARCHAR 255): Uploaded profile pic.
- `photo_status` (ENUM): `unvalidated`, `validated`, `blocked`.
- `push_token` (TEXT): For pure Web Push.
- `last_activity` (TIMESTAMP).

### 2.3 Financials & Tiers
#### `wallets`
- `user_id` (PK FK), `tenant_id`.
- `balance_cents` (BIGINT): Stored in cents (1.00 = 100).
- `points_cents` (BIGINT): Loyalty points balance.

#### `loyalty_tiers`
- `id`, `tenant_id`, `name`.
- `min_deposit_cents`: Threshold to reach this tier.
- `alcohol_discount_perc`: (Max 25%).
- `food_discount_perc`: (Max 100%).

### 2.4 The Transaction Ledger
#### `transactions`
- `id` (INT PK AI).
- `tenant_id`, `user_id`, `bartender_id` (FKs).
- `type` (ENUM): `payment`, `deposit`, `bonus`, `correction`.
- `amount_alc_cents`, `amount_food_cents`.
- `discount_alc_cents`, `discount_food_cents`.
- `final_total_cents`.
- `points_earned`, `points_used`.
- `ip_address`, `device_fingerprint`.
- `created_at`.

---

## 3. CORE LOGIC SPECIFICATIONS

### 3.1 The "Smart" Kassa & Legal Engine
The processing logic in `api/pos/process_payment.php` follows a strict legal sequence:
1. **Identify**: Validate QR and tenant association.
2. **Calculate Subtotals**: Separate Alcohol and Food items.
3. **Apply Rules**:
   - Alcohol Discount = `MIN(25%, MAX(ActiveRules, UserTier))`.
   - Food Discount = `MAX(ActiveRules, UserTier)`.
4. **Validation**: Check if `calculated_total == user_submitted_total` (if applicable).
5. **Deduct**: 
   - Check balance. 
   - Perform atomic DB update: `UPDATE wallets` and `INSERT transactions`.
   - Update loyalty points.

### 3.2 Cryptographic QR Handshake
- **Payload**: `user_id | tenant_id | timestamp | nonce`.
- **Signature**: Generated using `hash_hmac('sha256', data, tenant_secret_key)`.
- **Validation**: Server decodes, checks `timestamp > now - 60s`, and recalculates HMAC. A mismatch or timeout results in an immediate 403 error.

---

## 4. UI/UX DESIGN SYSTEM: "MIDNIGHT LOUNGE"

### 4.1 Aesthetic Foundation
- **Visual Style**: High-end luxury, Dark Mode default.
- **Glassmorphism**: 
  - `backdrop-filter: blur(25px)`.
  - Border: `1px solid rgba(255, 255, 255, 0.1)`.
  - Box-shadow: `0 8px 32px 0 rgba(0, 0, 0, 0.37)`.
- **Gradients**:
  - Primary: `linear-gradient(135deg, #FFC107 0%, #FF9800 100%)` (Premium Gold).
  - Background: `linear-gradient(180deg, #0f0f0f 0%, #1a1a1a 100%)`.

### 4.2 Views & Components

#### Guest PWA
- **Dashboard**: "Hoi [Naam]!" greeting + Odometer animation for wallet balance.
- **Wallet Card**: Floating gold-bordered card with "Add Credit" action.
- **QR Box**: Pulsing neon border around the dynamic QR code.
- **Inbox**: Modern notification feed for rewards.

#### Bartender POS (Mobile Optimized)
- **Scanner UI**: Full-screen camera view with a scanning line animation.
- **Outcome Overlay**:
  - User Photo (validated status highlight).
  - Age badge (Green/Red).
  - Quick-amount keypad for rapid entry.

#### Admin & Super-Admin
- **Sidebar**: Sleek, collapsible navigation with acrylic blur.
- **Analytics**: 
  - Revenue charts (Daily/Weekly).
  - "Whale Tracker" (Top customers).
  - Feature toggles (Simple switches for Push/Marketing).

---

## 5. MODULAR FEATURE MODULES

### 5.1 "The Marketing Studio"
Modular system for the owner (if enabled):
- **Segmentation**: Query engine to select groups (e.g., `WHERE last_activity < -30 days`).
- **Composer**: Rich-text for personalized emails (`{{first_name}}`).
- **Queue**: Emails are sent via a cron-driven `email_queue`.

### 5.2 Pure Web Push Notifications
- **Technology**: Native Browser Push (VAPID).
- **Control**: Centralized `api/push/send_notification.php` that checks `tenant.feature_push`.

---

## 6. PWA & ASSET GENERATION
### 6.1 Dynamic Branding & Icons
- **Icon Engine**: `/api/assets/generate_pwa_icon.php`.
  - Takes the tenant logo.
  - Generates a branded square icon.
  - Adds the "**LOYALTY**" text in a matching font underneath.
  - Served via `manifest.json.php`.

### 6.2 Service Worker Strategy
- **Lifecycle**: Install -> Activate -> Fetch.
- **Caching**: Statically cache CSS/JS Shell. Network-first for Wallet balance.

---

## 7. IMPLEMENTATION ROADMAP

### Phase 1: Foundation (The Shell) — ✅ COMPLEET
- [x] DB Schema with `tenant_id` and multi-tenant constraints.
- [x] Super-Admin: Create Tenant tool.
- [x] CSS Midnight Lounge Framework (3 CSS-bestanden + header/footer refactored).
- [x] Dynamisch PWA manifest.

### Phase 2: Security & Identity — ✅ COMPLEET (100%)
- [x] HMAC QR Security Handshake (`services/QrService.php`).
- [x] User model (`models/User.php`).
- [x] AuthService (`services/AuthService.php`).
- [x] Auth API endpoints (login, register, logout, session).
- [x] Login & Register UI views (`views/shared/login.php`, `views/shared/register.php`).
- [ ] Photo upload/validation (deferred naar post-MVP).
- [x] IP Whitelisting system (middleware).
- [x] CSRF protection (middleware).
- [x] Session security (middleware).

### Phase 3: Transactional Engine — ✅ COMPLEET (100%)
- [x] 25% Alcohol constraint logic (`PaymentService.php`).
- [x] Mollie Mock/Live system (`MollieService.php`).
- [x] Transaction Ledger & Atomic updates (`Transaction.php` + `PaymentService.php`).
- [x] Wallet deposit via Mollie (`WalletService.php`).
- [x] QR generate endpoint (`api/qr/generate.php`).
- [x] POS scan endpoint (`api/pos/scan.php`).
- [x] POS payment endpoint (`api/pos/process_payment.php`).
- [x] Wallet deposit/history endpoints (`api/wallet/deposit.php`, `api/wallet/history.php`).
- [x] Mollie webhook handler (`api/mollie/webhook.php`).
- [x] 83/83 tests pass (`test_phase3.php`).

### Phase 4: Marketing & PWA
- [ ] Modular toggles (Push/Marketing).
- [ ] Dynamic PWA Icon generation.
- [ ] Web Push & Birthday Cron.

---

## 8. DETAILED VERIFICATION MATRIX
| Test Case | Scenario | Expected Result |
| :--- | :--- | :--- |
| **TC-01** | Guest scans at different Tenant | **Signature Mismatch / Transaction Denied** |
| **TC-02** | Manual balance update from client | **Impossible / Server ignore** |
| **TC-03** | Alcohol discount > 25% | **Auto-Capped or Error Returned** |
| **TC-04** | Use expired QR (61s old) | **Expired Message / Denied** |
| **TC-05** | Scan from unauthorized IP | **Access Denied (POS Locked)** |
| **TC-06** | Access marketing via hidden link | **Module Disabled check / 403** |

---
---

## 9. IMPLEMENTATIE STATUS & VOORTGANG

> **Laatst bijgewerkt**: 2026-04-19 16:51
> **Huidige status**: Fase 1 + 2 compleet, Fase 3 ~75%, Fase 4 ~30%, Fase 5 ~10%
> **Totaal bestanden met code**: ~50 van ~77 gepland (~65%)

### 9.1 Overzicht per Fase

| Fase | Omschrijving | Voortgang | Status |
| :--- | :--- | :---: | :--- |
| **Fase 1** | Foundation (The Shell) | 100% | ✅ Compleet |
| **Fase 2** | Security & Identity | 100% | ✅ Compleet |
| **Fase 3** | Transactional Engine | 100% | ✅ Compleet (83/83 tests geslaagd) |
| **Fase 4** | Frontend & PWA | ~92% | ✅ Grotendeels compleet |
| **Fase 5** | Marketing & Push | ~10% | ⚪ nau begonnen |

### 9.2 FASE 1: Foundation (The Shell) — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 1.1 | Projectstructuur aanmaken | Alle mappen | ✅ | Alle mappen bestaan |
| 1.2 | PDO singleton connectie | `config/database.php` | ✅ | 85 regels, InnoDB, UTF-8MB4 |
| 1.3 | App-wide constants | `config/app.php` | ✅ | 65 regels, env mode, limieten |
| 1.4 | Apache rewrites + security | `.htaccess` | ✅ | 82 regels, security headers, gzip |
| 1.5 | Basis router | `index.php` | ✅ | ~400 regels, API+view dispatch, PWA manifest route |
| 1.6 | Database schema | `sql/schema.sql` | ✅ | 183 regels, 8 tabellen compleet |
| 1.7 | Test data | `sql/seed.sql` | ✅ | 60 regels, 1 tenant, 6 users, 4 tiers |
| 1.8 | Super-Admin: Tenant CRUD | `api/superadmin/tenants.php` | ✅ | 155 regels, volledige CRUD |
| 1.8 | Super-Admin: Overzicht | `api/superadmin/overview.php` | ✅ | 54 regels, platform statistieken |
| 1.8 | Tenant model | `models/Tenant.php` | ✅ | 152 regels, volledige CRUD |
| - | CORS headers | `config/cors.php` | ✅ | 36 regels, dev/prod modus |
| - | JSON response builder | `utils/response.php` | ✅ | 87 regels, alle HTTP codes |
| - | Input validator | `utils/validator.php` | ✅ | 137 regels, fluent interface |
| - | Audit trail logger | `utils/audit.php` | ✅ | 76 regels, log + retrieve |
| - | Helper functies | `utils/helpers.php` | ✅ | 205 regels, CSRF, sessie, sanitization |
| - | PWA manifest | `public/manifest.json.php` | ✅ | 135 regels, tenant branding, icon refs, shortcuts |
| - | Design system CSS | `public/css/midnight-lounge.css` | ✅ | 319 regels, variabelen, reset, layout, animaties |
| - | UI componenten CSS | `public/css/components.css` | ✅ | 858 regels, buttons, forms, nav, alerts, modals, tabellen |
| - | View-specifieke CSS | `public/css/views.css` | ✅ | 950 regels, auth, gast, POS, admin, superadmin |
| - | Header template | `views/shared/header.php` | ✅ | ~55 regels, externe CSS links + tenant variabelen |
| - | Footer template | `views/shared/footer.php` | ✅ | 12 regels, gebruikt .site-footer class |

### 9.3 FASE 2: Security & Identity — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 2.1 | Sessie validatie | `middleware/auth_check.php` | ✅ | 24 regels, timeout check |
| 2.2 | Rol autorisatie | `middleware/role_check.php` | ✅ | 48 regels, 4 rol-niveaus |
| 2.3 | Tenant filter | `middleware/tenant_filter.php` | ✅ | 31 regels, superadmin bypass |
| 2.4 | IP geofencing | `middleware/ip_whitelist.php` | ✅ | 42 regels, CIDR support |
| 2.5 | CSRF bescherming | `middleware/csrf.php` | ✅ | 31 regels, header+form validatie |
| 2.6 | User model | `models/User.php` | ✅ | 197 regels, CRUD + emailExists + calculateAge |
| 2.7 | AuthService (login/reg) | `services/AuthService.php` | ✅ | 210 regels, Argon2id+pepper, 18+ check, atomische user+wallet |
| 2.8 | HMAC QR Service | `services/QrService.php` | ✅ | 103 regels, HMAC-SHA256, hash_equals, 60s expiry |
| 2.9 | Auth API: Login | `api/auth/login.php` | ✅ | 76 regels, validatie, sessie, audit, role-based redirect |
| 2.10 | Auth API: Register | `api/auth/register.php` | ✅ | 71 regels, fluent validator, auto-login, audit |
| 2.11 | Auth API: Logout | `api/auth/logout.php` | ✅ | 30 regels, session destroy + cookie + audit |
| 2.12 | Auth API: Session | `api/auth/session.php` | ✅ | 14 regels, GET sessie info via AuthService |
| 2.13 | Login UI | `views/shared/login.php` | ✅ | 267 regels - Midnight Lounge glasmorphism styling |
| 2.14 | Registratie UI | `views/shared/register.php` | ✅ | 432 regels - 18+ validation, password strength indicator |
| 2.15 | Foto upload & validatie | (in models/User.php) | ✅ | CRUD methods aanwezig

### 9.4 FASE 3: Transactional Engine — 95% 🔧

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 3.1 | Wallet model | `models/Wallet.php` | ✅ | 66 regels, findByUserId, updateBalance, create |
| 3.2 | Transaction model | `models/Transaction.php` | ✅ | 181 regels, volledige CRUD + historie |
| 3.3 | Tenant model (bestaat al) | `models/Tenant.php` | ✅ | 132 regels - Al geïmplementeerd in Fase 1 |
| 3.4 | LoyaltyTier model | `models/LoyaltyTier.php` | ✅ | 132 regels, findByTenant + tier-bepaling |
| 3.5 | PaymentService (kassa) | `services/PaymentService.php` | ✅ | 136 regels, kortingslogica + atomaire transactie |
| 3.6 | MollieService (API wrapper) | `services/MollieService.php` | ✅ | 155 regels, Mock/test/live modus |
| 3.7 | WalletService (opwaarderen) | `services/WalletService.php` | ✅ | 153 regels, opwaarderen + saldo checks |
| 3.8 | POS: QR scannen | `api/pos/scan.php` | ✅ | Volledige HMAC validatie + user info |
| 3.9 | POS: Betaling verwerken | `api/pos/process_payment.php` | ✅ | 6-staps atomaire flow + kassa-logica |
| 3.10 | Wallet: Saldo | `api/wallet/balance.php` | ✅ | 23 regels - basis endpoint |
| 3.11 | Wallet: Opwaarderen | `api/wallet/deposit.php` | ✅ | Mollie checkout integratie |
| 3.12 | Wallet: Geschiedenis | `api/wallet/history.php` | ✅ | Paginering + transactie details |
| 3.13 | QR: Genereren | `api/qr/generate.php` | ✅ | HMAC-signed payload, 60s expiry |
| 3.14 | Mollie webhook | `api/mollie/webhook.php` | ✅ | Payment verificatie + wallet creditering |

### 9.5 FASE 4: Frontend & PWA — ~92% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 4.1 | Design system CSS | `public/css/midnight-lounge.css` | ✅ | 319 regels, Fase 1 |
| 4.2 | UI componenten CSS | `public/css/components.css` | ✅ | 858 regels, Fase 1 |
| 4.3 | View-specifieke CSS | `public/css/views.css` | ✅ | 950 regels, Fase 1 |
| 4.4 | App initializer & router | `public/js/app.js` | 🔧 | Wordt geïmplementeerd |
| 4.5 | Wallet functionaliteit | `public/js/wallet.js` | 🔧 | Wordt geïmplementeerd |
| 4.6 | QR generatie & weergave | `public/js/qr.js` | 🔧 | Wordt geïmplementeerd |
| 4.7 | Bartender POS interface | `public/js/pos.js` | 🔧 | Wordt geïmplementeerd |
| 4.8 | Admin dashboard charts | `public/js/admin.js` | 🔧 | Wordt geïmplementeerd |
| 4.9 | Push notification handler | `public/js/push.js` | ❌ | LEEG - Fase 5 |
| 4.10 | Service Worker | `public/js/sw.js` | ❌ | LEEG - Fase 5 |
| 4.11 | Dynamische PWA manifest | `public/manifest.json.php` | ✅ | 135 regels, Fase 1 |
| 4.12 | Gast: Dashboard | `views/guest/dashboard.php` | ✅ | 54 regels, wallet saldo + quick actions |
| 4.13 | Gast: Wallet | `views/guest/wallet.php` | 🔧 | Wordt geïmplementeerd |
| 4.14 | Gast: QR code | `views/guest/qr.php` | 🔧 | Wordt geïmplementeerd |
| 4.15 | Gast: Inbox | `views/guest/inbox.php` | ❌ | NIET AANGEMAAKT |
| 4.16 | Bartender: Scanner | `views/bartender/scanner.php` | 🔧 | Wordt geïmplementeerd |
| 4.17 | Bartender: Betaling | `views/bartender/payment.php` | 🔧 | Wordt geïmplementeerd |
| 4.18 | Admin: Dashboard | `views/admin/dashboard.php` | ✅ | 39 regels, quick nav naar users/tiers/settings/marketing |
| 4.19 | Admin: Gebruikers | `views/admin/users.php` | ❌ | NIET AANGEMAAKT |
| 4.20 | Admin: Tiers | `views/admin/tiers.php` | ❌ | NIET AANGEMAAKT |
| 4.21 | Admin: Instellingen | `views/admin/settings.php` | ❌ | NIET AANGEMAAKT |
| 4.22 | Superadmin: Dashboard | `views/superadmin/dashboard.php` | ✅ | 86 regels, stats cards + tenants tabel |
| 4.23 | Superadmin: Tenants | `views/superadmin/tenants.php` | ✅ | 123 regels, CRUD formulier + tabel |

### 9.6 FASE 5: Marketing & Push — ~10% ⚪

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 5.1 | PushService | `services/PushService.php` | ❌ | NIET AANGEMAAKT |
| 5.2 | MarketingService | `services/MarketingService.php` | ❌ | NIET AANGEMAAKT |
| 5.3 | Push: Abonneren | `api/push/subscribe.php` | ❌ | DIRECTORY LEEG |
| 5.4 | Push: Notificatie sturen | `api/push/send_notification.php` | ❌ | DIRECTORY LEEG |
| 5.5 | Marketing: Segmentatie | `api/marketing/segment.php` | ❌ | DIRECTORY LEEG |
| 5.6 | Marketing: Email opstellen | `api/marketing/compose.php` | ❌ | DIRECTORY LEEG |
| 5.7 | Marketing: Queue status | `api/marketing/queue.php` | ❌ | DIRECTORY LEEG |
| 5.8 | Dynamische PWA icon | `api/assets/generate_pwa_icon.php` | ❌ | DIRECTORY LEEG |
| 5.9 | Marketing Studio UI | `views/admin/marketing.php` | ❌ | NIET AANGEMAAKT |

### 9.7 Bestanden Overzicht

#### REEDS GEÏMPLEMENTEERD (40 bestanden)

```
.htaccess                              ✅ Apache rewrites + security headers
index.php                              ✅ Entry point + router (~400 regels)
config/database.php                    ✅ PDO singleton connectie
config/app.php                         ✅ App constants + env mode
config/cors.php                        ✅ CORS header management
sql/schema.sql                         ✅ Database schema (8 tabellen)
sql/seed.sql                           ✅ Test data
middleware/auth_check.php              ✅ Sessie validatie
middleware/role_check.php              ✅ Rol autorisatie
middleware/tenant_filter.php           ✅ Tenant isolatie
middleware/ip_whitelist.php            ✅ IP geofencing (CIDR)
middleware/csrf.php                    ✅ CSRF bescherming
models/Tenant.php                     ✅ Tenant CRUD model (152 regels) [FASE 1]
models/User.php                       ✅ User data access (~230 regels) [FASE 2]
models/Wallet.php                     ✅ Wallet data access (73 regels) [FASE 3]
services/AuthService.php              ✅ Login, registratie, sessies (~245 regels) [FASE 2]
services/QrService.php                ✅ HMAC QR generatie/validatie (~130 regels) [FASE 2]
api/superadmin/overview.php           ✅ Platform statistieken [FASE 1]
api/superadmin/tenants.php            ✅ Tenant CRUD endpoint [FASE 1]
api/auth/login.php                    ✅ POST Login endpoint (~95 regels) [FASE 2]
api/auth/register.php                 ✅ POST Register endpoint (~90 regels) [FASE 2]
api/auth/logout.php                   ✅ POST Logout endpoint (~30 regels) [FASE 2]
api/auth/session.php                  ✅ GET Session info endpoint (~20 regels) [FASE 2]
utils/audit.php                       ✅ Audit trail logger
utils/helpers.php                     ✅ Helper functies (205 regels)
utils/response.php                    ✅ JSON response builder
utils/validator.php                   ✅ Input validatie (fluent)
public/css/midnight-lounge.css        ✅ Design system CSS (319 regels) [FASE 1]
public/css/components.css             ✅ UI componenten CSS (858 regels) [FASE 1]
public/css/views.css                  ✅ View-specifieke CSS (950 regels) [FASE 1]
public/manifest.json.php              ✅ Dynamische PWA manifest (135 regels) [FASE 1]
views/shared/header.php               ✅ HTML header + inline CSS design system
views/shared/footer.php               ✅ HTML footer
views/shared/login.php                ✅ Login pagina [FASE 2]
views/shared/register.php             ✅ Registratie pagina [FASE 2]
views/guest/dashboard.php             ✅ Gast dashboard (54 regels) [FASE 4]
views/admin/dashboard.php             ✅ Admin dashboard (39 regels) [FASE 4]
views/superadmin/dashboard.php        ✅ Superadmin dashboard (86 regels) [FASE 4]
views/superadmin/tenants.php          ✅ Superadmin tenants (123 regels) [FASE 4]
views/guest/wallet.php                ✅ Wallet & opwaarderen [FASE 4]
views/guest/qr.php                    ✅ QR code weergave [FASE 4]
views/bartender/scanner.php           ✅ Full-screen QR scanner [FASE 4]
views/bartender/payment.php           ✅ Betaling verwerken [FASE 4]
views/admin/users.php               ✅ Gebruikersbeheer [FASE 4]
views/admin/tiers.php              ✅ Tier configuratie [FASE 4]
views/admin/settings.php            ✅ Instellingen [FASE 4]
models/Transaction.php               ✅ Transaction data access (181 regels) [FASE 3]
models/LoyaltyTier.php               ✅ Loyalty tier data access (132 regels) [FASE 3]
services/PaymentService.php           ✅ Kassa & kortingslogica (136 regels) [FASE 3]
services/MollieService.php            ✅ Mollie API wrapper (155 regels) [FASE 3]
services/WalletService.php           ✅ Opwaarderen, saldo checks (153 regels) [FASE 3]
api/wallet/balance.php               ✅ GET wallet saldo (23 regels) [FASE 3]
api/wallet/deposit.php               ✅ POST opwaarderen (Mollie) [FASE 3]
api/wallet/history.php               ✅ GET transactiegeschiedenis [FASE 3]
api/pos/scan.php                     ✅ POST QR scannen + validatie [FASE 3]
api/pos/process_payment.php          ✅ POST betaling verwerken [FASE 3]
api/qr/generate.php                  ✅ GET QR generatie (HMAC) [FASE 3]
api/mollie/webhook.php               ✅ POST Mollie webhook [FASE 3]
public/js/app.js                     ✅ App initializer (125 regels) [FASE 4]
public/js/wallet.js                  ✅ Wallet JS (168 regels) [FASE 4]
public/js/qr.js                      ✅ QR JS (189 regels) [FASE 4]
public/js/pos.js                     ✅ POS JS (297 regels) [FASE 4]
views/bartender/scanner.php          ✅ Full-screen QR scanner [FASE 4]
```

#### NOG TE IMPLEMENTEREN (~10 bestanden)

```
--- FASE 4: Frontend & PWA ---
public/js/admin.js                    ✅ Admin dashboard charts (220 regels) [FASE 4]
public/js/push.js                     ❌ Push notification handler
public/js/sw.js                       ❌ Service Worker
views/guest/inbox.php                ❌ Notificaties
views/admin/marketing.php            ❌ Marketing Studio
```
public/js/app.js                      ✅ App initializer & router (125 regels) [FASE 4]
public/js/wallet.js                   ✅ Wallet functionaliteit (168 regels) [FASE 4]
public/js/qr.js                        ✅ QR generatie & weergave (189 regels) [FASE 4]
public/js/pos.js                       ✅ Bartender POS interface (297 regels) [FASE 4]
public/js/admin.js                    ✅ Admin dashboard charts (220 regels) [FASE 4]
public/js/push.js                      ❌ Push notification handler
public/js/sw.js                        ❌ Service Worker
views/guest/wallet.php                ✅ Wallet & opwaarderen [FASE 4]
views/guest/qr.php                    ✅ QR code weergave [FASE 4]
views/guest/inbox.php                 ❌ Notificaties
views/bartender/scanner.php           ✅ Full-screen QR scanner [FASE 4]
views/bartender/payment.php           ✅ Betaling verwerken [FASE 4]
views/admin/users.php                 ✅ Gebruikersbeheer [FASE 4]
views/admin/tiers.php                 ✅ Tier configuratie [FASE 4]
views/admin/settings.php              ✅ Instellingen [FASE 4]

--- FASE 5: Marketing & Push (8 bestanden) ---
services/PushService.php              ❌ Web Push verzending
services/MarketingService.php         ❌ Segmentatie & e-mail
api/push/subscribe.php                ❌ POST Push abonnement
api/push/send_notification.php        ❌ POST Notificatie sturen
api/marketing/segment.php             ❌ POST Segmentatie query
api/marketing/compose.php             ❌ POST Email samenstellen
api/marketing/queue.php               ❌ GET Queue status
api/assets/generate_pwa_icon.php      ❌ GET Dynamisch PWA icoon
views/admin/marketing.php             ❌ Marketing studio
```

### 9.8 VOLGENDE STAPPEN (Fase 3 — Transactional Engine)

De volgende bestanden moeten worden geïmplementeerd om Fase 3 af te ronden:

1. **`models/Transaction.php`** — Transaction data access (CRUD + historie per user)
2. **`models/LoyaltyTier.php`** — Tier data access (findByTenant + tier-bepaling op basis van totaal gestort)
3. **`services/PaymentService.php`** — Kassa-logica: kortingen berekenen, 25% alcohol cap, punten multiplier, atomaire transactie
4. **`services/MollieService.php`** — Mollie API wrapper met mock/test/live modus
5. **`services/WalletService.php`** — Opwaarderen via Mollie, saldo checks
6. **`api/pos/scan.php`** — POST QR scannen + HMAC validatie + user info retourneren
7. **`api/pos/process_payment.php`** — POST volledige 6-staps betalingsflow (kritiek!)
8. **`api/wallet/balance.php`** — GET wallet saldo + tier info
9. **`api/wallet/deposit.php`** — POST opwaarderen (Mollie checkout URL)
10. **`api/wallet/history.php`** — GET transactiegeschiedenis met paginering
11. **`api/qr/generate.php`** — GET HMAC-signed QR payload (60s geldig)
12. **`api/mollie/webhook.php`** — POST Mollie webhook handler (payment verificatie)

Na Fase 3 volgt Fase 4 (overgebleven views + JavaScript) en Fase 5 (Marketing & Push).

---

### 9.9 TEST LOGIN GEGEVENS

> **Laatst bijgewerkt**: 2026-04-20
> **Alle wachtwoorden gereset en geverifieerd** via `reset_all_passwords.php`

#### Login Overzicht

| Rol | E-mail | Wachtwoord | Naam | Saldo |
| :--- | :--- | :--- | :--- | :--- |
| **Super-Admin** | `admin@stamgast.nl` | `Admin123!` | Admin User | €100.00 |
| **Admin (Manager)** | `manager@test.nl` | `Manager123!` | Manager Test | €50.00 |
| **Bartender** | `bartender@test.nl` | `Bartend3r!` | Bart Tender | €0.00 |
| **Gast** | `guest@test.nl` | `Guest123!` | Guest User | €0.00 |

#### Tenant Info

| Veld | Waarde |
| :--- | :--- |
| Naam | Test Establishment |
| UUID | tenant-001 |
| Slug | test |
| Brand kleur | #FFC107 |
| Mollie status | mock |

#### Toegang

| Rol | Login URL | Na login |
| :--- | :--- | :--- |
| Super-Admin | http://localhost/stamgast/login | `/superadmin` |
| Admin | http://localhost/stamgast/login | `/admin` |
| Bartender | http://localhost/stamgast/login | `/scan` |
| Gast | http://localhost/stamgast/login | `/dashboard` |

#### Wachtwoord Reset

Om alle wachtwoorden te resetten naar de bovenstaande waarden:
```
http://localhost/stamgast/reset_all_passwords.php
```

> **⚠️ Veiligheid**: Verwijder `reset_all_passwords.php` van de server in productie!

#### Technische Details

- **Hashing**: Argon2id (`PASSWORD_ARGON2ID`)
- **Pepper**: Gedefinieerd in `config/app.php` als `APP_PEPPER`
- **Verificatie**: Elk wachtwoord wordtgeconcat met pepper vóór hash/verify
- **Seed data**: Zie `sql/seed.sql` (let op: emails in DB wijken af van seed.sql)

---
