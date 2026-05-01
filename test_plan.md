# REGULR.vip PLATFORM - TESTING PLAN

## 1. USER ROLES TESTING

### 1.1 Superadmin Role Testing
- [ ] Login with superadmin credentials (admin@stamgast.nl / Admin123!)
- [ ] Verify superadmin can access /superadmin routes
- [ ] Verify superadmin can create/edit/delete tenants
- [ ] Verify superadmin can toggle feature_push and feature_marketing for tenants
- [ ] Verify superadmin cannot access admin-only endpoints (e.g., /api/admin/*)

### 1.2 Admin (Manager) Role Testing
- [ ] Login with admin credentials (manager@test.nl / Manager123!)
- [ ] Verify admin can access /admin routes
- [ ] Verify admin cannot access superadmin routes
- [ ] Verify admin can view settings but cannot modify feature toggles
- [ ] Verify admin can see module status in dashboard
- [ ] Verify admin cannot access marketing studio if feature_marketing is disabled

## 2. MODULE GOVERNANCE TESTING

### 2.1 Feature Push Module Testing
- [ ] Login as superadmin
- [ ] Navigate to tenant management
- [ ] Disable feature_push for a test tenant
- [ ] Login as admin for that tenant
- [ ] Verify push notifications are not available in admin UI
- [ ] Return to superadmin
- [ ] Re-enable feature_push
- [ ] Verify admin now has access to push notifications

### 2.2 Feature Marketing Module Testing
- [ ] Login as superadmin
- [ ] Navigate to tenant management
- [ ] Disable feature_marketing for a test tenant
- [ ] Login as admin for that tenant
- [ ] Verify marketing studio is not accessible/greyed out
- [ ] Return to superadmin
- [ ] Re-enable feature_marketing
- [ ] Verify admin now has access to marketing studio

## 3. UI VERIFICATION TESTING

### 3.1 Admin Dashboard Module Status
- [ ] Login as admin
- [ ] Check dashboard shows module status clearly
- [ ] Verify push/marketing indicators show enabled/disabled state

### 3.2 Admin Settings View
- [ ] Navigate to admin settings
- [ ] Verify feature toggles are read-only
- [ ] Verify clear indication that modules are managed by superadmin

## 4. SECURITY TESTING

### 4.1 Role Access Verification
- [ ] Attempt to access admin endpoint with superadmin account (should be denied for admin-only endpoints)
- [ ] Attempt to access superadmin endpoint with admin account (should be denied)
- [ ] Verify admin cannot access feature toggle controls

## 5. DATA INTEGRITY TESTING

### 5.1 Database Verification
- [ ] Check users table has superadmin with NULL tenant_id
- [ ] Check users table has admin with proper tenant_id
- [ ] Verify feature_push and feature_marketing fields in tenants table
- [ ] Check that module status is properly reflected in API responses