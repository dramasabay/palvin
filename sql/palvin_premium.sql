CREATE DATABASE IF NOT EXISTS palvin_premium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE palvin_premium;

DROP TABLE IF EXISTS media_files;
DROP TABLE IF EXISTS consignment_sales;
DROP TABLE IF EXISTS consignment_payouts;
DROP TABLE IF EXISTS consignment_inventory;
DROP TABLE IF EXISTS consignment_assignments;
DROP TABLE IF EXISTS consignment_main_inventory;
DROP TABLE IF EXISTS consignors;
DROP TABLE IF EXISTS retail_order_items;
DROP TABLE IF EXISTS retail_orders;
DROP TABLE IF EXISTS retail_inventory;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at DATETIME NULL
);

CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL
);

CREATE TABLE retail_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restock_date DATE NULL,
    item_name VARCHAR(180) NOT NULL,
    item_code VARCHAR(120) NOT NULL,
    reference_code VARCHAR(120) NULL,
    description_text TEXT NULL,
    quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL
);

CREATE TABLE retail_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(80) NOT NULL UNIQUE,
    customer_name VARCHAR(180) NOT NULL,
    contact_number VARCHAR(80) NULL,
    address_text TEXT NULL,
    deliver_by VARCHAR(180) NULL,
    payment_type VARCHAR(80) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    customer_type VARCHAR(80) NOT NULL DEFAULT 'New',
    order_date DATETIME NOT NULL
);

CREATE TABLE retail_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    inventory_id INT NOT NULL,
    item_name VARCHAR(180) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    line_note VARCHAR(255) NULL,
    CONSTRAINT fk_retail_order_items_order FOREIGN KEY (order_id) REFERENCES retail_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_retail_order_items_inventory FOREIGN KEY (inventory_id) REFERENCES retail_inventory(id) ON DELETE RESTRICT
);

CREATE TABLE consignors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_name VARCHAR(180) NOT NULL,
    branch_location VARCHAR(180) NULL,
    contact_person VARCHAR(180) NULL,
    phone VARCHAR(80) NULL,
    email VARCHAR(160) NULL,
    address_text TEXT NULL,
    commission_rate DECIMAL(6,2) NOT NULL DEFAULT 5,
    notes TEXT NULL
);

CREATE TABLE consignment_main_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(180) NOT NULL,
    reference_code VARCHAR(120) NULL,
    item_code VARCHAR(120) NOT NULL UNIQUE,
    total_stock INT NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    updated_at DATETIME NULL
);

CREATE TABLE consignment_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consignor_id INT NOT NULL,
    main_inventory_id INT NOT NULL,
    delivery_no VARCHAR(80) NULL,
    assigned_stock INT NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    commission_rate DECIMAL(6,2) NOT NULL DEFAULT 5,
    notes VARCHAR(255) NULL,
    issued_by VARCHAR(160) NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_consignment_assignments_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id) ON DELETE CASCADE,
    CONSTRAINT fk_consignment_assignments_main FOREIGN KEY (main_inventory_id) REFERENCES consignment_main_inventory(id) ON DELETE CASCADE
);

CREATE TABLE consignment_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    consignor_id INT NOT NULL,
    main_inventory_id INT NOT NULL,
    item_name VARCHAR(180) NOT NULL,
    reference_code VARCHAR(120) NULL,
    item_code VARCHAR(120) NOT NULL,
    stock_balance INT NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    commission_rate DECIMAL(6,2) NOT NULL DEFAULT 5,
    image_path VARCHAR(255) NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_consignment_inventory_assignment FOREIGN KEY (assignment_id) REFERENCES consignment_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_consignment_inventory_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id) ON DELETE CASCADE,
    CONSTRAINT fk_consignment_inventory_main FOREIGN KEY (main_inventory_id) REFERENCES consignment_main_inventory(id) ON DELETE CASCADE
);

CREATE TABLE consignment_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    assignment_id INT NULL,
    consignor_id INT NOT NULL,
    invoice_no VARCHAR(80) NOT NULL,
    item_name VARCHAR(180) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    commission_rate DECIMAL(6,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    payout_due DECIMAL(12,2) NOT NULL,
    opening_stock INT NOT NULL DEFAULT 0,
    closing_stock INT NOT NULL DEFAULT 0,
    sold_at DATETIME NOT NULL,
    CONSTRAINT fk_consignment_sales_inventory FOREIGN KEY (inventory_id) REFERENCES consignment_inventory(id) ON DELETE RESTRICT,
    CONSTRAINT fk_consignment_sales_assignment FOREIGN KEY (assignment_id) REFERENCES consignment_assignments(id) ON DELETE SET NULL,
    CONSTRAINT fk_consignment_sales_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id) ON DELETE CASCADE
);

CREATE TABLE consignment_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consignor_id INT NOT NULL,
    claim_month DATE NOT NULL,
    invoice_no VARCHAR(80) NOT NULL,
    payout_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('pending','overdue','claimed') NOT NULL DEFAULT 'pending',
    claimed_at DATETIME NULL,
    claimed_by_user_id INT NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    CONSTRAINT fk_consignment_payouts_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id) ON DELETE CASCADE,
    CONSTRAINT fk_consignment_payouts_user FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE media_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(30) NULL,
    uploaded_by VARCHAR(160) NULL,
    uploaded_at DATETIME NULL
);


