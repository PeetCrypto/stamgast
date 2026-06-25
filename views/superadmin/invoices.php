<?php
declare(strict_types=1);
/**
 * Superadmin - Invoice Management
 * REGULR.vip Loyalty Platform
 *
 * List invoices, generate new ones, change status, view details.
 */

require_once __DIR__ . '/../../models/PlatformInvoice.php';
require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../models/Tenant.php';
require_once __DIR__ . '/../../models/PlatformSetting.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$invoiceModel = new PlatformInvoice($db);
$tenantModel = new Tenant($db);
$settingModel = new PlatformSetting($db);

// Lijst van bedrijfsgegevens keys (gebruikt door save-handler én formulier)
$companyKeys = [
    'invoice_company_name', 'invoice_company_address', 'invoice_company_postal',
    'invoice_company_city', 'invoice_company_country', 'invoice_btw_number',
    'invoice_kvk', 'invoice_iban', 'invoice_email', 'invoice_website',
];

// ── Save bedrijfsgegevens ─────────────────────────────────────────────────
$companySaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company_info'])) {
    foreach ($companyKeys as $key) {
        $value = trim($_POST[$key] ?? '');
        $settingModel->set($key, $value);
    }
    $companySaved = true;
}

// Huidige waarden voor formulier (altijd laden, ook bij GET)
$companyFields = [];
foreach ($companyKeys as $k) {
    $companyFields[$k] = $settingModel->get($k) ?? '';
}

// Fetch invoice list + totals
$page = max(1, (int) ($_GET['page'] ?? 1));
$statusFilter = $_GET['status'] ?? null;
$invoiceData = $invoiceModel->getAll($page, 50, $statusFilter);
$invoiceTotals = $invoiceModel->getTotals();
$tenants = $tenantModel->getAll();

