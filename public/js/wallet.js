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
    async function initDeposit(amountCents) {
        if (depositProcessing) return;
        
        depositProcessing = true;
        
        try {
            const response = await window.REGULR.api('/wallet/deposit', {
                method: 'POST',
                body: {
                    amount_cents: amountCents
                }
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
            const amount = (pkg.topup_amount_cents / 100).toFixed(0);

            // Build discount info lines
            var perks = [];
            if (pkg.alcohol_discount_perc > 0) {
                perks.push(pkg.alcohol_discount_perc + '% dranken korting');
            }
            if (pkg.food_discount_perc > 0) {
                perks.push(pkg.food_discount_perc + '% eten korting');
            }
            if (pkg.points_multiplier > 1) {
                perks.push(pkg.points_multiplier + 'x punten');
            }

            var perksHtml = '';
            if (perks.length > 0) {
                perksHtml = '<span class="deposit-option__perks">' + perks.join(' &bull; ') + '</span>';
            }

            return '<button class="btn btn-deposit-option" data-amount="' + pkg.topup_amount_cents + '">' +
                '<span class="deposit-option__amount">&euro;' + amount + '</span>' +
                '<span class="deposit-option__name">' + pkg.name + '</span>' +
                perksHtml +
            '</button>';
        }).join('') + '</div>';

        // Add click handlers
        container.querySelectorAll('.btn-deposit-option').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var amount = parseInt(btn.dataset.amount, 10);
                initDeposit(amount);
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

            return '<div class="transaction-item">' +
                '<div class="transaction-icon">' +
                    '<i class="icon-' + tx.type + '"></i>' +
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
        
        // Load wallet data and packages in parallel
        await Promise.all([
            loadWalletData(),
            loadPackages()
        ]);
        
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
