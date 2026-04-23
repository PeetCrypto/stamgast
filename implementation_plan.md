# STAMGAST LOYALTY PLATFORM - THE MASTER BLUEPRINT (v10)

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
- [x] Photo upload/validation (in models/User.php).
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

### Phase 4: Frontend & PWA — ✅ COMPLEET (100%)
- [x] Alle CSS bestanden (3 bestanden, ~2127 regels totaal).
- [x] Alle JavaScript bestanden (6 bestanden, ~1900 regels totaal).
- [x] Alle gast views (dashboard, wallet, qr).
- [x] Unified Bartender POS dashboard (501 regels).
- [x] Alle admin views (dashboard, users, tiers, settings, marketing).
- [x] Alle superadmin views (dashboard, tenants, tenant_detail).
- [ ] ~~Bartender scanner/payment views~~ (geconsolideerd in unified dashboard).
- [x] Gast inbox view (`views/guest/inbox.php`).
- [x] Admin marketing view (`views/admin/marketing.php`).
- [x] Push notification handler (`public/js/push.js`).
- [x] Service Worker (`public/js/sw.js`).
- [x] Admin API endpoints (`api/admin/*.php`) — alle 4 bestanden gemaakt en werken correct!

### Phase 5: Marketing & Push — ✅ COMPLEET (100%)
- [x] PushService & MarketingService (backend business logic).
- [x] Push subscribe/send_notification API endpoints.
- [x] Marketing segment/compose/queue API endpoints.
- [x] Dynamic PWA Icon generation (GD library).

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

> **Laatst bijgewerkt**: 2026-04-22 16:44
> **Huidige status**: ✅ Alle fasen (1-5) compleet
> **Totaal bestanden met code**: 63 van ~65 gepland (~97%)
> **Bekende problemen**: Geen — alle geplande functionaliteit geïmplementeerd

### 9.1 Overzicht per Fase

| Fase | Omschrijving | Voortgang | Status |
| :--- | :--- | :---: | :--- |
| **Fase 1** | Foundation (The Shell) | 100% | ✅ Compleet |
| **Fase 2** | Security & Identity | 100% | ✅ Compleet |
| **Fase 3** | Transactional Engine | 100% | ✅ Compleet (83/83 tests geslaagd) |
| **Fase 4** | Frontend & PWA | 100% | ✅ Compleet |
| **Fase 5** | Marketing & Push | 100% | ✅ Compleet |

### 9.2 FASE 1: Foundation (The Shell) — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 1.1 | Projectstructuur aanmaken | Alle mappen | ✅ | Alle mappen bestaan |
| 1.2 | PDO singleton connectie | `config/database.php` | ✅ | InnoDB, UTF-8MB4 |
| 1.3 | App-wide constants | `config/app.php` | ✅ | env mode, limieten |
| 1.4 | Apache rewrites + security | `.htaccess` | ✅ | security headers, gzip |
| 1.5 | Basis router | `index.php` | ✅ | ~400 regels, API+view dispatch |
| 1.6 | Database schema | `sql/schema.sql` | ✅ | 8 tabellen compleet |
| 1.7 | Test data | `sql/seed.sql` | ✅ | 1 tenant, users, tiers |
| 1.8 | Super-Admin: Tenant CRUD | `api/superadmin/tenants.php` | ✅ | volledige CRUD |
| 1.8 | Super-Admin: Overzicht | `api/superadmin/overview.php` | ✅ | platform statistieken |
| 1.8 | Tenant model | `models/Tenant.php` | ✅ | volledige CRUD |
| - | CORS headers | `config/cors.php` | ✅ | dev/prod modus |
| - | JSON response builder | `utils/response.php` | ✅ | alle HTTP codes |
| - | Input validator | `utils/validator.php` | ✅ | fluent interface |
| - | Audit trail logger | `utils/audit.php` | ✅ | log + retrieve |
| - | Helper functies | `utils/helpers.php` | ✅ | CSRF, sessie, sanitization |
| - | PWA manifest | `public/manifest.json.php` | ✅ | tenant branding, icon refs, shortcuts |
| - | Design system CSS | `public/css/midnight-lounge.css` | ✅ | variabelen, reset, layout, animaties |
| - | UI componenten CSS | `public/css/components.css` | ✅ | buttons, forms, nav, alerts, modals, tabellen |
| - | View-specifieke CSS | `public/css/views.css` | ✅ | auth, gast, POS, admin, superadmin |
| - | Header template | `views/shared/header.php` | ✅ | externe CSS links + tenant variabelen |
| - | Footer template | `views/shared/footer.php` | ✅ | gebruikt .site-footer class |

