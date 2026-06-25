<?php
declare(strict_types=1);
/**
 * Superadmin - Invoice Detail / Print View
 * REGULR.vip Loyalty Platform
 *
 * Renders a single platform invoice as a print-ready A4 document.
 * Browser "Print" (Ctrl+P) → "Save as PDF" produces the download.
 *
 * Query: ?id=<invoice_id>
 */

require_once __DIR__ . '/../../models/PlatformInvoice.php';
require_once __DIR__ . '/../../models/PlatformSetting.php';

$db = Database::getInstance()->getConnection();
$invoiceModel = new PlatformInvoice($db);
$settingModel = new PlatformSetting($db);

$invoiceId = max(1, (int) ($_GET['id'] ?? 0));
$invoice = $invoiceModel->findById($invoiceId);

if (!$invoice) {
    http_response_code(404);
    require VIEWS_PATH . 'shared/header.php';
    echo '<div class="container" style="text-align:center;padding:4rem;max-width:600px;margin:0 auto;">'
       . '<h1 style="font-size:48px;margin-bottom:0;color:var(--accent-primary);">404</h1>'
       . '<p>Factuur niet gevonden.</p>'
       . '<a href="' . BASE_URL . '/superadmin/invoices" class="btn btn-primary" style="margin-top:var(--space-md);">&larr; Terug naar facturen</a>'
       . '</div>';
    require VIEWS_PATH . 'shared/footer.php';
    exit;
}

$fees = $invoiceModel->getFees($invoiceId);

// -- Bedrijfsgegevens (afzender) uit platform_settings met defaults --
$companyName    = $settingModel->get('invoice_company_name') ?: APP_NAME;
$companyAddress = $settingModel->get('invoice_company_address') ?: '';
$companyPostal  = $settingModel->get('invoice_company_postal') ?: '';
$companyCity    = $settingModel->get('invoice_company_city') ?: '';
$companyCountry = $settingModel->get('invoice_company_country') ?: 'Nederland';
$companyBtw     = $settingModel->get('invoice_btw_number') ?: '';
$companyKvk     = $settingModel->get('invoice_kvk') ?: '';
$companyIban    = $settingModel->get('invoice_iban') ?: '';
$companyEmail   = $settingModel->get('invoice_email') ?: '';
$companyWebsite = $settingModel->get('invoice_website') ?: '';

// -- Helpers --
function fmtEuro(int $cents): string
{
    return '&euro; ' . number_format($cents / 100, 2, ',', '.');
}
function fmtDate(string $date): string
{
    return date('d-m-Y', strtotime($date));
}
function fmtDateFull(string $date): string
{
    $months = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
    return (int)date('j', strtotime($date)) . ' ' . $months[(int)date('n', strtotime($date))-1] . ' ' . date('Y', strtotime($date));
}

$statusLabels = [
    'draft'     => 'Concept',
    'sent'      => 'Verzonden',
    'paid'      => 'Betaald',
    'overdue'   => 'Te Laat',
    'cancelled' => 'Geannuleerd',
];
$statusLabel = $statusLabels[$invoice['status']] ?? $invoice['status'];

// Stapelregels optellen voor totaal (som-check)
$sumGross = 0;
$sumFee   = 0;
foreach ($fees as $fee) {
    $sumGross += (int) ($fee['gross_amount_cents'] ?? 0);
    $sumFee   += (int) ($fee['fee_amount_cents'] ?? 0);
}

$periodTypeLabel = ($invoice['period_type'] === 'week') ? 'week' : 'maand';
$periodLabel = ucfirst($periodTypeLabel) . ' ' . fmtDateFull($invoice['period_start']);

