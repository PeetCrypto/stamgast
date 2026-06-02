/**
 * REGULR.vip - Admin Dashboard Charts & Functionaliteit
 * Admin: analytics, gebruikers, tiers, instellingen
 */
(function() {
    'use strict';

    let currentPage = 1;
    let usersData = [];
    let tiersData = [];
    let segmentedUserIds = [];

    // ============================================
    // DASHBOARD CHARTS
    // ============================================
    async function loadDashboardStats() {
        try {
            const response = await window.REGULR.api('/admin/dashboard');
            
            if (response.success) {
                renderStatsCards(response.data);
                renderRevenueChart(response.data.revenue);
                renderTopUsers(response.data.top_users);
            }
        } catch (error) {
            console.error('Dashboard load error:', error);
        }
    }

    function renderStatsCards(data) {
        const cards = [
            { id: 'stat-revenue-today', value: data.revenue_today, label: 'Vandaag' },
            { id: 'stat-revenue-week', value: data.revenue_week, label: 'Deze week' },
            { id: 'stat-total-users', value: data.total_users, label: 'Gebruikers' },
            { id: 'stat-active-tiers', value: data.active_tiers, label: 'Actieve tiers' }
        ];
        
        cards.forEach(card => {
            const el = document.getElementById(card.id);
            if (el) {
                const valueEl = el.querySelector('.stat-value');
                if (valueEl) {
                    valueEl.textContent = card.id.includes('users') || card.id.includes('tiers') 
                        ? card.value 
                        : window.REGULR.formatCurrency(card.value);
                }
            }
        });
    }

    function renderRevenueChart(revenueData) {
        const canvas = document.getElementById('revenue-chart');
        if (!canvas || !revenueData) return;

        // Simple bar chart using Canvas API
        const ctx = canvas.getContext('2d');
        const max = Math.max(...Object.values(revenueData));
        
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        const barWidth = canvas.width / Object.keys(revenueData).length - 10;
        let x = 0;
        
        Object.entries(revenueData).forEach(([day, value]) => {
            const barHeight = (value / max) * canvas.height * 0.8;
            const y = canvas.height - barHeight;
            
            // Draw bar
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--brand-color') || '#FFC107';
            ctx.fillRect(x, y, barWidth, barHeight);
            
            // Draw label
            ctx.fillStyle = 'rgba(255,255,255,0.6)';
            ctx.font = '10px Inter';
            ctx.fillText(day, x, canvas.height - 5);
            
            x += barWidth + 10;
        });
    }

    function renderTopUsers(users) {
        const container = document.getElementById('top-users-list');
        if (!container || !users) return;

        container.innerHTML = users.map((user, index) => `
            <div class="top-user-item">
                <span class="rank">#${index + 1}</span>
                <span class="name">${user.first_name} ${user.last_name}</span>
                <span class="spent">${window.REGULR.formatCurrency(user.total_spent)}</span>
            </div>
        `).join('');
    }

    // ============================================
    // USERS MANAGEMENT
    // ============================================
    let searchTimeout = null;

    function statusBadge(status) {
        var badges = {
            active: '<span style="background:rgba(76,175,80,0.15);color:#4CAF50;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">Actief</span>',
            unverified: '<span style="background:rgba(255,193,7,0.15);color:#FFC107;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">Niet geverifieerd</span>',
            suspended: '<span style="background:rgba(244,67,54,0.15);color:#f44336;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">Geblokkeerd</span>'
        };
        return badges[status] || badges.unverified;
    }
    
    async function loadUsers(page = 1) {
        const search = document.getElementById('search-input')?.value || '';
        const role = document.getElementById('role-filter')?.value || '';
        const tier = document.getElementById('tier-filter')?.value || '';
        
        try {
            const params = new URLSearchParams({ page, limit: 20 });
            if (search) params.append('search', search);
            if (role) params.append('role', role);
            if (tier) params.append('tier', tier);
            
            const response = await window.REGULR.api(`/admin/users?${params.toString()}`);
            
            if (response.success) {
                usersData = response.data.users;
                renderUsersTable(response.data.users);
                updatePagination(response.data.total, page);
            }
        } catch (error) {
            console.error('Users load error:', error);
        }
    }

    function renderUsersTable(users) {
        const tbody = document.getElementById('users-table-body');
        if (!tbody) return;

        if (!users || users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8">Geen gebruikers gevonden</td></tr>';
            return;
        }

        // Default avatar SVG
        const defaultAvatar = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44"><circle fill="rgba(255,193,7,0.2)" cx="22" cy="14" r="10"/><circle fill="rgba(255,193,7,0.2)" cx="22" cy="38" r="14"/></svg>');
        
        tbody.innerHTML = users.map(user => {
            const blockedRow = user.is_blocked ? ' class="user-blocked-row"' : '';
            const blockedBadge = user.is_blocked ? '<span class="badge-blocked">Geblokkeerd</span>' : '';
            return `
            <tr data-user-id="${user.id}"${blockedRow}>
                <td>
                    <div class="user-cell">
                        <img src="${user.photo_url || defaultAvatar}" alt="" class="avatar">
                        <span>${user.first_name} ${user.last_name}${blockedBadge}</span>
                    </div>
                </td>
                <td>${user.email}</td>
                <td><span class="badge badge-${user.role}">${roleLabel(user.role)}</span></td>
                <td>${user.tier_name || '-'}</td>
                <td style="text-align: center;">${user.role !== 'guest' ? '-' : statusBadge(user.account_status)}</td>
                <td style="text-align: center;">${window.REGULR.formatCurrency(user.balance_cents)}</td>
                <td style="text-align: center;">${formatDate(user.last_activity)}</td>
                <td>
                    ${user.role === 'guest' && user.account_status === 'unverified' 
                        ? '<button class="btn btn-sm btn-activate-inline" data-id="' + user.id + '" style="background:rgba(76,175,80,0.15);color:#4CAF50;margin-right:4px;">Activeer</button>' 
                        : ''}
                    ${user.role === 'guest' 
                        ? '<button class="btn btn-sm btn-credit-wallet" data-id="' + user.id + '" style="background:rgba(255,193,7,0.15);color:#FFC107;margin-right:4px;">Saldo +</button>' 
                        : ''}
                    <button class="btn btn-sm btn-edit" data-id="${user.id}">Bewerk</button>
                </td>
            </tr>`;
        }).join('');

        // Add click handlers
        tbody.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => openUserModal(parseInt(btn.dataset.id)));
        });
        tbody.querySelectorAll('.btn-activate-inline').forEach(btn => {
            btn.addEventListener('click', () => activateUserInline(parseInt(btn.dataset.id)));
        });
        tbody.querySelectorAll('.btn-credit-wallet').forEach(btn => {
            btn.addEventListener('click', () => openCreditWalletModal(parseInt(btn.dataset.id)));
        });
    }

    function openUserModal(userId) {
        const user = usersData.find(u => u.id === userId);
        if (!user) return;

        document.getElementById('modal-title').textContent = `Bewerk: ${user.first_name} ${user.last_name}`;
        document.getElementById('user-id').value = user.id;
        document.getElementById('user-first-name').value = user.first_name;
        document.getElementById('user-last-name').value = user.last_name;
        document.getElementById('user-email').value = user.email;
        document.getElementById('user-birthdate').value = user.birthdate || '';
        document.getElementById('user-role').value = user.role;

        // Hide password create field (only for new users)
        document.getElementById('password-create-group').style.display = 'none';
        document.getElementById('user-password').removeAttribute('required');
        document.getElementById('user-password').value = '';

        // Show password reset section (only for existing users)
        document.getElementById('password-reset-section').style.display = 'block';
        document.getElementById('reset-password-input').value = '';

        // ── Account Status Section ──
        var statusSection = document.getElementById('account-status-section');
        var statusInfo    = document.getElementById('account-status-info');
        var activateBtn   = document.getElementById('activate-user-btn');
        var blockBtn      = document.getElementById('block-user-btn');
        var unblockBtn    = document.getElementById('unblock-user-btn');

        // Only show for guests
        if (user.role === 'guest') {
            statusSection.style.display = 'block';
            var accStatus = user.account_status || 'unverified';

            // Status description
            var statusDescriptions = {
                unverified: 'Deze gast heeft het account nog niet laten activeren. De gast kan geen saldo storten of betalen.',
                active: 'Account is actief. De gast kan volledig gebruik maken van de wallet.',
                suspended: 'Account is geblokkeerd door een admin.'
            };
            statusInfo.textContent = statusDescriptions[accStatus] || '';

            // Activate button: only for unverified
            activateBtn.style.display = (accStatus === 'unverified') ? 'inline-block' : 'none';

            // Block/unblock based on is_blocked (photo_status)
            if (user.is_blocked) {
                blockBtn.style.display = 'none';
                unblockBtn.style.display = 'inline-block';
            } else {
                blockBtn.style.display = 'inline-block';
                unblockBtn.style.display = 'none';
            }
        } else {
            // Staff roles: hide account status section
            statusSection.style.display = 'none';
            activateBtn.style.display = 'none';
            blockBtn.style.display = 'none';
            unblockBtn.style.display = 'none';
        }

        document.getElementById('user-modal-overlay').classList.add('modal-overlay--open');
        document.getElementById('user-modal').classList.add('show');
    }

    async function saveUser() {
        const userId = document.getElementById('user-id').value;
        const isNewUser = !userId || userId === '';

        const data = {
            first_name: document.getElementById('user-first-name').value.trim(),
            last_name: document.getElementById('user-last-name').value.trim(),
            email: document.getElementById('user-email').value.trim(),
            birthdate: document.getElementById('user-birthdate').value || null,
            role: document.getElementById('user-role').value
        };

        // Validate required fields
        if (!data.first_name || !data.last_name || !data.email) {
            window.REGULR.showError('Vul alle verplichte velden in');
            return;
        }

        if (isNewUser) {
            // CREATE mode
            data.password = document.getElementById('user-password').value;
            if (!data.password || data.password.length < 8) {
                window.REGULR.showError('Wachtwoord is verplicht en moet minimaal 8 tekens lang zijn');
                return;
            }
            data.action = 'create';
        } else {
            // UPDATE mode
            data.user_id = parseInt(userId);
            data.action = 'update';
        }

        try {
            const response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: data
            });

            if (response.success) {
                window.REGULR.showSuccess(isNewUser ? 'Gebruiker aangemaakt' : 'Gebruiker opgeslagen');
                closeModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon gebruiker niet opslaan');
        }
    }

    async function blockUser() {
        const userId = parseInt(document.getElementById('user-id').value);
        if (!userId || userId <= 0) return;

        if (!confirm('Weet je zeker dat je deze gebruiker wilt blokkeren? Deze actie kan ongedaan gemaakt worden door een admin.')) {
            return;
        }

        try {
            const response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'block', user_id: userId }
            });

            if (response.success) {
                window.REGULR.showSuccess('Gebruiker geblokkeerd');
                closeModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon gebruiker niet blokkeren');
        }
    }

    async function unblockUser() {
        const userId = parseInt(document.getElementById('user-id').value);
        if (!userId || userId <= 0) return;

        if (!confirm('Weet je zeker dat je deze gebruiker wilt deblokkeren?')) {
            return;
        }

        try {
            const response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'unblock', user_id: userId }
            });

            if (response.success) {
                window.REGULR.showSuccess('Gebruiker gedeblokkeerd');
                closeModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon gebruiker niet deblokkeren');
        }
    }

    async function activateUser() {
        var userId = parseInt(document.getElementById('user-id').value);
        if (!userId || userId <= 0) return;

        if (!confirm('Weet je zeker dat je dit gast-account wilt activeren? De gast kan daarna saldo storten en betalen.')) {
            return;
        }

        try {
            var response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'activate', user_id: userId }
            });

            if (response.success) {
                window.REGULR.showSuccess('Gast geactiveerd');
                closeModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon gast niet activeren');
        }
    }

    async function activateUserInline(userId) {
        if (!userId || userId <= 0) return;

        if (!confirm('Weet je zeker dat je dit gast-account wilt activeren? De gast kan daarna saldo storten en betalen.')) {
            return;
        }

        try {
            var response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'activate', user_id: userId }
            });

            if (response.success) {
                window.REGULR.showSuccess('Gast geactiveerd');
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon gast niet activeren');
        }
    }

    async function resetPassword() {
        const userId = parseInt(document.getElementById('user-id').value);
        const newPassword = document.getElementById('reset-password-input').value;

        if (!userId || userId <= 0) {
            window.REGULR.showError('Geen gebruiker geselecteerd');
            return;
        }

        if (!newPassword || newPassword.length < 8) {
            window.REGULR.showError('Wachtwoord moet minimaal 8 tekens lang zijn');
            return;
        }

        if (!confirm('Weet je zeker dat je het wachtwoord van deze gebruiker wilt wijzigen?')) {
            return;
        }

        try {
            const response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'reset_password', user_id: userId, new_password: newPassword }
            });

            if (response.success) {
                window.REGULR.showSuccess('Wachtwoord gewijzigd');
                document.getElementById('reset-password-input').value = '';
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon wachtwoord niet wijzigen');
        }
    }

    // ============================================
    // WALLET CREDIT (admin manual top-up)
    // ============================================
    function openCreditWalletModal(userId) {
        const user = usersData.find(u => u.id === userId);
        if (!user) return;

        document.getElementById('credit-user-id').value = user.id;
        document.getElementById('credit-guest-name').textContent = user.first_name + ' ' + user.last_name;
        document.getElementById('credit-guest-email').textContent = user.email;
        document.getElementById('credit-current-balance').textContent = window.REGULR.formatCurrency(user.balance_cents);
        document.getElementById('credit-amount').value = '';
        document.getElementById('credit-reason').value = '';
        document.getElementById('credit-new-balance-preview').style.display = 'none';

        document.getElementById('credit-wallet-modal-overlay').classList.add('modal-overlay--open');
        document.getElementById('credit-wallet-modal').classList.add('show');
    }

    function closeCreditWalletModal() {
        document.getElementById('credit-wallet-modal-overlay')?.classList.remove('modal-overlay--open');
        document.getElementById('credit-wallet-modal')?.classList.remove('show');
    }

    function updateCreditPreview() {
        const amountEur = parseFloat(document.getElementById('credit-amount')?.value);
        const previewEl = document.getElementById('credit-new-balance-preview');
        const valueEl = document.getElementById('credit-new-balance-value');
        const currentBalanceText = document.getElementById('credit-current-balance')?.textContent;

        if (!previewEl || !valueEl) return;

        if (!amountEur || amountEur <= 0) {
            previewEl.style.display = 'none';
            return;
        }

        // Parse current balance from formatted text back to cents
        const currentCents = parseInt(currentBalanceText.replace(/[^0-9-]/g, '')) || 0;
        const addCents = Math.round(amountEur * 100);
        const newCents = currentCents + addCents;

        previewEl.style.display = 'block';
        valueEl.textContent = window.REGULR.formatCurrency(newCents);
    }

    async function creditWallet(e) {
        if (e) e.preventDefault();

        const userId = parseInt(document.getElementById('credit-user-id').value);
        const amountEur = parseFloat(document.getElementById('credit-amount').value);
        const reason = document.getElementById('credit-reason').value.trim();

        if (!amountEur || amountEur <= 0) {
            window.REGULR.showError('Voer een geldig bedrag in (groter dan €0)');
            return;
        }
        if (!reason || reason.length < 3) {
            window.REGULR.showError('Reden is verplicht (minimaal 3 tekens)');
            return;
        }

        const amountCents = Math.round(amountEur * 100);
        const currentBalanceText = document.getElementById('credit-current-balance').textContent;

        if (!confirm('Weet je zeker dat je €' + amountEur.toFixed(2) + ' wilt toevoegen aan het saldo van deze gast?\n\nHuidig saldo: ' + currentBalanceText + '\nOpwaardering: €' + amountEur.toFixed(2))) {
            return;
        }

        const submitBtn = document.getElementById('submit-credit-btn');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Bezig...'; }

        try {
            const response = await window.REGULR.api('/admin/users', {
                method: 'POST',
                body: { action: 'credit_wallet', user_id: userId, amount_cents: amountCents, reason: reason }
            });

            if (response.success) {
                window.REGULR.showSuccess('€' + amountEur.toFixed(2) + ' succesvol toegevoegd. Nieuw saldo: ' + window.REGULR.formatCurrency(response.data.balance_after));
                closeCreditWalletModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.REGULR.showError('Kon saldo niet toevoegen');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Saldo toevoegen'; }
        }
    }

    function closeModal() {
    document.querySelectorAll('.modal').forEach(m => m.classList.remove('show'));
    document.getElementById('user-modal-overlay')?.classList.remove('modal-overlay--open');
    document.getElementById('tier-modal-overlay')?.classList.remove('modal-overlay--open');
}

// ============================================
// TIERS / PACKAGES MANAGEMENT
// ============================================
let tiersLockedModel = null; // Set from API response — null = not chosen yet

async function loadTiers() {
    const grid = document.getElementById('packages-grid');
    try {
        const response = await window.REGULR.api('/admin/tiers');
        
        if (response.success) {
            tiersData = response.data.tiers;
            tiersLockedModel = response.data.tier_model_type || null;

            // Client-side self-heal: if tiers exist but model not locked, infer from first tier
            // and replace the model selector UI with the locked banner + add button
            if (!tiersLockedModel && response.data.tiers && response.data.tiers.length > 0) {
                tiersLockedModel = response.data.tiers[0].model_type || 'discount';
                var healLabel = tiersLockedModel === 'bonus' ? 'Opwaardeerbonus' : 'Kortingsmodel';
                var healSection = document.getElementById('model-selector-section');
                if (healSection && healSection.querySelector('.model-selector')) {
                    healSection.innerHTML =
                        '<div class="glass-card" style="padding: var(--space-md); margin-bottom: var(--space-lg); border: 1px solid rgba(255,193,7,0.3);"><p style="margin:0;"><strong>🔒 Actief model:</strong> ' + healLabel + '</p></div>' +
                        '<div style="margin-bottom: var(--space-lg);">' +
                        '<button class="btn btn-primary" id="add-tier-btn"><span style="margin-right: var(--space-sm);">+</span> Nieuw Pakket</button>' +
                        '</div>';
                    var healAddBtn = document.getElementById('add-tier-btn');
                    if (healAddBtn) {
                        healAddBtn.addEventListener('click', function() { openTierModal(0); });
                    }
                }
            }

            renderPackagesGrid(response.data.tiers);
        } else {
            // API returned success=false — show error state
            if (grid) {
                grid.innerHTML = `
                    <div class="empty-packages" style="grid-column: 1 / -1;">
                        <p style="color: var(--error, #f44336);">Fout bij laden: ${response.error || 'Onbekende fout'}</p>
                        <button class="btn btn-secondary" onclick="window.REGULR.admin.loadTiers()" style="margin-top: var(--space-md);">Opnieuw proberen</button>
                    </div>`;
            }
        }
    } catch (error) {
        console.error('Tiers load error:', error);
        // Replace loading placeholder with error state
        if (grid) {
            grid.innerHTML = `
                <div class="empty-packages" style="grid-column: 1 / -1;">
                    <p style="color: var(--error, #f44336);">Kan pakketten niet laden</p>
                    <p style="font-size:0.8rem; opacity:0.6;">Controleer je verbinding en probeer opnieuw</p>
                    <button class="btn btn-secondary" onclick="window.REGULR.admin.loadTiers()" style="margin-top: var(--space-md);">Opnieuw proberen</button>
                </div>`;
        }
    }
}

function renderPackagesGrid(tiers) {
    const grid = document.getElementById('packages-grid');
    if (!grid) return;

    if (!tiers || tiers.length === 0) {
        grid.innerHTML = `
            <div class="empty-packages" id="packages-empty" style="grid-column: 1 / -1;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3">
                    <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <p>Nog geen pakketten</p>
                <p style="font-size:0.8rem;">Klik op "Nieuw Pakket" om te beginnen</p>
            </div>`;
        return;
    }

    grid.innerHTML = tiers.map(tier => {
        const isActive = tier.is_active === 1;
        const topupEur = (tier.topup_amount_cents / 100).toFixed(0);
        const isBonus = tier.model_type === 'bonus';

        let discountHtml = '';
        const perks = [];
        if (isBonus) {
            // Bonus model: show fixed bonus amount + optional food discount
            const bonusCents = tier.bonus_cents > 0 ? tier.bonus_cents : Math.floor(tier.topup_amount_cents * tier.bonus_percentage / 100);
            const totalEur = ((tier.topup_amount_cents + bonusCents) / 100).toFixed(0);
            perks.push('€' + totalEur + ' tegoed');
        }
        if (!isBonus && tier.alcohol_discount_perc > 0) {
            perks.push('-' + tier.alcohol_discount_perc + '% alcohol');
        }
        if (tier.food_discount_perc > 0) {
            perks.push('-' + tier.food_discount_perc + '% non-alcohol');
        }
        if (tier.points_multiplier > 1) {
            perks.push(tier.points_multiplier + 'x punten');
        }

        const modelBadge = isBonus
            ? '<span style="font-size:0.7rem;background:rgba(76,175,80,0.15);color:#4CAF50;padding:2px 8px;border-radius:8px;">Bonus</span>'
            : '<span style="font-size:0.7rem;background:rgba(255,193,7,0.15);color:#FFC107;padding:2px 8px;border-radius:8px;">Korting</span>';

        discountHtml = perks.map(perk => `<span>${perk}</span>`).join(' · ');

        return `
        <div class="package-card ${isActive ? '' : 'is-inactive'}" data-tier-id="${tier.id}">
            <div class="package-card__badge ${isActive ? 'package-card__badge--active' : 'package-card__badge--inactive'}"
                 title="${isActive ? 'Actief' : 'Inactief'}"></div>
            <div class="package-card__name">${tier.name} ${modelBadge}</div>
            <div class="package-card__amount">€${topupEur}</div>
            <div class="package-card__discounts">
                ${discountHtml}
            </div>
            <div class="package-card__actions">
                <button class="btn btn-sm btn-secondary btn-toggle-tier" data-id="${tier.id}" data-active="${isActive ? 0 : 1}">
                    ${isActive ? 'Uitschakelen' : 'Inschakelen'}
                </button>
                <button class="btn btn-sm btn-primary btn-edit-tier" data-id="${tier.id}">Bewerk</button>
            </div>
        </div>`;
    }).join('');

    // Click handlers
    grid.querySelectorAll('.btn-edit-tier').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openTierModal(parseInt(btn.dataset.id));
        });
    });
    grid.querySelectorAll('.btn-toggle-tier').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleTier(parseInt(btn.dataset.id), btn.dataset.active === '1');
        });
    });
    // Click on card itself opens edit
    grid.querySelectorAll('.package-card').forEach(card => {
        card.addEventListener('click', () => {
            openTierModal(parseInt(card.dataset.tierId));
        });
    });
}

