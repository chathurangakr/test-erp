<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ChartColumn,
    Coins,
    Gauge,
    GitCompareArrows,
    TrendingUp,
    BookOpenCheck,
    Boxes,
    Landmark,
    ClipboardList,
    Building2,
    Mail,
    LayoutGrid,
    Layers,
    Package,
    Factory,
    Grid2x2,
    Sun,
    Tablet,
    BookMarked,
    Calculator,
    CalendarRange,
    Scale,
    ShieldCheck,
    UserCog,
    SlidersHorizontal,
    FileText,
    Banknote,
    CalendarClock,
    FileCheck2,
    ReceiptText,
    HandCoins,
    Hourglass,
    PackageSearch,
    Wallet,
    Receipt,
    ScrollText,
    ShoppingCart,
    Truck as TruckIcon,
    Truck,
    Users,
    Contact,
    CalendarCheck,
    BadgeDollarSign,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as companyEdit } from '@/routes/company';
import { edit as mailSettingsEdit } from '@/routes/mail-settings';
import { index as customersIndex } from '@/routes/customers';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import { index as lotsIndex } from '@/routes/inventory/lots';
import { index as stockIndex } from '@/routes/inventory/stock';
import { index as goodsReceiptsIndex } from '@/routes/goods-receipts';
import { index as purchaseOrdersIndex } from '@/routes/purchase-orders';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as salesOrdersIndex } from '@/routes/sales-orders';
import { index as dehuskingIndex } from '@/routes/dehusking';
import { index as huskChippingIndex } from '@/routes/husk-chipping';
import { index as copraIndex } from '@/routes/copra';
import { index as accountsIndex } from '@/routes/accounts';
import { index as journalsIndex } from '@/routes/journals';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import { index as trialBalanceIndex } from '@/routes/trial-balance';
import { index as fiscalPeriodsIndex } from '@/routes/fiscal-periods';
import { index as accountingSetupIndex } from '@/routes/accounting-setup';
import { index as supplierInvoicesIndex } from '@/routes/supplier-invoices';
import { index as supplierPaymentsIndex } from '@/routes/supplier-payments';
import { index as apAgingIndex } from '@/routes/ap-aging';
import { index as grniReconciliationIndex } from '@/routes/grni-reconciliation';
import { index as salesInvoicesIndex } from '@/routes/sales-invoices';
import { index as customerReceiptsIndex } from '@/routes/customer-receipts';
import { index as arAgingIndex } from '@/routes/ar-aging';
import { index as unbilledDeliveriesIndex } from '@/routes/unbilled-deliveries';
import { index as cashBankAccountsIndex } from '@/routes/cash-bank-accounts';
import { index as cashBankBookIndex } from '@/routes/cash-bank-book';
import { index as expenseVouchersIndex } from '@/routes/expense-vouchers';
import { index as fundTransfersIndex } from '@/routes/fund-transfers';
import { index as employeesIndex } from '@/routes/employees';
import { index as attendanceIndex } from '@/routes/attendance';
import { index as salaryAdvancesIndex } from '@/routes/salary-advances';
import { index as payrollsIndex } from '@/routes/payrolls';
import { index as financeDashboardIndex } from '@/routes/finance-dashboard';
import {
    balanceSheet as balanceSheetReport,
    cashBankPosition as cashBankPositionReport,
    inventoryGlReconciliation as inventoryGlReconciliationReport,
    inventoryValuation as inventoryValuationReport,
    productProfitability as productProfitabilityReport,
    profitLoss as profitLossReport,
} from '@/routes/reports';
import { home as kioskHome } from '@/routes/kiosk';
import { index as productsIndex } from '@/routes/products';
import { index as rolesIndex } from '@/routes/roles';
import { index as suppliersIndex } from '@/routes/suppliers';
import { index as usersIndex } from '@/routes/users';
import { index as warehousesIndex } from '@/routes/warehouses';
import type { NavItem } from '@/types';
import { useT } from '@/lib/i18n';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        // Open to everyone signed in; it shows only the jobs their roles allow.
        title: 'Simple mode',
        href: kioskHome(),
        icon: Tablet,
    },
];

