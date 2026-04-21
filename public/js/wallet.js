/**
 * STAMGAST - Wallet Functionaliteit
 * Gast: Wallet bekijken & opwaarderen
 */
(function() {
    'use strict';

    let walletData = null;
    let depositProcessing = false;

    // ============================================
    // WALLET DATA
    // ============================================
    async function loadWalletData() {
        try {
            const response = await window.STAMGAST.api('/wallet/balance');
            
            if (response.success) {
                walletData = response.data;
                updateWalletDisplay();
                return walletData;
            }
            
            throw new Error(response.error || 'Failed to load wallet');
        } catch (error) {
            console.error('Wallet load error:', error);
            window.STAMGAST.showError('Kon wallet niet laden');
            return null;
        }
    }

    function updateWalletDisplay() {
        if (!walletData) return;

        const balanceEl = document.getElementById('wallet-balance');
        const pointsEl = document.getElementById('wallet-points');
        const tierEl = document.getElementById('wallet-tier');

        if (balanceEl) {
            balanceEl.textContent = window.STAMGAST.formatCurrency(walletData.balance_cents);
            animateValue(balanceEl, walletData.balance_cents);
        }

        if (pointsEl) {
            pointsEl.textContent = window.STAMGAST.formatPoints(walletData.points_cents);
        }

        if (tierEl && walletData.tier) {
            tierEl.textContent = walletData.tier.name;
            if (walletData.tier.multiplier > 1) {
                tierEl.textContent += ` (${walletData.tier.multiplier}x)`;
            }
        }

        // Update deposit button state
        const depositBtn = document.getElementById('deposit-btn');
        if (depositBtn) {
            depositBtn.disabled = false;
        }
    }

    function animateValue(element, newValue) {
        // Odometer animation effect
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
        const depositBtn = document.getElementById('deposit-btn');
        
        try {
            window.STAMGAST.showLoading(depositBtn);
            
            const response = await window.STAMGAST.api('/wallet/deposit', {
                method: 'POST',
                body: {
                    amount_cents: amountCents
                }
            });

            if (response.success && response.data.checkout_url) {
                // Redirect to Mollie checkout
                window.location.href = response.data.checkout_url;
            } else if (response.success && response.data.status === 'mock') {
                // Mock mode - simulate instant deposit
                window.STAMGAST.showSuccess(`€${(amountCents / 100).toFixed(2)} toegevoegd!`);
                await loadWalletData();
            } else {
                throw new Error(response.error || 'Deposit failed');
            }
        } catch (error) {
            console.error('Deposit error:', error);
            window.STAMGAST.showError('Kon niet opwaarderen. Probeer het later opnieuw.');
        } finally {
            window.STAMGAST.hideLoading(depositBtn);
            depositProcessing = false;
        }
    }

    // ============================================
    // TRANSACTION HISTORY
    // ============================================
    async function loadTransactionHistory(page = 1, limit = 20) {
        try {
            const response = await window.STAMGAST.api(`/wallet/history?page=${page}&limit=${limit}`);
            
            if (response.success) {
                return response.data;
            }
            
            throw new Error(response.error || 'Failed to load history');
        } catch (error) {
            console.error('History load error:', error);
            window.STAMGAST.showError('Kon geschiedenis niet laden');
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

        container.innerHTML = transactions.map(tx => {
            const isPositive = tx.type === 'deposit' || tx.type === 'bonus';
            const amount = isPositive ? tx.final_total_cents : -tx.final_total_cents;
            const date = new Date(tx.created_at).toLocaleDateString('nl-NL', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });

            return `
                <div class="transaction-item">
                    <div class="transaction-icon">
                        <i class="icon-${tx.type}"></i>
                    </div>
                    <div class="transaction-details">
                        <div class="transaction-type">${getTransactionLabel(tx.type)}</div>
                        <div class="transaction-date">${date}</div>
                    </div>
                    <div class="transaction-amount ${isPositive ? 'positive' : 'negative'}">
                        ${isPositive ? '+' : ''}${window.STAMGAST.formatCurrency(amount)}
                    </div>
                </div>
            `;
        }).join('');
    }

    function getTransactionLabel(type) {
        const labels = {
            payment: 'Betaling',
            deposit: 'Opwaardering',
            bonus: 'Bonus',
            correction: 'Correctie'
        };
        return labels[type] || type;
    }

    // ============================================
    // QUICK DEPOSIT AMOUNTS
    // ============================================
    const DEPOSIT_AMOUNTS = [
        { cents: 500,  label: '€5' },
        { cents: 1000, label: '€10' },
        { cents: 2500, label: '€25' },
        { cents: 5000, label: '€50' },
        { cents: 10000, label: '€100' }
    ];

    function renderDepositButtons() {
        const container = document.getElementById('deposit-options');
        if (!container) return;

        container.innerHTML = DEPOSIT_AMOUNTS.map(amount => `
            <button class="btn btn-deposit-option" data-amount="${amount.cents}">
                ${amount.label}
            </button>
        `).join('');

        // Add event listeners
        container.querySelectorAll('.btn-deposit-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const amount = parseInt(btn.dataset.amount, 10);
                initDeposit(amount);
            });
        });
    }

    // ============================================
    // CUSTOM AMOUNT
    // ============================================
    function setupCustomDeposit() {
        const customInput = document.getElementById('custom-amount');
        const customBtn = document.getElementById('custom-deposit-btn');
        
        if (!customInput || !customBtn) return;

        // Format input as currency
        customInput.addEventListener('input', (e) => {
            let value = e.target.value.replace(/[^\d]/g, '');
            if (value) {
                value = parseInt(value, 10);
                e.target.value = '€' + (value / 100).toFixed(2);
            }
        });

        customBtn.addEventListener('click', () => {
            let value = customInput.value.replace(/[^\d]/g, '');
            const amount = parseInt(value, 10) || 0;
            
            if (amount < 500) {
                window.STAMGAST.showError('Minimum opwaardering is €5');
                return;
            }
            
            if (amount > 50000) {
                window.STAMGAST.showError('Maximum opwaardering is €500');
                return;
            }
            
            initDeposit(amount);
        });
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    async function initWallet() {
        console.log('Initializing Wallet...');
        
        // Load wallet data
        await loadWalletData();
        
        // Setup deposit UI
        renderDepositButtons();
        setupCustomDeposit();
        
        // Load transaction history
        const historyData = await loadTransactionHistory();
        if (historyData) {
            renderTransactionHistory(historyData.transactions);
        }
        
        console.log('Wallet initialized');
    }

    // Export to global
    window.STAMGAST = window.STAMGAST || {};
    window.STAMGAST.wallet = {
        init: initWallet,
        load: loadWalletData,
        deposit: initDeposit,
        loadHistory: loadTransactionHistory
    };

    // Auto-init if on wallet page
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWallet);
    } else if (window.location.pathname.includes('/wallet')) {
        initWallet();
    }

})();