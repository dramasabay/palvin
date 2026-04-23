<?php
/**
 * PALVIN Language System
 * Supports: English (en), Khmer (km)
 * To switch: ?lang=km or ?lang=en
 */

if (!function_exists('get_lang')) {
function get_lang(): string {
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','km'], true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
    return $_SESSION['lang'] ?? 'en';
}}

if (!function_exists('t')) {
function t(string $key, array $replace = []): string {
    static $strings = null;
    if ($strings === null) {
        $lang = get_lang();
        $strings = lang_strings($lang);
    }
    $text = $strings[$key] ?? $key;
    foreach ($replace as $k => $v) {
        $text = str_replace(':' . $k, (string)$v, $text);
    }
    return $text;
}}

if (!function_exists('lang_strings')) {
function lang_strings(string $lang): array {
    $en = [
        // Navigation
        'nav_overview'       => 'Overview',
        'nav_dashboard'      => 'Dashboard',
        'nav_main_inv'       => 'Main Inventory',
        'nav_retail'         => 'Retail',
        'nav_retail_inv'     => 'Inventory',
        'nav_pos'            => 'POS Orders',
        'nav_order_hist'     => 'Order History',
        'nav_reports'        => 'Reports',
        'nav_consignment'    => 'Consignment',
        'nav_consignors'     => 'Consignors',
        'nav_stock'          => 'Stock',
        'nav_issue_do'       => 'Issue DO',
        'nav_issue_inv'      => 'Issue INV',
        'nav_payments'       => 'Payments',
        'nav_system'         => 'System',
        'nav_settings'       => 'Settings',
        'nav_media'          => 'Media',
        'nav_backup'         => 'Backup Data',
        'nav_users'          => 'Users',
        // Common
        'save'               => 'Save',
        'cancel'             => 'Cancel',
        'delete'             => 'Delete',
        'edit'               => 'Edit',
        'add'                => 'Add',
        'search'             => 'Search',
        'export'             => 'Export',
        'import'             => 'Import',
        'logout'             => 'Logout',
        'actions'            => 'Actions',
        'confirm_delete'     => 'Are you sure you want to delete this?',
        'no_data'            => 'No data found.',
        'loading'            => 'Loading...',
        'total'              => 'Total',
        'subtotal'           => 'Subtotal',
        'discount'           => 'Discount',
        'grand_total'        => 'Grand Total',
        'date'               => 'Date',
        'status'             => 'Status',
        'name'               => 'Name',
        'email'              => 'Email',
        'phone'              => 'Phone',
        'address'            => 'Address',
        'notes'              => 'Notes',
        'quantity'           => 'Quantity',
        'price'              => 'Price',
        'role'               => 'Role',
        'created_at'         => 'Created At',
        'password'           => 'Password',
        'yes'                => 'Yes',
        'no'                 => 'No',
        'enable'             => 'Enable',
        'disable'            => 'Disable',
        'enabled'            => 'Enabled',
        'disabled'           => 'Disabled',
        // Dashboard
        'dashboard'          => 'Dashboard',
        'retail_stock'       => 'Retail Stock',
        'today_sales'        => 'Today Retail Sales',
        'consignment_on_hand'=> 'Consignment On Hand',
        'unclaimed_payout'   => 'Unclaimed Payout',
        'overdue_claims'     => 'Overdue Monthly Claims',
        'no_overdue'         => 'No overdue claims.',
        'quick_links'        => 'Quick Links',
        'open_pos'           => 'Open POS Orders',
        'assign_stock'       => 'Assign Consignment Stock',
        'system_settings'    => 'System Settings',
        // Users
        'users'              => 'Users',
        'create_user'        => 'Create User',
        'full_name'          => 'Full Name',
        'staff'              => 'Staff',
        'admin'              => 'Admin',
        'update_user'        => 'Update User',
        'user_created'       => 'User created.',
        'user_updated'       => 'User updated.',
        'user_deleted'       => 'User deleted.',
        'cannot_delete_self' => 'You cannot delete your own account.',
        'staff_no_create'    => 'Staff users cannot create new users.',
        // Settings
        'settings'           => 'Settings',
        'company_name'       => 'Company Name',
        'company_phone'      => 'Company Phone',
        'company_email'      => 'Company Email',
        'business_address'   => 'Business Address',
        'bank_name'          => 'Bank Name',
        'account_name'       => 'Account Name',
        'account_number'     => 'Account Number',
        'invoice_footer'     => 'Invoice Footer',
        'invoice_note'       => 'Invoice Note',
        'invoice_logo'       => 'Invoice Logo',
        'invoice_size'       => 'Invoice Paper Size',
        'commission_rate'    => 'Default Commission Rate (%)',
        'exchange_rate'      => 'Exchange Rate (1 USD = KHR)',
        'currency_display'   => 'Currency Display',
        'language'           => 'Language',
        'settings_saved'     => 'Settings updated.',
        'custom_css'         => 'Custom CSS',
        'custom_css_hint'    => 'Advanced: add your own CSS rules. Applied globally to all pages.',
        // Language overrides
        'lang_overrides'     => 'Translation Overrides',
        'lang_overrides_hint'=> 'Override any translation key. Enter key=value pairs, one per line. Example: nav_pos=Sales Counter',
        'lang_key'           => 'Translation Key',
        'lang_value'         => 'Translation Value (Khmer)',
        'lang_add'           => 'Add Override',
        'lang_save'          => 'Save Overrides',
        'lang_saved'         => 'Translation overrides saved.',
        // Backup
        'backup'             => 'Backup Data',
        'backup_desc'        => 'Download a SQL backup of the current database with structure and data.',
        'download_backup'    => 'Download SQL Backup',
        'import_db'          => 'Import Database',
        'import_desc'        => 'Upload a .sql file to restore or import data. This will overwrite existing data.',
        'import_sql'         => 'Upload SQL File',
        'import_btn'         => 'Import Now',
        'import_success'     => 'Database imported successfully.',
        'import_error'       => 'Import failed: :error',
        // POS / Orders
        'pos_orders'         => 'POS Orders',
        'customer_name'      => 'Customer Name',
        'contact_number'     => 'Contact Number',
        'payment_type'       => 'Payment Type',
        'customer_type'      => 'Customer Type',
        'order_no'           => 'Order No.',
        'place_order'        => 'Place Order',
        'pos_display_mode'   => 'POS Items Display Mode',
        'consignor_display'  => 'Consignor Display Mode',
        'grid_view'          => 'Grid',
        'list_view'          => 'List',
        'language_mode'      => 'Language Switch',
        'language_mode_hint' => 'When disabled, users cannot switch the language from the interface.',
        // Misc
        'invoice'            => 'Invoice',
        'cambodia_time'      => 'Cambodia Time',
        'switch_lang'        => 'ភាសាខ្មែរ',
        'switch_lang_en'     => 'English',
        // Consignment
        'consignor'          => 'Consignor',
        'retail_label'       => 'Retail',
        'consignment_label'  => 'Consignment',
        'billed_to'          => 'Billed To',
        'delivered_to'       => 'Delivered To',
        'payment_info'       => 'Payment Information',
        'gross_sales'        => 'Gross Sales',
        'commission'         => 'Commission',
        'payout_due'         => 'Payout Due',
        'total_lines'        => 'Total Lines',
        'total_qty'          => 'Total Qty',
        'issued_by'          => 'Issued By',
        'ref_do'             => 'Ref DO',
        'paid_by'            => 'Paid By',
    ];

    $km = [
        // Navigation
        'nav_overview'       => 'ទិដ្ឋភាពទូទៅ',
        'nav_dashboard'      => 'ផ្ទាំងគ្រប់គ្រង',
        'nav_main_inv'       => 'សារពើភណ្ឌមេ',
        'nav_retail'         => 'លក់រាយ',
        'nav_retail_inv'     => 'សារពើភណ្ឌ',
        'nav_pos'            => 'ការលក់ POS',
        'nav_order_hist'     => 'ប្រវត្តិការបញ្ជាទិញ',
        'nav_reports'        => 'របាយការណ៍',
        'nav_consignment'    => 'ការផ្ញើរទំនិញ',
        'nav_consignors'     => 'អ្នកផ្ញើរទំនិញ',
        'nav_stock'          => 'ស្តុក',
        'nav_issue_do'       => 'ចេញ DO',
        'nav_issue_inv'      => 'ចេញ INV',
        'nav_payments'       => 'ការទូទាត់',
        'nav_system'         => 'ប្រព័ន្ធ',
        'nav_settings'       => 'ការកំណត់',
        'nav_media'          => 'មេឌៀ',
        'nav_backup'         => 'បម្រុងទុកទិន្នន័យ',
        'nav_users'          => 'អ្នកប្រើប្រាស់',
        // Common
        'save'               => 'រក្សាទុក',
        'cancel'             => 'បោះបង់',
        'delete'             => 'លុប',
        'edit'               => 'កែប្រែ',
        'add'                => 'បន្ថែម',
        'search'             => 'ស្វែងរក',
        'export'             => 'នាំចេញ',
        'import'             => 'នាំចូល',
        'logout'             => 'ចាកចេញ',
        'actions'            => 'សកម្មភាព',
        'confirm_delete'     => 'តើអ្នកប្រាកដថាចង់លុបនេះទេ?',
        'no_data'            => 'រកមិនឃើញទិន្នន័យ។',
        'loading'            => 'កំពុងផ្ទុក...',
        'total'              => 'សរុប',
        'subtotal'           => 'សរុបរង',
        'discount'           => 'បញ្ចុះតម្លៃ',
        'grand_total'        => 'ចំនួនសរុប',
        'date'               => 'កាលបរិច្ឆេទ',
        'status'             => 'ស្ថានភាព',
        'name'               => 'ឈ្មោះ',
        'email'              => 'អ៊ីមែល',
        'phone'              => 'ទូរស័ព្ទ',
        'address'            => 'អាសយដ្ឋាន',
        'notes'              => 'កំណត់ចំណាំ',
        'quantity'           => 'បរិមាណ',
        'price'              => 'តម្លៃ',
        'role'               => 'តួនាទី',
        'created_at'         => 'បានបង្កើតនៅ',
        'password'           => 'ពាក្យសម្ងាត់',
        'yes'                => 'បាទ/ចា',
        'no'                 => 'ទេ',
        'enable'             => 'បើក',
        'disable'            => 'បិទ',
        'enabled'            => 'បើករួច',
        'disabled'           => 'បិទរួច',
        // Dashboard
        'dashboard'          => 'ផ្ទាំងគ្រប់គ្រង',
        'retail_stock'       => 'ស្តុកលក់រាយ',
        'today_sales'        => 'ការលក់ថ្ងៃនេះ',
        'consignment_on_hand'=> 'ទំនិញផ្ញើរនៅក្នុងដៃ',
        'unclaimed_payout'   => 'ការទូទាត់មិនទាន់ទទួល',
        'overdue_claims'     => 'ការទាមទារប្រចាំខែហួសកំណត់',
        'no_overdue'         => 'គ្មានការទាមទារហួសកំណត់។',
        'quick_links'        => 'តំណភ្ជាប់រហ័ស',
        'open_pos'           => 'បើក POS',
        'assign_stock'       => 'បែងចែកស្តុក',
        'system_settings'    => 'ការកំណត់ប្រព័ន្ធ',
        // Users
        'users'              => 'អ្នកប្រើប្រាស់',
        'create_user'        => 'បង្កើតអ្នកប្រើប្រាស់',
        'full_name'          => 'ឈ្មោះពេញ',
        'staff'              => 'បុគ្គលិក',
        'admin'              => 'អ្នកគ្រប់គ្រង',
        'update_user'        => 'កែប្រែអ្នកប្រើប្រាស់',
        'user_created'       => 'បានបង្កើតអ្នកប្រើប្រាស់ជោគជ័យ។',
        'user_updated'       => 'បានកែប្រែអ្នកប្រើប្រាស់ជោគជ័យ។',
        'user_deleted'       => 'បានលុបអ្នកប្រើប្រាស់ជោគជ័យ។',
        'cannot_delete_self' => 'អ្នកមិនអាចលុបគណនីផ្ទាល់ខ្លួនបានទេ។',
        'staff_no_create'    => 'បុគ្គលិកមិនអាចបង្កើតអ្នកប្រើប្រាស់ថ្មីបានទេ។',
        // Settings
        'settings'           => 'ការកំណត់',
        'company_name'       => 'ឈ្មោះក្រុមហ៊ុន',
        'company_phone'      => 'ទូរស័ព្ទក្រុមហ៊ុន',
        'company_email'      => 'អ៊ីមែលក្រុមហ៊ុន',
        'business_address'   => 'អាសយដ្ឋានអាជីវកម្ម',
        'bank_name'          => 'ឈ្មោះធនាគារ',
        'account_name'       => 'ឈ្មោះគណនី',
        'account_number'     => 'លេខគណនី',
        'invoice_footer'     => 'បាតវិក្កយបត្រ',
        'invoice_note'       => 'កំណត់ចំណាំវិក្កយបត្រ',
        'invoice_logo'       => 'រូបតំណាងវិក្កយបត្រ',
        'invoice_size'       => 'ទំហំក្រដាសវិក្កយបត្រ',
        'commission_rate'    => 'អត្រាកម្រៃលំនាំដើម (%)',
        'exchange_rate'      => 'អត្រាប្តូររូបិយប័ណ្ណ (1 USD = KHR)',
        'currency_display'   => 'ការបង្ហាញរូបិយប័ណ្ណ',
        'language'           => 'ភាសា',
        'settings_saved'     => 'បានកែប្រែការកំណត់ជោគជ័យ។',
        'custom_css'         => 'CSS ផ្ទាល់ខ្លួន',
        'custom_css_hint'    => 'កម្រិតខ្ពស់: បន្ថែម CSS ផ្ទាល់ខ្លួន។ អនុវត្តទៅគ្រប់ទំព័រ។',
        // Language overrides
        'lang_overrides'     => 'កែប្រែការបកប្រែ',
        'lang_overrides_hint'=> 'កែប្រែពាក្យបកប្រែណាមួយ។ បញ្ចូល key=value មួយជួរម្តង។ ឧ: nav_pos=ការលក់',
        'lang_key'           => 'គន្លឹះភាសា',
        'lang_value'         => 'តម្លៃការបកប្រែ (ខ្មែរ)',
        'lang_add'           => 'បន្ថែមការកែប្រែ',
        'lang_save'          => 'រក្សាទុកការកែប្រែ',
        'lang_saved'         => 'បានរក្សាទុកការកែប្រែការបកប្រែ។',
        // Backup
        'backup'             => 'បម្រុងទុកទិន្នន័យ',
        'backup_desc'        => 'ទាញយកការបម្រុងទុក SQL នៃមូលដ្ឋានទិន្នន័យបច្ចុប្បន្ន។',
        'download_backup'    => 'ទាញយកការបម្រុងទុក SQL',
        'import_db'          => 'នាំចូលមូលដ្ឋានទិន្នន័យ',
        'import_desc'        => 'ផ្ទុកឡើងឯកសារ .sql ដើម្បីស្ដារ ឬនាំចូលទិន្នន័យ។',
        'import_sql'         => 'ផ្ទុកឯកសារ SQL ឡើង',
        'import_btn'         => 'នាំចូលឥឡូវ',
        'import_success'     => 'បាននាំចូលមូលដ្ឋានទិន្នន័យជោគជ័យ។',
        'import_error'       => 'ការនាំចូលបរាជ័យ: :error',
        // POS / Orders
        'pos_orders'         => 'ការបញ្ជាទិញ POS',
        'customer_name'      => 'ឈ្មោះអតិថិជន',
        'contact_number'     => 'លេខទំនាក់ទំនង',
        'payment_type'       => 'ប្រភេទការទូទាត់',
        'customer_type'      => 'ប្រភេទអតិថិជន',
        'order_no'           => 'លេខបញ្ជាទិញ',
        'place_order'        => 'ដាក់ការបញ្ជាទិញ',
        'pos_display_mode'   => 'របៀបបង្ហាញទំនិញ POS',
        'consignor_display'  => 'របៀបបង្ហាញអ្នកផ្ញើរ',
        'grid_view'          => 'ក្រឡាចត្រង្គ',
        'list_view'          => 'បញ្ជី',
        'language_mode'      => 'ការផ្លាស់ប្ដូរភាសា',
        'language_mode_hint' => 'នៅពេលបិទ អ្នកប្រើប្រាស់មិនអាចផ្លាស់ប្ដូរភាសាបានទេ។',
        // Misc
        'invoice'            => 'វិក្កយបត្រ',
        'cambodia_time'      => 'ម៉ោងកម្ពុជា',
        'switch_lang'        => 'ភាសាខ្មែរ',
        'switch_lang_en'     => 'English',
        // Consignment
        'consignor'          => 'អ្នកផ្ញើរទំនិញ',
        'retail_label'       => 'លក់រាយ',
        'consignment_label'  => 'ការផ្ញើរទំនិញ',
        'billed_to'          => 'គិតថ្លៃទៅ',
        'delivered_to'       => 'ដឹកជញ្ជូនទៅ',
        'payment_info'       => 'ព័ត៌មានការទូទាត់',
        'gross_sales'        => 'ការលក់សរុប',
        'commission'         => 'កម្រៃជើងសា',
        'payout_due'         => 'ប្រាក់ត្រូវទូទាត់',
        'total_lines'        => 'ចំនួនជួរ',
        'total_qty'          => 'បរិមាណសរុប',
        'issued_by'          => 'ចេញដោយ',
        'ref_do'             => 'យោង DO',
        'paid_by'            => 'បង់ដោយ',
    ];

    // Apply DB overrides for km
    if ($lang === 'km' && isset($GLOBALS['pdo'])) {
        try {
            $overrides = db_all($GLOBALS['pdo'], 'SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE ?', ['lang_km_%']);
            foreach ($overrides as $row) {
                $key = substr($row['setting_key'], 7); // strip lang_km_
                if ($row['setting_value'] !== '') {
                    $km[$key] = $row['setting_value'];
                }
            }
        } catch (Throwable $e) { /* silently skip if table not ready */ }
    }

    return $lang === 'km' ? $km : $en;
}}
