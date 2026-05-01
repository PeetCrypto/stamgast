# Platform Fee & Mollie Connect - Hoe Het Werkt

## 🎯 Doel
Het implementeren van een **platform fee systeem** (Marketplace) voor het REGULR.vip platform volgens **Mollie Connect** architectuur. Dit is de enige oplossing die voldoet aan de eisen:
- ✅ **Hufter-proof**: Tenants kunnen de fee niet omzeilen (geen toegang tot API keys)
- ✅ **Geen geldbeheer**: Platform ontvangt fee direct van Mollie, geen tijdelijke hold
- ✅ **Audit-proof**: Alle fee transacties zijn immutable gesnapshottet en gelabeld
- ✅ **Facturatie-ready**: Verzamelfacturen per week/maand inclusief BTW
- ✅ **Future-proof**: Architectuur is 1-op-1 mappable naar Stripe Connect

## 🏗️ Architectuur

```
┌─────────────────────────────────────────────────────────────────────┐
│                        BETALINGSSTROOM (Deposit)                      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Gast klikt "Opwaarderen €100"                                       │
│         ↓                                                           │
│  WalletService::createDeposit()                                       │
│    ├─ Valideer tenant                                                 │
│    ├─ HARD FAIL: mollie_connect_status !== 'active' ❌                │
│    ├─ Bereken fee:                                                  │
│    │     fee = max(floor(100 * 1% / 100), 25) = €1.00              │
│    ├─ Maak transactie (status=pending)                              │
│    ├─ Maak PlatformFee record (snapshot: fee% = 1.00, fee_min=25)     │
│    ├─ MollieService::createPayment()                                │
│    │     ├─ API Key: MOLLIE_CONNECT_API_KEY (platform-level)        │
│    │     ├─ onBehalfOf: tenant.mollie_connect_id                     │
│    │     ├─ applicationFee: { amount: "1.00", currency: "EUR" }       │
│    │     └─ Returns checkout_url                                      │
│    └─ Return URL naar gast                                          │
│         ↓                                                           │
│  Gast voltooit betaling op Mollie                                   │
│         ↓                                                           │
│  Mollie stuurt webhook → /api/mollie/webhook                      │
│         ↓                                                           │
│  Webhook handler:                                                   │
│    ├─ Haal payment status op van Mollie                            │
│    ├─ Extraheer application_fee_cents uit response                  │
│    ├─ Update platform_fees.fee_amount_cents = Mollie's waarde     │
│    ├─ Update platform_fees.net_amount_cents = gross - fee           │
│    ├─ Credit wallet via WalletService::processDeposit()            │
│    └─ platform_fees.status = 'collected'                         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## 🔐 Beveiliging

### Tenant kan fee niet omzeilen
| Aanvalspoging | Voorkomen door |
|---------------|----------------|
| Wijzig Mollie API key | ❌ `mollie_api_key` verwijderd uit `$allowedFields` in Tenant model |
| Gebruik eigen Mollie account | ❌ Alle betalingen gebruiken **platform-level** API key (`MOLLIE_CONNECT_API_KEY`) |
| Fee uitschakelen per-transactie | ❌ Fee berekend server-side, opgeslagen in `platform_fees` voor Mollie call |
| Fee percentage wijzigen in DB | ❌ `fee_percentage` is snapshot; wijzigen tenant config verandert niets historisch |
| Webhook manipuleren | ❌ Webhook gebruikt `applicationFee.amount` van Mollie; lokale calc genegeerd |
| Replay attack | ❌ `mollie_payment_id` unique constraint + `transaction_id` 1:1 |

### Toegangscontrole
| Rol | Kan fee config wijzigen? | Kan fees bekijken? | Kan facturen genereren? |
|------|----------------------|----------------|------------------------|
| superadmin | ✅ Ja | ✅ Ja | ✅ Ja |
| admin (tenant) | ❌ Nee | ❌ Eigen tenant alleen (via tenant_detail) | ❌ Nee |
| bartender | ❌ Nee | ❌ Nee | ❌ Nee |
| gast | ❌ Nee | ❌ Nee | ❌ Nee |

## 💰 Fee Berekening

```php
/**
 * Bereken platform fee
 *
 * @param int   $amountCents   Deposit amount (gross)
 * @param float $percentage    Fee % (e.g. 1.00)
 * @param int   $minCents      Minimum fee (e.g. 25 = €0.25)
 * @return int  Fee in cents (rounded down, respecting minimum)
 */
