<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import GoodsReceiptActionController from '@/actions/App/Http/Controllers/Purchasing/GoodsReceiptActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/goods-receipts';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import type { GoodsReceiptDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ receipt: GoodsReceiptDetail }>();

const { t } = useT();

defineOptions({ layout: { breadcrumbs: [{ title: 'Goods receipts', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="receipt.grn_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="receipt.grn_number"
                    :description="`${receipt.supplier_code} — ${receipt.supplier_name}`"
                />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="badgeVariant(receipt.status)">{{ receipt.status_label }}</Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="receipt.can_edit && can('purchasing.grn_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${receipt.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="receipt.can_post && can('purchasing.grn_post')"
                    v-bind="GoodsReceiptActionController.post.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">
                        {{ t('Post to inventory') }}
                    </Button>
                </Form>

                <Form
                    v-if="receipt.can_reverse && can('purchasing.grn_reverse')"
                    v-bind="GoodsReceiptActionController.reverse.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Reverse') }}
                    </Button>
                </Form>

                <Form
                    v-if="receipt.can_cancel && can('purchasing.grn_cancel')"
                    v-bind="GoodsReceiptActionController.cancel.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Cancel draft') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="receipt.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This receipt is a draft — it has not changed stock. The costs below are a preview of what posting would record.') }}
        </div>

        <!-- Totals -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total quantity') }}</p>
                <p class="mt-1 font-mono text-lg">{{ receipt.total_quantity }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Material value') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(receipt.material_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Landed costs') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(receipt.landed_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total purchase cost') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(receipt.grand_total) }}</p>
            </div>
        </div>

        <!-- Header -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Warehouse') }}</dt>
                    <dd class="mt-1 text-sm">{{ receipt.warehouse_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Receipt date') }}</dt>
                    <dd class="mt-1 text-sm">{{ receipt.receipt_date }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Purchase order') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ receipt.po_number ?? t('Direct purchase') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Delivery reference') }}</dt>
                    <dd class="mt-1 text-sm">{{ receipt.supplier_delivery_reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Transport / Loading / Other') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ money(receipt.transport_cost) }} / {{ money(receipt.loading_cost) }} / {{ money(receipt.other_cost) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Posted') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ receipt.posted_at ? t(':date by :user', { date: receipt.posted_at, user: receipt.posted_by ?? '—' }) : '—' }}
                    </dd>
                </div>
                <div v-if="receipt.inventory_reference">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Inventory transaction') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ receipt.inventory_reference }}
                        </Link>
                        <template v-else>{{ receipt.inventory_reference }}</template>
                    </dd>
                </div>
                <div v-if="receipt.reversal_reference">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversal transaction') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ receipt.reversal_reference }}</dd>
                </div>
                <div v-if="receipt.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ receipt.notes }}</dd>
                </div>
            </dl>
        </div>

        <!-- Lines -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-3 py-3 font-medium">{{ t('UOM') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Secondary') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Unit price') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Material') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Landed share') }}</th>
                        <th class="px-3 py-3 text-right font-medium">{{ t('Final unit cost') }}</th>
                        <th class="px-3 py-3 font-medium">{{ t('Lot') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in receipt.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-3 py-3">
                            <span class="font-mono text-xs">{{ line.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.product_name }}</span>
                        </td>
                        <td class="text-muted-foreground px-3 py-3">{{ line.uom }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.quantity }}</td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ line.secondary_quantity ? `${line.secondary_quantity} ${line.secondary_uom ?? ''}` : '—' }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">{{ money(line.unit_price) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ money(line.material_cost) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ money(line.allocated_landed_cost) }}</td>
                        <td class="px-3 py-3 text-right font-mono font-medium">{{ money(line.final_unit_cost) }}</td>
                        <td class="px-3 py-3">
                            <span class="font-mono text-xs">{{ line.lot_number ?? '—' }}</span>
                            <span v-if="line.supplier_lot_number" class="text-muted-foreground block text-xs">
                                {{ t('supplier: :lot', { lot: line.supplier_lot_number }) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Landed costs are allocated across lines in proportion to material value. The final unit cost is what was posted to inventory — it is calculated by the system and can never be typed in. A posted receipt is corrected by reversal, never by editing.') }}
        </p>
    </div>
</template>
