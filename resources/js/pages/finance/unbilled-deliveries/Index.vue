<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index } from '@/routes/unbilled-deliveries';
import type { UnbilledDeliveryReport } from '@/types';

defineProps<{
    report: UnbilledDeliveryReport;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Unbilled deliveries', href: index() }] } });

function badgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'FULLY_BILLED') return 'secondary';
    if (status === 'PARTIALLY_BILLED') return 'default';
    return 'outline';
}

function label(status: string): string {
    return { UNBILLED: 'Unbilled', PARTIALLY_BILLED: 'Partly billed', FULLY_BILLED: 'Fully billed' }[status] ?? status;
}
</script>

<template>
    <Head title="Unbilled deliveries" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Unbilled deliveries"
            :description="`Goods delivered but not yet invoiced, in ${currency}.`"
        />

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Delivered value</p>
                <p class="mt-1 font-mono text-lg">{{ report.value_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Invoiced</p>
                <p class="mt-1 font-mono text-lg">{{ report.billed_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Still unbilled</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ report.remaining_total }}</p>
            </div>
        </div>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">Nothing to reconcile</p>
            <p class="text-muted-foreground text-sm">
                No delivery has reached the ledger yet — deliveries before the accounting cutover
                never do.
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Delivery</th>
                        <th class="px-4 py-3 font-medium">Customer</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 text-right font-medium">Sales value</th>
                        <th class="px-4 py-3 text-right font-medium">Invoiced</th>
                        <th class="px-4 py-3 text-right font-medium">Remaining</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.delivery_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${deliveriesIndex().url}/${row.delivery_id}`" class="hover:underline">
                                {{ row.delivery_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.customer_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.delivery_date }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.sales_value }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.billed_amount }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ row.remaining_amount }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ label(row.status) }}</Badge>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium" colspan="3">Totals</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.value_total }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.billed_total }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.remaining_total }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A delivery records what the goods cost when they leave; the revenue waits for the
            invoice. An amount still unbilled here is work done but not yet charged for, not an
            error. The delivered value shown is the figure the delivery itself stored, which is why
            a line priced per measured kilogram carries through exactly — nothing is repriced. A
            delivery appears here only once its cost of sales has reached the ledger.
        </p>
    </div>
</template>
