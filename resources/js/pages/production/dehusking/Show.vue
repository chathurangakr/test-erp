<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Pencil } from '@lucide/vue';
import DehuskingActionController from '@/actions/App/Http/Controllers/Production/DehuskingActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/dehusking';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import { index as lotsIndex } from '@/routes/inventory/lots';
import type { DehuskingDetail } from '@/types';

const { t } = useT();

defineProps<{ order: DehuskingDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dehusking', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="order.production_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="order.production_number"
                    :description="`Dehusking · ${order.production_date} · ${order.warehouse_code}`"
                />
                <Badge :variant="badgeVariant(order.status)" class="w-fit">
                    {{ order.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="order.can_edit && can('production.create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${order.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="order.can_post && can('production.post')"
                    v-bind="DehuskingActionController.post.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">
                        {{ t('Post production') }}
                    </Button>
                </Form>

                <Form
                    v-if="order.can_reverse && can('production.reverse')"
                    v-bind="DehuskingActionController.reverse.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Reverse') }}
                    </Button>
                </Form>

                <Form
                    v-if="order.can_cancel && can('production.cancel')"
                    v-bind="DehuskingActionController.cancel.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Cancel draft') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="order.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This production run is a') }} <strong>{{ t('draft') }}</strong> {{ t('— no coconut has been consumed and no output stock exists. Costs appear once it is posted, because the inventory cost is determined by the ledger at that moment.') }}
        </div>

        <!-- Value conservation -->
        <div class="grid gap-3 sm:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Coconut consumed') }}</p>
                <p class="mt-1 font-mono text-lg">
                    {{ order.input_quantity }}
                    <span class="text-muted-foreground text-sm">{{ order.input?.uom }}</span>
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Input value') }}</p>
                <p class="mt-1 font-mono text-lg">{{ order.input_value ?? '—' }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Labour') }}</p>
                <p class="mt-1 font-mono text-lg">{{ order.labour_cost ?? '—' }}</p>
                <p v-if="order.labour_rate" class="text-muted-foreground text-xs">
                    {{ t(':rate per coconut', { rate: order.labour_rate }) }}
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Output value') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ order.output_value ?? '—' }}</p>
            </div>
        </div>

        <!-- Input -->
        <div
            v-if="order.input"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">{{ t('Input') }}</h3>
            <dl class="mt-4 grid gap-6 sm:grid-cols-5">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Product') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ order.input.product_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Lot consumed') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="lotsIndex().url" class="hover:underline">
                            {{ order.input.lot_number ?? '—' }}
                        </Link>
                        <template v-else>{{ order.input.lot_number ?? '—' }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Quantity') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ order.input.quantity }} {{ order.input.uom }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Weighted avg cost') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ order.input.unit_cost ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Value consumed') }}</dt>
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
                        <th class="px-4 py-3 font-medium">{{ t('Output product') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Lot produced') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('UOM') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Cost share') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Allocated cost') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Unit cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="output in order.outputs"
                        :key="output.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ output.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ output.product_name }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link v-if="can('inventory.view')" :href="lotsIndex().url" class="hover:underline">
                                {{ output.lot_number ?? '—' }}
                            </Link>
                            <template v-else>{{ output.lot_number ?? '—' }}</template>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ output.quantity }}</td>
                        <td class="text-muted-foreground px-4 py-3">{{ output.uom }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(output.allocation_percent).toFixed(2) }}%
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ output.allocated_cost }}
                            <span v-if="output.labour_cost" class="text-muted-foreground block text-xs">
                                {{ t('incl. labour :amount', { amount: output.labour_cost }) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ output.unit_cost }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Traceability -->
        <div
            v-if="order.input?.lot_number && order.outputs.length"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">{{ t('Traceability') }}</h3>
            <div class="text-muted-foreground mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-mono">{{ order.input.lot_number }}</span>
                <ArrowRight class="size-3" />
                <span class="font-mono">{{ order.production_number }}</span>
                <ArrowRight class="size-3" />
                <span v-for="(output, i) in order.outputs" :key="output.id" class="font-mono">
                    {{ output.lot_number }}<span v-if="i < order.outputs.length - 1">,</span>
                </span>
            </div>
            <p class="text-muted-foreground mt-3 text-xs">
                {{ t('Each output lot records this production run as its source, and this run records the coconut lot it consumed — so stock can be traced in either direction.') }}
            </p>
        </div>

        <!-- Ledger references -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div v-if="order.consumption_reference">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Consumption transaction') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ order.consumption_reference }}
                        </Link>
                        <template v-else>{{ order.consumption_reference }}</template>
                    </dd>
                </div>
                <div v-if="order.output_reference">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Output transaction') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ order.output_reference }}
                        </Link>
                        <template v-else>{{ order.output_reference }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Posted') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ order.posted_at ? `${order.posted_at} by ${order.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div v-if="order.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ order.notes }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Dehusking increases the physical item count — one coconut becomes two things. The dehusked coconut carries the whole coconut value; the raw husk carries the dehusking labour. So output value = input value + labour, exactly. No electricity or overhead cost is added.') }}
        </p>
    </div>
</template>
