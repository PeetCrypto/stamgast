/**
 * STAMGAST - Admin Dashboard Charts & Functionaliteit
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
    let searchTimeout = null;
    
    async function loadUsers(page = 1) {
        const search = document.getElementById('search-input')?.value || '';
        const role = document.getElementById('role-filter')?.value || '';
        const tier = document.getElementById('tier-filter')?.value || '';
        
        try {
            const params = new URLSearchParams({ page, limit: 20 });
            if (search) params.append('search', search);
            if (role) params.append('role', role);
            if (tier) params.append('tier', tier);
            
            const response = await window.STAMGAST.api(`/admin/users?${params.toString()}`);
            
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

        // Default avatar SVG
        const defaultAvatar = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44"><circle fill="rgba(255,193,7,0.2)" cx="22" cy="14" r="10"/><circle fill="rgba(255,193,7,0.2)" cx="22" cy="38" r="14"/></svg>');
        
        tbody.innerHTML = users.map(user => `
            <tr data-user-id="${user.id}">
                <td>
                    <div class="user-cell">
                        <img src="${user.photo_url || defaultAvatar}" alt="" class="avatar">
                        <span>${user.first_name} ${user.last_name}</span>
                    </div>
                </td>
                <td>${user.email}</td>
                <td><span class="badge badge-${user.role}">${user.role}</span></td>
                <td>${user.tier_name || '-'}</td>
                <td>${window.STAMGAST.formatCurrency(user.balance_cents)}</td>
                <td style="text-align: center;">${formatDate(user.last_activity)}</td>
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

        // Show block button (hidden in add mode)
        document.getElementById('block-user-btn').style.display = 'block';

        document.getElementById('user-modal-overlay').classList.add('modal-overlay--open');
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

    async function blockUser() {
        const userId = parseInt(document.getElementById('user-id').value);
        if (!userId || userId <= 0) return;

        if (!confirm('Weet je zeker dat je deze gebruiker wilt blokkeren? Deze actie kan ongedaan gemaakt worden door een admin.')) {
            return;
        }

        try {
            const response = await window.STAMGAST.api('/admin/users', {
                method: 'POST',
                body: { action: 'block', user_id: userId }
            });

            if (response.success) {
                window.STAMGAST.showSuccess('Gebruiker geblokkeerd');
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
    document.getElementById('user-modal-overlay')?.classList.remove('modal-overlay--open');
    document.getElementById('tier-modal-overlay')?.classList.remove('modal-overlay--open');
}

// ============================================
// TIERS MANAGEMENT
// ============================================
async function loadTiers() {
    try {
        const response = await window.STAMGAST.api('/admin/tiers');
        
        if (response.success) {
            tiersData = response.data.tiers;
            renderTiersTable(response.data.tiers);
        }
    } catch (error) {
        console.error('Tiers load error:', error);
    }
}

function renderTiersTable(tiers) {
    const tbody = document.getElementById('tiers-table-body');
    if (!tbody) return;

    if (!tiers || tiers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">Nog geen tiers</td></tr>';
        return;
    }

    tbody.innerHTML = tiers.map(tier => `
        <tr data-tier-id="${tier.id}">
            <td>${tier.name}</td>
            <td>${window.STAMGAST.formatCurrency(tier.min_deposit_cents)}</td>
            <td>${tier.alcohol_discount_perc}%</td>
            <td>${tier.food_discount_perc}%</td>
            <td>${tier.points_multiplier}x</td>
            <td>
                <button class="btn btn-sm btn-edit-tier" data-id="${tier.id}">Bewerk</button>
                <button class="btn btn-sm btn-delete-tier" data-id="${tier.id}" style="display: none;">Verwijderen</button>
            </td>
        </tr>
    `).join('');

    // Add click handlers
    tbody.querySelectorAll('.btn-edit-tier').forEach(btn => {
        btn.addEventListener('click', () => openTierModal(parseInt(btn.dataset.id)));
    });
    tbody.querySelectorAll('.btn-delete-tier').forEach(btn => {
        btn.addEventListener('click', () => deleteTier(parseInt(btn.dataset.id)));
    });
}

function openTierModal(tierId) {
    const tier = tiersData.find(t => t.id === tierId);
    const isEdit = !!tier;

    document.getElementById('tier-modal-title').textContent = isEdit ? `Bewerk: ${tier.name}` : 'Nieuwe Tier';
    document.getElementById('tier-id').value = tier?.id || '';
    document.getElementById('tier-name').value = tier?.name || '';
    document.getElementById('tier-min-deposit').value = tier ? tier.min_deposit_cents / 100 : '';
    document.getElementById('tier-alc-discount').value = tier?.alcohol_discount_perc || 0;
    document.getElementById('tier-food-discount').value = tier?.food_discount_perc || 0;
    document.getElementById('tier-multiplier').value = tier?.points_multiplier || 1;
    document.getElementById('delete-tier-btn').style.display = isEdit ? 'inline-block' : 'none';

    // Open modal overlay and modal
    document.getElementById('tier-modal-overlay').classList.add('modal-overlay--open');
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

async function deleteTier(tierId) {
    if (!confirm('Weet je zeker dat je deze tier wilt verwijderen? Deze actie kan niet ongedaan gemaakt worden.')) {
        return;
    }

    try {
        const response = await window.STAMGAST.api('/admin/tiers', {
            method: 'POST',
            body: { action: 'delete', tier_id: tierId }
        });

        if (response.success) {
            window.STAMGAST.showSuccess('Tier verwijderd');
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
        const logoInput = document.getElementById('tenant-logo');
        const logoRemove = document.getElementById('logo-remove');

        // Handle logo upload first if a file is selected
        if (logoInput && logoInput.files && logoInput.files[0]) {
            const file = logoInput.files[0];

            // Client-side validation
            const allowedTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            const maxSize = 2 * 1024 * 1024; // 2MB

            if (!allowedTypes.includes(file.type)) {
                window.STAMGAST.showError('Alleen PNG, JPG, WebP en SVG bestanden toegestaan');
                return;
            }
            if (file.size > maxSize) {
                window.STAMGAST.showError(
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
                window.STAMGAST.showError('Logo upload mislukt: ' + error.message);
                return;
            }
        } else if (logoRemove && logoRemove.checked) {
            // Handle logo removal
            try {
                await window.STAMGAST.api('/admin/settings', {
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
                window.STAMGAST.showError('Logo verwijderen mislukt: ' + error.message);
                return;
            }
        }

        // Save other settings
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
                // Reload to apply new colors + header state
                window.location.reload();
            }
        } catch (error) {
            window.STAMGAST.showError(error.message);
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
                img.style.cssText = 'height:32px;width:auto;max-width:140px;object-fit:contain;';
            }
        } else {
            // Replace the <img> with a text span showing tenant name
            const img = centerLink.querySelector('img');
            if (img) {
                const span = document.createElement('span');
                span.style.cssText = 'font-weight:600;color:var(--text-primary);';
                span.textContent = document.title ? document.title.split(' - ')[0] : 'STAMGAST';
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
            };
            reader.readAsDataURL(file);
        });
    }

    // ============================================
    // MARKETING STUDIO
    // ============================================
    let segmentedUsers = [];

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
        document.getElementById('refresh-queue-btn')?.addEventListener('click', loadQueueStatus);
    }

    async function segmentUsers() {
        const criteria = {
            last_activity_days: document.getElementById('seg-last-activity')?.value || '',
            min_balance: document.getElementById('seg-min-balance')?.value || '',
            tier_name: document.getElementById('seg-tier')?.value || ''
        };

        try {
            const response = await window.STAMGAST.api('/marketing/segment', {
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
            window.STAMGAST.showError('Segmentatie fout: ' + error.message);
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
            window.STAMGAST.showError('Selecteer minimaal 1 gast');
            return;
        }

        const subject = document.getElementById('compose-subject')?.value?.trim();
        const bodyHtml = document.getElementById('compose-body')?.value?.trim();

        if (!subject || !bodyHtml) {
            window.STAMGAST.showError('Vul onderwerp en bericht in');
            return;
        }

        const sendBtn = document.getElementById('compose-send-btn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Bezig met verzenden...';
        }

        try {
            const response = await window.STAMGAST.api('/marketing/compose', {
                method: 'POST',
                body: { subject, body_html: bodyHtml, user_ids: userIds }
            });

            if (response.success) {
                window.STAMGAST.showSuccess('E-mails toegevoegd aan de wachtrij (' + userIds.length + ' berichten)');
                loadQueueStatus();
            } else {
                throw new Error(response.error || 'Verzenden mislukt');
            }
        } catch (error) {
            window.STAMGAST.showError('Verzendfout: ' + error.message);
        } finally {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Verstuur naar geselecteerde gasten';
            }
        }
    }

    async function loadQueueStatus() {
        try {
            const response = await window.STAMGAST.api('/marketing/queue');

            if (response.success) {
                const data = response.data;
                const pendingEl = document.getElementById('queue-pending');
                const sentEl = document.getElementById('queue-sent');
                const failedEl = document.getElementById('queue-failed');

                if (pendingEl) pendingEl.textContent = data.pending ?? '--';
                if (sentEl) sentEl.textContent = data.sent ?? '--';
                if (failedEl) failedEl.textContent = data.failed ?? '--';

                // Render recent items if available
                const itemsEl = document.getElementById('queue-items');
                if (itemsEl && data.recent) {
                    if (data.recent.length === 0) {
                        itemsEl.innerHTML = '<p style="color:var(--text-secondary);font-size:14px;text-align:center;padding:var(--space-md);">Geen berichten in wachtrij</p>';
                    } else {
                        itemsEl.innerHTML = data.recent.map(item => `
                            <div class="segment-result-item">
                                <span style="flex:1;">${item.subject || 'Geen onderwerp'}</span>
                                <span class="seg-email">${item.email || ''}</span>
                                <span class="seg-tier" style="background:${item.status === 'sent' ? 'rgba(76,175,80,0.15)' : item.status === 'failed' ? 'rgba(244,67,54,0.15)' : 'rgba(255,193,7,0.15)'};color:${item.status === 'sent' ? 'var(--success)' : item.status === 'failed' ? 'var(--error)' : 'var(--accent-primary)'};">${item.status}</span>
                            </div>
                        `).join('');
                    }
                }
            }
        } catch (error) {
            console.error('Queue status error:', error);
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
        
        console.log('Admin initialized');
    }

    function openAddUserModal() {
        document.getElementById('modal-title').textContent = 'Nieuwe gebruiker';
        document.getElementById('user-id').value = '';
        document.getElementById('user-first-name').value = '';
        document.getElementById('user-last-name').value = '';
        document.getElementById('user-email').value = '';
        document.getElementById('user-role').value = 'guest';

        // Hide block button in add mode
        document.getElementById('block-user-btn').style.display = 'none';

        document.getElementById('user-modal-overlay').classList.add('modal-overlay--open');
        document.getElementById('user-modal').classList.add('show');
    }

    function closeAddUserModal() {
        document.getElementById('user-modal-overlay').classList.remove('modal-overlay--open');
    }

    // Export
    window.STAMGAST = window.STAMGAST || {};
    window.STAMGAST.admin = {
        init: initAdmin,
        loadDashboard: loadDashboardStats,
        loadUsers,
        loadTiers,
        loadSettings,
        loadMarketing
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdmin);
    } else if (window.location.pathname.includes('/admin')) {
        initAdmin();
    }

})();