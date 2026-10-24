<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index, reverse } from '@/routes/customer-bags';
import { show as journalShow } from '@/routes/journals';
import type { CustomerBagDetail } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{ movement: CustomerBagDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer bags', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function reverseMovement(): void {
    if (confirm(t('Reverse this record? The bags move back to where they were.'))) {
        router.patch(reverse(props.movement.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="movement.movement_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="movement.movement_number" :description="`${movement.movement_date} · ${movement.kind_label}`" />
                <Badge :variant="movement.is_reversed ? 'destructive' : 'default'" class="w-fit">{{ movement.status_label }}</Badge>
            </div>
            <Button v-if="movement.can_reverse && can('sales.bags_reverse')" variant="outline" @click="reverseMovement">{{ t('Reverse') }}</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Customer') }}</p>
                <p class="mt-1 text-lg">{{ movement.customer_name }}</p>
                <span class="text-muted-foreground font-mono text-xs">{{ movement.customer_code }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ movement.product_name }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ Number(movement.quantity) }}</p>
                <span v-if="movement.warehouse" class="text-muted-foreground text-xs">{{ movement.warehouse }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('This customer holds now') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ Number(movement.held_now) }}</p>
            </div>
        </div>

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Recorded') }}</dt>
                <dd class="mt-1 text-sm">{{ movement.posted_at ?? '—' }} · {{ movement.created_by ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Stock movement') }}</dt>
                <dd class="mt-1 font-mono text-sm">
                    {{ movement.inventory_reference ?? '—' }}
                    <span v-if="movement.reversal_reference"> · {{ movement.reversal_reference }}</span>
                </dd>
            </div>
            <div v-if="movement.journal_number">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Journal') }}</dt>
                <dd class="mt-1 text-sm">
                    <Link v-if="movement.journal_id && can('finance.view')" :href="journalShow(movement.journal_id)" class="font-mono hover:underline">{{ movement.journal_number }}</Link>
                    <span v-else class="font-mono">{{ movement.journal_number }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">{{ movement.reversed_at ? `${movement.reversed_at} · ${movement.reversed_by ?? '—'}` : '—' }}</dd>
            </div>
            <div v-if="movement.note" class="sm:col-span-2">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Note') }}</dt>
                <dd class="mt-1 text-sm">{{ movement.note }}</dd>
            </div>
        </dl>
    </div>
</template>
