<?php
declare(strict_types=1);
/**
 * Admin Dashboard - Volledig Nieuw
 * STAMGAST Loyalty Platform
 * 
 * Secties:
 * 1. Live Monitor (Vandaag & Nu)
 * 2. Whale Tracker (Top 5 Gasten)
 * 3. Saldo & Liquiditeit (Spaarvarken)
 * 4. Marketing Performance
 * 5. Personeelscontrole (Anti-Fraude)
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
/* Dashboard-specifieke stijlen */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.stat-card {
    background: var(--glass-bg);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
}

.stat-card:hover {
    border-color: rgba(255, 255, 255, 0.2);
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.1;
    margin-bottom: var(--space-xs);
}

.stat-label {
    font-size: 14px;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-change {
    font-size: 13px;
    margin-top: var(--space-xs);
}

.stat-change.positive { color: var(--success); }
.stat-change.negative { color: var(--error); }

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: var(--space-md);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.section-icon {
    font-size: 20px;
}

/* Whale Card */
.whale-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.whale-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--accent-gradient);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 18px;
    color: #000;
    flex-shrink: 0;
}

.whale-info {
    flex: 1;
}

.whale-name {
    font-weight: 600;
    font-size: 16px;
}

.whale-stats {
    font-size: 13px;
    color: var(--text-secondary);
}

.whale-amount {
    font-size: 18px;
    font-weight: 700;
    color: var(--accent-primary);
}

/* Barman Card */
.barman-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.barman-info {
    flex: 1;
}

.barman-name {
    font-weight: 600;
}

.barman-stats {
    font-size: 13px;
    color: var(--text-secondary);
}

.barman-revenue {
    font-size: 16px;
    font-weight: 600;
}

/* Piektijden Chart */
.peak-hours-chart {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 120px;
    padding: var(--space-md) 0;
}

.bar {
    flex: 1;
    background: var(--accent-gradient);
    border-radius: 2px 2px 0 0;
    min-height: 4px;
    transition: height 0.3s ease;
}

/* Correctie Log */
.correction-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: rgba(244, 67, 54, 0.1);
    border: 1px solid rgba(244, 67, 54, 0.2);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-xs);
    font-size: 14px;
}

.correction-flag {
    color: var(--error);
    font-size: 18px;
}

/* Birthday */
.birthday-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 152, 0, 0.1) 100%);
    border: 1px solid rgba(255, 193, 7, 0.2);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.birthday-cake {
    font-size: 24px;
}

/* Action Buttons */
.btn-action {
    padding: 0.5rem 1rem;
    font-size: 13px;
    width: auto;
}

