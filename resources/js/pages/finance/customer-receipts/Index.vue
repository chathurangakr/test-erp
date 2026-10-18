<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/customer-receipts';
import type { OptionRef, Paginated, StatusOption, CustomerReceiptRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    receipts: Paginated<CustomerReceiptRow>;
    filters: { search: string; status: string; customer_id: number | null };
    statuses: StatusOption[];
    customers: OptionRef[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer receipts', href: index() }] } });

const { can } = usePermissions();

const status = ref(props.filters.status ?? '');
const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');

function applyFilters(): void {
    router.get(
        index().url,
        { status: status.value || undefined, customer_id: customerId.value || undefined },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([status, customerId], applyFilters);

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}


const { t } = useT();
</script>

<template>
    <Head :title="t('Customer receipts')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Customer receipts"
                :description="t('Money received from customers, in :currency. Every receipt is allocated to invoices.', { currency })"
            />
            <Button v-if="can('finance.ar.receipt_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New receipt') }}</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <label for="f-customer" class="text-sm font-medium">{{ t('Customer') }}</label>
                <SearchableSelect
                    id="f-customer"
                    v-model="customerId"
                    :options="[{ value: '', label: t('All customers') }, ...(customers).map((s) => ({ value: String(s.id), label: `${s.code} — ${s.name}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">{{ t('Status') }}</label>
                <SearchableSelect
                    id="f-status"
                    v-model="status"
                    :options="[{ value: '', label: t('All statuses') }, ...(statuses).map((s) => ({ value: s.value, label: `${(s.label) ?? ''}` }))]"
                />
            </div>
        </div>

        <div
            v-if="receipts.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No customer receipts found') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Receipt No') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Received into') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Reference') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in receipts.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                {{ row.receipt_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.customer_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.receipt_date }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.receipt_account }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.reference ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="receipts.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-muted-foreground text-sm">
                {{ t('Showing :from–:to of :total', { from: receipts.from ?? 0, to: receipts.to ?? 0, total: receipts.total }) }}
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    v-for="link in receipts.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                    v-html="link.label"
                />
            </div>
        </div>
    </div>
</template>
