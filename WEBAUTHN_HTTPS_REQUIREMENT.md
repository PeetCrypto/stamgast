# WebAuthn HTTPS Requirement - Explained

## The Problem
WebAuthn (Face ID/Fingerprint) has a **strict security requirement** from the W3C specification:

> **The relying party ID must be a registrable domain suffix of the current domain**

This means:
- ❌ `stamgast.test` with rpId `stamgast.test` = **REJECTED** (not HTTPS, not localhost)
- ❌ `http://localhost/stamgast/` with rpId `localhost` = **REJECTED** (path doesn't matter, but domain must match)
- ✅ `https://stamgast.test` with rpId `stamgast.test` = **ACCEPTED** (HTTPS)
- ✅ `http://localhost` with rpId `localhost` = **ACCEPTED** (exact localhost)
- ✅ `https://app.regulr.vip` with rpId `app.regulr.vip` = **ACCEPTED** (HTTPS)

## Why This Requirement?
This is a **security feature** to prevent:
- Phishing attacks (malicious sites claiming to be your bank)
- Man-in-the-middle attacks
- Credential theft

## Solution: Use localhost

Changed `.env` to:
```
APP_URL=http://localhost/stamgast
```

This works because:
- ✅ `localhost` is a special case in WebAuthn spec
- ✅ HTTP is allowed for `localhost` (security exception)
- ✅ rpId will be `localhost`
- ✅ Origin will be `http://localhost/stamgast`
- ✅ Browser accepts this combination

## How to Access
Instead of `http://stamgast.test`, use:
```
http://localhost/stamgast
```

## Alternative: Use HTTPS (Better for Production)

If you want to keep using `stamgast.test`, you need HTTPS:

### Option 1: Laragon Built-in SSL
1. Right-click Laragon tray icon → SSL → Generate SSL
2. Select `stamgast.test`
3. Laragon generates self-signed certificate
4. Access via `https://stamgast.test`
5. Browser will warn about self-signed cert (click "Advanced" → "Proceed")

### Option 2: Manual SSL Setup
1. Generate self-signed certificate for `stamgast.test`
2. Configure Apache/Nginx to use it
3. Add certificate to browser's trusted store (optional, to avoid warnings)

### Option 3: Use mkcert (Recommended)
```bash
# Install mkcert (one-time)
choco install mkcert

# Create certificate for stamgast.test
mkcert stamgast.test

# Configure Apache to use the certificate
# Update httpd-ssl.conf with certificate paths
```

## Current Setup
- **Domain**: `http://localhost/stamgast`
- **rpId**: `localhost`
- **Status**: ✅ WebAuthn will work

## Testing

### 1. Clear Old Credentials
```bash
php delete_credentials.php
```

### 2. Access via localhost
```
http://localhost/stamgast
```

### 3. Register Face ID
- Go to Guest Profile → Security
- Click "Enable Face ID"
- Confirm with biometric

### 4. Test Unlock
- Wait 60+ seconds in background
- Return to app
- Click FaceID button
- Confirm with biometric

## Browser Console Verification
```
[AppLock] rpId: localhost
[WebAuthn] Signature verification successful
```

## Important Notes

### Path Doesn't Matter for rpId
- `http://localhost/stamgast` → rpId is `localhost` (not `localhost/stamgast`)
- `http://localhost/` → rpId is `localhost`
- `http://localhost:8080/app` → rpId is `localhost`

### Subdomain Matters
- `http://app.localhost` → rpId is `app.localhost` (different from `localhost`)
- `http://localhost` → rpId is `localhost` (correct)

### Production Deployment
For production, use HTTPS with your actual domain:
```
APP_URL=https://app.regulr.vip
```
- rpId will be `app.regulr.vip`
- WebAuthn will work perfectly
- No special configuration needed

## Troubleshooting

### Still Getting "relying party ID" Error
1. Clear browser cache (Ctrl+Shift+R)
2. Delete all credentials: `php delete_credentials.php`
3. Verify `.env` has `APP_URL=http://localhost/stamgast`
4. Restart browser
5. Try again

### "No credentials registered"
- Run `php delete_credentials.php`
- Re-register Face ID
- Ensure biometric confirmation is given

### Works on localhost but not stamgast.test
- This is expected - `stamgast.test` needs HTTPS
- Either use `http://localhost/stamgast` OR
- Set up HTTPS for `stamgast.test` (see Alternative section above)

## Summary

| Domain | Protocol | rpId | WebAuthn | Works? |
|--------|----------|------|----------|--------|
| stamgast.test | HTTP | stamgast.test | No | ❌ |
| stamgast.test | HTTPS | stamgast.test | Yes | ✅ |
| localhost | HTTP | localhost | Yes | ✅ |
| localhost | HTTPS | localhost | Yes | ✅ |
| app.regulr.vip | HTTPS | app.regulr.vip | Yes | ✅ |

---

**Current Configuration**: `http://localhost/stamgast` ✅ Ready to use
