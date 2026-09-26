<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import PurchaseOrderApprovalController from '@/actions/App/Http/Controllers/Purchasing/PurchaseOrderApprovalController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/purchase-orders';
import { index as grnIndex } from '@/routes/goods-receipts';
import type { PurchaseOrderDetail } from '@/types';

const props = defineProps<{ order: PurchaseOrderDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED') return 'destructive';
    if (value === 'RECEIVED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}
</script>

<template>
    <Head :title="order.po_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="order.po_number" :description="`${order.supplier_code} — ${order.supplier_name}`" />
                <Badge :variant="badgeVariant(order.status)" class="w-fit">
                    {{ order.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="order.can_edit && can('purchasing.order_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${order.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <Form
                    v-if="order.can_approve && can('purchasing.order_approve')"
                    v-bind="PurchaseOrderApprovalController.approve.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">Approve</Button>
                </Form>

                <Form
                    v-if="order.can_cancel && can('purchasing.order_cancel')"
                    v-bind="PurchaseOrderApprovalController.cancel.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Cancel order
                    </Button>
                </Form>
            </div>
        </div>

        <!-- Summary totals -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Ordered quantity</p>
                <p class="mt-1 font-mono text-lg">{{ order.ordered_quantity }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Received quantity</p>
                <p class="mt-1 font-mono text-lg">{{ order.received_quantity }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Remaining</p>
                <p class="mt-1 font-mono text-lg">{{ order.remaining_quantity }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Material value</p>
                <p class="mt-1 font-mono text-lg">{{ order.material_total }}</p>
            </div>
        </div>

        <!-- Header details -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Warehouse</dt>
                    <dd class="mt-1 text-sm">{{ order.warehouse_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Order date</dt>
                    <dd class="mt-1 text-sm">{{ order.order_date }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Expected date</dt>
                    <dd class="mt-1 text-sm">{{ order.expected_date ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Supplier reference</dt>
                    <dd class="mt-1 text-sm">{{ order.supplier_reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Approved by</dt>
                    <dd class="mt-1 text-sm">{{ order.approved_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Approved at</dt>
                    <dd class="mt-1 text-sm">{{ order.approved_at ?? '—' }}</dd>
                </div>
                <div v-if="order.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ order.notes }}</dd>
                </div>
            </dl>
        </div>

        <!-- Lines -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">UOM</th>
                        <th class="px-4 py-3 text-right font-medium">Ordered</th>
                        <th class="px-4 py-3 text-right font-medium">Received</th>
                        <th class="px-4 py-3 text-right font-medium">Outstanding</th>
                        <th class="px-4 py-3 text-right font-medium">Unit price</th>
                        <th class="px-4 py-3 text-right font-medium">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in order.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ line.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.product_name }}</span>
                        </td>
                        <td class="text-muted-foreground px-4 py-3">{{ line.uom }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.quantity }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.received_quantity }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.outstanding_quantity }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.unit_price }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.line_total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Linked receipts -->
        <div v-if="order.goods_receipts.length" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <h3 class="text-sm font-medium">Goods receipts</h3>
            <ul class="mt-3 space-y-2">
                <li
                    v-for="grn in order.goods_receipts"
                    :key="grn.id"
                    class="flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <Link :href="`${grnIndex().url}/${grn.id}`" class="font-mono text-xs hover:underline">
                        {{ grn.grn_number }}
                    </Link>
                    <span class="text-muted-foreground text-xs">{{ grn.receipt_date }}</span>
                    <Badge :variant="grn.status === 'POSTED' ? 'default' : 'secondary'">
                        {{ grn.status_label }}
                    </Badge>
                </li>
            </ul>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A purchase order never changes stock. Inventory moves only when a goods receipt
            against it is posted.
        </p>
    </div>
</template>
