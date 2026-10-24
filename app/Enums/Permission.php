<?php

namespace App\Enums;

/**
 * Every capability the application understands — the permission catalogue.
 *
 * This enum is the single source of permission codes. `RolesAndPermissionsSeeder` mirrors it
 * into the `permissions` table so roles can reference it; each case is registered as a Gate
 * ability, and routes declare it with `->can()`. No screen may create a permission, because a
 * code that no route checks would be a checkbox that does nothing.
 *
 * Codes follow `module.document_action`. CREATE (which includes editing a draft), APPROVE,
 * POST, REVERSE and CANCEL are separate wherever a module has them, so one person need not
 * hold every step of a document's life. See docs/AUTHORIZATION_RULES.md.
 */
enum Permission: string
{
    // ------------------------------------------------------------- master data
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case SuppliersView = 'suppliers.view';
    case SuppliersManage = 'suppliers.manage';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case WarehousesView = 'warehouses.view';
    case WarehousesManage = 'warehouses.manage';

    // ------------------------------------------------------------- inventory
    case InventoryView = 'inventory.view';
    case InventoryDamageRecord = 'inventory.damage_record';
    case InventoryDamageReverse = 'inventory.damage_reverse';

    // ------------------------------------------------------------- purchasing
    case PurchasingView = 'purchasing.view';
    case PurchasingOrderCreate = 'purchasing.order_create';
    case PurchasingOrderApprove = 'purchasing.order_approve';
    case PurchasingOrderCancel = 'purchasing.order_cancel';
    case PurchasingGrnCreate = 'purchasing.grn_create';
    case PurchasingGrnPost = 'purchasing.grn_post';
    case PurchasingGrnReverse = 'purchasing.grn_reverse';
    case PurchasingGrnCancel = 'purchasing.grn_cancel';

    // ------------------------------------------------------------- sales
    case SalesView = 'sales.view';
    case SalesOrderCreate = 'sales.order_create';
    case SalesOrderApprove = 'sales.order_approve';
    case SalesOrderCancel = 'sales.order_cancel';
    case SalesDeliveryCreate = 'sales.delivery_create';
    case SalesDeliveryPost = 'sales.delivery_post';
    case SalesDeliveryReverse = 'sales.delivery_reverse';
    case SalesDeliveryCancel = 'sales.delivery_cancel';
    case SalesBagsRecord = 'sales.bags_record';
    case SalesBagsReverse = 'sales.bags_reverse';

    // ------------------------------------------------------------- production
    case ProductionView = 'production.view';
    case ProductionCreate = 'production.create';
    case ProductionPost = 'production.post';
    case ProductionReverse = 'production.reverse';
    case ProductionCancel = 'production.cancel';

    // ------------------------------------------------------------- finance
    case FinanceView = 'finance.view';
    case FinanceAccountsManage = 'finance.accounts_manage';
    case FinanceSetupManage = 'finance.setup_manage';
    case FinancePeriodManage = 'finance.period_manage';
    case FinanceJournalCreate = 'finance.journal_create';
    case FinanceJournalPost = 'finance.journal_post';
    case FinanceJournalReverse = 'finance.journal_reverse';
    case FinanceJournalCancel = 'finance.journal_cancel';

    // ------------------------------------------------------------- accounts payable
    case ApView = 'finance.ap.view';
    case ApInvoiceCreate = 'finance.ap.invoice_create';
    case ApInvoicePost = 'finance.ap.invoice_post';
    case ApInvoiceReverse = 'finance.ap.invoice_reverse';
    case ApInvoiceCancel = 'finance.ap.invoice_cancel';
    case ApPaymentCreate = 'finance.ap.payment_create';
    case ApPaymentPost = 'finance.ap.payment_post';
    case ApPaymentReverse = 'finance.ap.payment_reverse';
    case ApPaymentCancel = 'finance.ap.payment_cancel';

    // ------------------------------------------------------------- accounts receivable
    case ArView = 'finance.ar.view';
    case ArInvoiceCreate = 'finance.ar.invoice_create';
    case ArInvoicePost = 'finance.ar.invoice_post';
    case ArInvoiceReverse = 'finance.ar.invoice_reverse';
    case ArInvoiceCancel = 'finance.ar.invoice_cancel';
    case ArReceiptCreate = 'finance.ar.receipt_create';
    case ArReceiptPost = 'finance.ar.receipt_post';
    case ArReceiptReverse = 'finance.ar.receipt_reverse';
    case ArReceiptCancel = 'finance.ar.receipt_cancel';