function openTierModal(tierId) {
    const tier = tierId ? tiersData.find(t => t.id === tierId) : null;
    const isEdit = !!tier;
    const modelType = tiersLockedModel || (tier?.model_type || 'discount');

    var el;

    el = document.getElementById('tier-modal-title');
    if (el) el.textContent = isEdit ? 'Bewerk: ' + tier.name : 'Nieuw Pakket';

    el = document.getElementById('tier-id');
    if (el) el.value = tier?.id || '';

    el = document.getElementById('tier-name');
    if (el) el.value = tier?.name || '';

    el = document.getElementById('tier-topup-amount');
    if (el) el.value = tier ? tier.topup_amount_cents / 100 : 100;

    el = document.getElementById('tier-min-deposit');
    if (el) el.value = tier ? tier.min_deposit_cents / 100 : 0;

    el = document.getElementById('tier-multiplier');
    if (el) el.value = tier?.points_multiplier || 1;

    el = document.getElementById('tier-sort-order');
    if (el) el.value = tier?.sort_order ?? 0;

    el = document.getElementById('tier-is-active');
    if (el) el.checked = tier ? tier.is_active === 1 : true;

    el = document.getElementById('delete-tier-btn');
    if (el) el.style.display = isEdit ? 'inline-block' : 'none';

    // Show correct fields based on locked model type
    toggleModelFields(modelType);

    // Set model-specific values
    if (modelType === 'bonus') {
        var bonusEur = tier ? (tier.bonus_cents || 0) / 100 : 10;
        el = document.getElementById('tier-bonus-cents');
        if (el) el.value = bonusEur;
        el = document.getElementById('tier-food-discount');
        if (el) el.value = tier?.food_discount_perc || 0;
    } else {
        el = document.getElementById('tier-alc-discount');
        if (el) el.value = tier?.alcohol_discount_perc || 0;
        el = document.getElementById('tier-food-discount-d');
        if (el) el.value = tier?.food_discount_perc || 0;
    }

    // Open modal overlay and modal
    document.getElementById('tier-modal-overlay')?.classList.add('modal-overlay--open');
    document.getElementById('tier-modal')?.classList.add('show');
}

