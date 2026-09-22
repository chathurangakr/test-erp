<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Boxes,
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
            <NavMain :items="financeNavItems" label="Finance" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