    // ------------------------------------------------------------- cash and bank
    case CashView = 'finance.cash.view';
    case CashAccountsManage = 'finance.cash.accounts_manage';
    case CashExpenseCreate = 'finance.cash.expense_create';
    case CashExpensePost = 'finance.cash.expense_post';
    case CashExpenseReverse = 'finance.cash.expense_reverse';
    case CashExpenseCancel = 'finance.cash.expense_cancel';
    case CashTransferCreate = 'finance.cash.transfer_create';
    case CashTransferPost = 'finance.cash.transfer_post';
    case CashTransferReverse = 'finance.cash.transfer_reverse';
    case CashTransferCancel = 'finance.cash.transfer_cancel';

    // ------------------------------------------------------------- payroll
    case PayrollView = 'payroll.view';
    case PayrollEmployeesManage = 'payroll.employees_manage';
    case PayrollAttendanceRecord = 'payroll.attendance_record';
    case PayrollAdvancePay = 'payroll.advance_pay';
    case PayrollAdvanceReverse = 'payroll.advance_reverse';
    case PayrollSalaryPay = 'payroll.salary_pay';
    case PayrollSalaryReverse = 'payroll.salary_reverse';

    // ------------------------------------------------------------- reports
    case ReportsFinance = 'reports.finance';
    case ReportsInventory = 'reports.inventory';
    case ReportsManagement = 'reports.management';
    case ReportsWeeklyEmail = 'reports.weekly_email';

    // ------------------------------------------------------------- administration
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case CompanyManage = 'company.manage';
    case MailManage = 'mail.manage';

    /**
     * Route middleware requiring this permission, for resource registrations that cannot
     * take `->can()` directly: `->middlewareFor([...], Permission::X->middleware())`.
     */
    public function middleware(): string
    {
        return 'can:'.$this->value;
    }

    /**
     * The group this permission is shown under in the role permission matrix.
     */
    public function module(): string
    {
        return match (true) {
            str_starts_with($this->value, 'finance.ap.') => 'Accounts Payable',
            str_starts_with($this->value, 'finance.ar.') => 'Accounts Receivable',
            str_starts_with($this->value, 'finance.cash.') => 'Cash & Bank',
            str_starts_with($this->value, 'finance.') => 'Finance',
            str_starts_with($this->value, 'payroll.') => 'Payroll',
            str_starts_with($this->value, 'reports.') => 'Reports',
            str_starts_with($this->value, 'users.'), str_starts_with($this->value, 'roles.'), str_starts_with($this->value, 'company.'), str_starts_with($this->value, 'mail.') => 'Administration',
            str_starts_with($this->value, 'inventory.') => 'Inventory',
            str_starts_with($this->value, 'purchasing.') => 'Purchasing',
            str_starts_with($this->value, 'sales.') => 'Sales',
            str_starts_with($this->value, 'production.') => 'Production',
            default => 'Master Data',
        };
    }