function toggleModelFields(modelType) {
    const isBonus = (modelType === 'bonus');
    document.getElementById('bonus-fields').style.display = isBonus ? 'block' : 'none';
    document.getElementById('discount-fields').style.display = isBonus ? 'none' : 'block';
}

function getSelectedModelType() {
    // Model type is always determined by the tenant-level lock (set via model selector cards)
    return tiersLockedModel || 'discount';
}

    function selectModelType(modelType) {
        console.log('[REGULR] selectModelType called with:', modelType, 'lockedModel:', tiersLockedModel);
        if (tiersLockedModel) {
            console.log('[REGULR] Model already locked, ignoring click');
            return;
        }

        // Visual feedback — highlight selected card
        document.querySelectorAll('.model-selector-card').forEach(c => c.classList.remove('model-selector-card--selected'));
        var selectedCard = document.querySelector('[data-model="' + modelType + '"]');
        if (selectedCard) selectedCard.classList.add('model-selector-card--selected');

        // Set locked model locally (will be persisted server-side on first package create)
        tiersLockedModel = modelType;
        console.log('[REGULR] tiersLockedModel set to:', tiersLockedModel);

        // Update modal fields visibility
        toggleModelFields(modelType);

        // Replace model selector with locked banner + Stap 2 button
        var label = modelType === 'bonus' ? 'Opwaardeerbonus' : 'Kortingsmodel';
        var selectorSection = document.getElementById('model-selector-section');
        console.log('[REGULR] selectorSection element:', selectorSection);
        if (selectorSection) {
            selectorSection.innerHTML = 
                '<div class="glass-card" style="padding: var(--space-md); margin-bottom: var(--space-lg); border: 1px solid rgba(255,193,7,0.3);"><p style="margin:0;"><strong>🔒 Actief model:</strong> ' + label + '</p></div>' +
                '<div style="margin-bottom: var(--space-lg);">' +
                '<button class="btn btn-primary" id="add-tier-btn"><span style="margin-right: var(--space-sm);">+</span> Nieuw Pakket</button>' +
                '</div>';
        }

        // Re-attach click handler for the dynamically created button
        var addBtn = document.getElementById('add-tier-btn');
        if (addBtn) {
            addBtn.addEventListener('click', function() { openTierModal(0); });
        }

        if (window.REGULR && window.REGULR.showSuccess) {
            window.REGULR.showSuccess(label + ' geselecteerd');
        }
        console.log('[REGULR] selectModelType completed successfully');
    }

