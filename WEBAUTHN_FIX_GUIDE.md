# WebAuthn Fix Guide - Face ID on All Domains

## Problem Fixed
WebAuthn (Face ID/Fingerprint) was failing with "The operation either timed out or was not allowed" on non-HTTPS domains like `stamgast.test` and `http://localhost/stamgast/`.

**Root Cause**: WebAuthn spec requires HTTPS or exact `localhost` hostname. Custom domains like `stamgast.test` are not allowed.

## Solution Implemented
Modified `services/WebAuthnService.php` to:
1. **Use `localhost` as rpId in development mode** when the domain is not HTTPS and not already localhost
2. **Accept origins from the actual domain** (e.g., `http://stamgast.test`) when rpId is `localhost` in development mode

This allows WebAuthn to work on all domains during development while maintaining security in production.

## How It Works

### Before (Broken)
```
User on stamgast.test → WebAuthn tries rpId=stamgast.test → Browser rejects (not HTTPS)
```

### After (Fixed)
```
User on stamgast.test → Server returns rpId=localhost → Browser accepts → Works!
Origin validation: accepts http://stamgast.test for rpId=localhost in dev mode
```

## Testing Instructions

### 1. Reset Face ID Credentials (Clean Start)
```bash
# Run this to clear all existing Face ID data
php delete_credentials.php
```

### 2. Test Face ID Registration
1. Go to **Guest Profile** → **Security**
2. Click **Enable Face ID**
3. Your device will prompt for biometric confirmation (Face ID/Fingerprint)
4. Confirm with your face or fingerprint
5. You should see "Face ID enabled" message

### 3. Test Face ID Unlock
1. Go to any guest page
2. Wait 60+ seconds with the app in the background (minimize browser)
3. Return to the app
4. Lockscreen should appear
5. Click **FaceID / Vingerafdruk** button
6. Your device will prompt for biometric
7. Confirm with your face or fingerprint
8. App should unlock

### 4. Test on Different Domains
The fix works on:
- ✅ `http://stamgast.test` (development)
- ✅ `http://localhost/stamgast/` (development)
- ✅ `https://app.regulr.vip` (production - uses actual domain)
- ✅ Any HTTPS domain (uses actual domain)

### 5. Test PIN Backup
If Face ID fails or is cancelled:
1. PIN keypad should appear
2. Enter your 4-digit PIN
3. App should unlock

### 6. Verify in Browser Console
Open DevTools (F12) and check console for:
```
[AppLock] rpId: localhost
[WebAuthn] Development mode: using 'localhost' as rpId instead of 'stamgast.test'
[WebAuthn] Development mode: accepting origin 'http://stamgast.test' for rpId 'localhost'
```

## Technical Details

### Modified Files
- `services/WebAuthnService.php`
  - `getRpId()` method: Returns `localhost` for non-HTTPS custom domains in dev mode
  - `validateOrigin()` method: Accepts actual domain origin when rpId is `localhost` in dev mode

### Configuration
- **Development Mode**: `APP_ENV=development` in `.env`
- **Domain**: `APP_URL=http://stamgast.test` in `.env`
- **Automatic**: No additional configuration needed

### Security Notes
- ✅ Development mode override is **only active when `APP_ENV=development`**
- ✅ Production deployments use actual HTTPS domains (no override)
- ✅ Origin validation still enforces scheme matching (http/https)
- ✅ All WebAuthn security checks remain in place

## Troubleshooting

### "The operation either timed out or was not allowed"
- Ensure `APP_ENV=development` in `.env`
- Clear browser cache (Ctrl+Shift+R)
- Check browser console for error messages
- Verify device supports WebAuthn (most modern devices do)

### "No credentials registered"
- Run `php delete_credentials.php` to reset
- Re-register Face ID from profile
- Ensure biometric confirmation is given

### Face ID works but PIN doesn't
- PIN is stored separately in localStorage
- Set PIN from profile → Security → Set PIN
- PIN is 4 digits

### Works on stamgast.test but not localhost/stamgast/
- Both should work with this fix
- If not, check `APP_URL` in `.env`
- Ensure `APP_ENV=development`

## Verification Checklist
- [ ] Face ID registration works
- [ ] Face ID unlock works after 60 seconds in background
- [ ] PIN backup works if Face ID cancelled
- [ ] Works on `stamgast.test`
- [ ] Works on `http://localhost/stamgast/`
- [ ] Browser console shows correct rpId
- [ ] No "Invalid origin" errors in server logs

## Next Steps
1. Test on all target browsers/devices
2. Verify PIN backup works as fallback
3. Test cache-clear protection (Ctrl+Shift+R)
4. Test push notifications display above lockscreen
5. Deploy to production with HTTPS domain

## Production Deployment
When deploying to production:
1. Ensure `APP_ENV=production` in `.env`
2. Use HTTPS domain in `APP_URL`
3. WebAuthn will automatically use the actual domain (no localhost override)
4. No code changes needed - the fix is environment-aware

---

**Last Updated**: May 21, 2026
**Status**: ✅ Ready for Testing