/* Responsive */
@media (max-width: 1024px) {
    .dashboard-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

/* Loading skeleton */
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
</style>

<div class="container" style="padding: var(--space-md); max-width: 1400px; margin: 0 auto;">
    
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xl);">
        <div>
            <h1 style="font-size: 28px; font-weight: 700; margin-bottom: var(--space-xs);">
                Dashboard
            </h1>
            <p style="color: var(--text-secondary);"><?= sanitize($tenantName) ?></p>
        </div>
        <div style="display: flex; gap: var(--space-sm);">
            <?php if ($featurePush): ?>
            <a href="<?= BASE_URL ?>/admin/push" class="btn btn-secondary btn-sm">Push</a>
            <?php endif; ?>
            <?php if ($featureMarketing): ?>
            <a href="<?= BASE_URL ?>/admin/marketing" class="btn btn-secondary btn-sm">Marketing</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/admin/users" class="btn btn-secondary btn-sm">Gebruikers</a>
            <a href="<?= BASE_URL ?>/admin/settings" class="btn btn-secondary btn-sm">Instellingen</a>
            <a href="<?= BASE_URL ?>/logout" class="btn btn-ghost btn-sm">Uitloggen</a>
        </div>
    </div>

    <!-- Module Status Balk (PHP-rendered, controlled by Super-Admin) -->
    <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-md);">
        <span style="padding:4px 12px;border-radius:16px;font-size:12px;<?= $featurePush ? 'background:rgba(76,175,80,0.2);color:#4CAF50;' : 'background:rgba(255,255,255,0.05);color:var(--text-secondary);' ?>">Push: <?= $featurePush ? 'Actief' : 'Inactief' ?></span>
        <span style="padding:4px 12px;border-radius:16px;font-size:12px;<?= $featureMarketing ? 'background:rgba(76,175,80,0.2);color:#4CAF50;' : 'background:rgba(255,255,255,0.05);color:var(--text-secondary);' ?>">Marketing: <?= $featureMarketing ? 'Actief' : 'Inactief' ?></span>
    </div>

    <!-- Sectie 1: LIVE MONITOR -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">📊</span> Live Monitor - Vandaag & Nu</h2>
        
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-value" id="active-guests">--</div>
                <div class="stat-label">Actieve Gasten</div>
                <div class="stat-change">vandaag gescand</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="revenue-today">--</div>
                <div class="stat-label">Omzet Vandaag</div>
                <div class="stat-change positive" id="revenue-change"></div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="avg-spending">--</div>
                <div class="stat-label">Gem. Besteding</div>
                <div class="stat-change">per gast</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="total-users">--</div>
                <div class="stat-label">Totaal Gasten</div>
                <div class="stat-change">alle tijden</div>
            </div>
        </div>

        <!-- Piektijden Chart -->
        <div class="stat-card" style="margin-top: var(--space-md);">
            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md);">Piektijden (afgelopen 30 dagen)</h3>
            <div class="peak-hours-chart" id="peak-hours-chart">
                <!-- Bars worden via JS ingevuld -->
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary);">
                <span>00:00</span>
                <span>06:00</span>
                <span>12:00</span>
                <span>18:00</span>
                <span>24:00</span>
            </div>
        </div>
    </section>

    <!-- Sectie 2: WHALE TRACKER -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">🐋</span> Whale Tracker - Top Spenders (30 dagen)</h2>
        
        <div class="stat-card">
            <div id="whale-list">
                <!-- Whale items worden via JS ingevuld -->
                <div class="skeleton" style="height: 60px; margin-bottom: var(--space-sm);"></div>
                <div class="skeleton" style="height: 60px; margin-bottom: var(--space-sm);"></div>
                <div class="skeleton" style="height: 60px; margin-bottom: var(--space-sm);"></div>
            </div>
        </div>
    </section>

    <!-- Sectie 3: SALDO & LIQUIDITEIT -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">🐷</span> Saldo & Liquiditeit - Het Spaarvarken</h2>
        
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-value" id="total-outstanding">--</div>
                <div class="stat-label">Totaal Uitstaand</div>
                <div class="stat-change">ongebruikt saldo</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="burn-rate">--%</div>
                <div class="stat-label">Burn Rate</div>
                <div class="stat-change">30 dagen</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="deposited-30d">--</div>
                <div class="stat-label">Gestort (30d)</div>
                <div class="stat-change">inkomsten</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="spent-30d">--</div>
                <div class="stat-label">Uitgegeven (30d)</div>
                <div class="stat-change">omzet</div>
            </div>
        </div>
    </section>

    <?php if ($featureMarketing): ?>
    <!-- Sectie 4: MARKETING PERFORMANCE (alleen zichtbaar als Super-Admin module heeft aangezet) -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">🎂</span> Marketing Performance</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
            <!-- Verjaardagen -->
            <div class="stat-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md);">Verjaardagen Deze Week</h3>
                <div id="birthday-list">
                    <p style="color: var(--text-secondary); font-size: 14px;">Geen verjaardagen deze week</p>
                </div>
            </div>
            
            <!-- Dinsdag Bonus Effect -->
            <div class="stat-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md);">Dinsdag-Bonus Effect</h3>
                <div id="tuesday-effect">
                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-xs);">
                        <span style="color: var(--text-secondary);">Dinsdag omzet</span>
                        <span id="tuesday-total">--</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-secondary);">Aantal transacties</span>
                        <span id="tuesday-count">--</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Sectie 5: PERSONEELSCONTROLE -->
    <section style="margin-bottom: var(--space-xl);">
        <h2 class="section-title"><span class="section-icon">👮</span> Personeelscontrole - Anti-Fraude</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
            <!-- Omzet per Barman -->
            <div class="stat-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md);">Omzet per Barman</h3>
                <div id="bartender-list">
                    <div class="skeleton" style="height: 50px; margin-bottom: var(--space-xs);"></div>
                    <div class="skeleton" style="height: 50px;"></div>
                </div>
            </div>
            
            <!-- Correctie Log (Rode Vlaggen) -->
            <div class="stat-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-md);">
                    <span style="color: var(--error);">🚩</span> Correctie-log (7 dagen)
                </h3>
                <div id="correction-log">
                    <p style="color: var(--text-secondary); font-size: 14px;">Geen correcties recent</p>
                </div>
            </div>
        </div>
    </section>

