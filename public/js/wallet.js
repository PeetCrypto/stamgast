/**
 * REGULR.vip - Wallet Functionaliteit
 * Gast: Wallet bekijken & opwaarderen via dynamische pakketten
 */
(function() {
    'use strict';

    let walletData = null;
    let depositProcessing = false;
    let packagesData = [];

    // ============================================
    // WALLET DATA
    // ============================================
    async function loadWalletData() {
        try {
            const response = await window.REGULR.api('/wallet/balance');
            
            if (response.success) {
                walletData = response.data;
                updateWalletDisplay();
                return walletData;
            }
            
            throw new Error(response.error || 'Failed to load wallet');
        } catch (error) {
            console.error('Wallet load error:', error);
            window.REGULR.showError('Kon wallet niet laden');
            return null;
        }
    }

    function updateWalletDisplay() {
        if (!walletData) return;

        const balanceEl = document.getElementById('wallet-balance');
        const pointsEl = document.getElementById('wallet-points');
        const tierEl = document.getElementById('wallet-tier');

        if (balanceEl) {
            balanceEl.textContent = window.REGULR.formatCurrency(walletData.balance_cents);
            animateValue(balanceEl, walletData.balance_cents);
        }

        if (pointsEl) {
            pointsEl.textContent = window.REGULR.formatPoints(walletData.points_cents);
        }

        if (tierEl && walletData.tier) {
            tierEl.textContent = walletData.tier.name;
            if (walletData.tier.points_multiplier > 1) {
                tierEl.textContent += ' (' + walletData.tier.points_multiplier + 'x)';
            }
        }

        // Update deposit button state
        const depositBtn = document.getElementById('deposit-btn');
        if (depositBtn) {
            depositBtn.disabled = false;
        }
    }

    function animateValue(element, newValue) {
        element.classList.add('odometer-roll');
        setTimeout(() => {
            element.classList.remove('odometer-roll');
        }, 500);
    }

    // ============================================
    // DEPOSIT FLOW
    // ============================================
    async function initDeposit(amountCents, tierId) {
        if (depositProcessing) return;
        
        depositProcessing = true;
        
        try {
            var body = { amount_cents: amountCents };
            if (tierId > 0) { body.tier_id = tierId; }

            const response = await window.REGULR.api('/wallet/deposit', {
                method: 'POST',
                body: body
            });

            if (response.success && response.data.status === 'mock') {
                // Mock mode - deposit was processed instantly server-side
                var totalCents = response.data.total_cents || amountCents;
                var bonusCents = response.data.bonus_cents || 0;
                var msg = '€' + (totalCents / 100).toFixed(2) + ' toegevoegd!';
                if (bonusCents > 0) {
                    msg += ' (incl. €' + (bonusCents / 100).toFixed(2) + ' bonus)';
                }
                window.REGULR.showSuccess(msg);
                await loadWalletData();
            } else if (response.success && response.data.checkout_url) {
                // Test/Live mode - redirect to Mollie checkout.
                // Persist the pending payment in sessionStorage so the PWA can
                // resume polling when the guest returns from the external payment
                // (iOS opens Mollie in Safari; the PWA keeps running in the
                // background and detects the return via visibilitychange).
                REGULR.PaymentTracker.start({
                    payment_id:     response.data.payment_id,
                    transaction_id: response.data.transaction_id,
                    amount_cents:   amountCents,
                    balance_before: walletData ? walletData.balance_cents : 0
                });
                window.location.href = response.data.checkout_url;
            } else {
                throw new Error(response.error || 'Deposit failed');
            }
        } catch (error) {
            console.error('Deposit error:', error);
            window.REGULR.showError('Kon niet opwaarderen. Probeer het later opnieuw.');
        } finally {
            depositProcessing = false;
        }
    }

    // ============================================
    // PACKAGES (Dynamic from Admin)
    // ============================================
    async function loadPackages() {
        const container = document.getElementById('packages-container');
        if (!container) return;

        try {
            const response = await window.REGULR.api('/wallet/packages');

            if (response.success) {
                packagesData = response.data.packages || [];

                if (packagesData.length > 0) {
                    renderPackageCards(packagesData);
                } else {
                    container.innerHTML = `
                        <div class="empty-state" style="padding: var(--space-lg); text-align: center; opacity: 0.5;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3">
                                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                            </svg>
                            <p>Geen pakketten beschikbaar</p>
                            <p style="font-size:0.8rem;">De beheerder heeft nog geen pakketten ingesteld</p>
                        </div>`;
                }
            } else {
                throw new Error(response.error || 'Failed to load packages');
            }
        } catch (error) {
            console.error('Packages load error:', error);
            container.innerHTML = `
                <div class="empty-state" style="padding: var(--space-lg); text-align: center; opacity: 0.5;">
                    <p>Kon pakketten niet laden</p>
                </div>`;
        }
    }

    function renderPackageCards(packages) {
        const container = document.getElementById('packages-container');
        if (!container) return;

        container.innerHTML = '<div class="deposit-options">' + packages.map(function(pkg) {
            var topupEur = (pkg.topup_amount_cents / 100).toFixed(0);
            var isBonus = (pkg.model_type === 'bonus');

            // Calculate bonus and total for bonus packages
            var bonusCents = 0;
            var totalCents = pkg.topup_amount_cents;
            if (isBonus) {
                bonusCents = pkg.bonus_cents > 0
                    ? pkg.bonus_cents
                    : Math.floor(pkg.topup_amount_cents * (pkg.bonus_percentage || 0) / 100);
                totalCents = pkg.topup_amount_cents + bonusCents;
            }
            var totalEur = (totalCents / 100).toFixed(0);
            var bonusEur = (bonusCents / 100).toFixed(0);

            // Build the card
            var html = '';

            // Package name
            html += '<span class="deposit-option__name">' + pkg.name + '</span>';

            if (isBonus && bonusCents > 0) {
                // Bonus package: show pay/receive breakdown
                html += '<span class="deposit-option__stort">Betaal &euro;' + topupEur + '</span>';
                html += '<span class="deposit-option__total">Ontvang &euro;' + totalEur + ' tegoed</span>';
                html += '<span class="deposit-option__bonus-line">&euro;' + bonusEur + ' gratis</span>';
            } else {
                // Discount / simple package: show deposit amount
                html += '<span class="deposit-option__amount">&euro;' + topupEur + '</span>';
            }

            // Extras: discounts and points (both models)
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
            if (extras.length > 0) {
                html += '<span class="deposit-option__extras">' + extras.join(' &middot; ') + '</span>';
            }

            return '<button class="btn btn-deposit-option' + (isBonus && bonusCents > 0 ? ' btn-deposit-option--bonus' : '') + '" data-amount="' + pkg.topup_amount_cents + '" data-tier-id="' + (pkg.id || '') + '">' +
                html +
            '</button>';
        }).join('') + '</div>';

        // Add click handlers
        container.querySelectorAll('.btn-deposit-option').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var amount = parseInt(btn.dataset.amount, 10);
                var tierId = btn.dataset.tierId ? parseInt(btn.dataset.tierId, 10) : 0;
                initDeposit(amount, tierId);
            });
        });
    }

    // ============================================
    // TRANSACTION HISTORY
    // ============================================
    async function loadTransactionHistory(page, limit) {
        page = page || 1;
        limit = limit || 20;

        try {
            const response = await window.REGULR.api('/wallet/history?page=' + page + '&limit=' + limit);
            
            if (response.success) {
                return response.data;
            }
            
            throw new Error(response.error || 'Failed to load history');
        } catch (error) {
            console.error('History load error:', error);
            window.REGULR.showError('Kon geschiedenis niet laden');
            return null;
        }
    }

    function getTransactionIcon(type) {
        var icons = {
            payment:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            deposit:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/><path d="M18 12a2 2 0 100 4 2 2 0 000-4z"/><line x1="12" y1="2" x2="12" y2="7"/><line x1="9" y1="4.5" x2="15" y2="4.5"/></svg>',
            bonus:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            correction: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        };
        return {
            svg: icons[type] || icons.correction,
            cls: 'icon-' + (type || 'correction')
        };
    }

    // Rood uitroepteken icoon voor non-paid transacties
    var warningIconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

    function renderTransactionHistory(transactions) {
        const container = document.getElementById('transaction-list');
        if (!container) return;

        if (!transactions || transactions.length === 0) {
            container.innerHTML = '<div class="empty-state">Nog geen transacties</div>';
            return;
        }

        container.innerHTML = transactions.map(function(tx) {
            var status = tx.status || 'paid';
            var isPaid = (status === 'paid');
            var isPositive = (tx.type === 'deposit' || tx.type === 'bonus'
                || (tx.type === 'correction' && tx.final_total_cents > 0));
            var amount = isPositive ? tx.final_total_cents : -tx.final_total_cents;
            var date = new Date(tx.created_at).toLocaleDateString('nl-NL', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });

            // Gebruik rood uitroepteken icoon voor non-paid, normaal icoon voor paid
            var icon = isPaid ? getTransactionIcon(tx.type) : { svg: warningIconSvg, cls: 'icon-warning' };

            // Status modifier class for styling
            var statusClass = 'transaction-item';
            if (!isPaid) {
                statusClass += ' transaction-item--' + status;
            }

            // Status label for non-paid transactions
            var statusLabel = '';
            if (status === 'pending') {
                statusLabel = '<div class="transaction-status-label transaction-status-label--pending">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                    ' In behandeling</div>';
            } else if (status === 'failed') {
                statusLabel = '<div class="transaction-status-label transaction-status-label--failed">' +
                    'Mislukt</div>';
            } else if (status === 'expired') {
                statusLabel = '<div class="transaction-status-label transaction-status-label--failed">' +
                    'Verlopen</div>';
            } else if (status === 'cancelled') {
                statusLabel = '<div class="transaction-status-label transaction-status-label--cancelled">' +
                    'Geannuleerd</div>';
            }

            // Warning badge for failed/expired/cancelled deposits
            var warningBadge = '';
            if (!isPaid && (tx.type === 'deposit' || tx.type === 'bonus')) {
                warningBadge = '<div class="transaction-warning">Niet bijgeschreven</div>';
            }

            // Amount: paid = normaal met +/-, non-paid = rood zonder +/- teken
            var amountClass, amountText;
            if (isPaid) {
                amountClass = 'transaction-amount ' + (isPositive ? 'positive' : 'negative');
                amountText = (isPositive ? '+' : '') + window.REGULR.formatCurrency(amount);
            } else {
                amountClass = 'transaction-amount transaction-amount--failed';
                amountText = window.REGULR.formatCurrency(amount);
            }

            // Type label: rood voor non-paid
            var typeLabelClass = isPaid ? 'transaction-type' : 'transaction-type transaction-type--failed';

            return '<div class="' + statusClass + '">' +
                '<div class="transaction-icon ' + icon.cls + '">' +
                    icon.svg +
                '</div>' +
                '<div class="transaction-details">' +
                    '<div class="' + typeLabelClass + '">' + getTransactionLabel(tx.type) + '</div>' +
                    '<div class="transaction-date">' + date + '</div>' +
                    statusLabel +
                    warningBadge +
                '</div>' +
                '<div class="' + amountClass + '">' +
                    amountText +
                '</div>' +
            '</div>';
        }).join('');
    }

    function getTransactionLabel(type) {
        var labels = {
            payment: 'Betaling',
            deposit: 'Opwaardering',
            bonus: 'Bonus',
            correction: 'Correctie'
        };
        return labels[type] || type;
    }

    // ============================================
    // PAYMENT RETURN (race condition handling + PWA resume)
    // ============================================

    /**
     * Tracks a pending Mollie deposit so the wallet can resume polling when the
     * guest returns — either via a URL flag (?from_payment=1) or, crucially for
     * iOS PWAs, when the app regains visibility after the external payment.
     *
     * On iOS, the PWA keeps running in the background while Mollie opens in
     * Safari. When the guest switches back to the app, visibilitychange fires
     * and we resume polling to pick up the webhook-credited balance.
     */
    var pollTimer = null;

    function startBalancePolling(initialBalance, label) {
        if (pollTimer) clearInterval(pollTimer);

        if (label && window.REGULR && window.REGULR.showSuccess) {
            window.REGULR.showSuccess(label);
        }

        var attempts = 0;
        var maxAttempts = 12; // 12 × 2.5s = 30s window
        var baseline = (initialBalance != null) ? initialBalance
                       : (walletData ? walletData.balance_cents : 0);

        pollTimer = setInterval(async function() {
            attempts++;
            try {
                var freshData = await loadWalletData();
                if (freshData && freshData.balance_cents > baseline) {
                    stopBalancePolling();
                    window.REGULR.showSuccess('Saldo bijgewerkt!');
                    var historyData = await loadTransactionHistory();
                    if (historyData) renderTransactionHistory(historyData.transactions);
                    REGULR.PaymentTracker.clear();
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
            if (attempts >= maxAttempts) {
                stopBalancePolling();
                await loadWalletData(); // final refresh attempt
            }
        }, 2500);
    }

    function stopBalancePolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    /**
     * Handle return from Mollie payment. Two entry points:
     *  1. URL has ?from_payment=1 (inline browser context — payment_return.php
     *     redirected here because the guest was still logged in).
     *  2. PWA resumed (visibilitychange) with a tracked pending payment in
     *     sessionStorage.
     */
    function handlePaymentReturn() {
        var params = new URLSearchParams(window.location.search);
        var fromUrl = !!params.get('from_payment');

        if (fromUrl) {
            // Clean URL to avoid re-triggering on refresh
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        var pending = REGULR.PaymentTracker.get();
        if (!fromUrl && !pending) return;

        var baseline = (pending && pending.balance_before != null)
                       ? pending.balance_before
                       : (walletData ? walletData.balance_cents : 0);

        startBalancePolling(baseline, 'Betaling ontvangen! Je saldo wordt bijgewerkt...');
    }

    /**
     * On iOS, when the PWA (standalone) regains visibility after an external
     * payment in Safari, resume polling if there's a tracked pending payment.
     * This is the key fix: the guest doesn't have to do anything — the balance
     * updates automatically the moment they switch back to the app.
     */
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState !== 'visible') return;

        var pending = REGULR.PaymentTracker.get();
        if (!pending) return;

        // Stale guard: drop payments older than 1 hour.
        var ageMs = Date.now() - (pending.started_at || 0);
        if (ageMs > 3600000) { REGULR.PaymentTracker.clear(); return; }

        // Don't double-start if already polling.
        if (pollTimer) return;

        startBalancePolling(pending.balance_before || 0, 'Betaling controleren...');
    });

    // ============================================
    // INITIALIZATION
    // ============================================
    async function initWallet() {
        console.log('Initializing Wallet...');

        // Gated onboarding: skip deposit flow for unverified accounts
        // The PHP view already renders a verification banner instead of packages
        if (window.REGULR.state.accountStatus === 'unverified') {
            console.log('Wallet: account not verified, skipping deposit flow');
            return;
        }
        
        // Load wallet data
        await loadWalletData();

        // Load packages (if container exists on page)
        var packagesContainer = document.getElementById('packages-container');
        if (packagesContainer) {
            await loadPackages();
        }
        
        // Load transaction history
        var historyData = await loadTransactionHistory();
        if (historyData) {
            renderTransactionHistory(historyData.transactions);
        }

        // Handle return from Mollie payment (race condition polling)
        handlePaymentReturn();
        
        console.log('Wallet initialized');
    }

    // Export to global
    window.REGULR = window.REGULR || {};
    window.REGULR.wallet = {
        init: initWallet,
        load: loadWalletData,
        deposit: initDeposit,
        loadHistory: loadTransactionHistory,
        loadPackages: loadPackages
    };

    // Auto-init if on wallet page
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWallet);
    } else if (window.location.pathname.includes('/wallet')) {
        initWallet();
    }

})();
