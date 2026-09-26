<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Pencil } from '@lucide/vue';
import HuskChippingActionController from '@/actions/App/Http/Controllers/Production/HuskChippingActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/husk-chipping';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import { index as lotsIndex } from '@/routes/inventory/lots';
import type { HuskChippingDetail } from '@/types';

const props = defineProps<{ order: HuskChippingDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Husk chipping', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

/** Grades this batch actually produced — the only ones that got a lot and a cost. */
const produced = () => props.order.outputs.filter((o) => Number(o.quantity) > 0);

const skipped = () => props.order.outputs.filter((o) => Number(o.quantity) <= 0);
</script>

<template>
    <Head :title="order.production_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="order.production_number"
                    :description="`Husk chipping · ${order.production_date} · ${order.warehouse_code}`"
                />
                <Badge :variant="badgeVariant(order.status)" class="w-fit">
                    {{ order.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="order.can_edit && can('production.create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${order.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <Form
                    v-if="order.can_post && can('production.post')"
                    v-bind="HuskChippingActionController.post.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">Post production</Button>
                </Form>

                <Form
                    v-if="order.can_reverse && can('production.reverse')"
                    v-bind="HuskChippingActionController.reverse.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Reverse
                    </Button>
                </Form>

                <Form
                    v-if="order.can_cancel && can('production.cancel')"
                    v-bind="HuskChippingActionController.cancel.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Cancel draft
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="order.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            This production run is a <strong>draft</strong> — no husk has been consumed and no
            chip stock exists. The weights below are what the operator measured; costs appear
            once it is posted, because the inventory cost is determined by the ledger at that
            moment.
        </div>

        <!-- Value conservation -->
        <div class="grid gap-3 sm:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Husk consumed</p>
                <p class="mt-1 font-mono text-lg">
                    {{ order.input_quantity }}
                    <span class="text-muted-foreground text-sm">{{ order.input?.uom }}</span>
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Chips produced</p>
                <p class="mt-1 font-mono text-lg">
                    {{ order.output_quantity }}
                    <span class="text-muted-foreground text-sm">KG</span>
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Input value</p>
                <p class="mt-1 font-mono text-lg">{{ order.input_value ?? '—' }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Output value</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ order.output_value ?? '—' }}</p>
            </div>
        </div>

        <!-- Input -->
        <div
            v-if="order.input"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">Input</h3>
            <dl class="mt-4 grid gap-6 sm:grid-cols-5">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Product</dt>
                    <dd class="mt-1 font-mono text-sm">{{ order.input.product_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Lot consumed</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="lotsIndex().url" class="hover:underline">
                            {{ order.input.lot_number ?? '—' }}
                        </Link>
                        <template v-else>{{ order.input.lot_number ?? '—' }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Quantity</dt>
                    <dd class="mt-1 font-mono text-sm">{{ order.input.quantity }} {{ order.input.uom }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Weighted avg cost</dt>
                    <dd class="mt-1 font-mono text-sm">{{ order.input.unit_cost ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Value consumed</dt>
                    <dd class="mt-1 font-mono text-sm font-medium">{{ order.input.total_cost ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <!-- Outputs -->
        <div
            v-if="order.outputs.length"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Grade</th>
                        <th class="px-4 py-3 font-medium">Lot produced</th>
                        <th class="px-4 py-3 text-right font-medium">Measured</th>
                        <th class="px-4 py-3 font-medium">UOM</th>
                        <th class="px-4 py-3 text-right font-medium">Cost share</th>
                        <th class="px-4 py-3 text-right font-medium">Allocated cost</th>
                        <th class="px-4 py-3 text-right font-medium">Unit cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="output in order.outputs"
                        :key="output.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        :class="{ 'text-muted-foreground': Number(output.quantity) <= 0 }"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ output.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ output.product_name }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link v-if="output.lot_number && can('inventory.view')" :href="lotsIndex().url" class="hover:underline">
                                {{ output.lot_number }}
                            </Link>
                            <template v-else-if="output.lot_number">{{ output.lot_number }}</template>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ output.quantity }}</td>
                        <td class="text-muted-foreground px-4 py-3">{{ output.uom }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(output.allocation_percent).toFixed(2) }}%
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ output.allocated_cost ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ output.unit_cost ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="order.status === 'POSTED' && skipped().length" class="text-muted-foreground max-w-3xl text-xs">
            This batch produced no
            <span v-for="(o, i) in skipped()" :key="o.id" class="font-mono">
                {{ o.product_code }}<span v-if="i < skipped().length - 1">, </span>
            </span>
            — so it received no cost and no lot was created for it. Its configured share was
            spread over the grades that were produced, which is why their shares above do not
            match the configured 30 / 30 / 40.
        </p>

        <!-- Waste -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <h3 class="text-sm font-medium">Waste</h3>
            <p class="mt-2 font-mono text-sm">
                {{ order.waste_quantity ? `${order.waste_quantity} ${order.waste_uom ?? ''}` : 'Not recorded' }}
            </p>
            <p class="text-muted-foreground mt-3 max-w-3xl text-xs">
                Waste is information only. It creates no stock, no lot and no product, and it
                carries no cost — the whole consumed husk value stays with the saleable
                grades. If the factory later starts selling dust or another by-product, it can
                be promoted to a real inventory product then.
            </p>
        </div>

        <!-- Traceability -->
        <div
            v-if="order.input?.lot_number && produced().length"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">Traceability</h3>
            <div class="text-muted-foreground mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-mono">{{ order.input.lot_number }}</span>
                <ArrowRight class="size-3" />
                <span class="font-mono">{{ order.production_number }}</span>
                <ArrowRight class="size-3" />
                <span v-for="(output, i) in produced()" :key="output.id" class="font-mono">
                    {{ output.lot_number }}<span v-if="i < produced().length - 1">,</span>
                </span>
            </div>
            <p class="text-muted-foreground mt-3 text-xs">
                Each chip lot records this production run as its source, and this run records
                the husk lot it consumed — so stock can be traced in either direction.
            </p>
        </div>

        <!-- Ledger references -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div v-if="order.consumption_reference">
                    <dt class="text-muted-foreground text-sm font-medium">Consumption transaction</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ order.consumption_reference }}
                        </Link>
                        <template v-else>{{ order.consumption_reference }}</template>
                    </dd>
                </div>
                <div v-if="order.output_reference">
                    <dt class="text-muted-foreground text-sm font-medium">Output transaction</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ order.output_reference }}
                        </Link>
                        <template v-else>{{ order.output_reference }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Posted</dt>
                    <dd class="mt-1 text-sm">
                        {{ order.posted_at ? `${order.posted_at} by ${order.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div v-if="order.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ order.notes }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Husk is counted in NOS and chips are weighed in KG, so the quantities above are
            not comparable — and nothing in the system converts one into the other. What is
            conserved is value: the allocated chip costs sum exactly to the husk value
            consumed. No labour, electricity or overhead cost is added; that comes in a later
            costing phase.
        </p>
    </div>
</template>
