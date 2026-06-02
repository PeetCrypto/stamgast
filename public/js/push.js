/**
 * REGULR.vip — Push Notification System
 * 
 * Combineert Firebase Cloud Messaging (real-time push) met
 * polling fallback (elke 30s /api/notification/check).
 */

(function() {
    'use strict';

    var POLL_INTERVAL = 30000;
    var TOAST_DURATION = 6000;
    var POLL_URL = (window.__BASE_URL || '') + '/api/notification/check';
    var SUBSCRIBE_URL = (window.__BASE_URL || '') + '/api/push/subscribe';
    var CONFIG_URL = (window.__BASE_URL || '') + '/api/push/config';

    // DEFINIEER ALLE VARIABLES EERST
    var VAPID_KEY = '';
    var lastUnreadCount = 0;
    var lastSeenIds = {};
    var polling = false;
    var pollTimer = null;
    var fcmTokenSent = false;
    var onMessageSetup = false;
    var swRegistration = null;

    // Tenant branding (from meta tags)
    var tenantName = '';
    var tenantIcon = '';
    try {
        var tnMeta = document.querySelector('meta[name="tenant-name"]');
        if (tnMeta && tnMeta.content) tenantName = tnMeta.content;
        var tiMeta = document.querySelector('meta[name="tenant-icon"]');
        if (tiMeta && tiMeta.content) tenantIcon = tiMeta.content;
    } catch(_) {}

    // Load previously seen notification IDs
    try {
        var stored = localStorage.getItem('push_seen_ids');
        if (stored) lastSeenIds = JSON.parse(stored);
    } catch(_) {}

    function saveSeenIds() {
        try {
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
    // CONFIG FETCH - VAPID key ophalen
    // ════════════════════════════════════════════════════════════
    
    function initPush() {
        // Try to get VAPID key from meta tag first
        var vapidMeta = document.querySelector('meta[name="firebase-vapid-key"]');
        if (vapidMeta && vapidMeta.content) {
            VAPID_KEY = vapidMeta.content;
            console.log('[Push] VAPID key from meta tag:', VAPID_KEY.substring(0, 20) + '...');
            // Auto-init if permission already granted
            if (Notification.permission === 'granted') {
                ensureSWAndGetToken();
            }
        } else {
            // Fallback: fetch from server
            fetch(CONFIG_URL)
                .then(function(r) { return r.json(); })
                .then(function(config) {
                    if (config.success && config.data.vapid_key) {
                        VAPID_KEY = config.data.vapid_key;
                        console.log('[Push] VAPID key loaded from server');
                    }
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
        }
    }

    // ════════════════════════════════════════════════════════════
    // SERVICE WORKER REGISTRATION
    // ════════════════════════════════════════════════════════════

    function registerServiceWorker() {
        if (swRegistration) return Promise.resolve(swRegistration);

        if (!('serviceWorker' in navigator)) {
            console.warn('[Push] Geen Service Worker support');
            return Promise.reject(new Error('No SW support'));
        }

        // IMPORTANT: Use /sw.js (PHP route) NOT /js/sw.js (static file)
        // The PHP route injects Firebase config into the service worker
        var swUrl = (window.__BASE_URL || '') + '/sw.js';

        return navigator.serviceWorker.register(swUrl, { scope: '/' })
            .then(function(reg) {
                // Force update: als er een wachtende SW is, activeer deze direct
                if (reg.waiting) {
                    console.log('[Push] New SW waiting, forcing activation');
                    reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
                // Als er een installing SW is, wacht tot deze klaar is
                if (reg.installing) {
                    console.log('[Push] SW installing, waiting for activation...');
                    return new Promise(function(resolve, reject) {
                        reg.installing.addEventListener('statechange', function() {
                            if (reg.installing && reg.installing.state === 'activated') {
                                swRegistration = reg;
                                console.log('[Push] SW activated after install');
                                resolve(reg);
                            } else if (reg.installing && reg.installing.state === 'redundant') {
                                console.warn('[Push] SW became redundant, unregistering and retrying...');
                                reg.unregister().then(function() {
                                    // Retry registration once
                                    navigator.serviceWorker.register(swUrl, { scope: '/' })
                                        .then(function(reg2) {
                                            swRegistration = reg2;
                                            return navigator.serviceWorker.ready;
                                        })
                                        .then(resolve)
                                        .catch(reject);
                                }).catch(reject);
                            }
                        });
                    });
                }
                swRegistration = reg;
                console.log('[Push] SW registered, scope:', reg.scope);
                return navigator.serviceWorker.ready;
            })
            .then(function(reg) {
                swRegistration = reg;
                console.log('[Push] SW ready');
                return reg;
            })
            .catch(function(err) {
                console.warn('[Push] SW registration failed, attempting cleanup and retry:', err.message);
                // Try to unregister any broken SW and retry once
                return navigator.serviceWorker.getRegistrations().then(function(regs) {
                    var unregisterPromises = regs.map(function(r) { return r.unregister(); });
                    return Promise.all(unregisterPromises);
                }).then(function() {
                    console.log('[Push] Old SWs unregistered, retrying...');
                    return navigator.serviceWorker.register(swUrl, { scope: '/' });
                }).then(function(reg) {
                    swRegistration = reg;
                    console.log('[Push] SW registered after cleanup, scope:', reg.scope);
                    return navigator.serviceWorker.ready;
                }).then(function(reg) {
                    swRegistration = reg;
                    console.log('[Push] SW ready after cleanup');
                    return reg;
                });
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
            // Show notification with tenant branding
            var notifTitle = payload.notification?.title || payload.data?.title || tenantName || 'REGULR.vip';
            var notifBody = payload.notification?.body || payload.data?.body || '';
            var notifIcon = payload.data?.icon || payload.notification?.icon || tenantIcon || '/icons/favicon.png';
            showNotification(notifTitle, notifBody, notifIcon);
            checkNotifications();
        });
    }

    function ensureSWAndGetToken() {
        setupOnMessageListener();

        if (typeof firebase === 'undefined' || !firebase.messaging) {
            console.warn('[Push] Firebase SDK niet geladen');
            return Promise.reject(new Error('Firebase SDK niet geladen'));
        }

        return registerServiceWorker().then(function() {
            return getFCMToken();
        }).catch(function(err) {
            console.warn('[Push] SW setup failed:', err.message);
            return Promise.reject(err);
        });
    }

    function getFCMToken() {
        if (!VAPID_KEY) {
            console.warn('[Push] Geen VAPID key beschikbaar');
            return Promise.reject(new Error('Geen VAPID key'));
        }

        var messaging = firebase.messaging();
        console.log('[Push] Requesting FCM token...');

        return messaging.getToken({ vapidKey: VAPID_KEY }).then(function(token) {
            if (token) {
                console.log('[Push] ✅ FCM token ontvangen:', token.substring(0, 30) + '...');
                return sendTokenToServer(token).then(function() { return token; });
            } else {
                console.warn('[Push] Geen FCM token ontvangen (null)');
                return Promise.reject(new Error('Geen FCM token ontvangen'));
            }
        }).catch(function(err) {
            var msg = (err && err.message) ? err.message : 'unknown';
            console.warn('[Push] getToken failed:', msg);
            return Promise.reject(err);
        });
    }

    function sendTokenToServer(token) {
        return fetch(SUBSCRIBE_URL, {
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
                return true;
            } else {
                console.warn('[Push] Token opslaan faalde:', result.error || 'unknown');
                throw new Error(result.error || 'Token opslaan mislukt');
            }
        })
        .catch(function(err) {
            console.warn('[Push] Token submit error:', err.message);
            throw err;
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
        // Use tenant branding for icon
        var notifIcon = icon || tenantIcon || '/icons/favicon.png';

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try {
                var notif = new Notification(title, {
                    body: body,
                    icon: notifIcon,
                    badge: notifIcon,
                    tag: tag || 'regulr-notification',
                    renotify: true,
                    data: { url: url || (window.__BASE_URL || '') + '/inbox' }
                });

                notif.onclick = function() {
                    window.focus();
                    window.location.href = notif.data.url;
                    notif.close();
                };
                return;
            } catch (e) {
                console.warn('[Push] Browser notification failed:', e.message);
            }
        }
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

            updateBadge(unreadCount);

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
        .catch(function() {});
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
    // IN-APP TOAST
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

        // Log current permission state for debugging
        if (typeof Notification !== 'undefined') {
            console.log('[Push] Current permission:', Notification.permission);
        } else {
            console.warn('[Push] Notification API not available');
        }

        initPush();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ════════════════════════════════════════════════════════════
    // PUBLIC API
    // ════════════════════════════════════════════════════════════

    window.FCMHandler = {
        isPolling: true,
        check: checkNotifications,
        start: startPolling,
        stop: stopPolling,

        /**
         * Detect platform for UI guidance
         * Returns: 'ios_pwa', 'ios_browser', 'android', 'desktop'
         */
        detectPlatform: function() {
            var ua = navigator.userAgent;
            var isStandalone = false;
            try {
                isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true
                    || document.referrer.includes('android-app://');
            } catch(_) {}

            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var isAndroid = /Android/.test(ua);

            if (isIOS) {
                return isStandalone ? 'ios_pwa' : 'ios_browser';
            }
            if (isAndroid) {
                return 'android';
            }
            return 'desktop';
        },

        /**
         * Check if push is possible on this platform
         */
        canPush: function() {
            var platform = this.detectPlatform();

            // iOS browser (non-PWA) does NOT support push at all
            if (platform === 'ios_browser') {
                return { possible: false, reason: 'ios_install_required', platform: platform };
            }

            // All other platforms need Notification API + Service Worker
            if (typeof Notification === 'undefined') {
                return { possible: false, reason: 'unsupported', platform: platform };
            }
            if (!('serviceWorker' in navigator)) {
                return { possible: false, reason: 'no_sw', platform: platform };
            }
            if (!('PushManager' in window)) {
                return { possible: false, reason: 'no_push_api', platform: platform };
            }

            return { possible: true, platform: platform };
        },

        subscribe: function() {
            return new Promise(function(resolve) {
                if (typeof Notification === 'undefined') {
                    resolve({ granted: false, reason: 'unsupported' });
                    return;
                }

                // If already granted, skip permission dialog and go straight to token
                if (Notification.permission === 'granted') {
                    try { localStorage.removeItem('push_disabled'); } catch(_) {}
                    setupOnMessageListener();
                    ensureSWAndGetToken().then(function(token) {
                        if (token) {
                            resolve({ granted: true });
                        } else {
                            resolve({ granted: false, reason: 'no_token' });
                        }
                    }).catch(function(err) {
                        var msg = (err && err.message) ? err.message : 'unknown';
                        resolve({ granted: false, reason: 'token_error', message: msg });
                    });
                    return;
                }

                // If already denied, don't bother requesting
                if (Notification.permission === 'denied') {
                    console.warn('[Push] subscribe() — permission already denied');
                    resolve({ granted: false, reason: 'denied' });
                    return;
                }

                // permission === 'default' — need to ask
                console.log('[Push] subscribe() — requesting permission...');

                // Notify UI that we're waiting for the browser dialog
                if (typeof window.__pushPermissionCallback === 'function') {
                    window.__pushPermissionCallback('waiting_for_dialog');
                }

                // Timeout: if the user ignores the dialog for 20s, resolve with timeout
                var resolved = false;
                var timeoutId = setTimeout(function() {
                    if (!resolved) {
                        resolved = true;
                        console.warn('[Push] subscribe() — permission dialog timed out');
                        resolve({ granted: false, reason: 'timeout', message: 'De toestemmingsdialoog is niet beantwoord. Probeer het opnieuw.' });
                    }
                }, 20000);

                Notification.requestPermission().then(function(permission) {
                    clearTimeout(timeoutId);
                    if (resolved) return; // already timed out
                    resolved = true;

                    console.log('[Push] Permission result:', permission);

                    if (permission === 'granted') {
                        try { localStorage.removeItem('push_disabled'); } catch(_) {}
                        setupOnMessageListener();

                        // Notify UI that we're getting the token
                        if (typeof window.__pushPermissionCallback === 'function') {
                            window.__pushPermissionCallback('getting_token');
                        }

                        // Wait for actual FCM token — this catches incognito failures
                        ensureSWAndGetToken().then(function(token) {
                            if (token) {
                                console.log('[Push] subscribe() — token obtained, push fully active');
                                resolve({ granted: true });
                            } else {
                                console.warn('[Push] subscribe() — no token received');
                                resolve({ granted: false, reason: 'no_token' });
                            }
                        }).catch(function(err) {
                            var msg = (err && err.message) ? err.message : 'unknown';
                            console.warn('[Push] subscribe() — token failed:', msg);

                            // Detect incognito / push-not-supported scenarios
                            if (msg.indexOf('permission denied') !== -1 || msg.indexOf('Registration failed') !== -1) {
                                resolve({ granted: false, reason: 'push_unavailable', message: 'Push is niet beschikbaar in deze browser-sessie (bijv. incognito). Probeer het in een normaal venster.' });
                            } else {
                                resolve({ granted: false, reason: 'token_error', message: msg });
                            }
                        });
                    } else if (permission === 'denied') {
                        resolve({ granted: false, reason: 'denied' });
                    } else {
                        // 'default' — user dismissed the dialog without choosing
                        resolve({ granted: false, reason: 'dismissed' });
                    }
                }).catch(function(err) {
                    clearTimeout(timeoutId);
                    if (resolved) return;
                    resolved = true;
                    console.warn('[Push] Permission request error:', err);
                    resolve({ granted: false, reason: 'error' });
                });
            });
        },

        getPermissionStatus: function() {
            if (typeof Notification === 'undefined') return 'unsupported';
            return Notification.permission;
        }
    };
})();