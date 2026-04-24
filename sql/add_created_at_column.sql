-- SQL command to add the missing created_at column to the transactions table
ALTER TABLE `transactions` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;