async function saveTier() {
    const topupEur = parseFloat(document.getElementById('tier-topup-amount').value);
    const tierName = (document.getElementById('tier-name').value || '').trim();
    const currentTierId = parseInt(document.getElementById('tier-id').value) || 0;

    // Client-side validation
    if (!tierName) {
        window.REGULR.showError('Pakketnaam is verplicht');
        return;
    }
    // Duplicate name check (case-insensitive, exclude current tier being edited)
    var nameExists = (tiersData || []).some(function(t) {
        return t.id !== currentTierId && (t.name || '').toLowerCase() === tierName.toLowerCase();
    });
    if (nameExists) {
        window.REGULR.showError('Er bestaat al een pakket met de naam "' + tierName + '"');
        return;
    }
    if (!topupEur || topupEur < 100) {
        window.REGULR.showError('Opwaardeerbedrag moet minimaal €100 zijn');
        return;
    }
    if (topupEur > 500) {
        window.REGULR.showError('Opwaardeerbedrag mag maximaal €500 zijn');
        return;
    }

    const modelType = tiersLockedModel || getSelectedModelType();

    const data = {
        tier_id: document.getElementById('tier-id').value,
        name: document.getElementById('tier-name').value,
        model_type: modelType,
        topup_amount_cents: Math.round(topupEur * 100),
        min_deposit_cents: Math.round((parseFloat(document.getElementById('tier-min-deposit').value) || 0) * 100),
        points_multiplier: parseFloat(document.getElementById('tier-multiplier').value) || 1,
        sort_order: parseInt(document.getElementById('tier-sort-order').value) || 0,
        is_active: document.getElementById('tier-is-active').checked ? 1 : 0,
    };

    if (modelType === 'bonus') {
        var bonusEur = parseFloat(document.getElementById('tier-bonus-cents').value) || 0;
        data.bonus_cents = Math.round(bonusEur * 100);
        data.bonus_percentage = 0; // legacy field
        data.food_discount_perc = parseFloat(document.getElementById('tier-food-discount').value) || 0;
        // Don't send alcohol_discount_perc for bonus model — API blocks it
    } else {
        data.alcohol_discount_perc = parseFloat(document.getElementById('tier-alc-discount').value) || 0;
        data.food_discount_perc = parseFloat(document.getElementById('tier-food-discount-d').value) || 0;
        data.bonus_percentage = 0;
    }

    try {
        const response = await window.REGULR.api('/admin/tiers', {
            method: 'POST',
            body: data
        });

        if (response.success) {
            window.REGULR.showSuccess('Pakket opgeslagen');
            closeModal();
            loadTiers();
        }
    } catch (error) {
        window.REGULR.showError('Kon pakket niet opslaan');
    }
}

async function toggleTier(tierId, active) {
    try {
        const response = await window.REGULR.api('/admin/tiers', {
            method: 'POST',
            body: { action: 'toggle', tier_id: tierId, is_active: active }
        });

        if (response.success) {
            window.REGULR.showSuccess(response.data.message);
            loadTiers();
        }
    } catch (error) {
        window.REGULR.showError('Kon pakket niet wijzigen');
    }
}

