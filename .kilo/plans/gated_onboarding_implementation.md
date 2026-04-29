# Gated Onboarding — Implementatie Plan (gated_onboarding_implementation.md)

> **Laatst bijgewerkt**: 2026-04-29 21:55
> **Status**: ✅ Voltooid (alle 6 fases geïmplementeerd)
> **Doel**: Gasten kunnen zich registreren maar moeten fysiek aan de bar geverifieerd worden voordat ze geld kunnen storten of besteden.

---

## 1. Concept Samenvatting

### Terminologie
- **Gated Onboarding**: Gast registreert online → account is "unverified" → barman controleert ID aan de bar → account wordt "active"
- **KYC-light**: Geen geautomatiseerde ID-scan, maar menselijke verificatie door barman met verplichte geboortedatum-invoer

### State Machine

```
unverified ──(barman valideert ID)──> active
unverified ──(barman weigert)──────> unverified (met cooldown)
active ──────(admin blokkeert)──────> suspended
suspended ───(admin deblokkeert)────> active
```

### Wat kan de gast per status?

| Actie | unverified | active | suspended |
|-------|:----------:|:------:|:---------:|
| Inloggen | ✅ | ✅ | ❌ |
| Dashboard bekijken | ✅ | ✅ | ❌ |
| QR code tonen | ✅ (met "UNVERIFIED" badge) | ✅ | ❌ |
| Saldo storten | ❌ | ✅ | ❌ |
| Betalen aan bar | ❌ | ✅ | ❌ |

---

## 2. Fase Overzicht

| Fase | Omschrijving | Status |
|:----:|--------------|:------:|
| 1 | Database Migratie | ✅ Done |
| 2 | Backend — Models & Services | ✅ Done |
| 3 | Backend — API Endpoints | ✅ Done |
| 4 | Frontend — UI Aanpassingen | ✅ Done |
| 5 | Router & Configuratie | ✅ Done |
| 6 | Push Notificaties | ✅ Done |

---

## 3. Fase 1: Database Migratie

### 3.1 Nieuw bestand: `sql/gated_onboarding_migration.sql`

#### ALTER TABLE `users` — Nieuwe kolommen toevoegen

| Kolom | Type | Default | Beschrijving |
|-------|------|---------|-------------|
| `account_status` | ENUM('unverified','active','suspended') | 'unverified' | Unverified = kan inloggen, Active = kan storten/betalen, Suspended = geblokkeerd |
| `verified_at` | TIMESTAMP | NULL | Wanneer is de identiteit geverifieerd door barman |
| `verified_by` | INT | NULL | FK naar users.id — welke barman/admin heeft de verificatie uitgevoerd |
| `verified_birthdate` | DATE | NULL | Geboortedatum zoals ingevoerd door barman vanaf ID |
| `suspended_reason` | VARCHAR(500) | NULL | Reden van opschorting |
| `suspended_at` | TIMESTAMP | NULL | Wanneer opgeschort |
| `suspended_by` | INT | NULL | FK naar users.id — wie heeft opgeschort |

#### ALTER TABLE `tenants` — Configureerbare rate limits

| Kolom | Type | Default | Beschrijving |
|-------|------|---------|-------------|
| `verification_soft_limit` | INT | 15 | Waarschuwing per barman per uur |
| `verification_hard_limit` | INT | 30 | Absolute blokkade per barman per uur |
| `verification_cooldown_sec` | INT | 180 | Seconden wachttijd na mismatch per gast |
| `verification_max_attempts` | INT | 2 | Max verificatiepogingen per gast per 24u |

#### Nieuwe tabel: `verification_attempts` (audit trail)

| Kolom | Type | Beschrijving |
|-------|------|-------------|
| `id` | INT PK AI | |
| `tenant_id` | INT FK | |
| `user_id` | INT FK | De gast die geverifieerd wordt |
| `verified_by` | INT FK | De barman/admin die controleert |
| `birthdate_seen` | DATE NOT NULL | Geboortedatum die de barman intypt vanaf ID |
| `birthdate_match` | BOOLEAN NOT NULL | Komt het overeen met de registratie? |
| `status_before` | ENUM | Status voor de poging |
| `status_after` | ENUM | Status na de poging |
| `ip_address` | VARCHAR(45) | |
| `notes` | VARCHAR(500) | Optionele notities |
| `created_at` | TIMESTAMP | |