### 9.3 FASE 2: Security & Identity — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 2.1 | Sessie validatie | `middleware/auth_check.php` | ✅ | timeout check |
| 2.2 | Rol autorisatie | `middleware/role_check.php` | ✅ | 4 rol-niveaus |
| 2.3 | Tenant filter | `middleware/tenant_filter.php` | ✅ | superadmin bypass |
| 2.4 | IP geofencing | `middleware/ip_whitelist.php` | ✅ | CIDR support |
| 2.5 | CSRF bescherming | `middleware/csrf.php` | ✅ | header+form validatie |
| 2.6 | User model | `models/User.php` | ✅ | CRUD + emailExists + calculateAge |
| 2.7 | AuthService (login/reg) | `services/AuthService.php` | ✅ | Argon2id+pepper, 18+ check |
| 2.8 | HMAC QR Service | `services/QrService.php` | ✅ | HMAC-SHA256, hash_equals, 60s expiry |
| 2.9 | Auth API: Login | `api/auth/login.php` | ✅ | validatie, sessie, audit |
| 2.10 | Auth API: Register | `api/auth/register.php` | ✅ | fluent validator, auto-login |
| 2.11 | Auth API: Logout | `api/auth/logout.php` | ✅ | session destroy + cookie |
| 2.12 | Auth API: Session | `api/auth/session.php` | ✅ | GET sessie info |
| 2.13 | Login UI | `views/shared/login.php` | ✅ | Midnight Lounge glasmorphism |
| 2.14 | Registratie UI | `views/shared/register.php` | ✅ | 18+ validation, password strength |
| 2.15 | Foto upload & validatie | `models/User.php` | ✅ | CRUD methods aanwezig |

### 9.4 FASE 3: Transactional Engine — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 3.1 | Wallet model | `models/Wallet.php` | ✅ | findByUserId, updateBalance, create |
| 3.2 | Transaction model | `models/Transaction.php` | ✅ | volledige CRUD + historie |
| 3.3 | Tenant model | `models/Tenant.php` | ✅ | Al geïmplementeerd in Fase 1 |
| 3.4 | LoyaltyTier model | `models/LoyaltyTier.php` | ✅ | findByTenant + tier-bepaling |
| 3.5 | PaymentService (kassa) | `services/PaymentService.php` | ✅ | kortingslogica + atomaire transactie |
| 3.6 | MollieService (API wrapper) | `services/MollieService.php` | ✅ | Mock/test/live modus |
| 3.7 | WalletService (opwaarderen) | `services/WalletService.php` | ✅ | opwaarderen + saldo checks |
| 3.8 | POS: QR scannen | `api/pos/scan.php` | ✅ | HMAC validatie + user info |
| 3.9 | POS: Betaling verwerken | `api/pos/process_payment.php` | ✅ | 6-staps atomaire flow |
| 3.10 | Wallet: Saldo | `api/wallet/balance.php` | ✅ | basis endpoint |
| 3.11 | Wallet: Opwaarderen | `api/wallet/deposit.php` | ✅ | Mollie checkout integratie |
| 3.12 | Wallet: Geschiedenis | `api/wallet/history.php` | ✅ | Paginering + details |
| 3.13 | QR: Genereren | `api/qr/generate.php` | ✅ | HMAC-signed payload, 60s expiry |
| 3.14 | Mollie webhook | `api/mollie/webhook.php` | ✅ | Payment verificatie + wallet creditering |

