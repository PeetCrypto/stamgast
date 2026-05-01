# STAMGAST LOYALTY PLATFORM - DATABASE FIX

## Problem
The admin dashboard was showing a 500 Internal Server Error with the message:
"SQLSTATE[42S22]: Column not found: 1054 Unknown column 'created_at' in 'field list'"

## Root Cause
The database schema defined in `sql/schema.sql` includes a `created_at` column for various tables, but this column was missing in the actual database tables. The dashboard queries were trying to access this column, causing the error.

## Solution
1. Created SQL migration files to add the missing `created_at` column to database tables
2. Created PHP scripts to apply the migration
3. Provided multiple approaches to run the migration

## Files Created

### 1. SQL Migration File
- `sql/add_created_at_column.sql` - Contains SQL commands to add missing columns

### 2. PHP Migration Scripts
- `public/migrate.php` - Web-accessible migration script
- `run_migration.php` - Direct database migration script
- `verify_fix.php` - Verification script

## How to Apply the Fix

### Option 1: Run SQL Commands Directly
Connect to your MySQL database and run:
```sql
ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `tenants` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
-- Add to other tables as needed
```

### Option 2: Use the Migration Script
Access `public/migrate.php` through your web browser

### Option 3: Run the Direct Migration Script
Execute `run_migration.php` from the command line or through a web interface

## Verification
After applying the fix, the 500 error should be resolved and the admin dashboard should work correctly.