// Default period for generation: previous month
$defaultPeriodStart = date('Y-m-01', strtotime('first day of last month'));
$defaultPeriodEnd = date('Y-m-t', strtotime('last month'));
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 1200px; margin: 0 auto;">
    <!-- Navigation -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>Facturen</h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Invoice Totals Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Openstaand</p>
            <p style="font-size: 28px; font-weight: 700; color: #FF9800;">&euro; <?= number_format($invoiceTotals['total_outstanding'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Betaald</p>
            <p style="font-size: 28px; font-weight: 700; color: #4CAF50;">&euro; <?= number_format($invoiceTotals['total_collected'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Deze Maand Gefactureerd</p>
            <p style="font-size: 28px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($invoiceTotals['this_month_invoiced'] / 100, 2, ',', '.') ?></p>
        </div>
    </div>

    <!-- Generate Invoice Section -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Factuur Genereren</h2>
        <div style="display: flex; gap: var(--space-md); align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="text-sm text-secondary">Tenant</label>
                <select id="gen-tenant" class="form-input">
                    <option value="">-- Selecteer --</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="text-sm text-secondary">Van</label>
                <input type="date" id="gen-start" class="form-input" value="<?= $defaultPeriodStart ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="text-sm text-secondary">Tot</label>
                <input type="date" id="gen-end" class="form-input" value="<?= $defaultPeriodEnd ?>">
            </div>
            <button id="btn-generate" class="btn btn-primary">Genereer</button>
            <button id="btn-generate-all" class="btn btn-secondary">Genereer Alle Tenants</button>
        </div>
        <p id="gen-status" class="text-sm" style="margin-top: var(--space-sm);"></p>
    </div>

    <!-- Bedrijfsgegevens (afzender op facturen) -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md); display:flex; align-items:center; gap:var(--space-sm);">
            &#128233; Bedrijfsgegevens op factuur
            <?php if ($companySaved): ?>
                <span style="font-size:12px; font-weight:400; color:#4CAF50;">&mdash; Opgeslagen</span>
            <?php endif; ?>
        </h2>
        <form method="POST" action="">
            <input type="hidden" name="save_company_info" value="1">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-md);">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Bedrijfsnaam</label>
                    <input type="text" name="invoice_company_name" class="form-input" value="<?= sanitize((string)$companyFields['invoice_company_name']) ?>" placeholder="REGULR.vip">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Adres</label>
                    <input type="text" name="invoice_company_address" class="form-input" value="<?= sanitize((string)$companyFields['invoice_company_address']) ?>" placeholder="Straatnaam 1">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Postcode</label>
                    <input type="text" name="invoice_company_postal" class="form-input" value="<?= sanitize((string)$companyFields['invoice_company_postal']) ?>" placeholder="1234 AB">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Plaats</label>
                    <input type="text" name="invoice_company_city" class="form-input" value="<?= sanitize((string)$companyFields['invoice_company_city']) ?>" placeholder="Amsterdam">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Land</label>
                    <input type="text" name="invoice_company_country" class="form-input" value="<?= sanitize((string)$companyFields['invoice_company_country']) ?>" placeholder="Nederland">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">BTW-nummer</label>
                    <input type="text" name="invoice_btw_number" class="form-input" value="<?= sanitize((string)$companyFields['invoice_btw_number']) ?>" placeholder="NL001234567B01">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">KVK-nummer</label>
                    <input type="text" name="invoice_kvk" class="form-input" value="<?= sanitize((string)$companyFields['invoice_kvk']) ?>" placeholder="12345678">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">IBAN</label>
                    <input type="text" name="invoice_iban" class="form-input" value="<?= sanitize((string)$companyFields['invoice_iban']) ?>" placeholder="NL91ABNA0417164300">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">E-mail</label>
                    <input type="email" name="invoice_email" class="form-input" value="<?= sanitize((string)$companyFields['invoice_email']) ?>" placeholder="info@regulr.vip">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="text-sm text-secondary">Website</label>
                    <input type="text" name="invoice_website" class="form-input" value="<?= sanitize((string)$companyFields['invoice_website']) ?>" placeholder="https://regulr.vip">
                </div>
            </div>
            <div style="margin-top: var(--space-md);">
                <button type="submit" class="btn btn-primary">&#128190; Opslaan</button>
            </div>
        </form>
    </div>

    <!-- Status Filter -->
    <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-md); flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/superadmin/invoices" class="btn btn-sm" style="padding: 4px 12px; font-size: 13px; <?= !$statusFilter ? 'background:var(--accent-primary);color:#000;' : '' ?>">Alle</a>
        <a href="?status=draft" class="btn btn-sm" style="padding: 4px 12px; font-size: 13px; <?= $statusFilter === 'draft' ? 'background:var(--accent-primary);color:#000;' : '' ?>">Concept</a>
        <a href="?status=sent" class="btn btn-sm" style="padding: 4px 12px; font-size: 13px; <?= $statusFilter === 'sent' ? 'background:var(--accent-primary);color:#000;' : '' ?>">Verzonden</a>
        <a href="?status=paid" class="btn btn-sm" style="padding: 4px 12px; font-size: 13px; <?= $statusFilter === 'paid' ? 'background:var(--accent-primary);color:#000;' : '' ?>">Betaald</a>
        <a href="?status=overdue" class="btn btn-sm" style="padding: 4px 12px; font-size: 13px; <?= $statusFilter === 'overdue' ? 'background:#f44336;color:#fff;' : '' ?>">Te Laat</a>
    </div>

    <!-- Invoice Table -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <?php if (empty($invoiceData['invoices'])): ?>
            <p class="text-secondary">Geen facturen gevonden.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Factuurnr</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Tenant</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Periode</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Transacties</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Fee</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">BTW</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Totaal</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Status</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoiceData['invoices'] as $inv): ?>
                            <?php
                            $statusColors = [
                                'draft'     => 'rgba(158,158,158,0.2);color:#9e9e9e',
                                'sent'      => 'rgba(33,150,243,0.2);color:#2196F3',
                                'paid'      => 'rgba(76,175,80,0.2);color:#4CAF50',
                                'overdue'   => 'rgba(244,67,54,0.2);color:#f44336',
                                'cancelled' => 'rgba(158,158,158,0.1);color:#666',
                            ];
                            $sc = $statusColors[$inv['status']] ?? $statusColors['draft'];
                            $statusLabels = ['draft' => 'Concept', 'sent' => 'Verzonden', 'paid' => 'Betaald', 'overdue' => 'Te Laat', 'cancelled' => 'Geannuleerd'];
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: var(--space-sm); font-family: monospace; font-size: 13px;"><?= sanitize($inv['invoice_number']) ?></td>
                                <td style="padding: var(--space-sm);"><?= sanitize($inv['tenant_name']) ?></td>
                                <td style="padding: var(--space-sm); font-size: 13px;"><?= $inv['period_start'] ?> &mdash; <?= $inv['period_end'] ?></td>
                                <td style="padding: var(--space-sm); text-align: right;"><?= (int) $inv['transaction_count'] ?></td>
                                <td style="padding: var(--space-sm); text-align: right;">&euro; <?= number_format($inv['fee_total_cents'] / 100, 2, ',', '.') ?></td>
                                <td style="padding: var(--space-sm); text-align: right;">&euro; <?= number_format($inv['btw_amount_cents'] / 100, 2, ',', '.') ?></td>
                                <td style="padding: var(--space-sm); text-align: right; font-weight: 600;">&euro; <?= number_format($inv['total_incl_btw_cents'] / 100, 2, ',', '.') ?></td>
                                <td style="padding: var(--space-sm);">
                                    <span class="badge" style="background:<?= $sc ?>;"><?= $statusLabels[$inv['status']] ?? $inv['status'] ?></span>
                                </td>
                                 <td style="padding: var(--space-sm); white-space: nowrap;">
                                     <a href="<?= BASE_URL ?>/superadmin/invoices/view?id=<?= (int) $inv['id'] ?>" class="btn btn-sm" style="padding:2px 8px;font-size:12px;background:rgba(255,255,255,0.08);color:var(--text-primary);text-decoration:none;">Bekijk</a>
                                     <?php if ($inv['status'] === 'draft'): ?>
                                         <button class="btn btn-sm inv-status-btn" data-id="<?= (int) $inv['id'] ?>" data-status="sent" style="padding:2px 8px;font-size:12px;background:rgba(33,150,243,0.2);color:#2196F3;">Verzonden</button>
                                     <?php elseif ($inv['status'] === 'sent' || $inv['status'] === 'overdue'): ?>
                                         <button class="btn btn-sm inv-status-btn" data-id="<?= (int) $inv['id'] ?>" data-status="paid" style="padding:2px 8px;font-size:12px;background:rgba(76,175,80,0.2);color:#4CAF50;">Betaald</button>
                                     <?php endif; ?>
                                 </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination info -->
            <?php if ($invoiceData['total'] > $invoiceData['limit']): ?>
                <p class="text-sm text-secondary" style="margin-top: var(--space-md);">
                    Pagina <?= $invoiceData['page'] ?> van <?= ceil($invoiceData['total'] / $invoiceData['limit']) ?>
                    (<?= $invoiceData['total'] ?> facturen)
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
const CSRF = '<?= generateCSRFToken() ?>';
const BASE = '<?= BASE_URL ?>';

// Generate single invoice
document.getElementById('btn-generate')?.addEventListener('click', async () => {
    const tenantId = document.getElementById('gen-tenant').value;
    const start = document.getElementById('gen-start').value;
    const end = document.getElementById('gen-end').value;
    const statusEl = document.getElementById('gen-status');

    if (!tenantId) { statusEl.textContent = 'Selecteer een tenant'; statusEl.style.color = '#f44336'; return; }
    if (!start || !end) { statusEl.textContent = 'Selecteer een periode'; statusEl.style.color = '#f44336'; return; }

    statusEl.textContent = 'Genereren...';
    statusEl.style.color = 'var(--text-secondary)';

    try {
        const res = await fetch(BASE + '/api/superadmin/invoices', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'generate', tenant_id: parseInt(tenantId), period_start: start, period_end: end })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = 'Factuur ' + result.data.invoice_number + ' gegenereerd!';
            statusEl.style.color = '#4CAF50';
            setTimeout(() => window.location.reload(), 1500);
        } else {
            statusEl.textContent = result.error || 'Genereren mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = 'Netwerkfout opgetreden';
        statusEl.style.color = '#f44336';
    }
});