### 9.5 FASE 4: Frontend & PWA — 100% ✅

#### JavaScript Bestanden (allemaal geïmplementeerd)

| # | Item | Bestand | Regels | Status | Opmerking |
| :--- | :--- | :--- | :---: | :---: | :--- |
| 4.1 | App initializer & router | `public/js/app.js` | 398 | ✅ | IIFE, AppState, API client, routing, SW registratie, tenant branding |
| 4.2 | Wallet functionaliteit | `public/js/wallet.js` | 278 | ✅ | Deposit flow (Mollie+mock), transactie historie, quick deposit buttons |
| 4.3 | QR generatie & weergave | `public/js/qr.js` | 310 | ✅ | QR generatie/rendering, 60s countdown, auto-refresh, scanner+validatie |
| 4.4 | Bartender POS interface | `public/js/pos.js` | 127 | ✅ | QR validatie, betaling verwerken, discount preview |
| 4.5 | Admin dashboard charts | `public/js/admin.js` | 598 | ✅ | Stats/charts canvas, users CRUD, tiers CRUD, settings, marketing studio |
| 4.6 | Push notification handler | `public/js/push.js` | 290 | ✅ | Web Push API, VAPID keys, subscribe/unsubscribe, toggle |
| 4.7 | Service Worker | `public/js/sw.js` | 185 | ✅ | Cache-first voor shell, network-first voor API, push handler |

#### View Bestanden — Gast

| # | Item | Bestand | Regels | Status | Opmerking |
| :--- | :--- | :--- | :---: | :---: | :--- |
| 4.8 | Gast Dashboard | `views/guest/dashboard.php` | 54 | ✅ | Wallet saldo + quick actions grid |
| 4.9 | Gast Wallet | `views/guest/wallet.php` | 118 | ✅ | Balance/points/tier + deposit buttons + transactie historie |
| 4.10 | Gast QR code | `views/guest/qr.php` | 98 | ✅ | QR box + countdown + auto-refresh + QRCode.js CDN |
| 4.11 | Gast Inbox | `views/guest/inbox.php` | 196 | ✅ | Notificatie feed op basis van transactiehistorie |

#### View Bestanden — Bartender

| # | Item | Bestand | Regels | Status | Opmerking |
| :--- | :--- | :--- | :---: | :---: | :--- |
| 4.12 | Unified POS Dashboard | `views/bartender/dashboard.php` | 501 | ✅ | **HOOFDVIEW** — Scanner + Payment + Success in 1 pagina, inline JS |
| 4.13 | Scanner redirect | `views/bartender/scanner.php` | 11 | ✅ | Redirect stub naar `/bartender` (backward compat) |
| 4.14 | Payment redirect | `views/bartender/payment.php` | 11 | ✅ | Redirect stub naar `/bartender` (backward compat) |

#### View Bestanden — Admin

| # | Item | Bestand | Regels | Status | Opmerking |
| :--- | :--- | :--- | :---: | :---: | :--- |
| 4.15 | Admin Dashboard | `views/admin/dashboard.php` | 39 | ✅ | Nav hub naar users/tiers/settings/marketing |
| 4.16 | Admin Gebruikers | `views/admin/users.php` | 141 | ✅ | Tabel + filters + modal CRUD + paginering |
| 4.17 | Admin Tiers | `views/admin/tiers.php` | 102 | ✅ | Tiers grid + modal CRUD + delete |
| 4.18 | Admin Instellingen | `views/admin/settings.php` | 137 | ✅ | Form: algemeen, kleuren, logo, Mollie, IPs, toggles |
| 4.19 | Admin Marketing | `views/admin/marketing.php` | 256 | ✅ | Segmentatie + email composer + queue UI |

#### View Bestanden — Superadmin

