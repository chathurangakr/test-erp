<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/fund-transfers';
import type { FundTransferRow, Paginated } from '@/types';

const props = defineProps<{
    transfers: Paginated<FundTransferRow>;
    filters: { search: string; status: string };
    statuses: { value: string; label: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Fund transfers', href: index() }] } });

const { can } = usePermissions();

const search = ref(props.filters.search);
const status = ref(props.filters.status);

watch([search, status], () => {
    router.get(
        index().url,
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, replace: true },
    );
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Fund transfers" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                title="Fund transfers"
                description="Money moved between the factory's own cash and bank accounts."
            />
            <Button v-if="can('finance.cash.transfer_create')" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New transfer
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Input v-model="search" placeholder="Search number or reference" />
            <select v-model="status" :class="selectClass">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Transfer</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">From</th>
                        <th class="px-4 py-3 font-medium">To</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Amount ({{ currency }})</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="transfer in transfers.data"
                        :key="transfer.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="show(transfer.id)" class="font-mono text-xs font-medium hover:underline">
                                {{ transfer.transfer_number }}
                            </Link>
                            <span v-if="transfer.reference" class="text-muted-foreground block text-xs">
                                {{ transfer.reference }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ transfer.transfer_date }}</td>
                        <td class="px-4 py-3">{{ transfer.from_name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ transfer.to_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(transfer.status)">{{ transfer.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ transfer.amount }}</td>
                    </tr>
                    <tr v-if="transfers.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">No fund transfers yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A transfer moves money between accounts the factory already owns. Nothing is earned,
            spent or owed, and the combined cash-and-bank total is identical before and after.
        </p>
    </div>
</template>