CREATE INDEX idx_retail_orders_order_date ON retail_orders (order_date);
CREATE INDEX idx_retail_orders_contact_number ON retail_orders (contact_number);
CREATE INDEX idx_retail_orders_payment_order_date ON retail_orders (payment_type, order_date);
CREATE INDEX idx_retail_order_items_item_name ON retail_order_items (item_name);
CREATE INDEX idx_retail_inventory_item_name ON retail_inventory (item_name);
CREATE INDEX idx_retail_inventory_item_code ON retail_inventory (item_code);
CREATE INDEX idx_consignment_assignments_delivery_no ON consignment_assignments (delivery_no);
CREATE INDEX idx_consignment_assignments_consignor_delivery ON consignment_assignments (consignor_id, delivery_no);
CREATE INDEX idx_consignment_inventory_main_balance ON consignment_inventory (main_inventory_id, stock_balance);
CREATE INDEX idx_consignment_inventory_consignor_assignment ON consignment_inventory (consignor_id, assignment_id);
CREATE INDEX idx_consignment_sales_sold_at ON consignment_sales (sold_at);
CREATE INDEX idx_consignment_sales_invoice_no ON consignment_sales (invoice_no);
CREATE INDEX idx_consignment_sales_assignment_sold_at ON consignment_sales (assignment_id, sold_at);
CREATE INDEX idx_consignment_sales_consignor_sold_at ON consignment_sales (consignor_id, sold_at);
CREATE INDEX idx_consignment_payouts_claim_month_status ON consignment_payouts (claim_month, status);
CREATE INDEX idx_consignment_payouts_consignor_claim_month ON consignment_payouts (consignor_id, claim_month);
CREATE INDEX idx_media_files_uploaded_at ON media_files (uploaded_at);

INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES
('Administrator', 'admin@palvin.local', '$2y$12$9Z6tO5xJBhteZROOYdjbROHzVCmPSzcAl3cVOSo69N5A/8wll3lsu', 'admin', NOW());

INSERT INTO system_settings (setting_key, setting_value) VALUES
('company_name', 'PALVIN'),
('invoice_footer', 'Thank you for your business!'),
('default_consignor_commission', '5'),
('bank_name', 'Bank Name Here'),
('account_name', 'Your Business Name'),
('account_number', '987-654-321'),
('business_address', '987 Anywhere St, Any City, Any State 987655'),
('company_phone', '+123-456-7890'),
('company_email', 'hello@palvin.local'),
('invoice_note', 'Goods sold are subject to your standard store policy.'),
('claim_alert_mode', 'auto'),
('manual_claim_cutoff', ''),
('consignor_view_mode', 'grid'),
('fixed_discount_price', '0'),
('telegram_enabled', '0'),
('telegram_bot_token', ''),
('telegram_chat_id', ''),
('telegram_message_thread_id', ''),
('telegram_retail_alerts', '1'),
('telegram_consignment_alerts', '1'),
('invoice_size', 'A4'),
('pos_display_mode', 'grid'),
('language_switch_enabled', '1'),
('custom_css', ''),
('app_schema_version', '2026-04-18-palvin-v3b');

INSERT INTO retail_inventory (restock_date, item_name, item_code, reference_code, description_text, quantity, price, image_path) VALUES
(CURDATE(), 'Silk Dress', 'RET-001', 'REF-1001', 'Elegant retail dress', 12, 215.00, NULL),
(CURDATE(), 'Linen Shirt', 'RET-002', 'REF-1002', 'Premium linen shirt', 25, 120.00, NULL),
(CURDATE(), 'Classic Heel', 'RET-003', 'REF-1003', 'Popular retail heel', 8, 130.00, NULL);

INSERT INTO consignors (store_name, branch_location, contact_person, phone, email, address_text, commission_rate, notes) VALUES
('Boutique A', 'Phnom Penh', 'Sophy', '012345678', 'boutiquea@example.com', 'Street 123', 5.00, 'Main consignor'),
('Store B', 'Siem Reap', 'Dara', '098765432', 'storeb@example.com', 'Street 456', 8.00, 'Premium commission');

INSERT INTO consignment_main_inventory (item_name, reference_code, item_code, total_stock, sale_price, image_path, updated_at) VALUES
('Consigned Bag', 'C-REF-01', 'CON-001', 10, 180.00, NULL, NOW()),
('Consigned Shoe', 'C-REF-02', 'CON-002', 6, 220.00, NULL, NOW());

INSERT INTO consignment_assignments (consignor_id, main_inventory_id, delivery_no, assigned_stock, sale_price, commission_rate, notes, issued_by, updated_at) VALUES
(1, 1, 'DO-1001', 10, 180.00, 5.00, 'Initial delivery note', 'Administrator', NOW()),
(2, 2, 'DO-1002', 6, 220.00, 8.00, 'Initial delivery note', 'Administrator', NOW());

INSERT INTO consignment_inventory (assignment_id, consignor_id, main_inventory_id, item_name, reference_code, item_code, stock_balance, sale_price, commission_rate, image_path, updated_at) VALUES
(1, 1, 1, 'Consigned Bag', 'C-REF-01', 'CON-001', 10, 180.00, 5.00, NULL, NOW()),
(2, 2, 2, 'Consigned Shoe', 'C-REF-02', 'CON-002', 6, 220.00, 8.00, NULL, NOW());

-- PALVIN v2 additions (applied by ensure_runtime_schema)
-- exchange_rate, currency_display, app_language, app_favicon settings
-- are auto-created by ensure_setting_defaults() on first run
