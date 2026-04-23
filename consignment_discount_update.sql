-- Add discount fields to consignment tables
-- Run this SQL to add discount support to consignment module

-- 1. Add discount_amount to consignment_main_inventory
ALTER TABLE consignment_main_inventory 
ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price;

-- 2. Add discount_amount to consignment_assignments  
ALTER TABLE consignment_assignments 
ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price;

-- 3. Add discount_amount to consignment_inventory
ALTER TABLE consignment_inventory 
ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sale_price;

-- 4. Add discount_amount to consignment_sales
ALTER TABLE consignment_sales 
ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price;

-- Note: After running this migration, the application will calculate:
-- net_price = sale_price - discount_amount
-- gross_amount = quantity * net_price
-- commission_amount = gross_amount * (commission_rate / 100)
-- payout_due = gross_amount - commission_amount