</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>

<script>
// Feature flags from server (controlled by Super-Admin)
const FEATURE_PUSH = <?= $featurePush ? 'true' : 'false' ?>;
const FEATURE_MARKETING = <?= $featureMarketing ? 'true' : 'false' ?>;

// ==========================================
// Admin Dashboard JS
// Laad alle stats via de API
// ==========================================

(async function loadDashboard() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    try {
        // Laad dashboard data
        const response = await fetch((window.__BASE_URL || '') + '/api/admin/dashboard', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            }
        });

        const data = await response.json();

        if (!data.success) {
            console.error('Dashboard error:', data.error);
            return;
        }

        const d = data.data;
        
        // ==========================================
        // 1. LIVE MONITOR
        // ==========================================
        
        // Actieve gasten vandaag
        document.getElementById('active-guests').textContent = d.live_monitor?.active_guests_today ?? 0;
        
        // Omzet vandaag
        document.getElementById('revenue-today').textContent = formatEuro(d.revenue_today);
        
        // Gemiddelde besteding
        document.getElementById('avg-spending').textContent = formatEuro(d.live_monitor?.avg_spending);
        
        // Totaal gasten
        document.getElementById('total-users').textContent = d.total_users;
        
        // Piektijden chart
        renderPeakHoursChart(d.live_monitor?.peak_hours || []);
        
        // ==========================================
        // 2. WHALE TRACKER
        // ==========================================
        renderWhales(d.whales || []);
        
        // ==========================================
        // 3. SALDO & LIQUIDITEIT
        // ==========================================
        document.getElementById('total-outstanding').textContent = formatEuro(d.liquidity?.total_outstanding);
        document.getElementById('burn-rate').textContent = (d.liquidity?.burn_rate_percent ?? 0) + '%';
        document.getElementById('deposited-30d').textContent = formatEuro(d.liquidity?.deposited_30d);
        document.getElementById('spent-30d').textContent = formatEuro(d.liquidity?.spent_30d);
        
        // ==========================================
        // 4. MARKETING PERFORMANCE (alleen als actief)
        // ==========================================
        if (FEATURE_MARKETING) {
            renderBirthdays(d.marketing?.birthdays_this_week || []);
            renderTuesdayEffect(d.marketing?.tuesday_effect);
        }
        
        // ==========================================
        // 5. PERSONEELSCONTROLE
        // ==========================================
        renderBartenders(d.staff?.bartender_stats || []);
        renderCorrectionLog(d.staff?.correction_log || []);
        
    } catch (error) {
        console.error('Dashboard load error:', error);
    }
})();

// ------------------------------------------
// Helper: Format Euro
// ------------------------------------------
function formatEuro(cents) {
    if (cents === null || cents === undefined) return '€0,00';
    return '€' + (cents / 100).toFixed(2).replace('.', ',');
}

