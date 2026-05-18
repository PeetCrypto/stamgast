<?php
/**
 * Push Diagnostic — isolate the EXACT cause
 * No login required, no manifest, minimal SW
 * Tests TWO methods: server key vs client-generated key
 */
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Push Diagnostic</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: monospace; background: #111; color: #eee; padding: 1rem; }
        h1 { color: #FFC107; margin-bottom: 0.5rem; font-size: 1.1rem; }
        p.sub { color: #999; margin-bottom: 1rem; font-size: 13px; }
        button { background: #FFC107; color: #000; border: none; padding: 0.5rem 1rem; font-size: 0.9rem; cursor: pointer; margin: 0.3rem; font-family: monospace; }
        button:disabled { background: #555; color: #999; }
        .test { margin: 1rem 0; padding: 0.8rem; border: 1px solid #333; }
        .test h2 { font-size: 0.9rem; margin-bottom: 0.5rem; }
        .log { margin: 0.2rem 0; padding: 0.3rem; font-size: 12px; background: #1a1a1a; }
        .log.ok { color: #4CAF50; }
        .log.err { color: #f44336; }
        .log.w { color: #FF9800; }
    </style>
</head>
<body>
    <h1>Push Diagnostic Tool</h1>
    <p class="sub">Deze test bepaalt EXACT wat het probleem is. Geen gokken meer.</p>

    <div class="test">
        <h2>TEST A: Key client-side gegenereerd (zoals de demo)</h2>
        <p style="font-size:12px;color:#999;margin-bottom:0.5rem">Genereert VAPID key in de browser met crypto.subtle — exact hetzelfde als de working demo</p>
        <div id="outA"></div>
        <button onclick="testA()">Test A</button>
    </div>

    <div class="test">
        <h2>TEST B: Key van onze server</h2>
        <p style="font-size:12px;color:#999;margin-bottom:0.5rem">Haalt VAPID key op van /api/push/config</p>
        <div id="outB"></div>
        <button onclick="testB()">Test B</button>
    </div>

    <script>
    const outA = document.getElementById('outA');
    const outB = document.getElementById('outB');

    function log(msg, cls, el) {
        const d = document.createElement('div');
        d.className = 'log ' + (cls || '');
        d.textContent = msg;
        el.appendChild(d);
        console.log(msg);
    }

    function b64url(buf) {
        const bytes = new Uint8Array(buf);
        let s = '';
        for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function fromB64url(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        const d = atob(s);
        const a = new Uint8Array(d.length);
        for (let i = 0; i < d.length; i++) a[i] = d.charCodeAt(i);
        return a;
    }

    // Minimal SW — nothing fancy
    const SW_CODE = `self.addEventListener('push', e => { e.waitUntil(self.registration.showNotification('test', { body: 'test' })); }); self.addEventListener('install', () => self.skipWaiting()); self.addEventListener('activate', () => self.clients.claim());`;

    async function regSW() {
        log('Registering /sw.js...', '', this === outA ? outA : outB);
        const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        log('SW scope: ' + reg.scope, 'ok', this === outA ? outA : outB);
        await navigator.serviceWorker.ready;
        log('SW ready', 'ok', this === outA ? outA : outB);
        return reg;
    }

    async function trySubscribe(reg, keyBytes, el) {
        log('Subscribing with ' + keyBytes.length + ' bytes, first=0x' + keyBytes[0].toString(16) + '...', '', el);
        try {
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: keyBytes,
            });
            log('SUCCESS! Endpoint: ' + sub.endpoint.substring(0, 80), 'ok', el);
            return true;
        } catch (e) {
            log('FAILED: ' + e.name + ': ' + e.message, 'err', el);
            return false;
        }
    }

    // TEST A: Client-side generated key (like the demo)
    async function testA() {
        outA.innerHTML = '';
        log('=== TEST A: Client-side generated key ===', 'w', outA);
        try {
            const reg = await regSW.call(outA);

            log('Generating P-256 key pair via crypto.subtle...', '', outA);
            const keyPair = await crypto.subtle.generateKey(
                { name: 'ECDSA', namedCurve: 'P-256' },
                true,
                ['sign', 'verify']
            );

            log('Exporting public key...', '', outA);
            const pubKey = await crypto.subtle.exportKey('raw', keyPair.publicKey);
            const keyBytes = new Uint8Array(pubKey);

            log('Key length: ' + keyBytes.length + ' bytes, first=0x' + keyBytes[0].toString(16), 'ok', outA);
            log('Key (base64url): ' + b64url(pubKey).substring(0, 40) + '...', '', outA);

            await trySubscribe(reg, keyBytes, outA);
        } catch (e) {
            log('Error: ' + e.message, 'err', outA);
        }
    }

    // TEST B: Server key
    async function testB() {
        outB.innerHTML = '';
        log('=== TEST B: Server-generated key ===', 'w', outB);
        try {
            const reg = await regSW.call(outB);

            log('Fetching from /api/push/config...', '', outB);
            const r = await fetch('/api/push/config', { credentials: 'same-origin' });
            if (!r.ok) { log('HTTP ' + r.status, 'err', outB); return; }
            const j = await r.json();
            const keyStr = j.data?.vapid_public_key || j.vapid_public_key;
            if (!keyStr) { log('No key in response!', 'err', outB); return; }

            const keyBytes = fromB64url(keyStr);
            log('Key: ' + keyBytes.length + ' bytes, first=0x' + keyBytes[0].toString(16) +
                ', last 4: ' + Array.from(keyBytes.slice(-4)).map(b => b.toString(16).padStart(2, '0')).join(''), 'ok', outB);
            log('Key (raw): ' + keyStr.substring(0, 40) + '...', '', outB);

            await trySubscribe(reg, keyBytes, outB);
        } catch (e) {
            log('Error: ' + e.message, 'err', outB);
        }
    }
    </script>
</body>
</html>
