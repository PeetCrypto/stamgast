/**
 * REGULR.vip — Push Notification System
 *
 * Combineert Firebase Cloud Messaging (real-time push) met
 * polling fallback (elke 30s /api/notification/check).
 *
 * Flow:
 * 1. Registreer service worker (/sw.js)
 * 2. Als permission al granted → direct FCM token ophalen
 * 3. Luister naar foreground messages via onMessage()
 * 4. Polling als fallback voor non-push browsers
 * 5. FCMHandler.subscribe() — dashboard overlay & profile pagina gebruiken dit
 */

(function() {
    'use strict';

    var POLL_INTERVAL = 30000; // 30 seconds
    var TOAST_DURATION = 6000; // 6 seconds
    var POLL_URL = (window.__BASE_URL || '') + '/api/notification/check';
    var SUBSCRIBE_URL = (window.__BASE_URL || '') + '/api/push/subscribe';
    var CONFIG_URL = (window.__BASE_URL || '') + '/api/push/config';

    var VAPID_KEY = '';
    var lastUnreadCount = 0;
    var lastSeenIds = {};
    var polling = false;
    var pollTimer = null;
    var fcmTokenSent = false;
    var onMessageSetup = false;
    var swRegistration = null;

    // Load previously seen notification IDs from localStorage to prevent
    // re-showing the same notification on every page navigation
    try {
        var stored = localStorage.getItem('push_seen_ids');
        if (stored) lastSeenIds = JSON.parse(stored);
    } catch(_) {}

    function saveSeenIds() {
        try {
            // Prune: keep max 200 entries to prevent localStorage bloat
            var keys = Object.keys(lastSeenIds);
            if (keys.length > 200) {
                var keep = keys.slice(-100);
                var pruned = {};
                for (var i = 0; i < keep.length; i++) {
                    pruned[keep[i]] = true;
                }
                lastSeenIds = pruned;
            }
            localStorage.setItem('push_seen_ids', JSON.stringify(lastSeenIds));
        } catch(_) {}
    }

    console.log('[Push] System loaded');

    // ════════════════════════════════════════════════════════════
    // CONFIG FETCH
    // ════════════════════════════════════════════════════════════

    fetch(CONFIG_URL)
        .then(function(r) { return r.json(); })
        .then(function(config) {
            if (config.success && config.data.vapid_key) {
                VAPID_KEY = config.data.vapid_key;
                console.log('[Push] VAPID key loaded from server');
            }
    // Auto-init: if permission already granted → always get token (push is mandatory)
    if (Notification.permission === 'granted') {
        ensureSWAndGetToken();
    }
        })
        .catch(function(err) {
            console.warn('[Push] Failed to load config:', err.message);
            if (Notification.permission === 'granted') {
                ensureSWAndGetToken();
            }
        });

    // ════════════════════════════════════════════════════════════
    // SERVICE WORKER REGISTRATION
    // ════════════════════════════════════════════════════════════

    function registerServiceWorker() {
        if (swRegistration) return Promise.resolve(swRegistration);

        if (!('serviceWorker' in navigator)) {
            console.warn('[Push] Geen Service Worker support');
            return Promise.reject(new Error('No SW support'));
        }

        var swUrl = (window.__BASE_URL || '') + '/sw.js';

        return navigator.serviceWorker.ready.then(function(reg) {
            swRegistration = reg;
            console.log('[Push] SW already ready, scope:', reg.scope);
            return reg;
        }).catch(function() {
            return navigator.serviceWorker.register(swUrl, { scope: '/' })
                .then(function(reg) {
                    swRegistration = reg;
                    console.log('[Push] SW registered, scope:', reg.scope);
                    return navigator.serviceWorker.ready;
                });
        }).then(function(reg) {
            swRegistration = reg;
            return reg;
        });
    }

    // ════════════════════════════════════════════════════════════
    // FCM TOKEN
    // ════════════════════════════════════════════════════════════

    function setupOnMessageListener() {
        if (onMessageSetup) return;
        if (typeof firebase === 'undefined' || !firebase.messaging) return;
        onMessageSetup = true;

        var messaging = firebase.messaging();
        messaging.onMessage(function(payload) {
            console.log('[Push] Foreground message:', payload);

            // Don't show browser notification — the service worker (firebase-messaging-sw.js)
            // already handles background/foreground display for notification payloads.
            // Just refresh the badge count so the user sees the new unread count.
            checkNotifications();
        });
    }

    function ensureSWAndGetToken() {
        setupOnMessageListener();

        if (typeof firebase === 'undefined' || !firebase.messaging) {
            console.warn('[Push] Firebase SDK niet geladen');
            return;
        }

        registerServiceWorker().then(function() {
            getFCMToken();
        }).catch(function(err) {
            console.warn('[Push] SW setup failed:', err.message);
        });
    }

    function getFCMToken() {
        if (!VAPID_KEY) {
            console.warn('[Push] Geen VAPID key beschikbaar');
            return;
        }

        var messaging = firebase.messaging();
        console.log('[Push] Requesting FCM token...');

        messaging.getToken({ vapidKey: VAPID_KEY }).then(function(token) {
            if (token) {
                console.log('[Push] ✅ FCM token ontvangen:', token.substring(0, 30) + '...');
                sendTokenToServer(token);
            } else {
                console.warn('[Push] Geen FCM token ontvangen (null)');
            }
        }).catch(function(err) {
            var msg = (err && err.message) ? err.message : 'unknown';
            console.warn('[Push] getToken failed:', msg);
        });
    }

    function sendTokenToServer(token) {
        // Always send — even if sent before, token might have changed
        fetch(SUBSCRIBE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ fcm_token: token })
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(result) {
            if (result.success) {
                console.log('[Push] ✅ Token opgeslagen op server');
                fcmTokenSent = true;
            } else {
                console.warn('[Push] Token opslaan faalde:', result.error || 'unknown');
            }
        })
        .catch(function(err) {
            console.warn('[Push] Token submit error:', err.message);
        });
    }

    function getCSRFToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ════════════════════════════════════════════════════════════
    // SHOW NOTIFICATION (browser + toast fallback)
    // ════════════════════════════════════════════════════════════

    function showNotification(title, body, icon, tag, url) {
        // Try browser notification first
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try {
                var notif = new Notification(title, {
                    body: body,
                    icon: icon || '/icons/favicon.png',
                    badge: '/icons/favicon.png',
                    tag: tag || 'regulr-notification',
                    renotify: true,
                    vibrate: [100, 50, 100],
                    data: { url: url || (window.__BASE_URL || '') + '/inbox' }
                });

                notif.onclick = function() {
                    window.focus();
                    window.location.href = notif.data.url;
                    notif.close();
                };
                return; // Success — no need for toast
            } catch (e) {
                console.warn('[Push] Browser notification failed:', e.message);
            }
        }

        // Fallback: always show in-app toast
        showInAppToast(title, body);
    }

    // ════════════════════════════════════════════════════════════
    // POLLING FALLBACK
    // ════════════════════════════════════════════════════════════

    function checkNotifications() {
        fetch(POLL_URL, {
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(result) {
            if (!result.success || !result.data) return;

            var data = result.data;
            var unreadCount = data.unread_count || 0;
            var notifications = data.notifications || [];

            // Always update badge count
            updateBadge(unreadCount);

            // Track seen IDs so we don't re-process (persisted in localStorage)
            var hadNew = false;
            for (var i = 0; i < notifications.length; i++) {
                var id = notifications[i].id;
                if (!lastSeenIds[id]) {
                    lastSeenIds[id] = true;
                    hadNew = true;
                }
            }
            if (hadNew) {
                saveSeenIds();
                console.log('[Push] Badge updated, ' + unreadCount + ' unread');
            }

            lastUnreadCount = unreadCount;
        })
        .catch(function() {
            // Silent
        });
    }

    // ════════════════════════════════════════════════════════════
    // UNREAD BADGE
    // ════════════════════════════════════════════════════════════

    function updateBadge(count) {
        var badges = document.querySelectorAll('.notif-badge');
        for (var i = 0; i < badges.length; i++) {
            if (count > 0) {
                badges[i].textContent = count > 99 ? '99+' : count;
                badges[i].style.display = 'flex';
            } else {
                badges[i].style.display = 'none';
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    // IN-APP TOAST (fallback when browser notifications unavailable)
    // ════════════════════════════════════════════════════════════

    function showInAppToast(title, body) {
        var existing = document.getElementById('notif-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'notif-toast';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;'
            + 'min-width:280px;max-width:380px;padding:16px 20px;'
            + 'background:rgba(30,30,30,0.95);border:1px solid rgba(76,175,80,0.5);'
            + 'border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.4);'
            + 'cursor:pointer;transform:translateX(120%);transition:transform 0.4s cubic-bezier(0.175,0.885,0.32,1.275);'
            + 'backdrop-filter:blur(10px);';

        toast.innerHTML = '<div style="display:flex;align-items:flex-start;gap:12px;">'
            + '<span style="font-size:24px;line-height:1;">📢</span>'
            + '<div style="flex:1;min-width:0;">'
            + '<p style="font-weight:600;font-size:14px;color:#fff;margin:0 0 4px 0;">' + escapeHtml(title) + '</p>'
            + '<p style="font-size:13px;color:rgba(255,255,255,0.65);margin:0;line-height:1.4;">' + escapeHtml(body) + '</p>'
            + '</div>'
            + '</div>';

        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.style.transform = 'translateX(0)';
            });
        });

        toast.addEventListener('click', function() {
            toast.style.transform = 'translateX(120%)';
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
            window.location.href = (window.__BASE_URL || '') + '/inbox';
        });

        setTimeout(function() {
            toast.style.transform = 'translateX(120%)';
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
        }, TOAST_DURATION);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ════════════════════════════════════════════════════════════
    // POLLING CONTROLS
    // ════════════════════════════════════════════════════════════

    function startPolling() {
        if (polling) return;
        polling = true;
        console.log('[Push] Polling every ' + (POLL_INTERVAL / 1000) + 's');
        checkNotifications();
        pollTimer = setInterval(checkNotifications, POLL_INTERVAL);
    }

    function stopPolling() {
        polling = false;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // ════════════════════════════════════════════════════════════
    // INIT
    // ════════════════════════════════════════════════════════════

    function init() {
        var path = window.location.pathname;
        var publicPages = ['/login', '/register', '/forgot-password', '/reset-password'];
        var isPublic = publicPages.some(function(p) { return path.indexOf(p) !== -1; });
        var isJoin = path.indexOf('/j/') !== -1;

        if (isPublic || isJoin) return;

        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ════════════════════════════════════════════════════════════
    // PUBLIC API — gebruikt door profile toggle & dashboard banner
    // ════════════════════════════════════════════════════════════

    window.FCMHandler = {
        isPolling: true,
        check: checkNotifications,
        start: startPolling,
        stop: stopPolling,

        /**
         * subscribe() — Request permission, get FCM token, store in DB
         * Called by profile toggle (enable) and dashboard banner
         * Returns a Promise that resolves with { granted: true/false }
         */
        subscribe: function() {
            return new Promise(function(resolve) {
                if (typeof Notification === 'undefined') {
                    resolve({ granted: false, reason: 'unsupported' });
                    return;
                }

                console.log('[Push] subscribe() — requesting permission...');
                Notification.requestPermission().then(function(permission) {
                    console.log('[Push] Permission result:', permission);

                    if (permission === 'granted') {
                        // Clear disabled flag so auto-subscribe works again
                        try { localStorage.removeItem('push_disabled'); } catch(_) {}
                        setupOnMessageListener();
                        ensureSWAndGetToken();
                        resolve({ granted: true });
                    } else {
                        resolve({ granted: false, reason: permission });
                    }
                }).catch(function(err) {
                    console.warn('[Push] Permission request error:', err);
                    resolve({ granted: false, reason: 'error' });
                });
            });
        },

        /**
         * getPermissionStatus() — Check current browser permission
         */
        getPermissionStatus: function() {
            if (typeof Notification === 'undefined') return 'unsupported';
            return Notification.permission;
        }
    };
})();