// ------------------------------------------
// Helper: Format Aantal
// ------------------------------------------
function formatNumber(num) {
    if (num === null || num === undefined) return '0';
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// ------------------------------------------
// Render Peak Hours Chart
// ------------------------------------------
function renderPeakHoursChart(hourlyData) {
    const container = document.getElementById('peak-hours-chart');
    if (!container || !hourlyData || hourlyData.length === 0) return;
    
    // Vind max voor schaling
    const max = Math.max(...hourlyData) || 1;
    
    let html = '';
    for (let h = 0; h < 24; h++) {
        const value = hourlyData[h] || 0;
        const height = (value / max) * 100;
        const hour = h.toString().padStart(2, '0');
        html += `<div class="bar" style="height: ${height}%;" title="${hour}:00 - ${formatEuro(value)}"></div>`;
    }
    container.innerHTML = html;
}

// ------------------------------------------
// Render Whales
// ------------------------------------------
function renderWhales(whales) {
    const container = document.getElementById('whale-list');
    if (!container) return;
    
    if (!whales || whales.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary);">Nog geen whales deze maand</p>';
        return;
    }
    
    let html = '';
    whales.forEach((w, i) => {
        const initials = (w.first_name?.[0] || '') + (w.last_name?.[0] || '');
        const hasPush = w.has_push ? '✅' : '⚠️';
        const bonusBtn = FEATURE_PUSH
            ? `<button class="btn btn-secondary btn-action" onclick="sendWhaleBonus(${w.id})" title="Bonus sturen">🎁</button>`
            : '';
        
        html += `
            <div class="whale-card">
                <div class="whale-avatar">${initials}</div>
                <div class="whale-info">
                    <div class="whale-name">${w.first_name} ${w.last_name}</div>
                    <div class="whale-stats">${w.visits_30d} bezoeken${FEATURE_PUSH ? ' • ' + hasPush + ' push' : ''}</div>
                </div>
                <div class="whale-amount">${formatEuro(w.total_spent_30d)}</div>
                ${bonusBtn}
            </div>
        `;
    });
    container.innerHTML = html;
}

// ------------------------------------------
// Render Birthdays
// ------------------------------------------
function renderBirthdays(birthdays) {
    const container = document.getElementById('birthday-list');
    if (!container) return;
    
    if (!birthdays || birthdays.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary);">Geen verjaardagen deze week</p>';
        return;
    }
    
    let html = '';
    birthdays.forEach(b => {
        const cakeDate = new Date(b.birthdate).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
        html += `
            <div class="birthday-item">
                <span class="birthday-cake">🎂</span>
                <div>
                    <div style="font-weight: 600;">${b.first_name} ${b.last_name}</div>
                    <div style="font-size: 13px; color: var(--text-secondary);">${cakeDate} (${b.age} jaar)</div>
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

// ------------------------------------------
// Render Tuesday Effect
// ------------------------------------------
function renderTuesdayEffect(effect) {
    if (!effect) return;
    document.getElementById('tuesday-total').textContent = formatEuro(effect.total);
    document.getElementById('tuesday-count').textContent = effect.count || 0;
}

// ------------------------------------------
// Render Bartenders
// ------------------------------------------
function renderBartenders(bartenders) {
    const container = document.getElementById('bartender-list');
    if (!container) return;
    
    if (!bartenders || bartenders.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary);">Geen barmannen</p>';
        return;
    }
    
    let html = '';
    bartenders.forEach(b => {
        html += `
            <div class="barman-card">
                <div class="barman-info">
                    <div class="barman-name">${b.first_name} ${b.last_name}</div>
                    <div class="barman-stats">${b.transaction_count} betalingen</div>
                </div>
                <div class="barman-revenue">${formatEuro(b.total_revenue)}</div>
            </div>
        `;
    });
    container.innerHTML = html;
}

// ------------------------------------------
// Render Correction Log
// ------------------------------------------
function renderCorrectionLog(logs) {
    const container = document.getElementById('correction-log');
    if (!container) return;
    
    if (!logs || logs.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary); font-size: 14px;">Geen correcties recent</p>';
        return;
    }
    
    let html = '';
    logs.forEach(l => {
        const date = new Date(l.created_at).toLocaleDateString('nl-NL', { 
            day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' 
        });
        html += `
            <div class="correction-item">
                <span class="correction-flag">🚩</span>
                <div style="flex: 1;">
                    <div>${l.guest_name} • ${formatEuro(l.amount)}</div>
                    <div style="font-size: 12px; color: var(--text-secondary);">
                        ${l.bartender_name} • ${date}
                    </div>
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

// ------------------------------------------
// Send Whale Bonus
// ------------------------------------------
async function sendWhaleBonus(userId) {
    if (!confirm('Wil je deze whale een bonus sturen?')) return;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    try {
        const response = await fetch((window.__BASE_URL || '') + '/api/push/send_notification', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                user_id: userId,
                title: 'Bedankt! 🎉',
                body: 'Je bent een topgast! Hierbij een bedankje van ons.'
            })
        });
        
        const data = await response.json();
        if (data.success) {
            alert('Bonus melding verstuurd!');
        } else {
            alert('Fout: ' + data.error);
        }
    } catch (e) {
        alert('Kon bonus niet versturen');
    }
}
</script>