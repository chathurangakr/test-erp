<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { show as journalShow } from '@/routes/journals';
import { index, reverse } from '@/routes/stock-damages';
import type { StockDamageDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ damage: StockDamageDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Damaged stock', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function reverseDamage(): void {
    if (confirm(t('Reverse this write-off? The stock comes back at the value it left with.'))) {
        router.patch(reverse(props.damage.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="damage.damage_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="damage.damage_number" :description="`${damage.damage_date} · ${damage.warehouse ?? ''}`" />
                <Badge :variant="damage.is_reversed ? 'destructive' : 'default'" class="w-fit">{{ damage.status_label }}</Badge>
            </div>
            <Button v-if="damage.can_reverse && can('inventory.damage_reverse')" variant="outline" @click="reverseDamage">{{ t('Reverse') }}</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Reason') }}</p>
                <p class="mt-1 text-lg">{{ damage.reason }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Value lost (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(damage.total_cost) }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Lot') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Unit cost') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Value (:currency)', { currency }) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in damage.lines" :key="line.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3">
                            {{ line.product_name }}
                            <span class="text-muted-foreground block font-mono text-xs">{{ line.product_code }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ line.lot_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.quantity }} {{ line.uom }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.unit_cost) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.total_cost) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Written off') }}</dt>
                <dd class="mt-1 text-sm">{{ damage.posted_at ?? '—' }} · {{ damage.created_by ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Stock movement') }}</dt>
                <dd class="mt-1 font-mono text-sm">
                    {{ damage.inventory_reference ?? '—' }}
                    <span v-if="damage.reversal_reference"> · {{ damage.reversal_reference }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Journal') }}</dt>
                <dd class="mt-1 text-sm">
                    <Link v-if="damage.journal_id && can('finance.view')" :href="journalShow(damage.journal_id)" class="font-mono hover:underline">{{ damage.journal_number }}</Link>
                    <span v-else class="font-mono">{{ damage.journal_number ?? '—' }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">{{ damage.reversed_at ? `${damage.reversed_at} · ${damage.reversed_by ?? '—'}` : '—' }}</dd>
            </div>
            <div v-if="damage.notes" class="sm:col-span-2">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                <dd class="mt-1 text-sm whitespace-pre-line">{{ damage.notes }}</dd>
            </div>
        </dl>
    </div>
</template>
