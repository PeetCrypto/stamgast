<?php
declare(strict_types=1);
/**
 * Admin Reports — Boekhouding Overzicht
 * REGULR.vip Loyalty Platform
 *
 * Secties:
 * 1. Periode selector (dag / week / maand)
 * 2. Omzet + BTW specificatie
 * 3. Stortingen & Bonus tracker
 * 4. Bartender breakdown
 * 5. Transactielijst
 * 6. CSV export
 */

$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;

// Load tenant for feature flag checks (navigation)
$db = Database::getInstance()->getConnection();
$tenantModel = new Tenant($db);
$_tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$_tenant = $tenantModel->findById($_tenantId);
$featurePush = (bool) ($_tenant['feature_push'] ?? true);
$featureMarketing = (bool) ($_tenant['feature_marketing'] ?? true);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
.report-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.report-card {
    background: var(--glass-bg);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
}

.report-card:hover { border-color: rgba(255, 255, 255, 0.2); }

.report-card.full-width { grid-column: 1 / -1; }

.report-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.1;
    margin-bottom: var(--space-xs);
}

.report-label {
    font-size: 13px;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.report-sub {
    font-size: 14px;
    color: var(--text-secondary);
    margin-top: var(--space-xs);
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: var(--space-md);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.section-icon { font-size: 20px; }

/* Period toggle */
.period-toggle {
    display: flex;
    gap: var(--space-xs);
    background: rgba(255,255,255,0.05);
    border-radius: var(--radius-md);
    padding: 4px;
    margin-bottom: var(--space-lg);
}

.period-btn {
    padding: 8px 20px;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.period-btn.active {
    background: var(--accent-primary);
    color: #000;
    font-weight: 600;
}

.period-btn:hover:not(.active) {
    background: rgba(255,255,255,0.1);
    color: var(--text-primary);
}

/* Date navigation */
.date-nav {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.date-nav-btn {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
    padding: 8px 14px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.date-nav-btn:hover { border-color: var(--accent-primary); }

.date-label {
    font-size: 16px;
    font-weight: 600;
    min-width: 220px;
    text-align: center;
}

/* Divider */
.report-divider {
    border: none;
    border-top: 1px solid var(--glass-border);
    margin: var(--space-md) 0;
}

/* BTW detail row */
.btw-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-xs) 0;
    font-size: 14px;
}

.btw-row .label { color: var(--text-secondary); }
.btw-row .value { font-weight: 600; color: var(--text-primary); }

/* Transactie tabel */
.tx-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.tx-table th {
    text-align: left;
    padding: var(--space-sm) var(--space-md);
    color: var(--text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--glass-border);
}

.tx-table td {
    padding: var(--space-sm) var(--space-md);
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.tx-table tr:hover { background: rgba(255,255,255,0.03); }

.tx-type {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.tx-type.payment   { background: rgba(76,175,80,0.2); color: #4CAF50; }
.tx-type.deposit   { background: rgba(33,150,243,0.2); color: #2196F3; }
.tx-type.bonus     { background: rgba(255,193,7,0.2);  color: #FFC107; }
.tx-type.correction { background: rgba(244,67,54,0.2);  color: #F44336; }

/* Export button */
.export-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
}

/* Bartender bar */
.bar-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) 0;
}

.bar-name {
    width: 120px;
    font-size: 14px;
    font-weight: 500;
}

.bar-track {
    flex: 1;
    height: 24px;
    background: rgba(255,255,255,0.05);
    border-radius: 4px;
    overflow: hidden;
}

.bar-fill {
    height: 100%;
    background: var(--accent-gradient);
    border-radius: 4px;
    transition: width 0.5s ease;
}

.bar-amount {
    font-size: 14px;
    font-weight: 600;
    min-width: 90px;
    text-align: right;
}

/* Skeleton */
.skeleton {
    background: linear-gradient(90deg, var(--glass-bg) 25%, rgba(255,255,255,0.1) 50%, var(--glass-bg) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: var(--radius-md);
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Responsive */
@media (max-width: 900px) {
    .report-grid { grid-template-columns: 1fr; }
}
@media (min-width: 901px) and (max-width: 1200px) {
    .report-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="container" style="padding: var(--space-md); max-width: 1400px; margin: 0 auto;">

    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl); flex-wrap: wrap; gap: var(--space-sm);">
        <div>
            <h1 style="font-size: 28px; font-weight: 700; margin-bottom: var(--space-xs);">
                Overzicht
            </h1>
            <p style="color: var(--text-secondary);"><?= sanitize($tenantName) ?></p>
        </div>
        <div style="display: flex; gap: var(--space-sm);">
            <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary btn-sm">Dashboard</a>
            <?php if ($featurePush): ?>
            <a href="<?= BASE_URL ?>/admin/push" class="btn btn-secondary btn-sm">Push</a>
            <?php endif; ?>
            <?php if ($featureMarketing): ?>
            <a href="<?= BASE_URL ?>/admin/marketing" class="btn btn-secondary btn-sm">Marketing</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/admin/users" class="btn btn-secondary btn-sm">Gebruikers</a>
            <a href="<?= BASE_URL ?>/admin/tiers" class="btn btn-secondary btn-sm">Pakketten</a>
            <a href="<?= BASE_URL ?>/admin/settings" class="btn btn-secondary btn-sm">Instellingen</a>
            <a href="<?= BASE_URL ?>/logout" class="btn btn-ghost btn-sm">Uitloggen</a>
        </div>
    </div>

    <!-- Periode toggle -->
    <div class="period-toggle">
        <button class="period-btn active" data-period="daily">Dag</button>
        <button class="period-btn" data-period="weekly">Week</button>
        <button class="period-btn" data-period="monthly">Maand</button>
    </div>

    <!-- Datum navigatie -->
    <div class="date-nav">
        <button class="date-nav-btn" id="btn-prev">◀ Vorige</button>
        <span class="date-label" id="date-label">--</span>
        <button class="date-nav-btn" id="btn-next">Volgende ▶</button>
        <button class="date-nav-btn" id="btn-today" style="margin-left: auto;">Vandaag</button>
    </div>

    <!-- Sectie 1: OMZET + BTW -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">💰</span> Omzet & BTW</h2>

        <div class="report-grid">
            <div class="report-card">
                <div class="report-value" id="r-revenue">--</div>
                <div class="report-label">Omzet (incl. BTW)</div>
                <div class="report-sub" id="r-transactions">-- transacties</div>
            </div>
            <div class="report-card">
                <div class="report-value" id="r-btw-total">--</div>
                <div class="report-label">BTW Totaal</div>
                <div class="report-sub" id="r-discount">--</div>
            </div>
            <div class="report-card">
                <div class="report-value" id="r-revenue-excl">--</div>
                <div class="report-label">Omzet (excl. BTW)</div>
                <div class="report-sub" id="r-points">--</div>
            </div>
        </div>

        <!-- BTW Specificatie -->
        <div class="report-card full-width">
            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm);">BTW Specificatie</h3>
            <hr class="report-divider">
            <div class="btw-row">
                <span class="label"> Alcohol 21% — Omzet bruto</span>
                <span class="value" id="r-gross-alc">--</span>
            </div>
            <div class="btw-row">
                <span class="label"> Alcohol 21% — Korting</span>
                <span class="value" id="r-disc-alc" style="color: var(--error);">--</span>
            </div>
            <div class="btw-row">
                <span class="label"> Alcohol 21% — Netto omzet</span>
                <span class="value" id="r-net-alc">--</span>
            </div>
            <div class="btw-row" style="background: rgba(255,193,7,0.08); padding: var(--space-xs) var(--space-sm); border-radius: 4px; margin: var(--space-xs) 0;">
                <span class="label" style="font-weight: 600;"> Alcohol 21% — BTW</span>
                <span class="value" style="color: var(--accent-primary);" id="r-btw-alc">--</span>
            </div>
            <hr class="report-divider">
            <div class="btw-row">
                <span class="label"> Food 9% — Omzet bruto</span>
                <span class="value" id="r-gross-food">--</span>
            </div>
            <div class="btw-row">
                <span class="label"> Food 9% — Korting</span>
                <span class="value" id="r-disc-food" style="color: var(--error);">--</span>
            </div>
            <div class="btw-row">
                <span class="label"> Food 9% — Netto omzet</span>
                <span class="value" id="r-net-food">--</span>
            </div>
            <div class="btw-row" style="background: rgba(255,193,7,0.08); padding: var(--space-xs) var(--space-sm); border-radius: 4px; margin: var(--space-xs) 0;">
                <span class="label" style="font-weight: 600;"> Food 9% — BTW</span>
                <span class="value" style="color: var(--accent-primary);" id="r-btw-food">--</span>
            </div>
            <hr class="report-divider">
            <div class="btw-row" style="font-size: 16px; font-weight: 700;">
                <span>Totaal BTW af te dragen</span>
                <span class="value" id="r-btw-grand-total">--</span>
            </div>
        </div>
    </section>

    <!-- Sectie 2: STORTINGEN & BONUS -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">🏦</span> Stortingen & Bonus</h2>
        <div class="report-grid">
            <div class="report-card">
                <div class="report-value" id="r-deposits">--</div>
                <div class="report-label">Stortingen</div>
                <div class="report-sub" id="r-deposit-count">-- stortingen</div>
            </div>
            <div class="report-card">
                <div class="report-value" style="color: var(--accent-primary);" id="r-bonus">--</div>
                <div class="report-label">Bonus (marketingkosten)</div>
                <div class="report-sub" id="r-bonus-count">-- bonussen</div>
            </div>
            <div class="report-card">
                <div class="report-value" id="r-outstanding">--</div>
                <div class="report-label">Openstaand tegoed</div>
                <div class="report-sub" id="r-wallet-count">-- wallets</div>
            </div>
        </div>
    </section>

    <!-- Sectie 2b: FOOI -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">💸</span> Fooi</h2>
        <div class="report-grid">
            <div class="report-card">
                <div class="report-value" style="color: var(--accent-primary);" id="r-tip-total">--</div>
                <div class="report-label">Totale fooi ontvangen</div>
                <div class="report-sub" id="r-tip-info">--</div>
            </div>
            <div class="report-card">
                <div class="report-value" id="r-tip-avg">--</div>
                <div class="report-label">Gemiddelde fooi per betaling</div>
                <div class="report-sub" id="r-tip-pct">--</div>
            </div>
            <div class="report-card">
                <div class="report-value" id="r-tip-count">--</div>
                <div class="report-label">Betalingen met fooi</div>
                <div class="report-sub" id="r-tip-pct-payments">--</div>
            </div>
        </div>
    </section>

    <!-- Sectie 3: BARTENDER BREAKDOWN -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">🍸</span> Omzet per Bartender</h2>
        <div class="report-card full-width" id="bartender-section">
            <div class="skeleton" style="height: 40px; margin-bottom: var(--space-sm);"></div>
            <div class="skeleton" style="height: 40px; margin-bottom: var(--space-sm);"></div>
        </div>
    </section>

    <!-- Sectie 4: TRANSACTIELIJST -->
    <section style="margin-bottom: var(--space-xl);">
        <div class="export-bar">
            <h2 class="section-title" style="margin-bottom: 0;"><span class="section-icon">📋</span> Transacties</h2>
            <button class="btn btn-secondary btn-sm" id="btn-export-csv">⬇ CSV Export</button>
        </div>
        <div class="report-card full-width" style="overflow-x: auto;">
            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Tijd</th>
                        <th>Type</th>
                        <th>Gast</th>
                        <th>Alcohol</th>
                        <th>Food</th>
                        <th>Korting</th>
                        <th>Fooi</th>
                        <th>Totaal</th>
                        <th>BTW</th>
                    </tr>
                </thead>
                <tbody id="tx-body">
                    <tr><td colspan="9"><div class="skeleton" style="height: 30px;"></div></td></tr>
                    <tr><td colspan="9"><div class="skeleton" style="height: 30px;"></div></td></tr>
                    <tr><td colspan="9"><div class="skeleton" style="height: 30px;"></div></td></tr>
                </tbody>
            </table>
        </div>
    </section>

</div>

<script>
(function() {
    // ── State ──
    let currentPeriod = 'daily';
    let currentDate = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
    const baseUrl = window.__BASE_URL || '';

    // ── Helpers ──
    function fmt(cents) {
        if (cents == null) return '€0,00';
        return '€' + (cents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtNum(cents) {
        if (cents == null) return '0,00';
        return (cents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatTime(isoStr) {
        if (!isoStr) return '--';
        const d = new Date(isoStr);
        return d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    }

    const typeLabels = {
        payment: 'Betaling',
        deposit: 'Storting',
        bonus: 'Bonus',
        correction: 'Correctie'
    };

    // ── API call ──
    async function fetchReport(period, date) {
        try {
            const resp = await fetch(`${baseUrl}/api/admin/reports?action=${period}&date=${date}`, {
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }
            });
            const json = await resp.json();
            if (!json.success) throw new Error(json.message || 'Fout bij ophalen rapportage');
            return json.data;
        } catch (e) {
            console.error('Report fetch error:', e);
            return null;
        }
    }

    async function fetchTransactions(dateFrom, dateTo) {
        try {
            const resp = await fetch(`${baseUrl}/api/admin/reports?action=transactions&date_from=${dateFrom}&date_to=${dateTo}&limit=50`, {
                headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }
            });
            const json = await resp.json();
            if (!json.success) throw new Error(json.message || 'Fout bij ophalen transacties');
            return json.data;
        } catch (e) {
            console.error('Transaction fetch error:', e);
            return null;
        }
    }

    // ── Render report ──
    function renderReport(data) {
        if (!data) return;

        const p = data.payments;
        const periodLabel = data.period?.label || '--';

        // Date label
        document.getElementById('date-label').textContent = periodLabel;

        // Main cards
        document.getElementById('r-revenue').textContent = fmt(p.revenue_cents);
        document.getElementById('r-transactions').textContent = `${p.transaction_count} transactie${p.transaction_count !== 1 ? 's' : ''}`;
        document.getElementById('r-btw-total').textContent = fmt(p.btw_total_cents);
        document.getElementById('r-discount').textContent = `Korting: ${fmt(p.discount_alc_cents + p.discount_food_cents)}`;

        const revenueExcl = p.revenue_cents - p.btw_total_cents;
        document.getElementById('r-revenue-excl').textContent = fmt(revenueExcl);
        document.getElementById('r-points').textContent = `${p.points_earned} punten verdiend`;

        // BTW specificatie
        document.getElementById('r-gross-alc').textContent = fmt(p.gross_alc_cents);
        document.getElementById('r-disc-alc').textContent = '- ' + fmt(p.discount_alc_cents);
        document.getElementById('r-net-alc').textContent = fmt(p.gross_alc_cents - p.discount_alc_cents);
        document.getElementById('r-btw-alc').textContent = fmt(p.btw_alc_cents);

        document.getElementById('r-gross-food').textContent = fmt(p.gross_food_cents);
        document.getElementById('r-disc-food').textContent = '- ' + fmt(p.discount_food_cents);
        document.getElementById('r-net-food').textContent = fmt(p.gross_food_cents - p.discount_food_cents);
        document.getElementById('r-btw-food').textContent = fmt(p.btw_food_cents);

        document.getElementById('r-btw-grand-total').textContent = fmt(p.btw_total_cents);

        // Stortingen & bonus
        document.getElementById('r-deposits').textContent = fmt(data.deposits.deposit_total_cents);
        document.getElementById('r-deposit-count').textContent = `${data.deposits.deposit_count} storting${data.deposits.deposit_count !== 1 ? 'en' : ''}`;

        document.getElementById('r-bonus').textContent = fmt(data.bonuses.bonus_total_cents);
        document.getElementById('r-bonus-count').textContent = `${data.bonuses.bonus_count} bonus${data.bonuses.bonus_count !== 1 ? 'sen' : ''} gegeven`;

        document.getElementById('r-outstanding').textContent = fmt(data.wallets.outstanding_balance_cents);
        document.getElementById('r-wallet-count').textContent = `${data.wallets.wallet_count} actieve wallets`;

        // Tip section
        var tipTotal = data.payments.tip_total_cents || 0;
        var txCount = data.payments.transaction_count || 0;
        var revenueCents = data.payments.revenue_cents || 0;
        document.getElementById('r-tip-total').textContent = fmt(tipTotal);
        document.getElementById('r-tip-info').textContent = `Over ${txCount} betaling${txCount !== 1 ? 'en' : ''}`;
        document.getElementById('r-tip-avg').textContent = txCount > 0 ? fmt(Math.round(tipTotal / txCount)) : '€0,00';
        var tipPct = revenueCents > 0 ? (tipTotal / (revenueCents - tipTotal) * 100).toFixed(1) : '0.0';
        document.getElementById('r-tip-pct').textContent = `${tipPct}% van omzet`;
        document.getElementById('r-tip-count').textContent = '--'; // Updated from transactions
        document.getElementById('r-tip-pct-payments').textContent = '--';

        // Bartender breakdown
        renderBartenders(data.bartender_breakdown);
    }

    function renderBartenders(bartenders) {
        const el = document.getElementById('bartender-section');
        if (!bartenders || bartenders.length === 0) {
            el.innerHTML = '<p style="color: var(--text-secondary);">Geen betalingen in deze periode.</p>';
            return;
        }
        const maxRev = Math.max(...bartenders.map(b => b.revenue_cents), 1);
        el.innerHTML = bartenders.map(b => {
            const tipStr = b.tip_cents > 0 ? ' <span style="font-size:12px;color:var(--accent-primary);">(💸 ' + fmt(b.tip_cents) + ' fooi)</span>' : '';
            return `
            <div class="bar-item">
                <span class="bar-name">${b.name}</span>
                <div class="bar-track">
                    <div class="bar-fill" style="width: ${(b.revenue_cents / maxRev * 100).toFixed(1)}%;"></div>
                </div>
                <span class="bar-amount">${fmt(b.revenue_cents)}${tipStr}</span>
            </div>`;
        }).join('');
    }

    // ── Render transactielijst ──
    function renderTransactions(data) {
        const tbody = document.getElementById('tx-body');
        if (!data || !data.transactions || data.transactions.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="color: var(--text-secondary); text-align: center; padding: var(--space-xl);">Geen transacties gevonden</td></tr>';
            return;
        }
        var tipPayments = 0;
        var totalPayments = 0;
        tbody.innerHTML = data.transactions.map(tx => {
            const discount = tx.discount_alc_cents + tx.discount_food_cents;
            const tip = tx.tip_cents || 0;
            if (tx.type === 'payment') {
                totalPayments++;
                if (tip > 0) tipPayments++;
            }
            return `<tr>
                <td>${formatTime(tx.created_at)}</td>
                <td><span class="tx-type ${tx.type}">${typeLabels[tx.type] || tx.type}</span></td>
                <td>${tx.guest_name}</td>
                <td>${tx.amount_alc_cents > 0 ? fmt(tx.amount_alc_cents) : '-'}</td>
                <td>${tx.amount_food_cents > 0 ? fmt(tx.amount_food_cents) : '-'}</td>
                <td style="${discount > 0 ? 'color: var(--error);' : ''}">${discount > 0 ? '- ' + fmt(discount) : '-'}</td>
                <td style="${tip > 0 ? 'color: var(--accent-primary); font-weight: 600;' : ''}">${tip > 0 ? fmt(tip) : '-'}</td>
                <td style="font-weight: 600;">${fmt(tx.final_total_cents)}</td>
                <td>${tx.btw_total_cents > 0 ? fmt(tx.btw_total_cents) : '-'}</td>
            </tr>`;
        }).join('');

        // Update tip count cards after transactions are rendered
        document.getElementById('r-tip-count').textContent = tipPayments;
        var tipPctPayments = totalPayments > 0 ? (tipPayments / totalPayments * 100).toFixed(0) : '0';
        document.getElementById('r-tip-pct-payments').textContent = `${tipPctPayments}% van ${totalPayments} betalingen`;
    }

    // ── Load everything ──
    async function load() {
        const [reportData, txData] = await Promise.all([
            fetchReport(currentPeriod, currentDate),
            fetchTransactions(
                reportDataCache?.period?.date_from || currentDate,
                reportDataCache?.period?.date_to || currentDate
            )
        ]);

        if (reportData) {
            reportDataCache = reportData;
            renderReport(reportData);

            // Fetch transactions with the correct date range
            const txs = await fetchTransactions(reportData.period.date_from, reportData.period.date_to);
            if (txs) renderTransactions(txs);
        } else if (txData) {
            renderTransactions(txData);
        }
    }

    let reportDataCache = null;

    // ── Period toggle ──
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentPeriod = btn.dataset.period;
            load();
        });
    });

    // ── Date nav ──
    function shiftDate(dir) {
        const d = new Date(currentDate + 'T12:00:00');
        switch (currentPeriod) {
            case 'daily':   d.setDate(d.getDate() + dir); break;
            case 'weekly':  d.setDate(d.getDate() + (7 * dir)); break;
            case 'monthly': d.setMonth(d.getMonth() + dir); break;
        }
        currentDate = d.toISOString().split('T')[0];
        load();
    }

    document.getElementById('btn-prev').addEventListener('click', () => shiftDate(-1));
    document.getElementById('btn-next').addEventListener('click', () => shiftDate(1));
    document.getElementById('btn-today').addEventListener('click', () => {
        currentDate = new Date().toISOString().split('T')[0];
        load();
    });

    // ── CSV export ──
    document.getElementById('btn-export-csv').addEventListener('click', () => {
        if (!reportDataCache || !reportDataCache.period) return;
        const from = reportDataCache.period.date_from;
        const to = reportDataCache.period.date_to;
        window.location.href = `${baseUrl}/api/admin/reports?action=export_csv&date_from=${from}&date_to=${to}`;
    });

    // ── Init ──
    load();
})();
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
