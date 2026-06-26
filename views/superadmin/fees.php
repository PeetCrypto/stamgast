<?php
declare(strict_types=1);
/**
 * Superadmin - Platform Fee Dashboard
 * REGULR.vip Loyalty Platform
 *
 * Shows platform-wide fee totals, per-tenant fee breakdown,
 * and date range filtering.
 */

require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../services/PlatformFeeService.php';

$db = Database::getInstance()->getConnection();
$feeModel = new PlatformFee($db);
$feeService = new PlatformFeeService($db);

// Get platform-wide overview
$overview = $feeModel->getPlatformOverview();
$perTenant = $feeService->getPerTenantTotals();

// Default date range: this month
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 1200px; margin: 0 auto;">
    <!-- Navigation -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
        <h1>Platform Fees</h1>
        <a href="<?= BASE_URL ?>/superadmin" class="btn btn-secondary">&larr; Terug</a>
    </div>

    <!-- Fee Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Fee Vandaag</p>
            <p style="font-size: 28px; font-weight: 700; color: #4CAF50;">&euro; <?= number_format($overview['today']['fee_total'] / 100, 2, ',', '.') ?></p>
            <p class="text-sm text-secondary" style="margin-top: 4px;">Volume: &euro; <?= number_format($overview['today']['gross_total'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Fee Deze Maand</p>
            <p style="font-size: 28px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($overview['this_month']['fee_total'] / 100, 2, ',', '.') ?></p>
            <p class="text-sm text-secondary" style="margin-top: 4px;">Volume: &euro; <?= number_format($overview['this_month']['gross_total'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Fee All-Time</p>
            <p style="font-size: 28px; font-weight: 700; color: var(--accent-primary);">&euro; <?= number_format($overview['all_time']['fee_total'] / 100, 2, ',', '.') ?></p>
            <p class="text-sm text-secondary" style="margin-top: 4px;">Volume: &euro; <?= number_format($overview['all_time']['gross_total'] / 100, 2, ',', '.') ?></p>
        </div>
        <div class="glass-card" style="padding: var(--space-lg); text-align: center;">
            <p class="text-secondary text-sm">Gemiddelde Fee</p>
            <p style="font-size: 28px; font-weight: 700; color: var(--accent-primary);">
                <?php
                $totalGross = (int) $overview['all_time']['gross_total'];
                $totalFee = (int) $overview['all_time']['fee_total'];
                $avgPerc = $totalGross > 0 ? round(($totalFee / $totalGross) * 100, 2) : 0;
                ?>
                <?= number_format($avgPerc, 2, ',', '.') ?>%
            </p>
            <p class="text-sm text-secondary" style="margin-top: 4px;">effectief percentage</p>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg);">
        <div style="display: flex; gap: var(--space-md); align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="text-sm text-secondary">Van</label>
                <input type="date" id="filter-start" class="form-input" value="<?= $defaultStart ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="text-sm text-secondary">Tot</label>
                <input type="date" id="filter-end" class="form-input" value="<?= $defaultEnd ?>">
            </div>
            <button id="btn-filter" class="btn btn-primary">Filter</button>
            <button id="btn-clear-filter" class="btn btn-secondary">Reset</button>
        </div>
    </div>

    <!-- Per-Tenant Fee Table -->
    <div class="glass-card" style="padding: var(--space-lg);">
        <h2 style="margin-bottom: var(--space-md);">Fees per Tenant</h2>

        <?php if (empty($perTenant)): ?>
            <p class="text-secondary">Nog geen fee data beschikbaar.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; min-width: 700px;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Tenant</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Transacties</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Volume</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Platform Fee</th>
                            <th style="text-align: right; padding: var(--space-sm); color: var(--text-secondary);">Fee %</th>
                            <th style="text-align: left; padding: var(--space-sm); color: var(--text-secondary);">Acties</th>
                        </tr>
                    </thead>
                    <tbody id="fee-table-body">
                        <?php
                        $totalTx = 0;
                        $totalGrossSum = 0;
                        $totalFeeSum = 0;
                        ?>
                        <?php foreach ($perTenant as $row): ?>
                            <?php
                            $txCount = (int) $row['tx_count'];
                            $gross = (int) $row['gross_total'];
                            $fee = (int) $row['fee_total'];
                            $feePerc = (float) ($row['platform_fee_percentage'] ?? 0);
                            $totalTx += $txCount;
                            $totalGrossSum += $gross;
                            $totalFeeSum += $fee;
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: var(--space-sm);"><?= sanitize($row['tenant_name']) ?></td>
                                <td style="padding: var(--space-sm); text-align: right;"><?= $txCount ?></td>
                                <td style="padding: var(--space-sm); text-align: right;">&euro; <?= number_format($gross / 100, 2, ',', '.') ?></td>
                                <td style="padding: var(--space-sm); text-align: right; font-weight: 600; color: #4CAF50;">&euro; <?= number_format($fee / 100, 2, ',', '.') ?></td>
                                <td style="padding: var(--space-sm); text-align: right;"><?= number_format($feePerc, 2, ',', '.') ?>%</td>
                                <td style="padding: var(--space-sm);">
                                    <button class="btn btn-secondary btn-sm fee-detail-btn" data-tenant-id="<?= (int) $row['tenant_id'] ?>" data-tenant-name="<?= sanitize($row['tenant_name']) ?>" data-fee-perc="<?= number_format((float) ($row['platform_fee_percentage'] ?? 0), 2, '.', '') ?>" style="padding: 2px 8px; font-size: 12px;">Detail</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Totals row -->
                        <tr style="border-top: 2px solid rgba(255,255,255,0.2); font-weight: 700;">
                            <td style="padding: var(--space-sm);">Totaal</td>
                            <td style="padding: var(--space-sm); text-align: right;"><?= $totalTx ?></td>
                            <td style="padding: var(--space-sm); text-align: right;">&euro; <?= number_format($totalGrossSum / 100, 2, ',', '.') ?></td>
                            <td style="padding: var(--space-sm); text-align: right; color: #4CAF50;">&euro; <?= number_format($totalFeeSum / 100, 2, ',', '.') ?></td>
                            <td style="padding: var(--space-sm); text-align: right;">
                                <?= $totalGrossSum > 0 ? number_format(($totalFeeSum / $totalGrossSum) * 100, 2, ',', '.') : '0,00' ?>%
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Fee Detail Modal -->
<div id="fee-detail-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:flex-start; padding-top:40px; overflow-y:auto;">
    <div class="glass-card" style="width:95%; max-width:1000px; padding:var(--space-xl); margin-bottom:40px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--space-lg);">
            <div>
                <h2 id="modal-tenant-name" style="margin:0;">Fee Details</h2>
                <p id="modal-tenant-subtitle" class="text-sm text-secondary" style="margin-top:4px;"></p>
            </div>
            <button id="modal-close" class="btn btn-secondary" style="padding:4px 12px;">&times; Sluiten</button>
        </div>

        <!-- Summary cards inside modal -->
        <div id="modal-summary" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:var(--space-md); margin-bottom:var(--space-lg);">
        </div>

        <!-- Loading indicator -->
        <div id="modal-loading" style="text-align:center; padding:var(--space-xl);">
            <p class="text-secondary">Transacties laden...</p>
        </div>

        <!-- Transaction table -->
        <div id="modal-table-wrapper" style="display:none; overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; min-width:800px;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.1);">
                        <th style="text-align:left; padding:var(--space-sm); color:var(--text-secondary);">Datum</th>
                        <th style="text-align:left; padding:var(--space-sm); color:var(--text-secondary);">Gast</th>
                        <th style="text-align:right; padding:var(--space-sm); color:var(--text-secondary);">Bruto (deposit)</th>
                        <th style="text-align:right; padding:var(--space-sm); color:var(--text-secondary);">Fee %</th>
                        <th style="text-align:right; padding:var(--space-sm); color:var(--text-secondary);">Fee bedrag</th>
                        <th style="text-align:right; padding:var(--space-sm); color:var(--text-secondary);">Netto (naar tenant)</th>
                        <th style="text-align:left; padding:var(--space-sm); color:var(--text-secondary);">Mollie ID</th>
                        <th style="text-align:left; padding:var(--space-sm); color:var(--text-secondary);">Status</th>
                    </tr>
                </thead>
                <tbody id="modal-tbody"></tbody>
                <tfoot>
                    <tr id="modal-totals-row" style="border-top:2px solid rgba(255,255,255,0.2); font-weight:700;"></tr>
                </tfoot>
            </table>
            <p id="modal-pagination" class="text-sm text-secondary" style="margin-top:var(--space-md);"></p>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= generateCSRFToken() ?>';
const BASE = '<?= BASE_URL ?>';

// Date range filter — reload per-tenant table via API
document.getElementById('btn-filter')?.addEventListener('click', async () => {
    const start = document.getElementById('filter-start').value;
    const end = document.getElementById('filter-end').value;
    if (!start || !end) { alert('Selecteer een geldige periode'); return; }

    try {
        const res = await fetch(BASE + '/api/superadmin/fees?action=per_tenant&start=' + start + '&end=' + end, {
            headers: { 'X-CSRF-Token': CSRF }
        });
        const result = await res.json();
        if (result.success) {
            renderPerTenantTable(result.data.tenants);
        } else {
            alert('Actie mislukt. Probeer het opnieuw.');
        }
    } catch (err) {
        alert('Er is een netwerkfout opgetreden');
    }
});

document.getElementById('btn-clear-filter')?.addEventListener('click', async () => {
    document.getElementById('filter-start').value = '<?= $defaultStart ?>';
    document.getElementById('filter-end').value = '<?= $defaultEnd ?>';
    try {
        const res = await fetch(BASE + '/api/superadmin/fees?action=per_tenant', {
            headers: { 'X-CSRF-Token': CSRF }
        });
        const result = await res.json();
        if (result.success) {
            renderPerTenantTable(result.data.tenants);
        }
    } catch (err) {
        alert('Er is een netwerkfout opgetreden');
    }
});

function renderPerTenantTable(tenants) {
    const tbody = document.getElementById('fee-table-body');
    if (!tbody || !tenants) return;

    let html = '';
    let totalTx = 0, totalGross = 0, totalFee = 0;

    tenants.forEach(row => {
        const tx = parseInt(row.tx_count) || 0;
        const gross = parseInt(row.gross_total) || 0;
        const fee = parseInt(row.fee_total) || 0;
        const perc = parseFloat(row.platform_fee_percentage) || 0;
        totalTx += tx; totalGross += gross; totalFee += fee;

        html += `<tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
            <td style="padding:var(--space-sm);">${escapeHtml(row.tenant_name)}</td>
            <td style="padding:var(--space-sm);text-align:right;">${tx}</td>
            <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(gross)}</td>
            <td style="padding:var(--space-sm);text-align:right;font-weight:600;color:#4CAF50;">&euro; ${formatCents(fee)}</td>
            <td style="padding:var(--space-sm);text-align:right;">${perc.toFixed(2).replace('.', ',')}%</td>
            <td style="padding:var(--space-sm);"><button class="btn btn-secondary btn-sm fee-detail-btn" data-tenant-id="${row.tenant_id}" data-tenant-name="${escapeHtml(row.tenant_name)}" data-fee-perc="${perc}" style="padding:2px 8px;font-size:12px;">Detail</button></td>
        </tr>`;
    });

    const totalPerc = totalGross > 0 ? ((totalFee / totalGross) * 100).toFixed(2) : '0.00';
    html += `<tr style="border-top:2px solid rgba(255,255,255,0.2);font-weight:700;">
        <td style="padding:var(--space-sm);">Totaal</td>
        <td style="padding:var(--space-sm);text-align:right;">${totalTx}</td>
        <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(totalGross)}</td>
        <td style="padding:var(--space-sm);text-align:right;color:#4CAF50;">&euro; ${formatCents(totalFee)}</td>
        <td style="padding:var(--space-sm);text-align:right;">${totalPerc.replace('.', ',')}%</td>
        <td></td>
    </tr>`;

    tbody.innerHTML = html;
}

function formatCents(cents) {
    return (cents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ── Fee Detail Modal ─────────────────────────────────────────────────────────

let modalCurrentPage = 1;
let modalTotalFees = 0;
let modalTenantId = null;

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.fee-detail-btn');
    if (!btn) return;

    modalTenantId = btn.dataset.tenantId;
    const tenantName = btn.dataset.tenantName;
    const feePerc = parseFloat(btn.dataset.feePerc) || 0;

    document.getElementById('modal-tenant-name').textContent = 'Fee Details — ' + tenantName;
    document.getElementById('modal-tenant-subtitle').textContent = 'Platform fee percentage: ' + feePerc.toFixed(2).replace('.', ',') + '%';
    document.getElementById('fee-detail-modal').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    modalCurrentPage = 1;
    loadFeeDetail(modalTenantId, modalCurrentPage);
});

document.getElementById('modal-close')?.addEventListener('click', closeModal);
document.getElementById('fee-detail-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function closeModal() {
    document.getElementById('fee-detail-modal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

async function loadFeeDetail(tenantId, page) {
    document.getElementById('modal-loading').style.display = 'block';
    document.getElementById('modal-table-wrapper').style.display = 'none';

    try {
        const res = await fetch(BASE + '/api/superadmin/fees?action=tenant_fees&tenant_id=' + tenantId + '&page=' + page + '&limit=50', {
            headers: { 'X-CSRF-Token': CSRF }
        });
        const result = await res.json();
        if (!result.success) {
            alert('Kon fee details niet laden');
            return;
        }

        const data = result.data;
        const fees = data.fees || [];
        const total = data.total || 0;
        const limit = data.limit || 50;
        modalTotalFees = total;

        // Build summary cards
        let grossSum = 0, feeSum = 0, netSum = 0;
        fees.forEach(f => {
            grossSum += parseInt(f.gross_amount_cents) || 0;
            feeSum += parseInt(f.fee_amount_cents) || 0;
            netSum += parseInt(f.net_amount_cents) || 0;
        });

        document.getElementById('modal-summary').innerHTML = `
            <div class="glass-card" style="padding:var(--space-md); text-align:center;">
                <p class="text-secondary text-sm">Transacties totaal</p>
                <p style="font-size:22px; font-weight:700;">${total}</p>
            </div>
            <div class="glass-card" style="padding:var(--space-md); text-align:center;">
                <p class="text-secondary text-sm">Volume (deze pagina)</p>
                <p style="font-size:22px; font-weight:700;">&euro; ${formatCents(grossSum)}</p>
            </div>
            <div class="glass-card" style="padding:var(--space-md); text-align:center;">
                <p class="text-secondary text-sm">Platform fee (deze pagina)</p>
                <p style="font-size:22px; font-weight:700; color:#4CAF50;">&euro; ${formatCents(feeSum)}</p>
            </div>
            <div class="glass-card" style="padding:var(--space-md); text-align:center;">
                <p class="text-secondary text-sm">Netto naar tenant (deze pagina)</p>
                <p style="font-size:22px; font-weight:700;">&euro; ${formatCents(netSum)}</p>
            </div>
        `;

        // Build transaction rows
        let tbodyHtml = '';
        if (fees.length === 0) {
            tbodyHtml = '<tr><td colspan="8" style="padding:var(--space-lg); text-align:center;" class="text-secondary">Geen transacties gevonden.</td></tr>';
        } else {
            fees.forEach(f => {
                const date = f.created_at ? new Date(f.created_at).toLocaleString('nl-NL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '—';
                const guest = escapeHtml(((f.first_name || '') + ' ' + (f.last_name || '')).trim() || f.email || 'Onbekend');
                const statusColors = {
                    collected: 'rgba(76,175,80,0.2);color:#4CAF50',
                    invoiced: 'rgba(33,150,243,0.2);color:#2196F3',
                    settled: 'rgba(158,158,158,0.2);color:#9e9e9e'
                };
                const sc = statusColors[f.status] || statusColors.collected;
                const statusLabels = { collected: 'Verzameld', invoiced: 'Gefactureerd', settled: 'Vereffend' };

                tbodyHtml += `<tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:var(--space-sm); font-size:13px;">${date}</td>
                    <td style="padding:var(--space-sm);">${guest}</td>
                    <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(parseInt(f.gross_amount_cents) || 0)}</td>
                    <td style="padding:var(--space-sm);text-align:right;">${parseFloat(f.fee_percentage || 0).toFixed(2).replace('.', ',')}%</td>
                    <td style="padding:var(--space-sm);text-align:right;font-weight:600;color:#4CAF50;">&euro; ${formatCents(parseInt(f.fee_amount_cents) || 0)}</td>
                    <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(parseInt(f.net_amount_cents) || 0)}</td>
                    <td style="padding:var(--space-sm); font-size:12px; font-family:monospace;">${escapeHtml(f.mollie_payment_id || '—')}</td>
                    <td style="padding:var(--space-sm);"><span class="badge" style="background:${sc};">${statusLabels[f.status] || f.status}</span></td>
                </tr>`;
            });
        }
        document.getElementById('modal-tbody').innerHTML = tbodyHtml;

        // Totals row
        document.getElementById('modal-totals-row').innerHTML = `
            <td style="padding:var(--space-sm);" colspan="2">Subtotaal (deze pagina)</td>
            <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(grossSum)}</td>
            <td></td>
            <td style="padding:var(--space-sm);text-align:right;color:#4CAF50;">&euro; ${formatCents(feeSum)}</td>
            <td style="padding:var(--space-sm);text-align:right;">&euro; ${formatCents(netSum)}</td>
            <td colspan="2"></td>
        `;

        // Pagination
        const totalPages = Math.ceil(total / limit);
        let paginationHtml = '';
        if (totalPages > 1) {
            paginationHtml += `Pagina ${page} van ${totalPages} (${total} transacties) — `;
            if (page > 1) {
                paginationHtml += `<button class="btn btn-sm btn-secondary modal-page-btn" data-page="${page - 1}" style="padding:2px 8px;font-size:12px;">&laquo; Vorige</button> `;
            }
            if (page < totalPages) {
                paginationHtml += `<button class="btn btn-sm btn-secondary modal-page-btn" data-page="${page + 1}" style="padding:2px 8px;font-size:12px;">Volgende &raquo;</button>`;
            }
        } else if (total > 0) {
            paginationHtml = `${total} transacties`;
        }
        document.getElementById('modal-pagination').innerHTML = paginationHtml;

        // Bind pagination buttons
        document.querySelectorAll('.modal-page-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                modalCurrentPage = parseInt(this.dataset.page);
                loadFeeDetail(modalTenantId, modalCurrentPage);
            });
        });

        document.getElementById('modal-loading').style.display = 'none';
        document.getElementById('modal-table-wrapper').style.display = 'block';
    } catch (err) {
        alert('Er is een netwerkfout opgetreden bij het laden van fee details');
        document.getElementById('modal-loading').style.display = 'none';
    }
}
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
