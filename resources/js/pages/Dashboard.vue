<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ClipboardList,
    Factory,
    FileText,
    Gauge,
    Grid2x2,
    HandCoins,
    Receipt,
    ReceiptText,
    ShoppingCart,
    Sun,
    Tablet,
    Truck,
    UserCog,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { create as newCopra } from '@/routes/copra';
import { create as newDehusking } from '@/routes/dehusking';
import { create as newDelivery } from '@/routes/deliveries';
import { create as newExpenseVoucher } from '@/routes/expense-vouchers';
import { index as financeDashboard } from '@/routes/finance-dashboard';
import { create as newGoodsReceipt } from '@/routes/goods-receipts';
import { create as newHuskChipping } from '@/routes/husk-chipping';
import { index as stockOnHand } from '@/routes/inventory/stock';
import { home as kioskHome } from '@/routes/kiosk';
import { create as newPurchaseOrder } from '@/routes/purchase-orders';
import { create as newSalesInvoice } from '@/routes/sales-invoices';
import { create as newSalesOrder } from '@/routes/sales-orders';
import { create as newSupplierInvoice } from '@/routes/supplier-invoices';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Shortcut = {
    title: string;
    description: string;
    href: ReturnType<typeof dashboard>;
    icon: Component;
    /** Shown when the user holds at least one of these. Hiding is a courtesy; routes enforce. */
    permissions: string[];
};

const { t } = useT();
const { canAny } = usePermissions();
const page = usePage();

const userName = computed(() => page.props.auth?.user?.name ?? '');

const shortcuts: Shortcut[] = [
    {
        title: 'Receive goods',
        description: 'Record goods arriving from a supplier',
        href: newGoodsReceipt(),
        icon: ShoppingCart,
        permissions: ['purchasing.grn_create'],
    },
    {
        title: 'Dispatch goods',
        description: 'Record goods leaving for a customer',
        href: newDelivery(),
        icon: Truck,
        permissions: ['sales.delivery_create'],
    },
    {
        title: 'Dehusking',
        description: 'Coconuts into dehusked coconuts and husk',
        href: newDehusking(),
        icon: Factory,
        permissions: ['production.create'],
    },
    {
        title: 'Husk Chipping',
        description: 'Record the chips and cocopeat made',
        href: newHuskChipping(),
        icon: Grid2x2,
        permissions: ['production.create'],
    },
    {
        title: 'Copra Production',
        description: 'Record the copra and shell made',
        href: newCopra(),
        icon: Sun,
        permissions: ['production.create'],
    },
    {
        title: 'Purchase order',
        description: 'Order goods from a supplier',
        href: newPurchaseOrder(),
        icon: ClipboardList,
        permissions: ['purchasing.order_create'],
    },
    {
        title: 'Sales order',
        description: "Record a customer's order",
        href: newSalesOrder(),
        icon: Receipt,
        permissions: ['sales.order_create'],
    },
    {
        title: 'Bill a customer',
        description: 'Make a sales invoice from deliveries',
        href: newSalesInvoice(),
        icon: ReceiptText,
        permissions: ['finance.ar.invoice_create'],
    },
    {
        title: 'Enter a supplier bill',
        description: 'Record what a supplier has billed',
        href: newSupplierInvoice(),
        icon: FileText,
        permissions: ['finance.ap.invoice_create'],
    },
    {
        title: 'Pay an expense',
        description: 'An expense paid in cash or by bank',
        href: newExpenseVoucher(),
        icon: HandCoins,
        permissions: ['finance.cash.expense_create'],
    },
    {
        title: 'Stock on Hand',
        description: 'What is in each warehouse now',
        href: stockOnHand(),
        icon: Boxes,
        permissions: ['inventory.view'],
    },
    {
        title: 'Finance Dashboard',
        description: 'Profit, cash and what is owed',
        href: financeDashboard(),
        icon: Gauge,
        permissions: ['reports.management'],
    },
    {
        title: 'Simple mode',
        description: 'Big buttons for factory work',
        href: kioskHome(),
        icon: Tablet,
        permissions: [
            'purchasing.grn_post',
            'sales.delivery_post',
            'production.post',
            'inventory.view',
        ],
    },
];

const visibleShortcuts = computed(() =>
    shortcuts.filter((shortcut) => canAny(...shortcut.permissions)),
);
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                {{ t('Welcome, :name', { name: userName }) }}
            </h1>
            <p
                v-if="visibleShortcuts.length > 0"
                class="text-muted-foreground mt-1 text-sm"
            >
                {{ t('What would you like to do?') }}
            </p>
        </div>

        <div
            v-if="visibleShortcuts.length > 0"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <Link
                v-for="shortcut in visibleShortcuts"
                :key="shortcut.title"
                :href="shortcut.href"
                class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/50 flex items-start gap-3 rounded-xl border p-4 transition-colors"
            >
                <component
                    :is="shortcut.icon"
                    class="text-muted-foreground mt-0.5 size-5 shrink-0"
                />
                <span>
                    <span class="block font-medium">{{
                        t(shortcut.title)
                    }}</span>
                    <span class="text-muted-foreground block text-sm">{{
                        t(shortcut.description)
                    }}</span>
                </span>
            </Link>
        </div>

        <div
            v-else
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-6 text-center"
        >
            <UserCog class="text-muted-foreground mx-auto size-6" />
            <p class="mt-2 font-medium">{{ t('You have no role yet') }}</p>
            <p class="text-muted-foreground mt-1 text-sm">
                {{
                    t(
                        'Ask the administrator to give you a role. Until then you can only change your own settings.',
                    )
                }}
            </p>
        </div>
    </div>
</template>
