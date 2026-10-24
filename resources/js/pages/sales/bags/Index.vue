<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PackageMinus, PackagePlus, PackageX } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/customer-bags';
import type { CustomerBagHolding, CustomerBagRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    holdings: CustomerBagHolding[];
    movements: Paginated<CustomerBagRow>;
    filters: { customer_id: number | null };
    customers: { id: number; code: string; name: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer bags', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');
watch(customerId, () => {
    router.get(index().url, { customer_id: customerId.value || undefined }, { preserveState: true, replace: true });
});

const kindVariant = (row: CustomerBagRow) => (row.kind === 'GIVEN' ? 'default' : row.kind === 'RETURNED' ? 'secondary' : 'destructive');
</script>

<template>
    <Head :title="t('Customer bags')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <Heading title="Customer bags" description="Bags given free with the goods stay yours until they come back. This shows who holds how many." />
            <div v-if="can('sales.bags_record')" class="flex flex-wrap gap-2">
                <Button as-child><Link :href="create({ query: { kind: 'GIVEN' } })"><PackagePlus class="size-4" />{{ t('Give bags') }}</Link></Button>
                <Button variant="outline" as-child><Link :href="create({ query: { kind: 'RETURNED' } })"><PackageMinus class="size-4" />{{ t('Bags returned') }}</Link></Button>
                <Button variant="outline" as-child><Link :href="create({ query: { kind: 'LOST' } })"><PackageX class="size-4" />{{ t('Mark bags lost') }}</Link></Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SearchableSelect
                v-model="customerId"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
            />
        </div>

        <section class="space-y-2">
            <h2 class="text-sm font-medium">{{ t('Bags with customers now') }}</h2>
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Item') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Holding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in holdings" :key="`${row.customer_id}-${row.product_code}`" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3">
                                {{ row.customer_name }}
                                <span class="text-muted-foreground block font-mono text-xs">{{ row.customer_code }}</span>
                            </td>
                            <td class="px-4 py-3">{{ row.product_name }}</td>
                            <td class="px-4 py-3 text-right font-mono text-base font-medium">{{ Number(row.quantity) }}</td>
                        </tr>
                        <tr v-if="holdings.length === 0">
                            <td colspan="3" class="text-muted-foreground px-4 py-8 text-center">{{ t('No customer is holding bags.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-sm font-medium">{{ t('Bag records') }}</h2>
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Record') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('What happened') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Bags') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in movements.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3"><Link :href="show(row.id)" class="font-mono hover:underline">{{ row.movement_number }}</Link></td>
                            <td class="px-4 py-3">{{ row.movement_date }}</td>
                            <td class="px-4 py-3">{{ row.customer_name }}</td>
                            <td class="px-4 py-3"><Badge :variant="kindVariant(row)">{{ row.kind_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ Number(row.quantity) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.is_reversed ? 'destructive' : 'outline'">{{ row.status_label }}</Badge>
                            </td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No bags recorded yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="movements.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in movements.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