function calculateFee(int $amountCents, float $percentage, int $minCents): int
{
    $calculated = (int) floor($amountCents * $percentage / 100);
    return max($calculated, $minCents);
}
```

**Snapshot bij transactie aanmaken:**

In `WalletService::createDeposit()`:
```php
$tenant = $tenantModel->findById($tenantId);
$feeCents = calculateFee(
    $amountCents,
    (float) $tenant['platform_fee_percentage'],
    (int) $tenant['platform_fee_min_cents']
);

// Sla DIRECT op voor Mollie call
$platformFeeId = (new PlatformFee($db))->create([
    'tenant_id'          => $tenantId,
    'transaction_id'     => $transactionId,
    'user_id'            => $userId,
    'gross_amount_cents' => $amountCents,
    'fee_percentage'     => $tenant['platform_fee_percentage'], // SNAPSHOT
    'fee_min_cents'      => $tenant['platform_fee_min_cents'],  // SNAPSHOT
    'fee_amount_cents'   => 0,    // Wordt gevuld door webhook
    'net_amount_cents'   => 0,    // Wordt berekend: gross - fee
]);
```

**Update via webhook:**
```php
// Van Mollie response:
$applicationFeeCents = (int) ($paymentStatus['application_fee_cents'] ?? 0);

// Authoritatieve update — GEEN HERBEREKENING
$platformFeeModel->updateFeeFromMollie($feeId, $applicationFeeCents);
```

## 🧾 Facturatie

**Wanneer:** Cron job of handmatige trigger (super-admin)  
**Periode:** Per-tenant `invoice_period` (week of maand)  
**Scope:** Alle `platform_fees` met `status = 'collected'` in periode

**Algoritme:**

```php
function genereerMaandelijkseFactuur(int $tenantId, string $periodStart, string $periodEnd): array
{
    // 1. Controleer bestaand
    if (PlatformInvoice::bestaatVoorPeriode($tenantId, $periodStart, $periodEnd)) {
        throw new RuntimeException('Factuur bestaat al voor deze periode');
    }

    // 2. Verzamel fees in periode
    $fees = PlatformFee::getVerzameldVoorPeriode($tenantId, $periodStart, $periodEnd);
    if (empty($fees)) {
        throw new RuntimeException('Geen verzamelde fees in deze periode');
    }

    // 3. Bereken totalen
    $grossTotal = array_sum(array_column($fees, 'gross_amount_cents'));
    $feeTotal   = array_sum(array_column($fees, 'fee_amount_cents'));
    $btwAmount  = (int) floor($feeTotal * PLATFORM_FEE_BTW_PERCENTAGE / 100);
    $totalIncl  = $feeTotal + $btwAmount;
    $txCount    = count($fees);

    // 4. Maak factuur
    $factuurNummer = (new PlatformInvoice($db))->genereerFactuurNummer();
    $factuurId = (new PlatformInvoice($db))->create([
        'tenant_id'            => $tenantId,
        'invoice_number'       => $factuurNummer,
        'period_start'         => $periodStart,
        'period_end'           => $periodEnd,
        'period_type'          => $tenant['invoice_period'],
        'transaction_count'       => $txCount,
        'gross_total_cents'    => $grossTotal,
        'fee_total_cents'      => $feeTotal,
        'btw_percentage'         => PLATFORM_FEE_BTW_PERCENTAGE,
        'btw_amount_cents'     => $btwAmount,
        'total_incl_btw_cents' => $totalIncl,
        'status'               => 'draft',
    ]);

    // 5. Koppel fees aan factuur (batch)
    $feeIds = array_column($fees, 'id');
    PlatformFee::koppelAanFactuur($feeIds, $factuurId);

    return ['factuur_id' => $factuurId, 'nummer' => $factuurNummer];
}
```

## 🔗 Mollie Connect Onboarding

Tenant moet hun Mollie account eenmalig koppelen. Daarna gaan alle betalingen via hun gekoppelde organisatie.

### 1. Start Connect (Super-admin klikt "Connect Mollie")

```
GET /api/superadmin/tenants?action=initiate_connect&tenant_id=X
```

### 2. Tenant autoriseert op Mollie

Gebruiker logt in op Mollie, ziet:
> "REGULR.vip wil betalingen voor je maken. Je krijgt €99 van elke €100. REGULR.vip houdt €1."

Gebruiker klikt **Autoriseer** → Mollie redirect naar `connect-callback?code=XXX&state=YYY`

### 3. Callback handler

**Endpoint:** `GET /api/mollie/connect-callback` (nieuw bestand)

```php
$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