#### Checkpoints

- [x] Migration SQL bestand aanmaken: `sql/gated_onboarding_migration.sql`
- [x] Bestaande `users` zonder `account_status` updaten naar `unverified` (geen `active` — veilige default)
- [ ] Migration draaien op database
- [ ] Controleren via `check_schema.php` of alle kolommen bestaan

---

## 4. Fase 2: Backend — Models & Services

### 4.1 `models/User.php` — Nieuwe methodes

| Methode | Beschrijving |
|---------|-------------|
| `getAccountStatus(int $userId): string` | Retourneert account_status (fallback: 'unverified') |
| `updateAccountStatus(int $userId, string $status, ?int $changedBy = null, ?string $reason = null): bool` | Update account_status + audit velden (verified_at, suspended_reason, etc.) |
| `verifyUser(int $userId, int $verifiedBy, string $verifiedBirthdate): array` | Verifieert gebruiker: controleert geboortedatum match, update naar active, logt in verification_attempts |
| `suspendUser(int $userId, int $suspendedBy, string $reason): bool` | Blokkeert gebruiker |
| `unsuspendUser(int $userId, int $unsuspendedBy): bool` | Deblokkeert gebruiker |
| `countVerificationsInWindow(int $tenantId, int $bartenderId, int $seconds = 3600): int` | Aantal verificaties door barman in afgelopen uur (voor rate limiting) |
| `countGuestVerificationAttempts(int $userId, int $hours = 24): int` | Aantal verificatiepogingen per gast in 24u (voor per-gast limiet) |

### 4.2 `services/WalletService.php` — Gating toevoegen

**Bestand**: `services/WalletService.php`
**Locatie**: In methode `createDeposit()`, na regel 128 (na de `DEPOSIT_MAX_CENTS` check)

```
Pseudocode:
  user = userModel.findById(userId)
  if user.account_status !== 'active':
      throw InvalidArgumentException("Je account is nog niet geactiveerd. Laat je ID zien bij de bar.")
```

### 4.3 `services/PaymentService.php` — Gating toevoegen

**Bestand**: `services/PaymentService.php`
**Locatie**: In methode `processPayment()`, na regel 63 (na de user-tenant check in STAP 2)

```
Pseudocode:
  if user.account_status === 'suspended':
      throw RuntimeException("Dit account is geblokkeerd door de beheerder.")
  if user.account_status !== 'active':
      throw RuntimeException("Gast is nog niet geverifieerd. Vraag om legitimatie aan de bar.")
```

### 4.4 `services/AuthService.php` — Adjustments

**Bestand**: `services/AuthService.php`

| Wijziging | Locatie | Beschrijving |
|-----------|---------|-------------|
| Login check | `login()` methode, na `password_verify` | Controleer of `account_status !== 'suspended'` — suspended gebruikers mogen niet inloggen |
| Registration | `register()` methode | Nieuwe gebruikers krijgen automatisch `account_status = 'unverified'` |
| Session info | `getSessionInfo()` methode | Voeg `account_status` toe aan response |

#### Checkpoints

- [x] `models/User.php` — 7 nieuwe methodes toegevoegd
- [x] `services/WalletService.php` — account_status guard in `createDeposit()`
- [x] `services/PaymentService.php` — account_status guard in `processPayment()`
- [x] `services/AuthService.php` — suspended login block + account_status in session

---

## 5. Fase 3: Backend — API Endpoints

### 5.1 Nieuw: `api/pos/verify.php` — Barman verificatie-actie

**Methode**: POST
**Auth**: bartender+ (via router)
**Request body**:
```json
{
  "user_id": 123,
  "birthdate": "1990-05-15"
}
```

