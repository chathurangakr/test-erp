<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { show as deliveryShow } from '@/routes/deliveries';
import { productProfitability, profitLoss } from '@/routes/reports';
import type { OptionRef, ProductProfitabilityReport } from '@/types';

const props = defineProps<{
    report: ProductProfitabilityReport;
    deliveries: {
        id: number;
        delivery_number: string;
        delivery_date: string | null;
        customer_code: string | null;
        customer_name: string | null;
    }[];
    products: OptionRef[];
    customers: OptionRef[];
    filters: { from: string | null; to: string | null; product_id: number | null; customer_id: number | null };
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Operational product profitability', href: productProfitability() }] },
});

const { t } = useT();

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');

watch([from, to, productId, customerId], () => {
    router.get(
        productProfitability().url,
        {
            from: from.value || undefined,
            to: to.value || undefined,
            product_id: productId.value || undefined,
            customer_id: customerId.value || undefined,
        },
        { preserveState: false, replace: true },
    );
});

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Operational product profitability')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Operational product profitability"
            description="Was each physical sale profitable? Built from posted deliveries — not the financial P&L."
        />

        <section class="grid gap-4 sm:grid-cols-4">
            <div class="grid gap-2">
                <Label for="from">{{ t('From') }}</Label>
                <Input id="from" v-model="from" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="to">{{ t('To') }}</Label>
                <Input id="to" v-model="to" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="product">{{ t('Product') }}</Label>
                <select id="product" v-model="productId" :class="selectClass">
                    <option value="">{{ t('All products') }}</option>
                    <option v-for="p in products" :key="p.id" :value="String(p.id)">
                        {{ p.code }} — {{ p.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="customer">{{ t('Customer') }}</Label>
                <select id="customer" v-model="customerId" :class="selectClass">
                    <option value="">{{ t('All customers') }}</option>
                    <option v-for="c in customers" :key="c.id" :value="String(c.id)">
                        {{ c.code }} — {{ c.name }}
                    </option>
                </select>
            </div>
        </section>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Sales value (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ report.sales_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Cost of goods sold') }}</p>
                <p class="mt-1 font-mono text-lg">{{ report.cogs_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Gross profit') }}</p>
                <p
                    class="mt-1 font-mono text-lg font-medium"
                    :class="Number(report.gross_profit_total) < 0 ? 'text-destructive' : ''"
                >
                    {{ report.gross_profit_total }}
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Margin · deliveries') }}</p>
                <p class="mt-1 font-mono text-lg">
                    {{ report.gross_margin_percent }}%
                    <span class="text-muted-foreground text-sm">· {{ report.delivery_count }}</span>
                </p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Delivered') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Secondary') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Sales value') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('COGS') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Gross profit') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Margin') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Deliveries') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.product_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    productProfitability({
                                        query: {
                                            product_id: row.product_id,
                                            from: filters.from ?? undefined,
                                            to: filters.to ?? undefined,
                                            customer_id: filters.customer_id ?? undefined,
                                        },
                                    })
                                "
                                class="hover:underline"
                            >
                                <span class="font-mono text-xs font-medium">{{ row.product_code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ row.product_name }}</span>
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ row.quantity }} <span class="text-muted-foreground text-xs">{{ row.primary_uom }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            <template v-if="row.secondary_quantity !== null">
                                {{ row.secondary_quantity }}
                                <span class="text-muted-foreground text-xs">{{ row.secondary_uom }}</span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.sales_value }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.cost_of_goods_sold }}</td>
                        <td
                            class="px-4 py-3 text-right font-mono font-medium"
                            :class="Number(row.gross_profit) < 0 ? 'text-destructive' : ''"
                        >
                            {{ row.gross_profit }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.gross_margin_percent }}%</td>
                        <td class="px-4 py-3 text-right">{{ row.delivery_count }}</td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">
                            {{ t('No posted deliveries match these filters.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="filters.product_id"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Deliveries behind these figures') }}</h3>
                <p class="text-muted-foreground text-xs">{{ t('The 50 most recent posted deliveries of this product.') }}</p>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="d in deliveries"
                        :key="d.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-2">
                            <Link :href="deliveryShow(d.id)" class="font-mono text-xs hover:underline">
                                {{ d.delivery_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">{{ d.delivery_date }}</td>
                        <td class="px-4 py-2">{{ d.customer_code }} — {{ d.customer_name }}</td>
                    </tr>
                    <tr v-if="deliveries.length === 0">
                        <td colspan="3" class="text-muted-foreground px-4 py-6 text-center">{{ t('No deliveries.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('This is operational profitability: each posted delivery\'s own sales value less the cost of the stock that left, exactly as the delivery recorded them. It answers "was this physical sale profitable?".') }}
            <Link :href="profitLoss()" class="underline">{{ t('Profit & loss') }}</Link>
            {{ t('answers a different question — what revenue has been recognised through sales invoices — so the two can legitimately differ while a delivery is unbilled or partly billed. Products sold by weight use the delivery\'s stored value; nothing is converted between count and weight. Reversed deliveries are excluded.') }}
        </p>
    </div>
</template>
