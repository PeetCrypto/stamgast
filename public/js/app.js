/**
 * REGULR.vip - App Initializer & Router
 * Midnight Lounge PWA Framework
 */
(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    const APP_CONFIG = {
        API_BASE: (window.__BASE_URL || '') + '/api',
        SESSION_CHECK_INTERVAL: 30000, // 30 seconds
        QR_REFRESH_INTERVAL: 55000,   // 55 seconds (refresh before 60s expiry)
        DEBUG: false
    };

    // ============================================
    // STATE MANAGEMENT
    // ============================================
    const AppState = {
        user: null,
        tenant: null,
        wallet: null,
        accountStatus: null,
        sessionChecked: false,
        currentView: null
    };

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function log(...args) {
        if (APP_CONFIG.DEBUG) {
            console.log('[REGULR.vip]', ...args);
        }
    }

    function showError(message, duration = 5000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-error';
        alertDiv.textContent = message;
        
        const container = document.querySelector('.alerts-container') || document.body;
        container.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.classList.add('show'), 10);
        
        if (duration > 0) {
            setTimeout(() => {
                alertDiv.classList.remove('show');
                setTimeout(() => alertDiv.remove(), 300);
            }, duration);
        }
        
        return alertDiv;
    }

    function showSuccess(message, duration = 3000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success';
        alertDiv.textContent = message;
        
        const container = document.querySelector('.alerts-container') || document.body;
        container.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.classList.add('show'), 10);
        
        setTimeout(() => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 300);
        }, duration);
    }

    /**
     * Sanitize error objects for user-facing messages.
     * Strips URLs, stack traces, SQL fragments, and technical gibberish.
     * Returns a safe Dutch string suitable for showError().
     */
    function sanitizeError(error, fallback) {
        var msg = (error && error.message) ? error.message : String(error || '');
        var fb = fallback || 'Er is iets misgegaan. Probeer het opnieuw.';

        // Known network / browser error patterns → friendly Dutch
        var patterns = [
            [/Failed to fetch/i,                  'Geen verbinding met de server'],
            [/NetworkError/i,                     'Geen netwerkverbinding'],
            [/Network request failed/i,           'Geen netwerkverbinding'],
            [/netwerkfout/i,                      'Geen netwerkverbinding'],
            [/Server returned non-JSON/i,         'Serverfout, probeer het later opnieuw'],
            [/API Error/i,                        fb],
            [/Sessie verlopen/i,                  'Sessie verlopen — log opnieuw in'],
            [/load failed/i,                      'Geen verbinding met de server'],
            [/fetch/i,                            'Geen verbinding met de server'],
            [/timeout/i,                          'Server reageert te langzaam, probeer opnieuw'],
            [/4\d{2}|5\d{2}/,                     fb],  // HTTP status codes
        ];

        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i][0].test(msg)) {
                return patterns[i][1];
            }
        }

        // If the server returned a clean Dutch message (no URL/path artifacts), pass it through
        var clean = msg
            .replace(/https?:\/\/[^\s]+/gi, '')   // strip URLs
            .replace(/\/api\/[^\s]*/gi, '')        // strip API paths
            .replace(/\.php\b/gi, '')              // strip .php references
            .replace(/SQL\[[^\]]*\]/gi, '')        // strip SQL fragments
            .replace(/at\s+\S+\s+\(/gi, '')        // strip stack trace lines
            .trim();

        // If what remains is meaningful (>5 chars) and looks Dutch, use it
        if (clean.length > 5 && /[a-zA-Z]/.test(clean)) {
            return clean;
        }

        return fb;
    }

    function formatCurrency(cents) {
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency',
            currency: 'EUR'
        }).format(cents / 100);
    }

    function formatPoints(cents) {
        return new Intl.NumberFormat('nl-NL').format(cents / 100);
    }

    // ============================================
    // API CLIENT
    // ============================================
    async function apiCall(endpoint, options = {}) {
        const url = `${APP_CONFIG.API_BASE}${endpoint}`;
        const defaults = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            credentials: 'same-origin'
        };
        
        // Deep-merge headers so CSRF token + Content-Type are preserved when
        // callers pass their own options (e.g. { method: 'POST', body: {...} }).
        const config = { ...defaults, ...options, headers: { ...defaults.headers, ...(options.headers || {}) } };
        
        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }
        
        try {
            const response = await fetch(url, config);
            
            // Try to parse JSON, handle non-JSON responses gracefully
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                throw new Error('Server returned non-JSON response');
            }
            
            if (!response.ok) {
                // On 401 Unauthorized, redirect to login for server-rendered pages
                if (response.status === 401) {
                    const isServerPage = SERVER_RENDERED_PAGES.includes(
                        window.location.pathname.replace(/\/$/, '').split('?')[0]
                    );
                    if (isServerPage) {
                        // Server-rendered page with expired session — redirect to branded login
                        window.location.href = getLoginUrl();
                        throw new Error('Sessie verlopen — doorverwijzen...');
                    }
                }
                throw new Error(data.error || data.message || 'API Error');
            }
            
            return data;
        } catch (error) {
            log('API Error:', error);
            throw error;
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    /**
     * Get the tenant slug from the <meta name="tenant-slug"> tag.
     * Used to redirect guests to their branded login page /j/{slug}.
     */
    function getTenantSlug() {
        const meta = document.querySelector('meta[name="tenant-slug"]');
        return meta ? meta.content : '';
    }

    /**
     * Build the correct login URL for the current user.
     * Guests with a tenant slug → /j/{slug}, everyone else → /login.
     */
    function getLoginUrl() {
        const slug = getTenantSlug();
        if (slug) {
            return (window.__BASE_URL || '') + '/j/' + slug;
        }
        return (window.__BASE_URL || '') + '/login';
    }

    // ============================================
    // SESSION MANAGEMENT
    // ============================================

    /**
     * Pages rendered server-side by PHP (index.php router). These already have
     * proper session/auth checks in their view templates, so a failed JS
     * session-check must NOT redirect away — the server already validated access.
     * Only SPA-like navigations should trigger a redirect on session failure.
     */
    const SERVER_RENDERED_PAGES = [
        '/dashboard', '/wallet', '/qr', '/inbox',
        '/scan', '/payment',
        '/payment/return', // public Mollie return page — must NOT trigger JS login redirect
        '/admin', '/admin/users', '/admin/tiers', '/admin/settings', '/admin/marketing',
        '/superadmin', '/superadmin/tenants'
    ];

    function isServerRenderedPage() {
        const path = window.location.pathname.replace(/\/$/, '').split('?')[0];
        return SERVER_RENDERED_PAGES.includes(path);
    }

    async function checkSession() {
        try {
            const response = await apiCall('/auth/session');
            const data = response.data || response;
            if (data.authenticated) {
                AppState.user = data.user;
                AppState.accountStatus = data.user.account_status || 'unverified';
                AppState.sessionChecked = true;
                log('Session valid:', AppState.user);

                return true;
            } else {
                AppState.user = null;
                // Only redirect on non-login pages that are NOT server-rendered.
                // Server-rendered pages already have PHP session protection;
                // a failed JS session check here is likely a transient API issue.
                if (window.location.pathname !== '/login' &&
                    window.location.pathname !== '/' &&
                    !window.location.pathname.startsWith('/j/') &&
                    !isServerRenderedPage()) {
                    redirectToLogin();
                }
                return false;
            }
        } catch (error) {
            log('Session check failed:', error);
            // On API failure: do NOT redirect server-rendered pages.
            // The PHP session is still valid — this is likely a transient network/API error.
            return false;
        }
    }

    function redirectToLogin() {
        window.location.href = getLoginUrl();
    }

    function getUserRole() {
        return AppState.user?.role || null;
    }

    function isAuthenticated() {
        return AppState.user !== null;
    }

    // ============================================
    // ROUTING
    // ============================================
    const ROUTES = {
        '/': 'dashboard',
        '/dashboard': 'dashboard',
        '/wallet': 'wallet',
        '/qr': 'qr',
        '/inbox': 'inbox',
        '/scan': 'scanner',
        '/payment': 'payment',
        '/admin': 'admin',
        '/admin/users': 'adminUsers',
        '/admin/tiers': 'adminTiers',
        '/admin/settings': 'adminSettings',
        '/admin/marketing': 'adminMarketing',
        '/superadmin': 'superadmin',
        '/superadmin/tenants': 'superadminTenants'
    };

    function getRouteFromPath(pathname) {
        // Remove trailing slash and query params
        const cleanPath = pathname.replace(/\/$/, '').split('?')[0];
        return ROUTES[cleanPath] || ROUTES[cleanPath.replace('/stamgast', '')] || 'unknown';
    }

    async function handleRoute() {
        const route = getRouteFromPath(window.location.pathname);
        log('Handling route:', route);
        
        // Check authentication for protected routes
        if (!isAuthenticated() && route !== 'unknown') {
            await checkSession();
        }
        
        // Route-specific initialization (use REGULR.vip namespace, not global functions)
        switch (route) {
            case 'wallet':
                if (window.REGULR?.wallet?.init) window.REGULR.wallet.init();
                else if (typeof initWallet === 'function') initWallet();
                break;
            case 'qr':
                if (window.REGULR?.qr?.init) window.REGULR.qr.init();
                else if (typeof initQR === 'function') initQR();
                break;
            case 'scanner':
            case 'payment':
                if (window.REGULR?.pos?.init) window.REGULR.pos.init();
                else if (typeof initPOS === 'function') initPOS();
                break;
            case 'admin':
            case 'adminUsers':
            case 'adminTiers':
            case 'adminSettings':
            case 'adminMarketing':
                if (window.REGULR?.admin?.init) window.REGULR.admin.init();
                else if (typeof initAdmin === 'function') initAdmin();
                break;
            case 'superadmin':
            case 'superadminTenants':
                if (window.REGULR?.superadmin?.init) window.REGULR.superadmin.init();
                else if (typeof initSuperAdmin === 'function') initSuperAdmin();
                break;
        }
        
        AppState.currentView = route;
    }

    // ============================================
    // NAVIGATION
    // ============================================
    function navigateTo(path) {
        window.history.pushState({}, '', path);
        handleRoute();
    }

    function setupNavigation() {
        // Handle browser back/forward
        window.addEventListener('popstate', handleRoute);
        
        // Handle link clicks
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[data-link]');
            if (link) {
                e.preventDefault();
                navigateTo(link.getAttribute('href'));
            }
        });
    }

    // ============================================
    // UI HELPERS
    // ============================================
    function updateWalletDisplay() {
        const balanceEl = document.getElementById('wallet-balance');
        const pointsEl = document.getElementById('wallet-points');
        
        if (balanceEl && AppState.wallet) {
            balanceEl.textContent = formatCurrency(AppState.wallet.balance_cents);
        }
        
        if (pointsEl && AppState.wallet) {
            pointsEl.textContent = formatPoints(AppState.wallet.points_cents);
        }
    }

    function showLoading(element) {
        if (element) {
            element.classList.add('loading');
            element.dataset.originalContent = element.innerHTML;
            element.innerHTML = '<span class="spinner"></span>';
        }
    }

    function hideLoading(element) {
        if (element && element.classList.contains('loading')) {
            element.classList.remove('loading');
            element.innerHTML = element.dataset.originalContent || element.innerHTML;
        }
    }

    // ============================================
    // SERVICE WORKER REGISTRATION
    // ============================================
    async function registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                log('ServiceWorker registered:', registration);
            } catch (error) {
                log('ServiceWorker registration failed:', error);
            }
        }
    }

    // ============================================
    // THEME & BRANDING
    // ============================================
    function applyTenantBranding(tenant) {
        if (!tenant) return;
        
        const root = document.documentElement;
        
        if (tenant.brand_color) {
            root.style.setProperty('--brand-color', tenant.brand_color);
        }
        
        if (tenant.secondary_color) {
            root.style.setProperty('--secondary-color', tenant.secondary_color);
        }
        
        // Update page title with tenant name
        if (tenant.name) {
            document.title = `${tenant.name} - REGULR.vip`;
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    async function init() {
        log('Initializing REGULR.vip App...');
        
        // Setup navigation
        setupNavigation();
        
        // Check session
        await checkSession();
        
        // Register service worker — MUST be awaited so that
        // navigator.serviceWorker.ready resolves for push.js
        await registerServiceWorker();
        
        // Apply tenant branding if available
        if (AppState.user?.tenant_id) {
            try {
                const tenantResponse = await apiCall('/auth/session');
                if (tenantResponse.tenant) {
                    applyTenantBranding(tenantResponse.tenant);
                }
            } catch (e) {
                log('Could not load tenant branding');
            }
        }
        
        // Handle initial route
        await handleRoute();
        
        // Periodic session check
        setInterval(checkSession, APP_CONFIG.SESSION_CHECK_INTERVAL);

        // PWA payment resume: when the app regains visibility with a tracked
        // pending deposit, ensure we land on /wallet so wallet.js can poll the
        // balance. This covers pages that don't load wallet.js themselves
        // (e.g. /benefits, /dashboard). On /wallet, wallet.js handles polling
        // directly — we only redirect when not already there.
        setupPaymentResume();

        log('REGULR.vip App initialized');
    }

    /**
     * Resume a pending payment when the PWA becomes visible again.
     * On iOS, after the guest completes the external Mollie payment in Safari
     * and switches back to the installed app, this fires and routes them to
     * /wallet where balance polling picks up the webhook-credited amount.
     */
    function setupPaymentResume() {
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState !== 'visible') return;
            const pending = PaymentTracker.get();
            if (!pending) return;

            const path = window.location.pathname.replace(/\/+$/, '');
            // wallet.js handles polling on /wallet itself — don't interfere.
            if (path === '/wallet' || path.endsWith('/wallet')) return;

            // Route to /wallet so the guest sees the updated balance.
            // The browser cache-bust prevents a stale cached wallet page.
            window.location.href = (window.__BASE_URL || '') + '/wallet?from_payment=1';
        });
    }

    // ============================================
    // PAYMENT TRACKER (PWA Mollie resume support)
    // ============================================
    // Persists a pending Mollie deposit in sessionStorage so the wallet can
    // resume balance-polling when the guest returns from the external payment.
    // Critical for iOS PWAs: the Mollie checkout opens in Safari, the PWA keeps
    // running in the background, and resumes polling on visibilitychange.
    const PAYMENT_KEY = 'regulr_pending_payment';

    const PaymentTracker = {
        start(info) {
            try {
                const record = Object.assign({}, info, { started_at: Date.now() });
                sessionStorage.setItem(PAYMENT_KEY, JSON.stringify(record));
            } catch (e) {
                log('PaymentTracker.start failed (storage unavailable):', e);
            }
        },
        get() {
            try {
                const raw = sessionStorage.getItem(PAYMENT_KEY);
                if (!raw) return null;
                const data = JSON.parse(raw);
                if (!data || typeof data !== 'object') return null;
                // Stale guard: drop payments older than 1 hour.
                if (data.started_at && (Date.now() - data.started_at > 3600000)) {
                    this.clear();
                    return null;
                }
                return data;
            } catch (e) {
                return null;
            }
        },
        clear() {
            try { sessionStorage.removeItem(PAYMENT_KEY); } catch (e) {}
        }
    };

    // ============================================
    // EXPORTS
    // ============================================
    window.REGULR = {
        // State
        state: AppState,
        
        // API
        api: apiCall,
        
        // Navigation
        navigate: navigateTo,
        
        // UI
        showError,
        showSuccess,
        sanitizeError,
        showLoading,
        hideLoading,
        formatCurrency,
        formatPoints,
        
        // Auth
        checkSession,
        isAuthenticated,
        isAccountActive: () => AppState.accountStatus === 'active',
        getUserRole,
        redirectToLogin,
        
        // Routing
        handleRoute,
        
        // Payment (PWA Mollie resume support)
        PaymentTracker,
        
        // Config
        config: APP_CONFIG
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