// Valideer state matches stored waarde (CSRF check)
if ($state !== $_SESSION['mollie_connect_state']) {
    Response::error('Ongeldige state', 'INVALID_STATE', 400);
}

$mollie = new MollieService(MOLLIE_CONNECT_API_KEY, MOLLIE_MODE_DEFAULT);
$tokenData = $mollie->exchangeConnectCode($code, $redirectUri);

// Sla op bij tenant
$tenantModel->update($tenantId, [
    'mollie_connect_id'     => $tokenData['organization_id'],
    'mollie_connect_status' => 'active',
]);

Response::success(['connected' => true, 'organization_id' => $tokenData['organization_id']]);
```

**Status flow:**
- `none` → `pending` (na OAuth start, optioneel)
- `pending` → `active` (na callback success)
- `active` → `suspended` (tenant blokkeert betalingen, handmatige super-admin actie)
- `active` → `revoked` (tenant trekt app in van Mollie dashboard — webhook nodig?)

## 🧪 Testen Checklist

### Unit Tests (handmatig / phpunit)
- [x] `PlatformFee::create()` — slaagt met geldige data
- [x] `PlatformFee::updateFeeFromMollie()` — update fee amount correct
- [x] `PlatformInvoice::generateInvoiceNumber()` — sequentieel per maand, resets maandelijks
- [x] `PlatformInvoice::existsForPeriod()` — detecteert duplicaten
- [x] `calculateFee()` — edge cases: 0 amount, exact min, below min, large amount

### Integratie Tests
- [x] **Deposit flow end-to-end (mock mode):**
  1. Tenant status = 'active' + mollie_connect_id ingesteld
  2. POST /api/wallet/deposit { amount_cents: 10000 }
  3. Controleer: transactie aangemaakt, platform_fees rij aangemaakt (fee_amount=0 in eerste instantie)
  4. Simuleer webhook POST met mock payment_id
  5. Controleer: platform_fees.fee_amount_cents geüpdatet naar 100 (1%), status='collected'
  6. Controleer: wallet saldo gecrediteerd €100 (bruto) — gast ontvangt volledige waarde

- [x] **Hard fail zonder Connect:**
  1. Zet tenant mollie_connect_status = 'none'
  2. POST /api/wallet/deposit
  3. Verwacht: RuntimeException 'Tenant heeft geen actief Mollie Connect account'

- [x] **Factuur generatie:**
  1. Maak 3 verzamelde platform_fees voor tenant in April 2026
  2. POST /api/superadmin/invoices action=generate { tenant_id: X, period_start: '2026-04-01', period_end: '2026-04-30' }
  3. Controleer: factuur aangemaakt, fees gekoppeld (status='invoiced')
  4. Controleer: totalen correct (bruto som, fee som, BTW 21%, totaal incl BTW)

### Beveiliging Tests
- [x] Poging tot updaten `mollie_api_key` via `api/superadmin/tenants` → moet genegeerd/verwijderd worden
- [x] Tenant admin roept `api/wallet/deposit` aan met inactieve Connect → 500 error met duidelijke melding
- [x] Webhook zonder bijpassende `platform_fees` record → 200 OK (geen crash)
- [x] SQL injectie op fee samenvatting endpoints → PDO prepared statements veilig

### UI Tests (handmatig)
- [x] Superadmin tenant detail pagina toont fee config velden
- [x] Fee config opslaan persisteert naar DB
- [x] Fees pagina toont per-tenant tabel, filters werken
- [x] Facturen pagina: genereer knop maakt concept, markeren als verzonden update tijdstempel
- [x] Dashboard fee kaarten update (cache bust of herladen)

## 📦 Deployment Checklist

### Pre-deployment
- [x] `.env` geconfigureerd:
  ```
  MOLLIE_CONNECT_API_KEY=live_...
  MOLLIE_CONNECT_CLIENT_ID=...
  MOLLIE_CONNECT_CLIENT_SECRET=...
  ```
- [x] Database migratie uitgevoerd: `mysql -u root -p stamgast_db < sql/platform_fee_migration.sql`
- [x] Bevestig `tenants` bestaande rijen hebben standaardwaarden:
  ```sql
  UPDATE tenants
     SET platform_fee_percentage = 1.00,
         platform_fee_min_cents = 25,
         mollie_connect_status = 'none',
         invoice_period = 'month'
   WHERE platform_fee_percentage IS NULL;
  ```
- [x] Mollie Connect Partner account goedgekeurd & API keys gegenereerd
- [x] OAuth redirect URI toegestaan in Mollie dashboard: `https://yourdomain.nl/api/mollie/connect-callback`