const masterNavItems: NavItem[] = [
    {
        title: 'Products',
        href: productsIndex(),
        permission: 'products.view',
        icon: Package,
    },
    {
        title: 'Suppliers',
        href: suppliersIndex(),
        permission: 'suppliers.view',
        icon: Truck,
    },
    {
        title: 'Customers',
        href: customersIndex(),
        permission: 'customers.view',
        icon: Users,
    },
    {
        title: 'Warehouses',
        href: warehousesIndex(),
        permission: 'warehouses.view',
        icon: Building2,
    },
];

const inventoryNavItems: NavItem[] = [
    {
        title: 'Stock on Hand',
        href: stockIndex(),
        permission: 'inventory.view',
        icon: Boxes,
    },
    {
        title: 'Stock Ledger',
        href: ledgerIndex(),
        permission: 'inventory.view',
        icon: ScrollText,
    },
    {
        title: 'Lots',
        href: lotsIndex(),
        permission: 'inventory.view',
        icon: Layers,
    },
];

const purchasingNavItems: NavItem[] = [
    {
        title: 'Purchase Orders',
        href: purchaseOrdersIndex(),
        permission: 'purchasing.view',
        icon: ClipboardList,
    },
    {
        title: 'Goods Receipts',
        href: goodsReceiptsIndex(),
        permission: 'purchasing.view',
        icon: ShoppingCart,
    },
];

const salesNavItems: NavItem[] = [
    {
        title: 'Sales Orders',
        href: salesOrdersIndex(),
        permission: 'sales.view',
        icon: Receipt,
    },
    {
        title: 'Deliveries',
        href: deliveriesIndex(),
        permission: 'sales.view',
        icon: TruckIcon,
    },
];

const productionNavItems: NavItem[] = [
    {
        title: 'Dehusking',
        href: dehuskingIndex(),
        permission: 'production.view',
        icon: Factory,
    },
    {
        title: 'Husk Chipping',
        href: huskChippingIndex(),
        permission: 'production.view',
        icon: Grid2x2,
    },
    {
        title: 'Copra Production',
        href: copraIndex(),
        permission: 'production.view',
        icon: Sun,
    },
];

const financeNavItems: NavItem[] = [
    {
        title: 'Chart of Accounts',
        href: accountsIndex(),
        permission: 'finance.view',
        icon: BookMarked,
    },
    {
        title: 'Journal Entries',
        href: journalsIndex(),
        permission: 'finance.view',
        icon: Calculator,
    },
    {
        title: 'General Ledger',
        href: generalLedgerIndex(),
        permission: 'finance.view',
        icon: Wallet,
    },
    {
        title: 'Trial Balance',
        href: trialBalanceIndex(),
        permission: 'finance.view',
        icon: Scale,
    },
    {
        title: 'Fiscal Periods',
        href: fiscalPeriodsIndex(),
        permission: 'finance.view',
        icon: CalendarRange,
    },
    {
        title: 'Accounting Setup',
        href: accountingSetupIndex(),
        permission: 'finance.view',
        icon: SlidersHorizontal,
    },
];

const payablesNavItems: NavItem[] = [
    {
        title: 'Supplier Invoices',
        href: supplierInvoicesIndex(),
        permission: 'finance.ap.view',
        icon: FileText,
    },
    {
        title: 'Supplier Payments',
        href: supplierPaymentsIndex(),
        permission: 'finance.ap.view',
        icon: Banknote,
    },
    {
        title: 'AP Aging',
        href: apAgingIndex(),
        permission: 'finance.ap.view',
        icon: CalendarClock,
    },
    {
        title: 'GRNI Reconciliation',
        href: grniReconciliationIndex(),
        permission: 'finance.ap.view',
        icon: FileCheck2,
    },
];

const receivablesNavItems: NavItem[] = [
    {
        title: 'Sales Invoices',
        href: salesInvoicesIndex(),
        permission: 'finance.ar.view',
        icon: ReceiptText,
    },
    {
        title: 'Customer Receipts',
        href: customerReceiptsIndex(),
        permission: 'finance.ar.view',
        icon: HandCoins,
    },
    {
        title: 'AR Aging',
        href: arAgingIndex(),
        permission: 'finance.ar.view',
        icon: Hourglass,
    },
    {
        title: 'Unbilled Deliveries',
        href: unbilledDeliveriesIndex(),
        permission: 'finance.ar.view',
        icon: PackageSearch,
    },
];

