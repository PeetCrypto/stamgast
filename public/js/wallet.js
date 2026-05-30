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
                window.REGULR.showSuccess('€' + (amountCents / 100).toFixed(2) + ' toegevoegd!');
                await loadWalletData();
            } else if (response.success && response.data.checkout_url) {
                // Test/Live mode - redirect to Mollie checkout
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

    function renderTransactionHistory(transactions) {
        const container = document.getElementById('transaction-list');
        if (!container) return;

        if (!transactions || transactions.length === 0) {
            container.innerHTML = '<div class="empty-state">Nog geen transacties</div>';
            return;
        }

        container.innerHTML = transactions.map(function(tx) {
            const isPositive = tx.type === 'deposit' || tx.type === 'bonus';
            const amount = isPositive ? tx.final_total_cents : -tx.final_total_cents;
            const date = new Date(tx.created_at).toLocaleDateString('nl-NL', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });

            var icon = getTransactionIcon(tx.type);

            return '<div class="transaction-item">' +
                '<div class="transaction-icon ' + icon.cls + '">' +
                    icon.svg +
                '</div>' +
                '<div class="transaction-details">' +
                    '<div class="transaction-type">' + getTransactionLabel(tx.type) + '</div>' +
                    '<div class="transaction-date">' + date + '</div>' +
                '</div>' +
                '<div class="transaction-amount ' + (isPositive ? 'positive' : 'negative') + '">' +
                    (isPositive ? '+' : '') + window.REGULR.formatCurrency(amount) +
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
        
        // Load transaction history
        var historyData = await loadTransactionHistory();
        if (historyData) {
            renderTransactionHistory(historyData.transactions);
        }
        
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
