<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BookOpen,
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
    FolderGit2,
    LayoutGrid,
    Layers,
    Package,
    Factory,
    Grid2x2,
    Sun,
    BookMarked,
    Calculator,
    CalendarRange,
    Scale,
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
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { index as financeDashboardIndex } from '@/routes/finance-dashboard';
import {
    balanceSheet as balanceSheetReport,
    cashBankPosition as cashBankPositionReport,
    inventoryGlReconciliation as inventoryGlReconciliationReport,
    inventoryValuation as inventoryValuationReport,
    productProfitability as productProfitabilityReport,
    profitLoss as profitLossReport,
} from '@/routes/reports';
import { index as productsIndex } from '@/routes/products';
import { index as suppliersIndex } from '@/routes/suppliers';
import { index as warehousesIndex } from '@/routes/warehouses';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const masterNavItems: NavItem[] = [
    {
        title: 'Products',
        href: productsIndex(),
        icon: Package,
    },
    {
        title: 'Suppliers',
        href: suppliersIndex(),
        icon: Truck,
    },
    {
        title: 'Customers',
        href: customersIndex(),
        icon: Users,
    },
    {
        title: 'Warehouses',
        href: warehousesIndex(),
        icon: Building2,
    },
];

const inventoryNavItems: NavItem[] = [
    {
        title: 'Stock on Hand',
        href: stockIndex(),
        icon: Boxes,
    },
    {
        title: 'Stock Ledger',
        href: ledgerIndex(),
        icon: ScrollText,
    },
    {
        title: 'Lots',
        href: lotsIndex(),
        icon: Layers,
    },
];

const purchasingNavItems: NavItem[] = [
    {
        title: 'Purchase Orders',
        href: purchaseOrdersIndex(),
        icon: ClipboardList,
    },
    {
        title: 'Goods Receipts',
        href: goodsReceiptsIndex(),
        icon: ShoppingCart,
    },
];

const salesNavItems: NavItem[] = [
    {
        title: 'Sales Orders',
        href: salesOrdersIndex(),
        icon: Receipt,
    },
    {
        title: 'Deliveries',
        href: deliveriesIndex(),
        icon: TruckIcon,
    },
];

const productionNavItems: NavItem[] = [
    {
        title: 'Dehusking',
        href: dehuskingIndex(),
        icon: Factory,
    },
    {
        title: 'Husk Chipping',
        href: huskChippingIndex(),
        icon: Grid2x2,
    },
    {
        title: 'Copra Production',
        href: copraIndex(),
        icon: Sun,
    },
];

const financeNavItems: NavItem[] = [
    {
        title: 'Chart of Accounts',
        href: accountsIndex(),
        icon: BookMarked,
    },
    {
        title: 'Journal Entries',
        href: journalsIndex(),
        icon: Calculator,
    },
    {
        title: 'General Ledger',
        href: generalLedgerIndex(),
        icon: Wallet,
    },
    {
        title: 'Trial Balance',
        href: trialBalanceIndex(),
        icon: Scale,
    },
    {
        title: 'Fiscal Periods',
        href: fiscalPeriodsIndex(),
        icon: CalendarRange,
    },
    {
        title: 'Accounting Setup',
        href: accountingSetupIndex(),
        icon: SlidersHorizontal,
    },
];

const payablesNavItems: NavItem[] = [
    {
        title: 'Supplier Invoices',
        href: supplierInvoicesIndex(),
        icon: FileText,
    },
    {
        title: 'Supplier Payments',
        href: supplierPaymentsIndex(),
        icon: Banknote,
    },
    {
        title: 'AP Aging',
        href: apAgingIndex(),
        icon: CalendarClock,
    },
    {
        title: 'GRNI Reconciliation',
        href: grniReconciliationIndex(),
        icon: FileCheck2,
    },
];

const receivablesNavItems: NavItem[] = [
    {
        title: 'Sales Invoices',
        href: salesInvoicesIndex(),
        icon: ReceiptText,
    },
    {
        title: 'Customer Receipts',
        href: customerReceiptsIndex(),
        icon: HandCoins,
    },
    {
        title: 'AR Aging',
        href: arAgingIndex(),
        icon: Hourglass,
    },
    {
        title: 'Unbilled Deliveries',
        href: unbilledDeliveriesIndex(),
        icon: PackageSearch,
    },
];

const reportsNavItems: NavItem[] = [
    {
        title: 'Finance Dashboard',
        href: financeDashboardIndex(),
        icon: Gauge,
    },
    {
        title: 'Profit & Loss',
        href: profitLossReport(),
        icon: TrendingUp,
    },
    {
        title: 'Balance Sheet',
        href: balanceSheetReport(),
        icon: Scale,
    },
    {
        title: 'Inventory Valuation',
        href: inventoryValuationReport(),
        icon: Boxes,
    },
    {
        title: 'Inventory vs GL',
        href: inventoryGlReconciliationReport(),
        icon: GitCompareArrows,
    },
    {
        title: 'Cash / Bank Position',
        href: cashBankPositionReport(),
        icon: Coins,
    },
    {
        title: 'Product Profitability',
        href: productProfitabilityReport(),
        icon: ChartColumn,
    },
];

const cashNavItems: NavItem[] = [
    {
        title: 'Cash / Bank Accounts',
        href: cashBankAccountsIndex(),
        icon: Landmark,
    },
    {
        title: 'Cash / Bank Book',
        href: cashBankBookIndex(),
        icon: BookOpenCheck,
    },
    {
        title: 'Expense Vouchers',
        href: expenseVouchersIndex(),
        icon: Receipt,
    },
    {
        title: 'Fund Transfers',
        href: fundTransfersIndex(),
        icon: ArrowLeftRight,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
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
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