// Generate all tenants
document.getElementById('btn-generate-all')?.addEventListener('click', async () => {
    const start = document.getElementById('gen-start').value;
    const end = document.getElementById('gen-end').value;
    const statusEl = document.getElementById('gen-status');

    if (!start || !end) { statusEl.textContent = 'Selecteer een periode'; statusEl.style.color = '#f44336'; return; }

    if (!confirm('Facturen genereren voor ALLE actieve tenants voor ' + start + ' t/m ' + end + '?')) return;

    statusEl.textContent = 'Batch genereren...';
    statusEl.style.color = 'var(--text-secondary)';

    try {
        const res = await fetch(BASE + '/api/superadmin/invoices', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action: 'generate_all', period_start: start, period_end: end })
        });
        const result = await res.json();
        if (result.success) {
            statusEl.textContent = result.data.generated + ' facturen gegenereerd!';
            statusEl.style.color = '#4CAF50';
            setTimeout(() => window.location.reload(), 1500);
        } else {
            statusEl.textContent = result.error || 'Batch genereren mislukt';
            statusEl.style.color = '#f44336';
        }
    } catch (err) {
        statusEl.textContent = 'Netwerkfout opgetreden';
        statusEl.style.color = '#f44336';
    }
});

// Status change buttons
document.querySelectorAll('.inv-status-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const invoiceId = parseInt(this.dataset.id);
        const newStatus = this.dataset.status;
        const row = this.closest('tr');

        try {
            const res = await fetch(BASE + '/api/superadmin/invoices', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'update_status', invoice_id: invoiceId, status: newStatus })
            });
            const result = await res.json();
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.error || 'Status wijziging mislukt');
            }
        } catch (err) {
            alert('Er is een netwerkfout opgetreden');
        }
    });
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
