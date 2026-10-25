<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import type { Paginated } from '@/types';
import type { AdvanceRow } from './types';

defineProps<{
    title: string;
    description: string;
    partyLabel: string;
    newLabel: string;
    canRecord: boolean;
    advances: Paginated<AdvanceRow>;
    currency: string;
    createHref: string;
    showHref: (id: number) => string;
}>();

const { t } = useT();
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="title" :description="description" />
            <Button v-if="canRecord" as-child>
                <Link :href="createHref"><Plus class="size-4" />{{ t(newLabel) }}</Link>
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Advance') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t(partyLabel) }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Not yet applied') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in advances.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="showHref(row.id)" class="font-mono hover:underline">{{ row.advance_number }}</Link></td>
                        <td class="px-4 py-3">{{ row.advance_date }}</td>
                        <td class="px-4 py-3">{{ row.party }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.amount) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.remaining) }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.status === 'REVERSED' ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="advances.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No advances yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="advances.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in advances.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