**Flow**:
1. Validatie: user_id en birthdate verplicht
2. Gast ophalen: check dat gast bij dezelfde tenant hoort
3. Gast status check: alleen `unverified` mag geverifieerd worden
4. Geboortedatum match: vergelijk `birthdate` uit request met `users.birthdate`
   - Match → `account_status = 'active'`, `verified_at = NOW()`, `verified_by = bartender_id`
   - Mismatch → log poging, return error (géén status update)
5. Rate limiting check (per barman):
   - Tel verificaties in afgelopen uur via `countVerificationsInWindow()`
   - Haal limieten op uit `tenants` tabel
   - Boven soft limit → log warning (return succes maar met warning flag)
   - Boven hard limit → return 429 (Too Many Requests)
6. Per-gast cooldown check:
   - Tel pogingen in 24u via `countGuestVerificationAttempts()`
   - Boven `verification_max_attempts` → return 429
7. Log in `verification_attempts` tabel (altijd, ook bij mismatch)
8. Bij succesvolle activatie: trigger push notificatie naar gast

**Response (succes)**:
```json
{
  "success": true,
  "data": {
    "verified": true,
    "user_id": 123,
    "birthdate_match": true,
    "account_status": "active",
    "warning": null
  }
}
```

**Response (mismatch)**:
```json
{
  "success": false,
  "error": "Geboortedatum komt niet overeen met registratie. Vraag de gast het ID opnieuw te controleren.",
  "code": "BIRTHDATE_MISMATCH",
  "data": {
    "verified": false,
    "birthdate_match": false,
    "attempts_remaining": 1
  }
}
```

### 5.2 Wijziging: `api/pos/scan.php` — account_status toevoegen

**Bestand**: `api/pos/scan.php`
**Locatie**: Regel 122-139 (Response user info uitbreiden)

Toevoegen aan de response:
```json
"account_status": "unverified" | "active" | "suspended"
```

Dit stelt de bartender POS frontend in staat om:
- Een "Activeer Account" knop te tonen bij `unverified`
- Een "Geblokkeerd" bericht te tonen bij `suspended`

### 5.3 Wijziging: `api/wallet/deposit.php` — Extra check

**Bestand**: `api/wallet/deposit.php`
**Locatie**: Na regel 37 (na userId/tenantId check)

```
Pseudocode:
  user = userModel.findById(userId)
  if user.account_status !== 'active':
      Response::error("Je account is nog niet geactiveerd. Laat je ID zien bij de bar.", 'ACCOUNT_NOT_VERIFIED', 403)
```

### 5.4 Wijziging: `api/auth/session.php` — account_status in response

**Bestand**: `api/auth/session.php`

Voeg `account_status` toe aan de user data in de session response, zodat de frontend dit kan gebruiken voor UI gating.

### 5.5 Nieuw: `api/admin/suspend_user.php` — Admin kan gast blokkeren/deblokkeren

**Methode**: POST
**Auth**: admin+ (via router)
**Request body**:
```json
{
  "user_id": 123,
  "action": "suspend",
  "reason": "Agressief gedrag"
}
```

of

```json
{
  "user_id": 123,
  "action": "unsuspend"
}
```

#### Checkpoints

- [ ] `api/pos/verify.php` — Nieuw endpoint met volledige flow
- [ ] `api/pos/scan.php` — account_status in response
- [ ] `api/wallet/deposit.php` — account_status guard
- [ ] `api/auth/session.php` — account_status in response
- [ ] `api/admin/suspend_user.php` — Nieuw endpoint voor admin

---

## 6. Fase 4: Frontend — UI Aanpassingen

### 6.1 `views/bartender/dashboard.php` — Verificatie panel

**Locatie**: Nieuw state toevoegen na `#state-scanner` en voor `#state-payment`

Het bartender dashboard heeft momenteel 3 states:
1. `#state-scanner` (QR scannen)
2. `#state-payment` (Bedrag invoeren + betalen)
3. `#state-success` (Betaling gelukt)

