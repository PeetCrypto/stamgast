<?php
declare(strict_types=1);
/**
 * Superadmin - Platform Fee Dashboard
 * STAMGAST Loyalty Platform
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
                                    <a href="<?= BASE_URL ?>/superadmin/tenant/<?= (int) $row['tenant_id'] ?>" class="btn btn-secondary btn-sm" style="padding: 2px 8px; font-size: 12px;">Detail</a>
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
            alert('Fout: ' + (result.error || 'Onbekend'));
        }
    } catch (err) {
        alert('Netwerkfout: ' + err.message);
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
        alert('Netwerkfout: ' + err.message);
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
            <td style="padding:var(--space-sm);"><a href="${BASE}/superadmin/tenant/${row.tenant_id}" class="btn btn-secondary btn-sm" style="padding:2px 8px;font-size:12px;">Detail</a></td>
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
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
