<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/stock-damages';
import type { Paginated, StockDamageRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    damages: Paginated<StockDamageRow>;
    filters: { product_id: number | null };
    products: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Damaged stock', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
watch(productId, () => {
    router.get(index().url, { product_id: productId.value || undefined }, { preserveState: true, replace: true });
});
</script>

<template>
    <Head :title="t('Damaged stock')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Damaged stock" description="Stock thrown away because it was damaged — torn bags, spoiled coconuts. Each write-off takes the stock out and records its value as a loss." />
            <Button v-if="can('inventory.damage_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Write off damaged stock') }}</Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SearchableSelect
                v-model="productId"
                :options="[{ value: '', label: t('All products') }, ...products.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
            />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Write-off') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Products') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Reason') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Value lost (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="damage in damages.data" :key="damage.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="show(damage.id)" class="font-mono hover:underline">{{ damage.damage_number }}</Link></td>
                        <td class="px-4 py-3">{{ damage.damage_date }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ damage.products.join(', ') }}</td>
                        <td class="px-4 py-3">{{ damage.reason }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(damage.total_cost) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="damage.is_reversed ? 'destructive' : 'default'">{{ damage.status_label }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="damages.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No damaged stock written off yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="damages.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in damages.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