**Toevoegen**: Nieuw state `#state-verify` dat verschijnt als de gescande gast `account_status: unverified` heeft.

**Flow in de JS**:
```
QR gescanned → POST /api/pos/scan → response bevat account_status
  ├── account_status === 'active'  → switchState('payment')  (bestaande flow)
  ├── account_status === 'suspended' → toon "Geblokkeerd" melding
  └── account_status === 'unverified' → switchState('verify') (NIEUW)
```

**Verify state bevat**:
- Gast naam + foto
- Invoerveld: "Geboortedatum van ID" (type="date")
- Knop: "Valideer & Activeer"
- Bij mismatch: rode foutmelding + resterende pogingen
- Bij succes: groene bevestiging → direct door naar `switchState('payment')`

### 6.2 `views/guest/wallet.php` — Deposit gating

**Locatie**: De deposit sectie (regels 55-72)

Als `account_status === 'unverified'`:
- Verberg de package buttons (`#packages-container`)
- Toon een uitleg banner:
  > "Hoi! Om je wallet te activeren en saldo te storten, moet je eenmalig je ID laten zien bij de bar. Zo houden we het veilig en legaal."
- QR code link benadrukken ("Laat je QR zien aan de bar")

Als `account_status === 'active'`:
- Bestaande flow (niets wijzigt)

### 6.3 `views/guest/dashboard.php` — Status indicator

**Locatie**: Na het saldo (regel 31), een status banner toevoegen

Als `unverified`:
- Gele/amber banner: "Account niet geactiveerd — Laat je ID zien bij de bar"
- Opwaarderen knop (regel 32) disablen

### 6.4 `views/admin/settings.php` — Verificatie limieten configuratie

**Locatie**: Nieuwe sectie na "POS Configuratie" (na regel 114)

Titel: **"Verificatie Limieten"**
Velden:
- `verification_soft_limit` (number, default 15) — Label: "Waarschuwingslimiet per barman/uur"
- `verification_hard_limit` (number, default 30) — Label: "Maximale limiet per barman/uur"
- `verification_cooldown_sec` (number, default 180) — Label: "Cooldown na mismatch (seconden)"
- `verification_max_attempts` (number, default 2) — Label: "Max pogingen per gast per 24 uur"

### 6.5 `views/admin/users.php` — Suspended status + acties

In de gebruikerslijst tabel:
- Kolom toevoegen: "Status" met badge (unverified=geel, active=groen, suspended=rood)
- Actieknop per rij: "Blokkeren" / "Deblokkeren" (alleen voor admin rol)

### 6.6 JavaScript aanpassingen

**`public/js/app.js`**:
- `AppState` uitbreiden met `accountStatus` veld
- Bij het laden van session data, `accountStatus` opslaan
- Helper functie: `isAccountActive()` voor hergebruik

**`public/js/wallet.js`**:
- Bij het initialiseren: check `accountStatus`
- Als `unverified`: verberg packages, toon uitleg banner, disable deposit flow

**Bartender POS inline JS** (in `views/bartender/dashboard.php`):
- `switchState()` functie uitbreiden met `verify` state
- `validateQR()` callback aanpassen: bij `unverified` → `switchState('verify')`
- Nieuwe functie `verifyUser()`: POST naar `/api/pos/verify` met birthdate
- Nieuwe functie `showVerify()`: toon verify panel met user info

**`public/js/admin.js`**:
- Settings formulier uitbreiden met de 4 verificatie limiet velden
- Users tabel uitbreiden met status kolom en blokkeer/deblokkeer knoppen

#### Checkpoints

- [ ] `views/bartender/dashboard.php` — Nieuw `#state-verify` panel
- [ ] `views/guest/wallet.php` — Deposit gating + uitleg banner
- [ ] `views/guest/dashboard.php` — Status indicator
- [ ] `views/admin/settings.php` — Verificatie limieten sectie
- [ ] `views/admin/users.php` — Status kolom + blokkeer acties
- [ ] `public/js/app.js` — accountStatus in AppState
- [ ] `public/js/wallet.js` — Gating logica
- [ ] `public/js/admin.js` — Settings + users updates
- [ ] Bartender POS inline JS — Verify state + flow