const reportsNavItems: NavItem[] = [
    {
        title: 'Finance Dashboard',
        href: financeDashboardIndex(),
        permission: 'reports.management',
        icon: Gauge,
    },
    {
        title: 'Profit & Loss',
        href: profitLossReport(),
        permission: 'reports.finance',
        icon: TrendingUp,
    },
    {
        title: 'Balance Sheet',
        href: balanceSheetReport(),
        permission: 'reports.finance',
        icon: Scale,
    },
    {
        title: 'Inventory Valuation',
        href: inventoryValuationReport(),
        permission: 'reports.inventory',
        icon: Boxes,
    },
    {
        title: 'Inventory vs GL',
        href: inventoryGlReconciliationReport(),
        permission: 'reports.inventory',
        icon: GitCompareArrows,
    },
    {
        title: 'Cash / Bank Position',
        href: cashBankPositionReport(),
        permission: 'reports.finance',
        icon: Coins,
    },
    {
        title: 'Product Profitability',
        href: productProfitabilityReport(),
        permission: 'reports.management',
        icon: ChartColumn,
    },
];

const cashNavItems: NavItem[] = [
    {
        title: 'Cash / Bank Accounts',
        href: cashBankAccountsIndex(),
        permission: 'finance.cash.view',
        icon: Landmark,
    },
    {
        title: 'Cash / Bank Book',
        href: cashBankBookIndex(),
        permission: 'finance.cash.view',
        icon: BookOpenCheck,
    },
    {
        title: 'Expense Vouchers',
        href: expenseVouchersIndex(),
        permission: 'finance.cash.view',
        icon: Receipt,
    },
    {
        title: 'Fund Transfers',
        href: fundTransfersIndex(),
        permission: 'finance.cash.view',
        icon: ArrowLeftRight,
    },
];

const payrollNavItems: NavItem[] = [
    {
        title: 'Employees',
        href: employeesIndex(),
        permission: 'payroll.view',
        icon: Contact,
    },
    {
        title: 'Attendance',
        href: attendanceIndex(),
        permission: 'payroll.view',
        icon: CalendarCheck,
    },
    {
        title: 'Salary advances',
        href: salaryAdvancesIndex(),
        permission: 'payroll.view',
        icon: HandCoins,
    },
    {
        title: 'Monthly salaries',
        href: payrollsIndex(),
        permission: 'payroll.view',
        icon: BadgeDollarSign,
    },
];

const administrationNavItems: NavItem[] = [
    {
        title: 'Users',
        href: usersIndex(),
        permission: 'users.view',
        icon: UserCog,
    },
    {
        title: 'Roles & Permissions',
        href: rolesIndex(),
        permission: 'roles.view',
        icon: ShieldCheck,
    },
    {
        title: 'Company details',
        href: companyEdit(),
        permission: 'company.manage',
        icon: Building2,
    },
    {
        title: 'Email settings',
        href: mailSettingsEdit(),
        permission: 'mail.manage',
        icon: Mail,
    },
];

const { t } = useT();
const page = usePage();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain :items="masterNavItems" label="Master data" />
            <NavMain :items="inventoryNavItems" label="Inventory" />
            <NavMain :items="purchasingNavItems" label="Purchasing" />
            <NavMain :items="salesNavItems" label="Sales" />
            <NavMain :items="productionNavItems" label="Production" />
            <NavMain :items="reportsNavItems" label="Reports" />
            <NavMain :items="financeNavItems" label="Finance" />
            <NavMain :items="payablesNavItems" label="Accounts payable" />
            <NavMain :items="receivablesNavItems" label="Accounts receivable" />
            <NavMain :items="cashNavItems" label="Cash &amp; bank" />
            <NavMain :items="payrollNavItems" label="Payroll" />
            <NavMain :items="administrationNavItems" label="Administration" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
            <p
                class="text-muted-foreground px-2 text-xs group-data-[collapsible=icon]:hidden"
            >
                {{ t('Version :version', { version: page.props.appVersion }) }}
            </p>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