async function deleteTier(tierId) {
    if (!confirm('Weet je zeker dat je dit pakket wilt verwijderen? Deze actie kan niet ongedaan gemaakt worden.')) {
        return;
    }

    try {
        const response = await window.REGULR.api('/admin/tiers', {
            method: 'POST',
            body: { action: 'delete', tier_id: tierId }
        });

        if (response.success) {
            window.REGULR.showSuccess('Pakket verwijderd');
            loadTiers();
        }
    } catch (error) {
        window.REGULR.showError('Kon pakket niet verwijderen');
    }
}

    // ============================================
    // SETTINGS
    // ============================================
    async function loadSettings() {
        try {
            const response = await window.REGULR.api('/admin/settings');
            
            if (response.success) {
                populateSettings(response.data);
            }
        } catch (error) {
            console.error('Settings load error:', error);
        }
    }

    function populateSettings(data) {
        document.getElementById('tenant-name').value = data.name || '';
        document.getElementById('brand-color').value = data.brand_color || '#FFC107';
        document.getElementById('brand-color-hex').value = data.brand_color || '#FFC107';

        // Verification limits (gated onboarding)
        var softLimit = document.getElementById('verification-soft-limit');
        var hardLimit = document.getElementById('verification-hard-limit');
        var cooldown = document.getElementById('verification-cooldown-sec');
        var maxAttempts = document.getElementById('verification-max-attempts');
        if (softLimit) softLimit.value = data.verification_soft_limit ?? 15;
        if (hardLimit) hardLimit.value = data.verification_hard_limit ?? 30;
        if (cooldown) cooldown.value = data.verification_cooldown_sec ?? 180;
        if (maxAttempts) maxAttempts.value = data.verification_max_attempts ?? 2;

        // Tip amounts
        var tip1 = document.getElementById('tip-amount-1');
        var tip2 = document.getElementById('tip-amount-2');
        var tip3 = document.getElementById('tip-amount-3');
        if (tip1) tip1.value = ((data.tip_amount_1_cents ?? 100) / 100).toFixed(2);
        if (tip2) tip2.value = ((data.tip_amount_2_cents ?? 250) / 100).toFixed(2);
        if (tip3) tip3.value = ((data.tip_amount_3_cents ?? 500) / 100).toFixed(2);
    }

    async function saveSettings() {
        const logoInput = document.getElementById('tenant-logo');
        const logoRemove = document.getElementById('logo-remove');

        // Handle logo upload first if a file is selected
        if (logoInput && logoInput.files && logoInput.files[0]) {
            const file = logoInput.files[0];

            // Client-side validation
            const allowedTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            const maxSize = 2 * 1024 * 1024; // 2MB

            if (!allowedTypes.includes(file.type)) {
                window.REGULR.showError('Alleen PNG, JPG, WebP en SVG bestanden toegestaan');
                return;
            }
            if (file.size > maxSize) {
                window.REGULR.showError(
                    'Bestand te groot (max 2MB, jouw bestand is ' +
                    (file.size / 1024 / 1024).toFixed(1) + 'MB)'
                );
                return;
            }

            try {
                const logoFormData = new FormData();
                logoFormData.append('logo', file);

                // Add CSRF token for multipart upload
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                logoFormData.append('_csrf_token', csrfToken);

                const uploadResponse = await fetch(window.__BASE_URL + '/api/upload/logo', {
                    method: 'POST',
                    credentials: 'include',
                    body: logoFormData
                });

                const uploadResult = await uploadResponse.json();

                if (!uploadResult.success) {
                    throw new Error(uploadResult.error);
                }

                // Update preview in settings form
                const preview = document.getElementById('logo-preview');
                if (preview && uploadResult.data.logo_url) {
                    preview.src = uploadResult.data.logo_url;
                    preview.style.display = 'block';
                }

                // Update header logo immediately (all roles share same nav)
                updateHeaderLogo(uploadResult.data.logo_url);

                // Show remove checkbox now that logo exists
                const removeField = document.getElementById('logo-remove-field');
                if (removeField) removeField.style.display = '';

                // Uncheck remove if it was checked
                if (logoRemove) logoRemove.checked = false;

            } catch (error) {
                window.REGULR.showError('Logo upload mislukt');
                return;
            }
        } else if (logoRemove && logoRemove.checked) {
            // Handle logo removal
            try {
                await window.REGULR.api('/admin/settings', {
                    method: 'POST',
                    body: { logo_path: '' }
                });

                // Clear preview in settings form
                const preview = document.getElementById('logo-preview');
                if (preview) {
                    preview.src = '';
                    preview.style.display = 'none';
                }

                // Remove header logo, show tenant name instead
                updateHeaderLogo(null);

                // Hide remove checkbox
                const removeField = document.getElementById('logo-remove-field');
                if (removeField) removeField.style.display = 'none';

            } catch (error) {
                window.REGULR.showError('Logo verwijderen mislukt');
                return;
            }
        }

        // Save other settings
        const data = {
            brand_color: document.getElementById('brand-color').value,
            secondary_color: document.getElementById('secondary-color').value,
            mollie_status: document.getElementById('mollie-status').value,
            whitelisted_ips: document.getElementById('whitelisted-ips').value,
            verification_soft_limit: parseInt(document.getElementById('verification-soft-limit')?.value) || 15,
            verification_hard_limit: parseInt(document.getElementById('verification-hard-limit')?.value) || 30,
            verification_cooldown_sec: parseInt(document.getElementById('verification-cooldown-sec')?.value) || 180,
            verification_max_attempts: parseInt(document.getElementById('verification-max-attempts')?.value) || 2,
            tip_amount_1_cents: Math.round(parseFloat(document.getElementById('tip-amount-1')?.value || '1') * 100),
            tip_amount_2_cents: Math.round(parseFloat(document.getElementById('tip-amount-2')?.value || '2.5') * 100),
            tip_amount_3_cents: Math.round(parseFloat(document.getElementById('tip-amount-3')?.value || '5') * 100),
        };

        // NOTE: feature_push/feature_marketing are controlled by Super-Admin only.
        // Admin cannot toggle these modules — they are read-only in the settings view.

        try {
            const response = await window.REGULR.api('/admin/settings', {
                method: 'POST',
                body: data
            });

            if (response.success) {
                window.REGULR.showSuccess('Instellingen opgeslagen');
                // Reload to apply new colors + header state
                window.location.reload();
            }
        } catch (error) {
            window.REGULR.showError('Instellingen konden niet worden opgeslagen');
        }
    }
    
    /**
     * Update the header logo across all roles.
     * @param {string|null} logoUrl - New logo URL, or null to remove (shows tenant name)
     */
    function updateHeaderLogo(logoUrl) {
        // The header nav has a center link (2nd child) that contains logo or tenant name.
        // Find it by looking for the nav-top center anchor
        const navTop = document.querySelector('.nav-top');
        if (!navTop) return;

        // The center link is the 2nd <a> inside the nav flex container
        const links = navTop.querySelectorAll('a');
        // links[0] = REGULR.vip branding (left), links[1] = tenant logo/name (center)
        const centerLink = links[1];
        if (!centerLink) return;

        if (logoUrl) {
            // Ensure an <img> exists inside the center link
            let img = centerLink.querySelector('img');
            if (!img) {
                // Replace the text span with an img
                const span = centerLink.querySelector('span');
                if (span) {
                    img = document.createElement('img');
                    centerLink.replaceChild(img, span);
                }
            }
            if (img) {
                img.src = logoUrl;
                img.alt = document.title || 'Logo';
                img.style.cssText = 'height:32px;width:auto;max-width:140px;object-fit:contain;background:transparent;border-radius:0;';
            }
        } else {
            // Replace the <img> with a text span showing tenant name
            const img = centerLink.querySelector('img');
            if (img) {
                const span = document.createElement('span');
                span.style.cssText = 'font-weight:600;color:var(--text-primary);';
                span.textContent = document.title ? document.title.split(' - ')[0] : 'REGULR.vip';
                centerLink.replaceChild(span, img);
            }
        }
    }
    
    // Logo preview handler
    function initLogoPreview() {
        const logoInput = document.getElementById('tenant-logo');
        const preview = document.getElementById('logo-preview');
        const removeField = document.getElementById('logo-remove-field');
        
        if (!logoInput || !preview) return;
        
        logoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Client-side preview
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
                preview.style.background = 'transparent';
            };
            reader.readAsDataURL(file);
        });
    }

    // ============================================
    // MARKETING STUDIO
    // ============================================
    let segmentedUsers = [];
    let queuePage = 1;
    let queueFilter = '';

    function loadMarketing() {
        loadQueueStatus();

        // Segment form
        document.getElementById('segment-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); segmentUsers();
        });

        // Select-all checkbox
        document.getElementById('select-all-seg')?.addEventListener('change', (e) => {
            document.querySelectorAll('.seg-user-check').forEach(cb => {
                cb.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Compose form
        document.getElementById('compose-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); composeEmail();
        });

        // Queue refresh
        document.getElementById('refresh-queue-btn')?.addEventListener('click', () => loadQueueStatus(queuePage, queueFilter));

        // Process queue
        document.getElementById('process-queue-btn')?.addEventListener('click', processQueue);

        // Queue filters
        document.querySelectorAll('.queue-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                queueFilter = btn.dataset.status || '';
                queuePage = 1;
                document.querySelectorAll('.queue-filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                loadQueueStatus(queuePage, queueFilter);
            });
        });
    }

    async function segmentUsers() {
        const criteria = {
            last_activity_days: document.getElementById('seg-last-activity')?.value || '',
            min_balance: document.getElementById('seg-min-balance')?.value || '',
            tier_name: document.getElementById('seg-tier')?.value || ''
        };

        try {
            const response = await window.REGULR.api('/marketing/segment', {
                method: 'POST',
                body: { criteria }
            });

            if (response.success) {
                segmentedUsers = response.data.users || [];
                renderSegmentResults(segmentedUsers);
            } else {
                throw new Error(response.error || 'Segmentatie mislukt');
            }
        } catch (error) {
            window.REGULR.showError('Kon gasten niet filteren');
        }
    }

    function renderSegmentResults(users) {
        const listEl = document.getElementById('segment-list');
        const resultsEl = document.getElementById('segment-results');
        const emptyEl = document.getElementById('segment-empty');
        const countEl = document.getElementById('segment-count');

        if (!listEl || !resultsEl) return;

        if (!users || users.length === 0) {
            resultsEl.style.display = 'none';
            emptyEl.style.display = 'block';
            countEl.style.display = 'none';
            document.getElementById('compose-send-btn').disabled = true;
            document.getElementById('compose-target-info').textContent = 'Selecteer eerst gasten in stap 1';
            return;
        }

        emptyEl.style.display = 'none';
        resultsEl.style.display = 'block';
        countEl.style.display = 'inline-flex';
        countEl.textContent = users.length + ' gasten geselecteerd';

        listEl.innerHTML = users.map(user => `
            <div class="segment-result-item">
                <input type="checkbox" class="seg-user-check" data-user-id="${user.id}" checked style="width:16px;height:16px;">
                <span>${user.first_name} ${user.last_name}</span>
                <span class="seg-email">${user.email}</span>
                ${user.tier_name ? '<span class="seg-tier">' + user.tier_name + '</span>' : ''}
            </div>
        `).join('');

        // Per-checkbox change listener
        listEl.querySelectorAll('.seg-user-check').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checked = document.querySelectorAll('.seg-user-check:checked');
        const countEl = document.getElementById('segment-count');
        const sendBtn = document.getElementById('compose-send-btn');
        const infoEl = document.getElementById('compose-target-info');

        if (countEl) countEl.textContent = checked.length + ' gasten geselecteerd';
        if (sendBtn) sendBtn.disabled = checked.length === 0;
        if (infoEl) infoEl.textContent = checked.length > 0
            ? 'Versturen naar ' + checked.length + ' geselecteerde gast(en)'
            : 'Selecteer eerst gasten in stap 1';
    }

    async function composeEmail() {
        const checkedBoxes = document.querySelectorAll('.seg-user-check:checked');
        const userIds = Array.from(checkedBoxes).map(cb => parseInt(cb.dataset.userId));

        if (userIds.length === 0) {
            window.REGULR.showError('Selecteer minimaal 1 gast');
            return;
        }

        const subject = document.getElementById('compose-subject')?.value?.trim();
        const bodyHtml = document.getElementById('compose-body')?.value?.trim();

        if (!subject || !bodyHtml) {
            window.REGULR.showError('Vul onderwerp en bericht in');
            return;
        }

        const sendBtn = document.getElementById('compose-send-btn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Bezig met verzenden...';
        }

        try {
            const response = await window.REGULR.api('/marketing/compose', {
                method: 'POST',
                body: { subject, body_html: bodyHtml, user_ids: userIds }
            });

            if (response.success) {
                window.REGULR.showSuccess('E-mails toegevoegd aan de wachtrij (' + userIds.length + ' berichten)');
                loadQueueStatus();
            } else {
                throw new Error(response.error || 'Verzenden mislukt');
            }
        } catch (error) {
            window.REGULR.showError('E-mails konden niet worden verzonden');
        } finally {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Verstuur naar geselecteerde gasten';
            }
        }
    }

    async function loadQueueStatus(page, filter) {
        if (page !== undefined) queuePage = page;
        if (filter !== undefined) queueFilter = filter;

        try {
            const params = new URLSearchParams({ page: queuePage, per_page: 10, status: queueFilter });
            const response = await window.REGULR.api('/marketing/queue?' + params.toString());

            if (response.success) {
                const data = response.data;
                const pendingEl = document.getElementById('queue-pending');
                const sentEl = document.getElementById('queue-sent');
                const failedEl = document.getElementById('queue-failed');

                if (pendingEl) pendingEl.textContent = data.pending ?? '--';
                if (sentEl) sentEl.textContent = data.sent ?? '--';
                if (failedEl) failedEl.textContent = data.failed ?? '--';

                // Render table
                const itemsEl = document.getElementById('queue-items');
                if (!itemsEl) return;

                const items = data.items || [];
                if (items.length === 0) {
                    itemsEl.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Geen berichten gevonden</p>';
                } else {
                    itemsEl.innerHTML = '<table class="queue-table"><thead><tr><th>Onderwerp</th><th>Ontvanger</th><th>Status</th><th>Datum</th></tr></thead><tbody>'
                        + items.map(item => {
                            const dateStr = item.sent_at || item.created_at;
                            const shortDate = dateStr ? new Date(dateStr).toLocaleDateString('nl-NL', {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : '-';
                            const statusLabels = {pending:'Wachtend',sent:'Verstuurd',failed:'Mislukt'};
                            return '<tr>'
                                + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (item.subject || 'Geen onderwerp') + '</td>'
                                + '<td style="color:var(--text-secondary);font-size:12px;">' + (item.first_name ? item.first_name + ' ' : '') + (item.last_name || '') + '<br><span style="font-size:11px;opacity:0.7;">' + (item.email || '') + '</span></td>'
                                + '<td><span class="status-badge ' + (item.status || '') + '">' + (statusLabels[item.status] || item.status) + '</span></td>'
                                + '<td style="color:var(--text-secondary);font-size:12px;white-space:nowrap;">' + shortDate + '</td>'
                                + '</tr>';
                        }).join('')
                        + '</tbody></table>';
                }

                // Render pagination
                const pagEl = document.getElementById('queue-pagination');
                if (!pagEl) return;
                const pag = data.pagination || {};
                if (pag.total_pages <= 1) {
                    pagEl.innerHTML = '';
                    return;
                }

                let pagHtml = '<button class="queue-page-btn" onclick="loadQueueStatus(' + (queuePage - 1) + ')"' + (queuePage <= 1 ? ' disabled' : '') + '>&laquo;</button>';

                // Show max 7 page buttons with ellipsis
                const totalPages = pag.total_pages;
                const current = queuePage;
                let startPage = Math.max(1, current - 3);
                let endPage = Math.min(totalPages, startPage + 6);
                if (endPage - startPage < 6) startPage = Math.max(1, endPage - 6);

                if (startPage > 1) {
                    pagHtml += '<button class="queue-page-btn" onclick="loadQueueStatus(1)">1</button>';
                    if (startPage > 2) pagHtml += '<span style="color:var(--text-secondary);padding:4px;">...</span>';
                }

                for (let p = startPage; p <= endPage; p++) {
                    pagHtml += '<button class="queue-page-btn' + (p === current ? ' active' : '') + '" onclick="loadQueueStatus(' + p + ')">' + p + '</button>';
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) pagHtml += '<span style="color:var(--text-secondary);padding:4px;">...</span>';
                    pagHtml += '<button class="queue-page-btn" onclick="loadQueueStatus(' + totalPages + ')">' + totalPages + '</button>';
                }

                pagHtml += '<button class="queue-page-btn" onclick="loadQueueStatus(' + (queuePage + 1) + ')"' + (queuePage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';
                pagEl.innerHTML = pagHtml;
            }
        } catch (error) {
            console.error('Queue status error:', error);
        }
    }

    async function processQueue() {
        const btn = document.getElementById('process-queue-btn');
        if (!btn) return;

        btn.disabled = true;
        btn.textContent = 'Bezig met verzenden...';

        try {
            const response = await window.REGULR.api('/marketing/process', {
                method: 'POST',
                body: { batch_size: 50 }
            });

            if (response.success) {
                const d = response.data;
                window.REGULR.showSuccess(d.message || 'Wachtrij verwerkt');
                loadQueueStatus();
            } else {
                throw new Error(response.error || 'Verwerken mislukt');
            }
        } catch (error) {
            window.REGULR.showError('Wachtrij kon niet worden verwerkt');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Verstuur wachtrij';
        }
    }

    // ============================================
    // HELPERS
    // ============================================
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
    }

    function roleLabel(role) {
        const labels = { admin: 'Admin', bartender: 'Bartender', guest: 'Gast', superadmin: 'Superadmin' };
        return labels[role] || role;
    }

    function updatePagination(total, page) {
        const totalPages = Math.ceil(total / 20);
        document.getElementById('page-info').textContent = `Pagina ${page} van ${totalPages}`;
        document.getElementById('prev-page').disabled = page <= 1;
        document.getElementById('next-page').disabled = page >= totalPages;
    }

    // ============================================
    // PUSH NOTIFICATIONS
    // ============================================
    let pushMode = 'broadcast';
    let selectedUserId = null;
    let pushSearchTimeout = null;
    let pushHistoryPage = 1;

    function loadPushPage() {
        // Tab switching
        document.getElementById('tab-broadcast')?.addEventListener('click', () => switchPushMode('broadcast'));
        document.getElementById('tab-individual')?.addEventListener('click', () => switchPushMode('individual'));

        // Character counters + live preview
        document.getElementById('push-title')?.addEventListener('input', updatePushPreview);
        document.getElementById('push-body')?.addEventListener('input', updatePushPreview);

        // User search
        document.getElementById('user-search')?.addEventListener('input', (e) => {
            clearTimeout(pushSearchTimeout);
            pushSearchTimeout = setTimeout(() => searchPushUsers(e.target.value), 300);
        });
        document.getElementById('clear-user-btn')?.addEventListener('click', clearSelectedUser);

        // Compose form
        document.getElementById('push-compose-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            sendPushNotification();
        });

        // History refresh
        document.getElementById('refresh-history-btn')?.addEventListener('click', () => { pushHistoryPage = 1; loadPushHistory(); });

        // Initial load
        loadPushHistory();
    }

    function switchPushMode(mode) {
        pushMode = mode;
        document.querySelectorAll('.target-tab').forEach(t => t.classList.remove('active'));
        document.getElementById('tab-' + mode)?.classList.add('active');

        document.getElementById('broadcast-info').style.display = mode === 'broadcast' ? 'block' : 'none';
        document.getElementById('individual-section').style.display = mode === 'individual' ? 'block' : 'none';

        const sendBtn = document.getElementById('push-send-btn');
        if (sendBtn) {
            sendBtn.textContent = mode === 'broadcast' ? '📢 Verstuur naar iedereen' : '👤 Verstuur naar geselecteerde gast';
        }

        if (mode === 'individual') clearSelectedUser();
    }

    function updatePushPreview() {
        const titleEl = document.getElementById('push-title');
        const bodyEl = document.getElementById('push-body');
        const title = titleEl?.value || 'Je titel hier...';
        const body = bodyEl?.value || 'Je bericht verschijnt hier als voorbeeld...';

        const previewTitle = document.getElementById('preview-title');
        const previewBody = document.getElementById('preview-body');
        if (previewTitle) previewTitle.textContent = title;
        if (previewBody) previewBody.textContent = body;

        // Character counters
        const titleLen = (titleEl?.value || '').length;
        const bodyLen = (bodyEl?.value || '').length;
        const titleCount = document.getElementById('title-count');
        const bodyCount = document.getElementById('body-count');

        if (titleCount) {
            titleCount.textContent = titleLen;
            titleCount.parentElement.className = 'char-counter' +
                (titleLen > 90 ? ' warn' : '') + (titleLen > 100 ? ' over' : '');
        }
        if (bodyCount) {
            bodyCount.textContent = bodyLen;
            bodyCount.parentElement.className = 'char-counter' +
                (bodyLen > 450 ? ' warn' : '') + (bodyLen > 500 ? ' over' : '');
        }
    }

    async function searchPushUsers(query) {
        if (!query || query.length < 2) return;

        try {
            const response = await window.REGULR.api('/admin/users?search=' + encodeURIComponent(query) + '&limit=10');
            if (response.success) {
                renderPushUserResults(response.data.users);
            }
        } catch (error) {
            console.error('User search error:', error);
        }
    }

    function renderPushUserResults(users) {
        const container = document.getElementById('user-search-results');
        if (!container) return;

        if (!users || users.length === 0) {
            container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Geen gasten gevonden</p>';
            container.style.display = 'block';
            return;
        }

        container.innerHTML = users.filter(u => u.role === 'guest').map(u => `
            <div class="user-search-item" data-user-id="${u.id}" data-user-name="${u.first_name} ${u.last_name}" data-user-email="${u.email}">
                <div class="user-info">
                    <div class="user-name">${u.first_name} ${u.last_name}</div>
                    <div class="user-email">${u.email}</div>
                </div>
                <span class="push-badge ${(u.has_push || u.push_subscribed) ? 'subscribed' : 'no-push'}">
                    ${(u.has_push || u.push_subscribed) ? '✓ Push' : 'Geen push'}
                </span>
            </div>
        `).join('');
        container.style.display = 'block';

        container.querySelectorAll('.user-search-item').forEach(item => {
            item.addEventListener('click', () => selectPushUser(
                parseInt(item.dataset.userId),
                item.dataset.userName,
                item.dataset.userEmail
            ));
        });
    }

    function selectPushUser(id, name, email) {
        selectedUserId = id;
        document.getElementById('user-search-results').style.display = 'none';
        document.getElementById('user-search').value = '';
        document.getElementById('selected-user-info').style.display = 'block';
        document.getElementById('selected-user-name').textContent = name;
        document.getElementById('selected-user-email').textContent = email;
    }

    function clearSelectedUser() {
        selectedUserId = null;
        document.getElementById('selected-user-info').style.display = 'none';
        document.getElementById('user-search-results').style.display = 'none';
        document.getElementById('user-search').value = '';
    }

    async function sendPushNotification() {
        const title = document.getElementById('push-title')?.value?.trim();
        const body = document.getElementById('push-body')?.value?.trim();

        if (!title || !body) {
            window.REGULR.showError('Vul titel en bericht in');
            return;
        }

        const sendBtn = document.getElementById('push-send-btn');
        if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Bezig met verzenden...'; }

        try {
            let response;
            if (pushMode === 'broadcast') {
                response = await window.REGULR.api('/push/broadcast', {
                    method: 'POST',
                    body: { title, body }
                });
            } else {
                if (!selectedUserId) {
                    window.REGULR.showError('Selecteer eerst een gast');
                    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = '👤 Verstuur naar geselecteerde gast'; }
                    return;
                }
                response = await window.REGULR.api('/push/send_notification', {
                    method: 'POST',
                    body: { user_id: selectedUserId, title, body }
                });
            }

            if (response.success) {
                window.REGULR.showSuccess('Notificatie verzonden! (' + (response.data.sent || 0) + ' afgeleverd)');
                document.getElementById('push-title').value = '';
                document.getElementById('push-body').value = '';
                updatePushPreview();
                clearSelectedUser();
                loadPushHistory();
            } else {
                throw new Error(response.error || 'Verzenden mislukt');
            }
        } catch (error) {
            window.REGULR.showError('Notificatie kon niet worden verzonden');
        } finally {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = pushMode === 'broadcast' ? '📢 Verstuur naar iedereen' : '👤 Verstuur naar geselecteerde gast';
            }
        }
    }

    async function loadPushHistory(page) {
        if (page !== undefined) pushHistoryPage = page;
        const container = document.getElementById('push-history');
        if (!container) return;

        container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Geschiedenis ophalen...</p>';

        try {
            const params = new URLSearchParams({ page: pushHistoryPage, per_page: 10 });
            const response = await window.REGULR.api('/admin/push_history?' + params.toString());
            if (response.success) {
                const data = response.data;

                // Update stats counters
                if (data.push_stats) {
                    const statSent = document.getElementById('stat-sent');
                    const statFailed = document.getElementById('stat-failed');
                    if (statSent) statSent.textContent = data.push_stats.sent_7d || 0;
                    if (statFailed) statFailed.textContent = data.push_stats.failed_7d || 0;
                }

                const items = data.items || [];
                if (items.length > 0) {
                    renderPushHistory(items);
                } else {
                    container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Nog geen verzonden notificaties</p>';
                }

                // Render pagination
                renderPushHistoryPagination(data.pagination || {});
            } else {
                container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Nog geen verzonden notificaties</p>';
            }
        } catch (error) {
            container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Geschiedenis kon niet worden geladen</p>';
        }
    }

    function renderPushHistory(items) {
        const container = document.getElementById('push-history');
        if (!container) return;

        if (!items || items.length === 0) {
            container.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Nog geen verzonden notificaties</p>';
            return;
        }

        container.innerHTML = items.map(item => {
            const isBroadcast = item.action === 'push.broadcast_sent';
            const date = new Date(item.created_at).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            const details = item.details || {};

            return `
                <div class="history-item">
                    <div class="history-icon ${isBroadcast ? 'broadcast' : 'individual'}">
                        ${isBroadcast ? '📢' : '👤'}
                    </div>
                    <div class="history-details">
                        <div class="history-title">${details.title || 'Geen titel'}</div>
                        <div class="history-meta">
                            ${isBroadcast ? 'Broadcast' : 'Individueel'} • ${details.sent || 0} verzonden${details.failed > 0 ? ' • ' + details.failed + ' mislukt' : ''} • ${date}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderPushHistoryPagination(pagination) {
        const pagEl = document.getElementById('push-history-pagination');
        if (!pagEl) return;
        const totalPages = pagination.total_pages || 1;
        if (totalPages <= 1) { pagEl.innerHTML = ''; return; }

        const current = pagination.page || 1;
        let html = '<button class="queue-page-btn" onclick="loadPushHistory(' + (current - 1) + ')"' + (current <= 1 ? ' disabled' : '') + '>&laquo;</button>';

        let startPage = Math.max(1, current - 3);
        let endPage = Math.min(totalPages, startPage + 6);
        if (endPage - startPage < 6) startPage = Math.max(1, endPage - 6);

        if (startPage > 1) {
            html += '<button class="queue-page-btn" onclick="loadPushHistory(1)">1</button>';
            if (startPage > 2) html += '<span style="color:var(--text-secondary);padding:4px;">...</span>';
        }
        for (let p = startPage; p <= endPage; p++) {
            html += '<button class="queue-page-btn' + (p === current ? ' active' : '') + '" onclick="loadPushHistory(' + p + ')">' + p + '</button>';
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += '<span style="color:var(--text-secondary);padding:4px;">...</span>';
            html += '<button class="queue-page-btn" onclick="loadPushHistory(' + totalPages + ')">' + totalPages + '</button>';
        }

        html += '<button class="queue-page-btn" onclick="loadPushHistory(' + (current + 1) + ')"' + (current >= totalPages ? ' disabled' : '') + '>&raquo;</button>';
        pagEl.innerHTML = html;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    function initAdmin() {
        if (window.__adminInitialized) return;
        window.__adminInitialized = true;

        console.log('Initializing Admin...');
        
        const path = window.location.pathname;
        
        // Route-specific data loading
        if (path.includes('/admin/users')) {
            loadUsers(currentPage);
            
            // Search with debounce
            document.getElementById('search-input')?.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => { currentPage = 1; loadUsers(1); }, 300);
            });
            
            // Filters
            document.getElementById('role-filter')?.addEventListener('change', () => { currentPage = 1; loadUsers(1); });
            document.getElementById('tier-filter')?.addEventListener('change', () => { currentPage = 1; loadUsers(1); });
        } else if (path.includes('/admin/tiers')) {
            loadTiers();
        } else if (path.includes('/admin/settings')) {
            loadSettings();
            initLogoPreview();
        } else if (path.includes('/admin/marketing')) {
            loadMarketing();
        } else if (path.includes('/admin/push')) {
            loadPushPage();
        } else if (path.includes('/admin')) {
            loadDashboardStats();
        }
        
        // Setup event listeners
        document.getElementById('prev-page')?.addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; loadUsers(currentPage); }
        });
        
        document.getElementById('next-page')?.addEventListener('click', () => {
            currentPage++; loadUsers(currentPage);
        });
        
         document.getElementById('user-form')?.addEventListener('submit', (e) => {
             e.preventDefault(); saveUser();
         });
         
         document.getElementById('block-user-btn')?.addEventListener('click', blockUser);
          document.getElementById('unblock-user-btn')?.addEventListener('click', unblockUser);
          document.getElementById('activate-user-btn')?.addEventListener('click', activateUser);
          document.getElementById('reset-password-btn')?.addEventListener('click', resetPassword);
          document.getElementById('toggle-password-visibility')?.addEventListener('click', () => {
              const input = document.getElementById('reset-password-input');
              input.type = input.type === 'password' ? 'text' : 'password';
          });
         
         // Credit wallet modal event handlers
         document.getElementById('close-credit-modal')?.addEventListener('click', closeCreditWalletModal);
         document.getElementById('cancel-credit-modal')?.addEventListener('click', closeCreditWalletModal);
         document.getElementById('credit-wallet-form')?.addEventListener('submit', creditWallet);
         document.getElementById('credit-wallet-modal-overlay')?.addEventListener('click', (e) => {
             if (e.target.id === 'credit-wallet-modal-overlay') closeCreditWalletModal();
         });
         document.getElementById('credit-amount')?.addEventListener('input', updateCreditPreview);
         
         document.getElementById('tier-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); saveTier();
        });
        
        document.getElementById('settings-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); saveSettings();
        });
        
        // Modal event handlers
        document.getElementById('add-user-btn')?.addEventListener('click', () => openAddUserModal());
        document.getElementById('close-modal')?.addEventListener('click', closeModal);
        document.getElementById('user-modal-overlay')?.addEventListener('click', (e) => {
            if (e.target.id === 'user-modal-overlay') closeModal();
        });
        
        // Tier modal event handlers
        document.getElementById('add-tier-btn')?.addEventListener('click', () => openTierModal(0));
        document.getElementById('close-tier-modal')?.addEventListener('click', closeModal);
        document.getElementById('tier-modal-overlay')?.addEventListener('click', (e) => {
            if (e.target.id === 'tier-modal-overlay') closeModal();
        });
        document.getElementById('delete-tier-btn')?.addEventListener('click', () => {
            const tierId = parseInt(document.getElementById('tier-id').value);
            if (tierId) deleteTier(tierId);
        });

        // Model selector card click handlers (aparte sectie boven grid)
        document.getElementById('select-discount-model')?.addEventListener('click', () => selectModelType('discount'));
        document.getElementById('select-bonus-model')?.addEventListener('click', () => selectModelType('bonus'));
        
        console.log('Admin initialized');
    }

    function openAddUserModal() {
        document.getElementById('modal-title').textContent = 'Nieuwe gebruiker';
        document.getElementById('user-id').value = '';
        document.getElementById('user-first-name').value = '';
        document.getElementById('user-last-name').value = '';
        document.getElementById('user-email').value = '';
        document.getElementById('user-birthdate').value = '';
        document.getElementById('user-role').value = 'guest';

        // Show password field for new user (required)
        document.getElementById('password-create-group').style.display = 'block';
        document.getElementById('user-password').setAttribute('required', 'required');
        document.getElementById('user-password').value = '';

        // Hide password reset section (only for editing)
        document.getElementById('password-reset-section').style.display = 'none';

        // Hide block/unblock buttons (not applicable for new users)
        document.getElementById('block-user-btn').style.display = 'none';
        document.getElementById('unblock-user-btn').style.display = 'none';

        // Hide account status section (not applicable for new users)
        document.getElementById('account-status-section').style.display = 'none';
        document.getElementById('activate-user-btn').style.display = 'none';

        document.getElementById('user-modal-overlay').classList.add('modal-overlay--open');
        document.getElementById('user-modal').classList.add('show');
    }

    function closeAddUserModal() {
        document.getElementById('user-modal-overlay').classList.remove('modal-overlay--open');
    }

    // Export
    window.REGULR = window.REGULR || {};
    window.REGULR.admin = {
        init: initAdmin,
        loadDashboard: loadDashboardStats,
        loadUsers,
        loadTiers,
        loadSettings,
        loadMarketing,
        activateUserInline
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdmin);
    } else if (window.location.pathname.includes('/admin')) {
        initAdmin();
    }

    // Expose pagination handlers globally for inline onclick
    window.loadPushHistory = loadPushHistory;
    window.loadQueueStatus = loadQueueStatus;

})();