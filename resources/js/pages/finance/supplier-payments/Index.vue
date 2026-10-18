<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/supplier-payments';
import type { OptionRef, Paginated, StatusOption, SupplierPaymentRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    payments: Paginated<SupplierPaymentRow>;
    filters: { search: string; status: string; supplier_id: number | null };
    statuses: StatusOption[];
    suppliers: OptionRef[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier payments', href: index() }] } });

const { can } = usePermissions();

const status = ref(props.filters.status ?? '');
const supplierId = ref(props.filters.supplier_id ? String(props.filters.supplier_id) : '');

function applyFilters(): void {
    router.get(
        index().url,
        { status: status.value || undefined, supplier_id: supplierId.value || undefined },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([status, supplierId], applyFilters);

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('Supplier payments')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Supplier payments"
                :description="t('Money paid to suppliers, in :currency. Every payment is allocated to invoices.', { currency })"
            />
            <Button v-if="can('finance.ap.payment_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New payment') }}</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <label for="f-supplier" class="text-sm font-medium">{{ t('Supplier') }}</label>
                <select id="f-supplier" v-model="supplierId" :class="controlClass">
                    <option value="">{{ t('All suppliers') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.code }} — {{ s.name }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">{{ t('Status') }}</label>
                <select id="f-status" v-model="status" :class="controlClass">
                    <option value="">{{ t('All statuses') }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
        </div>

        <div
            v-if="payments.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No supplier payments found') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Payment No') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Supplier') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Paid from') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Reference') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in payments.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                {{ row.payment_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.supplier_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.supplier_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.payment_date }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.payment_account }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.reference ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="payments.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-muted-foreground text-sm">
                {{ t('Showing :from–:to of :total', { from: payments.from ?? 0, to: payments.to ?? 0, total: payments.total }) }}
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    v-for="link in payments.links"
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