### Post-deployment
- [x] Test deposit flow in **mock mode** eerst (alle tenants `mollie_status='mock'`)
- [x] Koppel één test tenant via OAuth
- [x] Voer echte deposit uit met Mollie test kaart
- [x] Controleer of fee verschijnt in `platform_fees` na webhook
- [x] Genereer factuur, controleer PDF generatie (indien geïmplementeerd)
- [x] Controleer e-mailbezorging (indien factuur e-mail geïmplementeerd)
- [x] Monitor webhook logs op fouten

## 🔄 Toekomstige Verbeteringen

| Functie | Prioriteit | Notities |
|---------|------------|----------|
| **Stripe Connect ondersteuning** | LAAG | Architectuur is 1:1 mappable — maak `StripeService` met dezelfde interface |
| **Factuur PDF generatie** | MEDIUM | Gebruik dompdf of externe service; opslaan in `pdf_path` |
| **Factuur e-mail automatisering** | MEDIUM | Wanneer status wordt 'sent', e-mail PDF naar `invoice_email` |
| **Fee waivers / promoties** | LAAG | Voeg `fee_override` kolom toe op platform_fees (NULL = standaard) |
| **Abonnementen (terugkerende stortingen)** | LAAG | Zou nieuwe transactietype nodig hebben; fee logica hetzelfde |
| **Terugbetaling afhandeling** | MEDIUM | Wanneer deposit terugbetaald: moet fee ook terugbetaald worden? Momenteel onduidelijk Mollie gedrag |
| **Afrekening afstemming** | HOOG | Match `mollie_settlement_id` met werkelijke bankoverschrijvingen |
| **Multi-valuta** | LAAG | Alle bedragen in EUR voor nu; schema ondersteunt valutakolom |

## 📞 Contact & Ondersteuning

Voor vragen over dit implementatieplan, neem contact op met de platform architect.