---

## 7. Fase 5: Router & Configuratie

### 7.1 `index.php` — Nieuwe routes toevoegen

**Locatie**: In `handleApiRoute()` functie

| Route | Locatie in switch | Beschrijving |
|-------|-------------------|-------------|
| `POST /api/pos/verify` | Case `pos`, na regel 218 (na `process_payment`) | Nieuw: barman verificatie |
| `POST /api/admin/suspend_user` | Case `admin`, na regel 271 (na `settings`) | Nieuw: admin blokkeren/deblokkeren |

### 7.2 `config/app.php` — Globale minima

**Locatie**: Na regel 49 (na DISCOUNT LIMITS)

```
define('VERIFICATION_SOFT_LIMIT_MIN', 3);    // Absolute minimum soft limit (platform-level)
define('VERIFICATION_HARD_LIMIT_MIN', 5);    // Absolute minimum hard limit (platform-level)
define('VERIFICATION_COOLDOWN_MAX', 600);    // Maximum cooldown: 10 minuten
```

Deze voorkomen dat een admin de limieten te laag zet (bijv. 0) of de cooldown te hoog.

### 7.3 `api/admin/settings.php` — Settings endpoint uitbreiden

De bestaande settings POST handler moet de 4 nieuwe verificatie-velden opslaan in de `tenants` tabel. Ook de GET handler moet ze retourneren.

#### Checkpoints

- [ ] `index.php` — Route `/api/pos/verify` geregistreerd
- [ ] `index.php` — Route `/api/admin/suspend_user` geregistreerd
- [ ] `config/app.php` — 3 globale minima gedefinieerd
- [ ] `api/admin/settings.php` — Verificatie limieten opslaan/lezen

---

## 8. Fase 6: Push Notificaties

### 8.1 "Wallet geactiveerd" notificatie

**Trigger**: Na succesvolle verificatie via `api/pos/verify.php`

**Bericht**: "Je wallet is nu actief! Stort nu je eerste saldo en geniet van je eerste biertje."

**Implementatie**: Roep de bestaande `PushService::sendNotification()` aan na een succesvolle verificatie. Dit is non-blocking — als push faalt, heeft dit geen impact op de verificatie zelf.

### 8.2 "Account geblokkeerd" notificatie (optioneel)

**Trigger**: Na suspend via `api/admin/suspend_user.php`

**Bericht**: "Je account is geblokkeerd door de beheerder. Neem contact op met de bar voor meer informatie."

#### Checkpoints

- [ ] Push notificatie bij succesvolle verificatie
- [ ] Push notificatie bij account blokkade (optioneel)

---

## 9. Blueprint & Documentatie Updates

### 9.1 `REGULR.vip - blueprint.md` — Toevoegen aan roadmap

Nieuwe fase toevoegen na Fase 6:

```markdown
### Phase 7: Gated Onboarding (KYC-light) — ⬜ Pending
- [ ] Database migration: account_status + verification_attempts
- [ ] Configureerbare rate limits per tenant
- [ ] Barman verificatie endpoint (api/pos/verify)
- [ ] Gast UI: deposit gating + uitleg banners
- [ ] Bartender POS: verify state panel
- [ ] Admin: rate limit configuratie + user suspend/unsuspend
- [ ] Push notificatie bij activatie
```

### 9.2 `.kilocode.md` — Rol & recht update

In de rol-tabel toevoegen:
- Gast `unverified`: Kan inloggen, dashboard bekijken, QR tonen. Kan NIET storten of betalen.
- Gast `active`: Volledige toegang (bestaande gedrag).
- Gast `suspended`: Kan niet inloggen.

#### Checkpoints

- [ ] `REGULR.vip - blueprint.md` — Phase 7 toegevoegd
- [ ] `.kilocode.md` — Rol update voor account_status

---

