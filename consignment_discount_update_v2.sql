-- Add discount fields to consignment tables
-- Run this SQL to add discount support to consignment module
-- Safe migration: checks if columns exist before adding

-- 1. Add discount_amount and discount_type to consignment_main_inventory
SET @dbname = DATABASE();
SELECT COUNT(*) INTO @col_count FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'consignment_main_inventory' AND COLUMN_NAME = 'discount_type';
SET @sql = IF(@col_count = 0,
    'ALTER TABLE consignment_main_inventory ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price, ADD COLUMN discount_type ENUM(\'amount\',\'percent\') NOT NULL DEFAULT \'amount\' AFTER discount_amount',
    'SELECT "Column discount_type already exists in consignment_main_inventory"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add discount_amount and discount_type to consignment_assignments
SELECT COUNT(*) INTO @col_count FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'consignment_assignments' AND COLUMN_NAME = 'discount_type';
SET @sql = IF(@col_count = 0,
    'ALTER TABLE consignment_assignments ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price, ADD COLUMN discount_type ENUM(\'amount\',\'percent\') NOT NULL DEFAULT \'amount\' AFTER discount_amount',
    'SELECT "Column discount_type already exists in consignment_assignments"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add discount_amount and discount_type to consignment_inventory
SELECT COUNT(*) INTO @col_count FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'consignment_inventory' AND COLUMN_NAME = 'discount_type';
SET @sql = IF(@col_count = 0,
    'ALTER TABLE consignment_inventory ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price, ADD COLUMN discount_type ENUM(\'amount\',\'percent\') NOT NULL DEFAULT \'amount\' AFTER discount_amount',
    'SELECT "Column discount_type already exists in consignment_inventory"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add discount_amount and discount_type to consignment_sales
SELECT COUNT(*) INTO @col_count FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'consignment_sales' AND COLUMN_NAME = 'discount_type';
SET @sql = IF(@col_count = 0,
    'ALTER TABLE consignment_sales ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price, ADD COLUMN discount_type ENUM(\'amount\',\'percent\') NOT NULL DEFAULT \'amount\' AFTER discount_amount',
    'SELECT "Column discount_type already exists in consignment_sales"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Note: After running this migration, the application will calculate:
-- if discount_type = 'percent': net_price = sale_price * (1 - discount_amount/100)
-- if discount_type = 'amount': net_price = sale_price - discount_amount
-- gross_amount = quantity * net_price
-- commission_amount = gross_amount * (commission_rate / 100)
-- total_with_commission = gross_amount + commission_amount