    /**
     * A human label for the matrix.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductsView => 'View products',
            self::ProductsManage => 'Create, edit and deactivate products',
            self::SuppliersView => 'View suppliers',
            self::SuppliersManage => 'Create, edit and deactivate suppliers',
            self::CustomersView => 'View customers',
            self::CustomersManage => 'Create, edit and deactivate customers',
            self::WarehousesView => 'View warehouses',
            self::WarehousesManage => 'Create, edit, deactivate and set the default warehouse',
            self::InventoryView => 'View stock on hand, stock ledger, lots and damaged stock',
            self::InventoryDamageRecord => 'Write off damaged stock',
            self::InventoryDamageReverse => 'Reverse damaged stock write-offs',
            self::PurchasingView => 'View purchase orders and goods receipts',
            self::PurchasingOrderCreate => 'Create and edit draft purchase orders',
            self::PurchasingOrderApprove => 'Approve purchase orders',
            self::PurchasingOrderCancel => 'Cancel purchase orders',
            self::PurchasingGrnCreate => 'Create and edit draft goods receipts',
            self::PurchasingGrnPost => 'Post goods receipts (stock in)',
            self::PurchasingGrnReverse => 'Reverse posted goods receipts',
            self::PurchasingGrnCancel => 'Cancel draft goods receipts',
            self::SalesView => 'View sales orders, deliveries and customer bags',
            self::SalesOrderCreate => 'Create and edit draft sales orders',
            self::SalesOrderApprove => 'Approve sales orders',
            self::SalesOrderCancel => 'Cancel sales orders',
            self::SalesDeliveryCreate => 'Create and edit draft deliveries',
            self::SalesDeliveryPost => 'Post deliveries (stock out)',
            self::SalesDeliveryReverse => 'Reverse posted deliveries',
            self::SalesDeliveryCancel => 'Cancel draft deliveries',
            self::SalesBagsRecord => 'Record bags given to, returned by or lost by customers',
            self::SalesBagsReverse => 'Reverse customer bag records',
            self::ProductionView => 'View dehusking, husk chipping and copra runs',
            self::ProductionCreate => 'Create and edit draft production runs',
            self::ProductionPost => 'Post production runs',
            self::ProductionReverse => 'Reverse posted production runs',
            self::ProductionCancel => 'Cancel draft production runs',
            self::FinanceView => 'View chart of accounts, journals, general ledger, trial balance and fiscal periods',
            self::FinanceAccountsManage => 'Create and edit ledger accounts',
            self::FinanceSetupManage => 'Change accounting setup and product account mappings',
            self::FinancePeriodManage => 'Create and close fiscal periods',
            self::FinanceJournalCreate => 'Create and edit draft manual journals',
            self::FinanceJournalPost => 'Post manual journals',
            self::FinanceJournalReverse => 'Reverse posted journals',
            self::FinanceJournalCancel => 'Cancel draft journals',
            self::ApView => 'View supplier invoices, payments, AP aging and GRNI reconciliation',
            self::ApInvoiceCreate => 'Create and edit draft supplier invoices',
            self::ApInvoicePost => 'Post supplier invoices',
            self::ApInvoiceReverse => 'Reverse posted supplier invoices',
            self::ApInvoiceCancel => 'Cancel draft supplier invoices',
            self::ApPaymentCreate => 'Create draft supplier payments',
            self::ApPaymentPost => 'Post supplier payments',
            self::ApPaymentReverse => 'Reverse posted supplier payments',
            self::ApPaymentCancel => 'Cancel draft supplier payments',
            self::ArView => 'View sales invoices, receipts, AR aging and unbilled deliveries',
            self::ArInvoiceCreate => 'Create and edit draft sales invoices',
            self::ArInvoicePost => 'Post sales invoices',
            self::ArInvoiceReverse => 'Reverse posted sales invoices',
            self::ArInvoiceCancel => 'Cancel draft sales invoices',
            self::ArReceiptCreate => 'Create draft customer receipts',
            self::ArReceiptPost => 'Post customer receipts',
            self::ArReceiptReverse => 'Reverse posted customer receipts',
            self::ArReceiptCancel => 'Cancel draft customer receipts',
            self::CashView => 'View cash/bank accounts, the cash/bank book, vouchers and transfers',
            self::CashAccountsManage => 'Configure cash and bank accounts',
            self::CashExpenseCreate => 'Create and edit draft expense vouchers',
            self::CashExpensePost => 'Post expense vouchers',
            self::CashExpenseReverse => 'Reverse posted expense vouchers',
            self::CashExpenseCancel => 'Cancel draft expense vouchers',
            self::CashTransferCreate => 'Create draft fund transfers',
            self::CashTransferPost => 'Post fund transfers',
            self::CashTransferReverse => 'Reverse posted fund transfers',
            self::CashTransferCancel => 'Cancel draft fund transfers',
            self::PayrollView => 'View employees, attendance, salary advances and paid salaries',
            self::PayrollEmployeesManage => 'Add and edit employees and their daily rates',
            self::PayrollAttendanceRecord => 'Record daily attendance and leave',
            self::PayrollAdvancePay => 'Pay salary advances',
            self::PayrollAdvanceReverse => 'Reverse salary advances',
            self::PayrollSalaryPay => 'Pay monthly salaries',
            self::PayrollSalaryReverse => 'Reverse paid salaries',
            self::ReportsFinance => 'Profit & loss, balance sheet and cash/bank position',
            self::ReportsInventory => 'Inventory valuation and inventory-vs-GL reconciliation',
            self::ReportsManagement => 'Finance dashboard and operational product profitability',
            self::ReportsWeeklyEmail => 'Receive the weekly update email (Sunday evening)',
            self::UsersView => 'View users',
            self::UsersManage => 'Assign roles to users and activate or deactivate them',
            self::RolesView => 'View roles and their permissions',
            self::RolesManage => 'Create and edit roles and assign permissions',
            self::CompanyManage => 'Edit the company name, address and logo printed on invoices and reports',
            self::MailManage => 'Set up the email account the system sends from, and send a test email',
        };
    }

    /**
     * The order modules appear in on the matrix.
     *
     * @return list<string>
     */
    public static function moduleOrder(): array
    {
        return [
            'Master Data', 'Inventory', 'Purchasing', 'Sales', 'Production', 'Finance',
            'Accounts Payable', 'Accounts Receivable', 'Cash & Bank', 'Payroll', 'Reports', 'Administration',
        ];
    }
}