| # | Item | Bestand | Regels | Status | Opmerking |
| :--- | :--- | :--- | :---: | :---: | :--- |
| 4.20 | Superadmin Dashboard | `views/superadmin/dashboard.php` | 118 | ✅ | Stats cards + tenants grid/tabel |
| 4.21 | Superadmin Tenants | `views/superadmin/tenants.php` | 191 | ✅ | CRUD formulier + tabel |
| 4.22 | Superadmin Tenant Detail | `views/superadmin/tenant_detail.php` | 269 | ✅ | **NIEUW** — Stats, NAW edit, users tabel met role dropdown |

#### CSS & PWA (Fase 1, al compleet)

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 4.23 | Design system CSS | `public/css/midnight-lounge.css` | ✅ | Variabelen, reset, layout, animaties |
| 4.24 | UI componenten CSS | `public/css/components.css` | ✅ | Buttons, forms, nav, alerts, modals |
| 4.25 | View-specifieke CSS | `public/css/views.css` | ✅ | Auth, gast, POS, admin, superadmin |
| 4.26 | Dynamische PWA manifest | `public/manifest.json.php` | ✅ | Tenant branding, icon refs |

#### ✅ Admin API Endpoints — OPGELOST

Alle 4 API endpoints zijn nu gemaakt en werken correct:

| Bestand | Functie | Status |
| :--- | :--- | :---: |
| `api/admin/dashboard.php` | GET Admin statistieken | ✅ Compleet |
| `api/admin/users.php` | GET/POST Gebruikersbeheer | ✅ Compleet |
| `api/admin/tiers.php` | GET/POST Tier beheer | ✅ Compleet |
| `api/admin/settings.php` | GET/POST Instellingen | ✅ Compleet |

> **Impact**: `admin.js` maakt API calls naar `/admin/dashboard`, `/admin/users`, `/admin/tiers`, `/admin/settings`. Deze werken nu correct. De eerdere 500-error is opgelost. De admin functionaliteit is volledig operationeel.

### 9.6 FASE 5: Marketing & Push — 100% ✅

| # | Item | Bestand | Status | Opmerking |
| :--- | :--- | :--- | :---: | :--- |
| 5.1 | PushService | `services/PushService.php` | ✅ | ~280 regels — subscribe, sendNotification, broadcast, mock/dev mode |
| 5.2 | MarketingService | `services/MarketingService.php` | ✅ | ~280 regels — segmentUsers (3 filters), composeEmail, getQueueStatus, processQueue |
| 5.3 | Push: Abonneren | `api/push/subscribe.php` | ✅ | ~65 regels — validatie endpoint URL + base64, audit logging |
| 5.4 | Push: Notificatie sturen | `api/push/send_notification.php` | ✅ | ~80 regels — feature_push toggle, tenant isolatie |
| 5.5 | Marketing: Segmentatie | `api/marketing/segment.php` | ✅ | ~75 regels — feature_marketing toggle, criteria sanitization |
| 5.6 | Marketing: Email opstellen | `api/marketing/compose.php` | ✅ | ~80 regels — max 500 users, tenant-validatie |
| 5.7 | Marketing: Queue status | `api/marketing/queue.php` | ✅ | ~45 regels — pending/sent/failed counts |
| 5.8 | Dynamische PWA icon | `api/assets/generate_pwa_icon.php` | ✅ | ~200 regels — GD library gradient, font fallback, 24h cache |

### 9.7 Bestanden Overzicht

#### REEDS GEÌMPLEMENTEERD (63 bestanden)

