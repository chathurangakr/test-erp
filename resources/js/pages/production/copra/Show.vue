<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Pencil } from '@lucide/vue';
import CopraActionController from '@/actions/App/Http/Controllers/Production/CopraActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/copra';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import { index as lotsIndex } from '@/routes/inventory/lots';
import type { CopraDetail } from '@/types';
import { money } from '@/lib/money';

const { t } = useT();

const props = defineProps<{ order: CopraDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Copra production', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

/** Outputs that received a lot — every copra output does, because all quantities are positive. */
const lotted = () => props.order.outputs.filter((o) => o.lot_number !== null);
</script>

<template>
    <Head :title="order.production_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="order.production_number"
                    :description="`Copra production · ${order.production_date} · ${order.warehouse_code}`"
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
                    v-bind="CopraActionController.post.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">{{ t('Post production') }}</Button>
                </Form>

                <Form
                    v-if="order.can_reverse && can('production.reverse')"
                    v-bind="CopraActionController.reverse.form(order.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Reverse') }}
                    </Button>
                </Form>

                <Form
                    v-if="order.can_cancel && can('production.cancel')"
                    v-bind="CopraActionController.cancel.form(order.id)"
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
            {{ t('This production run is a') }} <strong>{{ t('draft') }}</strong> {{ t('— no coconut has been consumed and no copra or shell stock exists. The quantities below are what the operator measured; costs appear once it is posted, because the inventory cost is determined by the ledger at that moment.') }}
        </div>

        <!-- Value conservation -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Dehusked coconut consumed') }}</p>
                <p class="mt-1 font-mono text-lg">
                    {{ order.input_quantity }}
                    <span class="text-muted-foreground text-sm">{{ order.input?.uom }}</span>
                </p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Input value') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(order.input_value) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Output value') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(order.output_value) }}</p>
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
                    <dd class="mt-1 font-mono text-sm">{{ order.input.quantity }} {{ order.input.uom }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Weighted avg cost') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ money(order.input.unit_cost) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Value consumed') }}</dt>
                    <dd class="mt-1 font-mono text-sm font-medium">{{ money(order.input.total_cost) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Outputs -->
        <div
            v-for="output in order.outputs"
            :key="output.id"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">
                    {{ output.product_name }}
                    <span class="text-muted-foreground font-mono text-xs">{{ output.product_code }}</span>
                </h3>
                <p class="text-muted-foreground text-xs">
                    {{ t('Cost share :percent%', { percent: Number(output.allocation_percent).toFixed(2) }) }}
                </p>
            </div>

            <dl class="mt-4 grid gap-6 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Lot produced') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="output.lot_number && can('inventory.view')" :href="lotsIndex().url" class="hover:underline">
                            {{ output.lot_number }}
                        </Link>
                        <template v-else-if="output.lot_number">{{ output.lot_number }}</template>
                        <span v-else>—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Quantity') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ output.quantity }} {{ output.uom }}</dd>
                </div>
                <div v-if="output.secondary_quantity !== null">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Measured weight') }}</dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ output.secondary_quantity }} {{ output.secondary_uom }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Allocated cost') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ money(output.allocated_cost) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Inventory cost / :uom', { uom: output.uom ?? '' }) }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm font-medium">{{ money(output.unit_cost) }}</dd>
                </div>
                <div v-if="output.informational_secondary_unit_cost !== null">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Cost / :uom (informational)', { uom: output.secondary_uom ?? '' }) }}
                    </dt>
                    <dd class="text-muted-foreground mt-1 font-mono text-sm">
                        {{ money(output.informational_secondary_unit_cost) }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="output.informational_secondary_unit_cost !== null"
                class="text-muted-foreground mt-3 max-w-3xl text-xs"
            >
                {{ t(':product is valued in :uom, because that is its primary unit — the figure per :secondary is shown only because the factory thinks about it by weight. It is', { product: output.product_code ?? '', uom: output.uom ?? '', secondary: output.secondary_uom ?? '' }) }}
                <strong>{{ t('informational') }}</strong> {{ t('and is never used as a weighted average cost.') }}
            </p>
        </div>

        <!-- Traceability -->
        <div
            v-if="order.input?.lot_number && lotted().length"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">{{ t('Traceability') }}</h3>
            <div class="text-muted-foreground mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-mono">{{ order.input.lot_number }}</span>
                <ArrowRight class="size-3" />
                <span class="font-mono">{{ order.production_number }}</span>
                <ArrowRight class="size-3" />
                <span v-for="(output, i) in lotted()" :key="output.id" class="font-mono">
                    {{ output.lot_number }}<span v-if="i < lotted().length - 1">,</span>
                </span>
            </div>
            <p class="text-muted-foreground mt-3 max-w-3xl text-xs">
                {{ t('Each copra and shell lot records this production run as its source, and this run records the dehusked coconut lot it consumed — which in turn traces back through dehusking to the whole coconut lot and the goods receipt that supplied it.') }}
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
            {{ t('Coconut is counted in NOS and copra is weighed in KG, so the quantities above are not comparable — and nothing in the system converts one into the other. The shell count is the one derived figure: one coconut leaves one shell. What is conserved is value: the allocated copra and shell costs sum exactly to the coconut value consumed, in the split set by the recipe this run used. No drying labour, fuel, electricity or overhead cost is added; that comes in a later costing phase, and moisture loss is not yet recorded.') }}
        </p>
    </div>
</template>
