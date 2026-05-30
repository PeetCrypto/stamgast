<?php
declare(strict_types=1);
/**
 * REGULR.vip - Gast Mijn Voordelen
 * Gast: Pakketten bekijken en bestellen (netjes onder elkaar)
 */

// Session and auth are already handled by index.php router.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
    redirect(getGuestLoginUrl());
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;

// Account status check for gated onboarding
$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$accountStatus = $userModel->getAccountStatus((int) $user['user_id']);
$tenantModel = new Tenant($db);
$tenantData = $tenantModel->findById((int) ($user['tenant_id'] ?? 0));
$verificationRequired = (bool) ($tenantData['verification_required'] ?? true);
$pointsEnabled = (bool) ($tenantData['points_enabled'] ?? true);
$isUnverified = ($accountStatus !== 'active' && $verificationRequired);

// Get wallet balance
require_once __DIR__ . '/../../models/Wallet.php';
$walletModel = new Wallet($db);
$wallet = $walletModel->findByUserAndTenant((int) $user['user_id'], (int) ($user['tenant_id'] ?? 0));
$balanceCents = $wallet ? (int) $wallet['balance_cents'] : 0;
$pointsCents = $wallet ? (int) $wallet['points_cents'] : 0;

require __DIR__ . '/../shared/header.php';
?>

<style>
/* ── Silver Benefit Card (zelfde stijl als dashboard Quick Actions) ── */
.benefit-card {
    position: relative;
    border: none;
    border-radius: 20px;
    background-clip: padding-box;
    overflow: visible;
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    background: var(--glass-bg, rgba(255,255,255,0.05));
}