```
--- CONFIG & INFRASTRUCTUUR ---
.htaccess                              ✅ Apache rewrites + security headers
index.php                              ✅ Entry point + router (~400 regels)
config/database.php                    ✅ PDO singleton connectie
config/app.php                         ✅ App constants + env mode
config/cors.php                        ✅ CORS header management
sql/schema.sql                         ✅ Database schema (8 tabellen)
sql/seed.sql                           ✅ Test data

--- MIDDLEWARE ---
middleware/auth_check.php              ✅ Sessie validatie
middleware/role_check.php              ✅ Rol autorisatie
middleware/tenant_filter.php           ✅ Tenant isolatie
middleware/ip_whitelist.php            ✅ IP geofencing (CIDR)
middleware/csrf.php                    ✅ CSRF bescherming

--- MODELS ---
models/Tenant.php                     ✅ Tenant CRUD model [FASE 1]
models/User.php                       ✅ User data access [FASE 2]
models/Wallet.php                     ✅ Wallet data access [FASE 3]
models/Transaction.php                ✅ Transaction data access [FASE 3]
models/LoyaltyTier.php                ✅ Loyalty tier data access [FASE 3]

--- SERVICES ---
services/AuthService.php              ✅ Login, registratie, sessies [FASE 2]
services/QrService.php                ✅ HMAC QR generatie/validatie [FASE 2]
services/PaymentService.php           ✅ Kassa & kortingslogica [FASE 3]
services/MollieService.php            ✅ Mollie API wrapper [FASE 3]
services/WalletService.php            ✅ Opwaarderen, saldo checks [FASE 3]
services/PushService.php               ✅ Web Push verzending (~280 regels) [FASE 5]
services/MarketingService.php          ✅ Segmentatie & e-mail (~280 regels) [FASE 5]

--- API ENDPOINTS ---
api/superadmin/overview.php           ✅ Platform statistieken [FASE 1]
api/superadmin/tenants.php            ✅ Tenant CRUD endpoint [FASE 1]
api/auth/login.php                    ✅ POST Login endpoint [FASE 2]
api/auth/register.php                 ✅ POST Register endpoint [FASE 2]
api/auth/logout.php                   ✅ POST Logout endpoint [FASE 2]
api/auth/session.php                  ✅ GET Session info endpoint [FASE 2]
api/wallet/balance.php                ✅ GET wallet saldo [FASE 3]
api/wallet/deposit.php                ✅ POST opwaarderen (Mollie) [FASE 3]
api/wallet/history.php                ✅ GET transactiegeschiedenis [FASE 3]
api/pos/scan.php                      ✅ POST QR scannen + validatie [FASE 3]
api/pos/process_payment.php           ✅ POST betaling verwerken [FASE 3]
api/qr/generate.php                   ✅ GET QR generatie (HMAC) [FASE 3]
api/mollie/webhook.php                ✅ POST Mollie webhook [FASE 3]
api/push/subscribe.php                 ✅ POST Push abonnement (~65 regels) [FASE 5]
api/push/send_notification.php         ✅ POST Notificatie sturen (~80 regels) [FASE 5]
api/marketing/segment.php              ✅ POST Segmentatie query (~75 regels) [FASE 5]
api/marketing/compose.php              ✅ POST Email samenstellen (~80 regels) [FASE 5]
api/marketing/queue.php                ✅ GET Queue status (~45 regels) [FASE 5]
api/assets/generate_pwa_icon.php       ✅ GET Dynamisch PWA icoon (~200 regels) [FASE 5]

--- UTILS ---
utils/audit.php                       ✅ Audit trail logger
utils/helpers.php                     ✅ Helper functies
utils/response.php                    ✅ JSON response builder
utils/validator.php                   ✅ Input validatie (fluent)

--- CSS & PWA ---
public/css/midnight-lounge.css        ✅ Design system CSS [FASE 1]
public/css/components.css             ✅ UI componenten CSS [FASE 1]
public/css/views.css                  ✅ View-specifieke CSS [FASE 1]
public/manifest.json.php              ✅ Dynamische PWA manifest [FASE 1]

--- JAVASCRIPT ---
public/js/app.js                      ✅ App initializer (398 regels) [FASE 4]
public/js/wallet.js                   ✅ Wallet functionaliteit (278 regels) [FASE 4]
public/js/qr.js                       ✅ QR generatie & weergave (310 regels) [FASE 4]
public/js/pos.js                      ✅ Bartender POS interface (127 regels) [FASE 4]
public/js/admin.js                    ✅ Admin dashboard (598 regels) [FASE 4]
public/js/push.js                     ✅ Push notification handler (290 regels) [FASE 4]

--- VIEWS: SHARED ---
views/shared/header.php               ✅ HTML header + tenant variabelen
views/shared/footer.php               ✅ HTML footer
views/shared/login.php                ✅ Login pagina [FASE 2]
views/shared/register.php             ✅ Registratie pagina [FASE 2]

--- VIEWS: GAST ---
views/guest/dashboard.php             ✅ Gast dashboard (54 regels) [FASE 4]
views/guest/wallet.php                ✅ Wallet & opwaarderen (118 regels) [FASE 4]
views/guest/qr.php                    ✅ QR code weergave (98 regels) [FASE 4]

--- VIEWS: BARTENDER ---
views/bartender/dashboard.php         ✅ Unified POS (501 regels) [FASE 4]
views/bartender/scanner.php           ✅ Redirect → /bartender (11 regels) [FASE 4]
views/bartender/payment.php           ✅ Redirect → /bartender (11 regels) [FASE 4]

--- VIEWS: ADMIN ---
views/admin/dashboard.php             ✅ Admin hub (39 regels) [FASE 4]
views/admin/users.php                 ✅ Gebruikersbeheer (141 regels) [FASE 4]
views/admin/tiers.php                 ✅ Tier configuratie (102 regels) [FASE 4]
views/admin/settings.php              ✅ Instellingen (137 regels) [FASE 4]
views/admin/marketing.php             ✅ Marketing Studio (256 regels) [FASE 4]

--- VIEWS: SUPERADMIN ---
views/superadmin/dashboard.php        ✅ Superadmin dashboard (118 regels) [FASE 4]
views/superadmin/tenants.php          ✅ Tenants CRUD (191 regels) [FASE 4]
views/superadmin/tenant_detail.php    ✅ Tenant detail (269 regels) [FASE 4] **NIEUW**
```

