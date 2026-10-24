<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/sales-returns';
import type { Paginated, SalesReturnRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    returns: Paginated<SalesReturnRow>;
    filters: { customer_id: number | null };
    customers: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Returns', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');
watch(customerId, () => {
    router.get(index().url, { customer_id: customerId.value || undefined }, { preserveState: true, replace: true });
});
</script>

<template>
    <Head :title="t('Returns')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Returns" description="Goods customers brought back. They are credited at the price charged; good goods go back into stock and damaged ones are written off." />
            <Button v-if="can('sales.return_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Record a return') }}</Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SearchableSelect
                v-model="customerId"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
            />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Return') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Credit (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Settled by') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in returns.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(row.id)" class="font-mono hover:underline">{{ row.return_number }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ row.delivery_number }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.return_date }}</td>
                        <td class="px-4 py-3">{{ row.customer_name }}</td>
                        <td class="px-4 py-3">{{ row.product_name }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ Number(row.quantity) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.credit_amount) }}</td>
                        <td class="px-4 py-3">{{ row.settlement_label }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.is_reversed ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="returns.data.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">{{ t('No returns recorded yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="returns.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in returns.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