.benefit-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 20px;
    padding: 2px;
    background: linear-gradient(135deg, #e8e8e8, #a0a0a0, #d0d0d0, #888888, #c0c0c0);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.benefit-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}

.benefit-card:active {
    transform: scale(0.98);
}

.benefit-card__info {
    flex: 1;
    min-width: 0;
}

.benefit-card__name {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: var(--space-xs);
    color: var(--text-primary, #fff);
}

.benefit-card__meta {
    font-size: 13px;
    color: var(--text-secondary, rgba(255,255,255,0.6));
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}

.benefit-card__bonus {
    display: inline-block;
    background: rgba(76, 175, 80, 0.2);
    color: #4CAF50;
    font-size: 12px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
}

.benefit-card__extra {
    display: inline-block;
    background: rgba(33, 150, 243, 0.15);
    color: #64B5F6;
    font-size: 12px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
}

.benefit-card__price {
    text-align: right;
    white-space: nowrap;
    flex-shrink: 0;
}

.benefit-card__amount {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary, #fff);
}

.benefit-card__label {
    font-size: 12px;
    color: var(--text-secondary, rgba(255,255,255,0.5));
}

.benefit-card__arrow {
    flex-shrink: 0;
    color: var(--text-muted, rgba(255,255,255,0.3));
    font-size: 20px;
    margin-left: var(--space-xs);
}

/* ── Bonus card highlight ── */
.benefit-card--bonus {
    border-color: rgba(76, 175, 80, 0.3);
    background: rgba(76, 175, 80, 0.06);
}

.benefit-card--bonus .benefit-card__amount {
    background: linear-gradient(135deg, #4CAF50, #81C784);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── Loading skeleton ── */
.skeleton-card {
    background: var(--glass-bg, rgba(255,255,255,0.05));
    border: 1px solid var(--glass-border, rgba(255,255,255,0.1));
    border-radius: 16px;
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    animation: skeleton-pulse 1.5s infinite;
}

@keyframes skeleton-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 0.8; }
}

/* ── Gold Wallet Card (zelfde stijl als dashboard) ── */
.gold-wallet-card {
    position: relative;
    border: none;
    border-radius: 20px;
    background-clip: padding-box;
}

.gold-wallet-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 20px;
    padding: 2px;
    background: linear-gradient(135deg, #cfc09f, #634f2c, #cfc09f, #ffecb3, #cfc09f);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.gold-text {
    background: linear-gradient(to bottom, #cfc09f 27%, #ffecb3 40%, #3a2c0f 78%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    color: #fff;
    position: relative;
    font-size: 48px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.gold-text::after {
    background: none;
    content: attr(data-heading);
    inset: 0;
    z-index: -1;
    position: absolute;
    -webkit-text-fill-color: transparent;
    color: transparent;
    text-shadow:
        -1px 0 1px #c6bb9f,
        0 1px 1px #c6bb9f,
        5px 5px 10px rgba(0, 0, 0, 0.4),
        -5px -5px 10px rgba(0, 0, 0, 0.4);
}

/* ── Security note ── */
.security-note {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: var(--space-sm);
    color: var(--text-muted);
    font-size: 13px;
    border-top: 1px solid var(--glass-border, rgba(255,255,255,0.08));
    margin-top: var(--space-md);
}
</style>

<div class="container" style="padding: var(--space-lg);">
    <!-- Header -->
    <div style="margin-bottom: var(--space-lg);">
        <h1>Mijn voordelen</h1>
    </div>

    <!-- Wallet Card (goud omlijst) -->
    <div class="glass-card gold-wallet-card" style="padding: var(--space-xl); margin-bottom: var(--space-lg); text-align: center; position: relative;">
        <p class="text-secondary text-sm">Je saldo</p>
        <h2 class="gold-text" data-heading="€ <?= number_format($balanceCents / 100, 2, ',', '.') ?>">€ <?= number_format($balanceCents / 100, 2, ',', '.') ?></h2>
        <?php if ($pointsEnabled): ?>
        <p class="text-secondary text-sm" style="margin-top: var(--space-xs);"><?= number_format($pointsCents / 100, 0) ?> punten</p>
        <?php endif; ?>
    </div>

    <?php if (!$isUnverified): ?>
    <!-- Package List -->
    <div id="packages-container">
        <!-- Loading skeletons -->
        <div class="skeleton-card">
            <div style="flex:1;">
                <div style="height:18px;width:60%;background:rgba(255,255,255,0.08);border-radius:8px;margin-bottom:8px;"></div>
                <div style="height:14px;width:40%;background:rgba(255,255,255,0.05);border-radius:8px;"></div>
            </div>
            <div style="width:60px;height:28px;background:rgba(255,255,255,0.08);border-radius:8px;"></div>
        </div>
        <div class="skeleton-card">
            <div style="flex:1;">
                <div style="height:18px;width:50%;background:rgba(255,255,255,0.08);border-radius:8px;margin-bottom:8px;"></div>
                <div style="height:14px;width:35%;background:rgba(255,255,255,0.05);border-radius:8px;"></div>
            </div>
            <div style="width:60px;height:28px;background:rgba(255,255,255,0.08);border-radius:8px;"></div>
        </div>
    </div>

    <div class="security-note" id="security-note" style="display:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
        <span>Veilig betalen via Mollie</span>
    </div>

    <?php else: ?>
    <!-- Unverified Account -->
    <div class="glass-card" style="padding: var(--space-lg); border: 2px solid rgba(255,193,7,0.4); background: rgba(255,193,7,0.06); text-align: center;">
        <p style="font-size: 18px; color: #FFC107; font-weight: 600; margin-bottom: 0.5rem;">Account niet geactiveerd</p>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 1rem;">
            Om pakketten te bestellen, moet je eerst je ID laten zien bij de bar.
        </p>
        <a href="<?= BASE_URL ?>/pay" class="btn btn-outline" style="border-color: #FFC107; color: #FFC107;">Activeer bij de bar</a>
    </div>
    <?php endif; ?>

    <!-- Back to dashboard -->
    <div style="margin-top: var(--space-xl);">
        <a href="<?= BASE_URL ?>/dashboard" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Terug naar dashboard
        </a>
    </div>
</div>

<!-- Alerts Container -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script>
(function() {
    'use strict';

    var depositProcessing = false;

    // ============================================
    // LOAD PACKAGES
    // ============================================
    async function loadPackages() {
        var container = document.getElementById('packages-container');
        if (!container) return;

        try {
            var response = await window.REGULR.api('/wallet/packages');

            if (response.success) {
                var packages = response.data.packages || [];

                if (packages.length > 0) {
                    renderPackages(packages);
                    var securityNote = document.getElementById('security-note');
                    if (securityNote) securityNote.style.display = 'flex';
                } else {
                    container.innerHTML =
                        '<div style="text-align:center;padding:3rem 1rem;opacity:0.5;">' +
                            '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 1rem;display:block;">' +
                                '<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>' +
                            '</svg>' +
                            '<p style="font-size:16px;font-weight:600;margin-bottom:4px;">Geen pakketten beschikbaar</p>' +
                            '<p style="font-size:13px;">De beheerder heeft nog geen pakketten ingesteld</p>' +
                        '</div>';
                }
            } else {
                throw new Error(response.error || 'Failed to load packages');
            }
        } catch (error) {
            console.error('Benefits load error:', error);
            container.innerHTML =
                '<div style="text-align:center;padding:3rem 1rem;opacity:0.5;">' +
                    '<p>Kon pakketten niet laden</p>' +
                '</div>';
        }
    }

    // ============================================
    // RENDER PACKAGES (vertical cards)
    // ============================================
    function renderPackages(packages) {
        var container = document.getElementById('packages-container');
        if (!container) return;

        container.innerHTML = packages.map(function(pkg) {
            var topupEur = (pkg.topup_amount_cents / 100).toFixed(2).replace('.', ',');
            var isBonus = (pkg.model_type === 'bonus');

            // Calculate bonus for bonus packages
            var bonusCents = 0;
            var totalCents = pkg.topup_amount_cents;
            if (isBonus) {
                bonusCents = pkg.bonus_cents > 0
                    ? pkg.bonus_cents
                    : Math.floor(pkg.topup_amount_cents * (pkg.bonus_percentage || 0) / 100);
                totalCents = pkg.topup_amount_cents + bonusCents;
            }
            var totalEur = (totalCents / 100).toFixed(2).replace('.', ',');
            var bonusEur = (bonusCents / 100).toFixed(2).replace('.', ',');

            var cardClass = 'benefit-card' + (isBonus && bonusCents > 0 ? ' benefit-card--bonus' : '');

            // Price section
            var priceHtml = '<div class="benefit-card__price">';
            if (isBonus && bonusCents > 0) {
                priceHtml += '<div class="benefit-card__label">Betaal</div>';
                priceHtml += '<div class="benefit-card__amount">&euro;' + topupEur + '</div>';
            } else {
                priceHtml += '<div class="benefit-card__label">&nbsp;</div>';
                priceHtml += '<div class="benefit-card__amount">&euro;' + topupEur + '</div>';
            }
            priceHtml += '</div>';

            // Meta tags (bonus badge, extras)
            var metaHtml = '';
            if (isBonus && bonusCents > 0) {
                metaHtml += '<span class="benefit-card__bonus">&euro;' + bonusEur + ' gratis tegoed</span>';
            }

            // Extras: discounts and points
            var extras = [];
            if (!isBonus && pkg.alcohol_discount_perc > 0) {
                extras.push('-' + pkg.alcohol_discount_perc + '% alcohol');
            }
            if (pkg.food_discount_perc > 0) {
                extras.push('-' + pkg.food_discount_perc + '% non-alcohol');
            }
            if (pkg.points_multiplier > 1) {
                extras.push(pkg.points_multiplier + 'x punten');
            }
            for (var i = 0; i < extras.length; i++) {
                metaHtml += '<span class="benefit-card__extra">' + extras[i] + '</span>';
            }

            return '<div class="' + cardClass + '" data-amount="' + pkg.topup_amount_cents + '" data-tier-id="' + (pkg.id || '') + '">' +
                '<div class="benefit-card__info">' +
                    '<div class="benefit-card__name">' + pkg.name + '</div>' +
                    '<div class="benefit-card__meta">' + metaHtml + '</div>' +
                '</div>' +
                priceHtml +
                '<div class="benefit-card__arrow">&rsaquo;</div>' +
            '</div>';
        }).join('');

        // Click handlers
        container.querySelectorAll('.benefit-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var amount = parseInt(card.dataset.amount, 10);
                var tierId = card.dataset.tierId ? parseInt(card.dataset.tierId, 10) : 0;
                initDeposit(amount, tierId);
            });
        });
    }

    // ============================================
    // DEPOSIT (same as wallet.js)
    // ============================================
    async function initDeposit(amountCents, tierId) {
        if (depositProcessing) return;
        depositProcessing = true;

        try {
            var body = { amount_cents: amountCents };
            if (tierId > 0) { body.tier_id = tierId; }

            var response = await window.REGULR.api('/wallet/deposit', {
                method: 'POST',
                body: body
            });

            if (response.success && response.data.status === 'mock') {
                window.REGULR.showSuccess('\u20AC' + (amountCents / 100).toFixed(2) + ' toegevoegd aan je wallet!');
            } else if (response.success && response.data.checkout_url) {
                window.location.href = response.data.checkout_url;
            } else {
                throw new Error(response.error || 'Deposit failed');
            }
        } catch (error) {
            console.error('Deposit error:', error);
            window.REGULR.showError('Kon niet bestellen. Probeer het later opnieuw.');
        } finally {
            depositProcessing = false;
        }
    }

    // ============================================
    // INIT
    // ============================================
    async function initBenefits() {
        // Skip for unverified accounts (PHP already shows banner)
        if (window.REGULR && window.REGULR.state && window.REGULR.state.accountStatus === 'unverified') {
            return;
        }
        await loadPackages();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBenefits);
    } else {
        initBenefits();
    }
})();
</script>

<?php require __DIR__ . '/../shared/footer.php'; ?>
