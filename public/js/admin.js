/**
 * STAMGAST - Admin Dashboard Charts & Functionaliteit
 * Admin: analytics, gebruikers, tiers, instellingen
 */
(function() {
    'use strict';

    let currentPage = 1;
    let usersData = [];
    let tiersData = [];

    // ============================================
    // DASHBOARD CHARTS
    // ============================================
    async function loadDashboardStats() {
        try {
            const response = await window.STAMGAST.api('/admin/dashboard');
            
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
                        : window.STAMGAST.formatCurrency(card.value);
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
                <span class="spent">${window.STAMGAST.formatCurrency(user.total_spent)}</span>
            </div>
        `).join('');
    }

    // ============================================
    // USERS MANAGEMENT
    // ============================================
    async function loadUsers(page = 1) {
        try {
            const response = await window.STAMGAST.api(`/admin/users?page=${page}&limit=20`);
            
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
            tbody.innerHTML = '<tr><td colspan="7">Geen gebruikers gevonden</td></tr>';
            return;
        }

        tbody.innerHTML = users.map(user => `
            <tr data-user-id="${user.id}">
                <td>
                    <div class="user-cell">
                        <img src="${user.photo_url || '/public/icons/default-avatar.png'}" alt="" class="avatar">
                        <span>${user.first_name} ${user.last_name}</span>
                    </div>
                </td>
                <td>${user.email}</td>
                <td><span class="badge badge-${user.role}">${user.role}</span></td>
                <td>${user.tier_name || '-'}</td>
                <td>${window.STAMGAST.formatCurrency(user.balance_cents)}</td>
                <td>${formatDate(user.last_activity)}</td>
                <td>
                    <button class="btn btn-sm btn-edit" data-id="${user.id}">Bewerk</button>
                </td>
            </tr>
        `).join('');

        // Add click handlers
        tbody.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => openUserModal(parseInt(btn.dataset.id)));
        });
    }

    function openUserModal(userId) {
        const user = usersData.find(u => u.id === userId);
        if (!user) return;

        document.getElementById('modal-title').textContent = `Bewerk: ${user.first_name}`;
        document.getElementById('user-id').value = user.id;
        document.getElementById('user-first-name').value = user.first_name;
        document.getElementById('user-last-name').value = user.last_name;
        document.getElementById('user-email').value = user.email;
        document.getElementById('user-role').value = user.role;

        document.getElementById('user-modal').classList.add('show');
    }

    async function saveUser() {
        const form = document.getElementById('user-form');
        const data = {
            user_id: parseInt(document.getElementById('user-id').value),
            first_name: document.getElementById('user-first-name').value,
            last_name: document.getElementById('user-last-name').value,
            email: document.getElementById('user-email').value,
            role: document.getElementById('user-role').value
        };

        try {
            const response = await window.STAMGAST.api('/admin/users', {
                method: 'POST',
                body: { action: 'update', ...data }
            });

            if (response.success) {
                window.STAMGAST.showSuccess('Gebruiker opgeslagen');
                closeModal();
                loadUsers(currentPage);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            window.STAMGAST.showError(error.message);
        }
    }

    function closeModal() {
        document.querySelectorAll('.modal').forEach(m => m.classList.remove('show'));
    }

    // ============================================
    // TIERS MANAGEMENT
    // ============================================
    async function loadTiers() {
        try {
            const response = await window.STAMGAST.api('/admin/tiers');
            
            if (response.success) {
                tiersData = response.data.tiers;
                renderTiersGrid(response.data.tiers);
            }
        } catch (error) {
            console.error('Tiers load error:', error);
        }
    }

    function renderTiersGrid(tiers) {
        const grid = document.getElementById('tiers-grid');
        if (!grid) return;

        if (!tiers || tiers.length === 0) {
            grid.innerHTML = '<div class="empty-state">Nog geen tiers</div>';
            return;
        }

        const colors = ['#CD7F32', '#C0C0C0', '#FFD700', '#E5E4E2'];
        
        grid.innerHTML = tiers.map((tier, index) => `
            <div class="tier-card" data-id="${tier.id}" style="--tier-color: ${colors[index % colors.length]}">
                <h3>${tier.name}</h3>
                <div class="tier-threshold">${window.STAMGAST.formatCurrency(tier.min_deposit_cents)}+</div>
                <div class="tier-discounts">
                    <div>Alcohol: ${tier.alcohol_discount_perc}%</div>
                    <div>Food: ${tier.food_discount_perc}%</div>
                    <div>Bonus: ${tier.points_multiplier}x</div>
                </div>
                <button class="btn btn-sm btn-edit-tier" data-id="${tier.id}">Bewerk</button>
            </div>
        `).join('');

        grid.querySelectorAll('.btn-edit-tier').forEach(btn => {
            btn.addEventListener('click', () => openTierModal(parseInt(btn.dataset.id)));
        });
    }

    function openTierModal(tierId) {
        const tier = tiersData.find(t => t.id === tierId);
        
        document.getElementById('modal-title').textContent = tier ? `Bewerk: ${tier.name}` : 'Nieuwe Tier';
        document.getElementById('tier-id').value = tier?.id || '';
        document.getElementById('tier-name').value = tier?.name || '';
        document.getElementById('tier-min-deposit').value = tier ? tier.min_deposit_cents / 100 : '';
        document.getElementById('tier-alc-discount').value = tier?.alcohol_discount_perc || 0;
        document.getElementById('tier-food-discount').value = tier?.food_discount_perc || 0;
        document.getElementById('tier-multiplier').value = tier?.points_multiplier || 1;

        document.getElementById('tier-modal').classList.add('show');
    }

    async function saveTier() {
        const data = {
            tier_id: document.getElementById('tier-id').value,
            name: document.getElementById('tier-name').value,
            min_deposit_cents: parseInt(document.getElementById('tier-min-deposit').value) * 100,
            alcohol_discount_perc: parseFloat(document.getElementById('tier-alc-discount').value),
            food_discount_perc: parseFloat(document.getElementById('tier-food-discount').value),
            points_multiplier: parseFloat(document.getElementById('tier-multiplier').value)
        };

        try {
            const response = await window.STAMGAST.api('/admin/tiers', {
                method: 'POST',
                body: data
            });

            if (response.success) {
                window.STAMGAST.showSuccess('Tier opgeslagen');
                closeModal();
                loadTiers();
            }
        } catch (error) {
            window.STAMGAST.showError(error.message);
        }
    }

    // ============================================
    // SETTINGS
    // ============================================
    async function loadSettings() {
        try {
            const response = await window.STAMGAST.api('/admin/settings');
            
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
    }

    async function saveSettings() {
        const form = document.getElementById('settings-form');
        const formData = new FormData(form);
        
        const data = {
            brand_color: document.getElementById('brand-color').value,
            secondary_color: document.getElementById('secondary-color').value,
            mollie_api_key: document.getElementById('mollie-api-key').value,
            mollie_status: document.getElementById('mollie-status').value,
            whitelisted_ips: document.getElementById('whitelisted-ips').value,
            feature_push: document.getElementById('feature-push').checked,
            feature_marketing: document.getElementById('feature-marketing').checked
        };

        try {
            const response = await window.STAMGAST.api('/admin/settings', {
                method: 'POST',
                body: data
            });

            if (response.success) {
                window.STAMGAST.showSuccess('Instellingen opgeslagen');
            }
        } catch (error) {
            window.STAMGAST.showError(error.message);
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

    function updatePagination(total, page) {
        const totalPages = Math.ceil(total / 20);
        document.getElementById('page-info').textContent = `Pagina ${page} van ${totalPages}`;
        document.getElementById('prev-page').disabled = page <= 1;
        document.getElementById('next-page').disabled = page >= totalPages;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    function initAdmin() {
        console.log('Initializing Admin...');
        
        const path = window.location.pathname;
        
        if (path.includes('/admin') && !path.includes('users') && !path.includes('tiers') && !path.includes('settings')) {
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
        
        document.getElementById('tier-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); saveTier();
        });
        
        document.getElementById('settings-form')?.addEventListener('submit', (e) => {
            e.preventDefault(); saveSettings();
        });
        
        document.querySelectorAll('.btn-close, .modal').forEach(el => {
            el.addEventListener('click', closeModal);
        });
        
        console.log('Admin initialized');
    }

    // Export
    window.STAMGAST = window.STAMGAST || {};
    window.STAMGAST.admin = {
        init: initAdmin,
        loadDashboard: loadDashboardStats,
        loadUsers,
        loadTiers,
        loadSettings
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdmin);
    } else if (window.location.pathname.includes('/admin')) {
        initAdmin();
    }

})();