#### FASE 5: MARKETING & PUSH ✅ COMPLEET

```
--- Fase 5: Marketing & Push (backend + API endpoints) ---
services/PushService.php               ✅ Web Push verzending (~280 regels) [FASE 5]
services/MarketingService.php          ✅ Segmentatie & e-mail (~280 regels) [FASE 5]
api/push/subscribe.php                 ✅ POST Push abonnement (~65 regels) [FASE 5]
api/push/send_notification.php         ✅ POST Notificatie sturen (~80 regels) [FASE 5]
api/marketing/segment.php              ✅ POST Segmentatie query (~75 regels) [FASE 5]
api/marketing/compose.php              ✅ POST Email samenstellen (~80 regels) [FASE 5]
api/marketing/queue.php                ✅ GET Queue status (~45 regels) [FASE 5]
api/assets/generate_pwa_icon.php       ✅ GET Dynamisch PWA icoon (~200 regels) [FASE 5]
```

> **Alle fasen (1-5) zijn nu volledig geïmplementeerd.**

### 9.8 IMPLEMENTATIE STATUS

#### ✅ ALLE FASEN COMPLEET

| Fase | Beschrijving | Status |
| :--- | :--- | :--- |
| Fase 1 | Foundation (config, router, DB schema) | ✅ Compleet |
| Fase 2 | Security & Identity (auth, middleware, QR) | ✅ Compleet |
| Fase 3 | Transactional Engine (wallet, payments, POS) | ✅ Compleet |
| Fase 4 | Frontend & PWA (views, CSS, JS, Service Worker) | ✅ Compleet |
| Fase 5 | Marketing & Push (services, API endpoints) | ✅ Compleet |

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
| Bartender | http://localhost/stamgast/login | `/bartender` |
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
- **Verificatie**: Elk wachtwoord wordt geconcat met pepper vóór hash/verify
- **Seed data**: Zie `sql/seed.sql` (let op: emails in DB wijken af van seed.sql)

---