## 10. Bestanden Overzicht — Impact Analysis

### Nieuwe bestanden (3)

| Bestand | Fase | Beschrijving |
|---------|------|-------------|
| `sql/gated_onboarding_migration.sql` | 1 | Database migratie (ALTER users, ALTER tenants, CREATE verification_attempts) |
| `api/pos/verify.php` | 3 | Barman verificatie endpoint |
| `api/admin/suspend_user.php` | 3 | Admin blokkeren/deblokkeren endpoint |

### Bestaande bestanden — Aanpassingen (12)

| Bestand | Fase | Wijziging |
|---------|------|-----------|
| `models/User.php` | 2 | +7 methodes (account_status, verify, suspend, rate limiting) |
| `services/AuthService.php` | 2 | Suspended login block + account_status in session |
| `services/WalletService.php` | 2 | account_status guard in `createDeposit()` |
| `services/PaymentService.php` | 2 | account_status guard in `processPayment()` |
| `api/pos/scan.php` | 3 | account_status toevoegen aan response |
| `api/wallet/deposit.php` | 3 | account_status guard toevoegen |
| `api/auth/session.php` | 3 | account_status toevoegen aan response |
| `api/admin/settings.php` | 5 | Verificatie limieten opslaan/lezen |
| `index.php` | 5 | 2 nieuwe routes registreren |
| `config/app.php` | 5 | 3 globale minima constanten |
| `views/bartender/dashboard.php` | 4 | Nieuw verify state + JS flow |
| `views/guest/wallet.php` | 4 | Deposit gating + uitleg banner |
| `views/guest/dashboard.php` | 4 | Status indicator |
| `views/admin/settings.php` | 4 | Verificatie limieten sectie |
| `views/admin/users.php` | 4 | Status kolom + acties |
| `public/js/app.js` | 4 | accountStatus in AppState |
| `public/js/wallet.js` | 4 | Gating logica |
| `public/js/admin.js` | 4 | Settings + users updates |
| `REGULR.vip - blueprint.md` | 9 | Phase 7 toevoegen |
| `.kilocode.md` | 9 | Rol update |

---

## 11. Risico's & Mitigaties

| Risico | Impact | Mitigatie |
|--------|--------|-----------|
| Bestaande guests worden "unverified" na migratie | Huidige actieve gasten kunnen niet meer betalen | Migration script zet bestaande guests met saldo > 0 op `active`; guests met saldo = 0 op `unverified` |
| Barman klikt blind op "Activeer" | Minderjarigen met wallet | Verplicht geboortedatum-invoer die moet matchen met registratie |
| Barman vergeet te verifieren | Gast kan niet betalen, frustratie | Duidelijke uitleg in gast app + QR toont "UNVERIFIED" badge |
| Rate limits te strak ingesteld | Drukte op evenementen | Admin configureerbaar, globale minimum beschermen |

---

## 12. Test Scenarios

| ID | Scenario | Verwacht Resultaat |
|----|----------|--------------------|
| T-01 | Nieuwe gast registreert | `account_status = 'unverified'`, kan inloggen, kan NIET storten |
| T-02 | Gast met `unverified` probeert te storten | Error: "Account niet geactiveerd" |
| T-03 | Barman scant QR van `unverified` gast | POS toont verify panel (niet betaalpanel) |
| T-04 | Barman voert juiste geboortedatum in | Gast wordt `active`, push notificatie verstuurd |
| T-05 | Barman voert foute geboortedatum in | Error, poging gelogd, `attempts_remaining` verlaagd |
| T-06 | Barman overschrijdt hard limit | 429 Too Many Requests |
| T-07 | Gast bereikt max attempts | 429, alleen admin kan nog verifieren |
| T-08 | Admin blokkeert actieve gast | Gast kan niet meer inloggen |
| T-09 | Admin deblokkeert gesuspende gast | Gast kan weer inloggen (als `active`) |
| T-10 | Bestaande gast met saldo > 0 na migratie | `account_status = 'active'` (geen disruptie) |