// Paper CSS class (watermark)
$paperClass = ($invoice['status'] === 'draft') ? 'is-draft' : '';
$paperClass = ($invoice['status'] === 'paid') ? 'is-paid' : $paperClass;
?>
<style>
  /* ── Invoice page: screen chrome ─────────────────────────────────────── */
  .invoice-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
  }
  .invoice-toolbar .btn { text-decoration: none; }

  /* The white paper document (screen preview = card, print = full A4) */
  .invoice-paper {
    background: #ffffff;
    color: #111;
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.35);
    width: 210mm;
    max-width: 100%;
    margin: 0 auto;
    padding: 16mm 16mm 14mm 16mm;
    font-family: 'Inter', Arial, Helvetica, sans-serif;
    font-size: 10.5pt;
    line-height: 1.5;
    position: relative;
  }

  /* draft watermark */
  .invoice-paper.is-draft::before {
    content: 'CONCEPT';
    position: fixed;
    top: 35%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-35deg);
    font-size: 90px;
    font-weight: 900;
    color: rgba(200,200,200,0.45);
    pointer-events: none;
    z-index: 0;
    letter-spacing: 8px;
  }
  .invoice-paper.is-paid::after {
    content: 'BETAALD';
    position: fixed;
    top: 35%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-35deg);
    font-size: 80px;
    font-weight: 900;
    color: rgba(76,175,80,0.18);
    pointer-events: none;
    z-index: 0;
    letter-spacing: 6px;
  }
  .invoice-paper > * { position: relative; z-index: 1; }

  /* ── Document header (logo row from / to) ───────────────────────────── */
  .invoice-doc-head {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    align-items: start;
    margin-bottom: 22px;
  }
  .invoice-from-title,
  .invoice-to-title {
    font-size: 7.5pt;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 6px;
  }
  .invoice-from-name,
  .invoice-to-name {
    font-size: 12pt;
    font-weight: 700;
    color: #111;
    margin-bottom: 2px;
  }
  .invoice-from-detail,
  .invoice-to-detail {
    font-size: 9.5pt;
    color: #444;
    line-height: 1.6;
  }
  .invoice-from-detail .inv-label { color: #888; }

  /* ── Invoice meta block (nr, date, period, status) ──────────────────── */
  .invoice-meta {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    margin-bottom: 22px;
    border-top: 2px solid #FFC107;
    border-bottom: 2px solid #FFC107;
    padding: 12px 0;
  }
  .invoice-meta-item {
    padding: 0 14px;
    border-left: 1px solid #e0e0e0;
  }
  .invoice-meta-item:first-child { border-left: none; padding-left: 0; }
  .invoice-meta-label {
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #888;
    margin-bottom: 4px;
  }
  .invoice-meta-value {
    font-size: 10.5pt;
    font-weight: 600;
    color: #111;
  }
  .invoice-meta-value.status-pill {
    display: inline-block;
    padding: 2px 12px;
    border-radius: 10px;
    font-size: 9pt;
    font-weight: 700;
  }
  .status-draft     { background: #eee; color: #777; }
  .status-sent      { background: #E3F2FD; color: #1565C0; }
  .status-paid      { background: #E8F5E9; color: #2E7D32; }
  .status-overdue   { background: #FFEBEE; color: #C62828; }
  .status-cancelled { background: #fce4ec; color: #7B1FA2; }

  /* ── Description line ───────────────────────────────────────────────── */
  .invoice-desc {
    font-size: 10pt;
    color: #333;
    margin-bottom: 18px;
    padding: 8px 12px;
    background: #FAFAFA;
    border-left: 3px solid #FFC107;
    border-radius: 0 4px 4px 0;
  }
  .invoice-desc strong { color: #111; }

  /* ── Line-items table ──────────────────────────────────────────────── */
  .invoice-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
    font-size: 9.5pt;
  }
  .invoice-table thead th {
    background: #F5F5F5;
    color: #555;
    font-weight: 700;
    font-size: 7.5pt;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 9px 10px;
    border-bottom: 2px solid #ddd;
    text-align: left;
  }
  .invoice-table thead th.num { text-align: right; }
  .invoice-table tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid #eee;
    color: #222;
  }
  .invoice-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .invoice-table tbody tr:nth-child(even) td { background: #fdfdfd; }
  .invoice-table tbody tr:hover td { background: #FFFDE7; }

  /* ── Totals block ──────────────────────────────────────────────────── */
  .invoice-totals {
    display: grid;
    grid-template-columns: 1fr 260px;
    gap: 30px;
    margin-bottom: 20px;
  }
  .invoice-notes-box {
    font-size: 9pt;
    color: #555;
    line-height: 1.55;
  }
  .invoice-notes-box h4 {
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #888;
    margin: 0 0 6px 0;
    font-weight: 700;
  }
  .invoice-totals-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10pt;
  }
  .invoice-totals-table td {
    padding: 5px 10px;
    color: #333;
  }
  .invoice-totals-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .invoice-totals-table .row-btw td { color: #555; font-size: 9.5pt; }
  .invoice-totals-table .row-grand td {
    font-size: 12.5pt;
    font-weight: 800;
    color: #111;
    border-top: 2px solid #111;
    padding-top: 8px;
  }

  /* ── Footer ────────────────────────────────────────────────────────── */
  .invoice-footer {
    margin-top: 24px;
    padding-top: 14px;
    border-top: 1px solid #e0e0e0;
    font-size: 8pt;
    color: #888;
    text-align: center;
    line-height: 1.7;
  }
  .invoice-footer .footer-strong { color: #444; font-weight: 600; }
  .invoice-footer .mollie-note {
    display: inline-block;
    margin-top: 6px;
    padding: 4px 12px;
    background: #FFF8E1;
    border-radius: 4px;
    color: #7a6a1f;
    font-size: 8pt;
  }

  /* ── Print stylesheet ──────────────────────────────────────────────── */
  @media print {
    @page {
      size: A4;
      margin: 12mm 12mm 10mm 12mm;
    }
    body {
      background: #fff !important;
      color: #000 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    /* Hide app chrome */
    .nav-top,
    #viewing-as-banner,
    .pwa-install-banner,
    .invoice-toolbar,
    footer,
    .no-print { display: none !important; }

    /* Reset dark-theme variables that leak into print */
    :root {
      --text-primary: #000;
      --text-secondary: #555;
    }

    /* Paper fills the page */
    .invoice-paper {
      box-shadow: none !important;
      border-radius: 0 !important;
      width: 100% !important;
      max-width: 100% !important;
      margin: 0 !important;
      padding: 4mm 2mm 2mm 2mm;
      font-size: 10pt;
    }

    /* Keep table header visible */
    .invoice-table thead th {
      background: #F5F5F5 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    /* Watermarks visible in print */
    .invoice-paper.is-draft::before { color: rgba(180,180,180,0.35); }
    .invoice-paper.is-paid::after   { color: rgba(76,175,80,0.15); }

    /* Avoid breaking tables across pages */
    .invoice-table { page-break-inside: avoid; }
    .invoice-totals { page-break-inside: avoid; }
    .invoice-footer { page-break-inside: avoid; }
  }
</style>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="max-width: 900px; margin: 0 auto; padding: var(--space-md);">

  <!-- Toolbar (hidden in print) -->
  <div class="invoice-toolbar no-print">
    <a href="<?= BASE_URL ?>/superadmin/invoices" class="btn btn-secondary">&larr; Terug</a>
    <div style="display:flex;gap:var(--space-sm);">
      <button onclick="window.print()" class="btn btn-primary">&#128424; Print / PDF</button>
    </div>
  </div>

  <!-- Invoice document -->
  <div class="invoice-paper <?= $paperClass ?>">

    <!-- From / To -->
    <div class="invoice-doc-head">
      <div>
        <div class="invoice-from-title">Van</div>
        <div class="invoice-from-name"><?= sanitize($companyName) ?></div>
        <div class="invoice-from-detail">
          <?php if ($companyAddress): ?><span class="inv-label">Adres:</span> <?= sanitize($companyAddress) ?><br><?php endif; ?>
          <?php if ($companyPostal || $companyCity): ?>
            <span class="inv-label">&nbsp;</span> <?= sanitize(trim($companyPostal . ' ' . $companyCity)) ?><br>
          <?php endif; ?>
          <?php if ($companyCountry): ?><span class="inv-label">&nbsp;</span> <?= sanitize($companyCountry) ?><br><?php endif; ?>
          <?php if ($companyBtw): ?><span class="inv-label">BTW:</span> <?= sanitize($companyBtw) ?><br><?php endif; ?>
          <?php if ($companyKvk): ?><span class="inv-label">KVK:</span> <?= sanitize($companyKvk) ?><br><?php endif; ?>
          <?php if ($companyIban): ?><span class="inv-label">IBAN:</span> <?= sanitize($companyIban) ?><br><?php endif; ?>
          <?php if ($companyEmail): ?><span class="inv-label">E-mail:</span> <?= sanitize($companyEmail) ?><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="invoice-to-title">Aan</div>
        <div class="invoice-to-name"><?= sanitize($invoice['tenant_name']) ?></div>
        <div class="invoice-to-detail">
          <?php if (!empty($invoice['contact_name'])): ?><?= sanitize($invoice['contact_name']) ?><br><?php endif; ?>
          <?php if (!empty($invoice['address'])): ?><?= sanitize($invoice['address']) ?><br><?php endif; ?>
          <?php if (!empty($invoice['postal_code']) || !empty($invoice['city'])): ?>
            <?= sanitize(trim(($invoice['postal_code'] ?? '') . ' ' . ($invoice['city'] ?? ''))) ?><br>
          <?php endif; ?>
          <?php if (!empty($invoice['btw_number'])): ?><span class="inv-label">BTW:</span> <?= sanitize($invoice['btw_number']) ?><br><?php endif; ?>
          <?php if (!empty($invoice['contact_email'])): ?><span class="inv-label">E-mail:</span> <?= sanitize($invoice['contact_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Meta -->
    <div class="invoice-meta">
      <div class="invoice-meta-item">
        <div class="invoice-meta-label">Factuurnummer</div>
        <div class="invoice-meta-value" style="font-family:monospace;font-size:12pt;"><?= sanitize($invoice['invoice_number']) ?></div>
      </div>
      <div class="invoice-meta-item">
        <div class="invoice-meta-label">Factuurdatum</div>
        <div class="invoice-meta-value"><?= fmtDateFull($invoice['created_at']) ?></div>
      </div>
      <div class="invoice-meta-item">
        <div class="invoice-meta-label">Periode</div>
        <div class="invoice-meta-value"><?= fmtDate($invoice['period_start']) ?> &mdash; <?= fmtDate($invoice['period_end']) ?></div>
      </div>
      <div class="invoice-meta-item">
        <div class="invoice-meta-label">Status</div>
        <div class="invoice-meta-value">
          <span class="invoice-meta-value status-pill status-<?= $invoice['status'] ?>"><?= $statusLabel ?></span>
        </div>
      </div>
    </div>

    <!-- Description -->
    <div class="invoice-desc">
      <strong>Verzamelfactuur platform fees</strong> &mdash;
      <?= (int) $invoice['transaction_count'] ?> transactie<?= ((int)$invoice['transaction_count'] !== 1) ? 's' : '' ?> in
      <?= $periodLabel ?>.
      <?php if (!empty($invoice['notes'])): ?><br><em>Notitie: <?= sanitize($invoice['notes']) ?></em><?php endif; ?>
    </div>

    <!-- Summary line (no per-transaction rows) -->
    <table class="invoice-table">
      <thead>
        <tr>
          <th>Omschrijving</th>
          <th class="num" style="width:80px;">Aantal</th>
          <th class="num" style="width:120px;">Volume</th>
          <th class="num" style="width:120px;">Bedrag</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            Platform fee over <?= (int) $invoice['transaction_count'] ?> saldo-storting<?= ((int)$invoice['transaction_count'] !== 1) ? 'en' : '' ?>
            in <?= $periodLabel ?>
          </td>
          <td class="num"><?= (int) $invoice['transaction_count'] ?></td>
          <td class="num"><?= fmtEuro((int) $invoice['gross_total_cents']) ?></td>
          <td class="num"><?= fmtEuro((int) $invoice['fee_total_cents']) ?></td>
        </tr>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="invoice-totals">
      <div class="invoice-notes-box">
        <?php if (!empty($companyWebsite)): ?>
          <h4>Contact</h4>
          <?= sanitize($companyWebsite) ?><br><br>
        <?php endif; ?>
        <h4>Uitleg</h4>
        De platform fee wordt per transactie verrekend via Mollie Connect.
        Deze verzamelfactuur dient als specificatie en BTW-verrekening over de
        verzamelde fees in de aangegeven periode.
      </div>
      <table class="invoice-totals-table">
        <tr>
          <td>Subtotaal (platform fee excl. BTW)</td>
          <td class="num"><?= fmtEuro((int) $invoice['fee_total_cents']) ?></td>
        </tr>
        <tr class="row-btw">
          <td>BTW <?= number_format((float) $invoice['btw_percentage'], 0, ',', '.') ?>%</td>
          <td class="num"><?= fmtEuro((int) $invoice['btw_amount_cents']) ?></td>
        </tr>
        <tr class="row-grand">
          <td>Totaal</td>
          <td class="num"><?= fmtEuro((int) $invoice['total_incl_btw_cents']) ?></td>
        </tr>
      </table>
    </div>

    <!-- Footer -->
    <div class="invoice-footer">
      <div class="footer-strong"><?= sanitize($companyName) ?></div>
      <?php if ($companyBtw): ?>BTW: <?= sanitize($companyBtw) ?> &middot; <?php endif; ?>
      <?php if ($companyKvk): ?>KVK: <?= sanitize($companyKvk) ?> &middot; <?php endif; ?>
      <?php if ($companyIban): ?>IBAN: <?= sanitize($companyIban) ?><?php endif; ?>
      <br>
      <?php if ($companyEmail): ?><?= sanitize($companyEmail) ?> &middot; <?php endif; ?>
      <?php if ($companyWebsite): ?><?= sanitize($companyWebsite) ?><?php endif; ?>
      <div class="mollie-note">&#128200; Verrekend via Mollie bij betaling</div>
    </div>

  </div><!-- /invoice-paper -->

  <!-- Bottom back-link (hidden in print) -->
  <div class="no-print" style="text-align:center;margin-top:var(--space-lg);">
    <a href="<?= BASE_URL ?>/superadmin/invoices" class="btn btn-secondary">&larr; Terug naar facturen</a>
    <button onclick="window.print()" class="btn btn-primary">&#128424; Print / PDF</button>
  </div>

</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
