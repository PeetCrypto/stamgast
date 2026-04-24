# Database Fix Solution

## Problem
The admin dashboard is showing a 500 Internal Server Error with the message:
"SQLSTATE[42S22]: Column not found: 1054 Unknown column 'created_at' in 'field list'"

## Root Cause
The database schema defined in `sql/schema.sql` includes a `created_at` column for various tables, but this column was missing in the actual database tables. The dashboard queries were trying to access this column, causing the error.

## Solution
1. A database migration script has been created at `public/migrate.php` to add the missing `created_at` column to database tables
2. The migration script can be run by accessing it through your web browser
3. The script will check if the `created_at` column exists in each table and add it if missing

## Files for Database Fix
- `public/migrate.php` - Web-accessible migration script
- `test_db_fix.php` - Verification script to test if the fix has been applied

## How to Apply the Fix
1. Access `public/migrate.php` through your web browser to run the database migration
2. After running the migration, test the fix by accessing `test_db_fix.php` through your web browser
3. The 500 error should now be resolved and the admin dashboard should work correctly

## Manual SQL Commands (Alternative Solution)
If you prefer to run the SQL commands directly, connect to your MySQL database and run:
```sql
ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `tenants` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
-- Add to other tables as needed
```