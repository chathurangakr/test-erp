<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    CopraFormValues,
    CopraOutputColumn,
    CopraRecipeInfo,
    OptionRef,
    ProductionLotOption,
} from '@/types';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: CopraRecipeInfo;
    inputStock: string;
    inputUnitCost: string;
    initial?: CopraFormValues | null;
}>();

const today = new Date().toISOString().slice(0, 10);

/**
 * One row per recipe output.
 *
 * A derived output's quantity is deliberately left empty — the server refuses a submitted
 * value for it and calculates the shell count itself.
 */
function initialOutputs(): { product_id: number; quantity: string; secondary_quantity: string }[] {
    return props.recipe.outputs.map((output) => {
        const existing = props.initial?.outputs?.find((o) => o.product_id === output.product_id);

        return {
            product_id: output.product_id,
            quantity: output.ratio === null ? (existing?.quantity ?? '') : '',
            secondary_quantity: output.requires_secondary_quantity
                ? (existing?.secondary_quantity ?? '')
                : '',
        };
    });
}

const form = useForm({
    production_date: props.initial?.production_date ?? today,
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    lot_id: props.initial?.lot_id ? String(props.initial.lot_id) : '',
    quantity: props.initial?.quantity ?? '',
    outputs: initialOutputs(),
    notes: props.initial?.notes ?? '',
});

/** Dehusked coconut lots holding stock in the chosen warehouse. */
const availableLots = computed(() => {
    if (!form.warehouse_id) {
        return [];
    }

    return props.lots.filter((lot) =>
        lot.warehouses.some((w) => String(w.warehouse_id) === form.warehouse_id),
    );
});

const selectedLotQuantity = computed(() => {
    const lot = props.lots.find((l) => String(l.id) === form.lot_id);

    return (
        lot?.warehouses.find((w) => String(w.warehouse_id) === form.warehouse_id)?.quantity ?? null
    );
});

const processed = computed(() => {
    const parsed = Number(form.quantity);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
});

const exceedsLot = computed(() => {
    const available = Number(selectedLotQuantity.value ?? 0);

    return processed.value > 0 && available > 0 && processed.value > available;
});

/** What the consumption is expected to be worth. The ledger decides for real at posting. */
const estimatedConsumedValue = computed(() => processed.value * Number(props.inputUnitCost));

function rowIndex(productId: number): number {
    return props.recipe.outputs.findIndex((o) => o.product_id === productId);
}

/** The shell count the server will calculate: one per coconut processed. */
function derivedQuantity(output: CopraOutputColumn): number {
    return processed.value * Number(output.ratio ?? 0);
}

function enteredQuantity(output: CopraOutputColumn): number {
    const row = form.outputs[rowIndex(output.product_id)];
    const parsed = Number(output.ratio === null ? row?.quantity : derivedQuantity(output));

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function enteredSecondary(output: CopraOutputColumn): number {
    const parsed = Number(form.outputs[rowIndex(output.product_id)]?.secondary_quantity);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

/**
 * The provisional split, previewed.
 *
 * The percentages are configuration read from the recipe — the screen never proposes them
 * and the operator cannot type an allocated cost. The money shown is an estimate only,
 * because the real consumed value comes from the weighted average in force when you post.
 */
const allocationPreview = computed(() =>
    props.recipe.outputs.map((output) => {
        const percent = Number(output.allocation_percent);
        const allocated = (estimatedConsumedValue.value * percent) / 100;
        const quantity = enteredQuantity(output);
        const secondary = enteredSecondary(output);

        return {
            ...output,
            percent,
            allocated,
            quantity,
            unit_cost: quantity > 0 ? allocated / quantity : null,
            informational_secondary_unit_cost: secondary > 0 ? allocated / secondary : null,
        };
    }),
);

const percentTotal = computed(() =>
    props.recipe.outputs.reduce((sum, o) => sum + Number(o.allocation_percent), 0),
);

function error(index: number, field: 'quantity' | 'secondary_quantity'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`outputs.${index}.${field}`];
}

function money(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function submit(): void {
    form.submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Production details</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="production_date">
                        Production date <span class="text-destructive">*</span>
                    </Label>
                    <Input id="production_date" v-model="form.production_date" type="date" required />
                    <InputError :message="form.errors.production_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="warehouse_id">Warehouse <span class="text-destructive">*</span></Label>
                    <select id="warehouse_id" v-model="form.warehouse_id" required :class="selectClass">
                        <option value="" disabled>Select a warehouse…</option>
                        <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                            {{ w.code }} — {{ w.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.warehouse_id" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">Input — {{ recipe.input_code }}</h3>
                <p class="text-muted-foreground text-xs">
                    Company stock on hand:
                    <span class="font-mono">{{ inputStock }}</span> {{ recipe.input_uom }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="lot_id">
                        Dehusked coconut lot <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="lot_id"
                        v-model="form.lot_id"
                        required
                        :disabled="!form.warehouse_id"
                        :class="selectClass"
                    >
                        <option value="" disabled>
                            {{ form.warehouse_id ? 'Select a lot…' : 'Choose a warehouse first' }}
                        </option>
                        <option v-for="lot in availableLots" :key="lot.id" :value="String(lot.id)">
                            {{ lot.lot_number }}
                        </option>
                    </select>
                    <p v-if="selectedLotQuantity" class="text-muted-foreground text-xs">
                        Available lot stock:
                        <span class="font-mono">{{ selectedLotQuantity }}</span>
                        {{ recipe.input_uom }}
                    </p>
                    <InputError :message="form.errors.lot_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="quantity">
                        Dehusked coconut to process ({{ recipe.input_uom }})
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input id="quantity" v-model="form.quantity" type="number" step="1" min="0" required />
                    <p v-if="exceedsLot" class="text-destructive text-xs">
                        This exceeds the {{ selectedLotQuantity }} available in the selected lot.
                    </p>
                    <InputError :message="form.errors.quantity" />
                </div>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <dt class="text-muted-foreground text-xs font-medium">
                        Current inventory cost (weighted average)
                    </dt>
                    <dd class="mt-1 font-mono text-sm">{{ inputUnitCost }}</dd>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <dt class="text-muted-foreground text-xs font-medium">Estimated consumed value</dt>
                    <dd class="mt-1 font-mono text-sm font-medium">
                        {{ money(estimatedConsumedValue) }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Measured outputs -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Measured output</h3>

            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm">
                Weigh the copra and the shell, and enter what the scale says. Drying loss
                varies from batch to batch, so nothing here is calculated from the coconut
                count — there is no coconut-to-kilogram conversion in the system. Only the
                shell <em>count</em> is calculated, because one coconut leaves one shell.
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <template v-for="output in recipe.outputs" :key="output.product_id">
                    <!-- Measured: the operator weighs it, because no ratio can predict it. -->
                    <div v-if="output.ratio === null" class="grid gap-2">
                        <Label :for="`output-${output.product_id}`">
                            {{ output.code }} actual ({{ output.uom }})
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            :id="`output-${output.product_id}`"
                            v-model="form.outputs[rowIndex(output.product_id)].quantity"
                            type="number"
                            step="0.001"
                            min="0"
                            required
                        />
                        <p class="text-muted-foreground text-xs">{{ output.name }}</p>
                        <InputError :message="error(rowIndex(output.product_id), 'quantity')" />
                    </div>

                    <!-- Derived: read-only, and refused by the server if submitted. -->
                    <div v-else class="grid gap-2">
                        <Label :for="`derived-${output.product_id}`">
                            {{ output.code }} ({{ output.uom }}) — calculated
                        </Label>
                        <Input
                            :id="`derived-${output.product_id}`"
                            :model-value="processed > 0 ? derivedQuantity(output).toFixed(3) : ''"
                            type="text"
                            readonly
                            tabindex="-1"
                            class="bg-muted/50"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ Number(output.ratio).toFixed(2) }} per
                            {{ recipe.input_uom }} processed — not editable.
                        </p>
                        <InputError :message="error(rowIndex(output.product_id), 'quantity')" />
                    </div>

                    <div v-if="output.requires_secondary_quantity" class="grid gap-2">
                        <Label :for="`secondary-${output.product_id}`">
                            {{ output.code }} actual weight ({{ output.secondary_uom }})
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            :id="`secondary-${output.product_id}`"
                            v-model="form.outputs[rowIndex(output.product_id)].secondary_quantity"
                            type="number"
                            step="0.001"
                            min="0"
                            required
                        />
                        <p class="text-muted-foreground text-xs">
                            Shell weights vary, so this is measured, never converted from the
                            count.
                        </p>
                        <InputError
                            :message="error(rowIndex(output.product_id), 'secondary_quantity')"
                        />
                    </div>
                </template>
            </div>

            <InputError :message="form.errors.outputs" />
        </section>

        <!-- Provisional allocation -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Provisional cost allocation</h3>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Output</th>
                            <th class="px-4 py-3 text-right font-medium">Quantity</th>
                            <th class="px-4 py-3 text-right font-medium">Share</th>
                            <th class="px-4 py-3 text-right font-medium">Estimated cost</th>
                            <th class="px-4 py-3 text-right font-medium">Estimated unit cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in allocationPreview"
                            :key="row.product_id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ row.code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ row.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ row.quantity.toFixed(3) }} {{ row.uom }}
                                <span
                                    v-if="row.requires_secondary_quantity"
                                    class="text-muted-foreground block text-xs"
                                >
                                    {{ enteredSecondary(row).toFixed(3) }} {{ row.secondary_uom }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.percent.toFixed(2) }}%</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.allocated) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">
                                {{ row.unit_cost === null ? '—' : money(row.unit_cost) }}
                                <span class="text-muted-foreground text-xs">/ {{ row.uom }}</span>
                                <span
                                    v-if="row.informational_secondary_unit_cost !== null"
                                    class="text-muted-foreground block text-xs"
                                >
                                    {{ money(row.informational_secondary_unit_cost) }} /
                                    {{ row.secondary_uom }} (informational)
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-muted-foreground text-xs">
                These shares total
                <span class="font-mono">{{ percentTotal.toFixed(2) }}%</span> and are
                configuration, not something you can type here — the consumed coconut value is
                divided between copra and shell in exactly these proportions, so copra
                production neither creates nor destroys inventory value. The
                <strong>75 / 25 split is provisional</strong> and will later be replaced by a
                market-value / NRV method. Every figure above is an estimate: the real money is
                calculated when you post, from the weighted average cost in the ledger at that
                moment.
            </p>
            <p class="text-muted-foreground text-xs">
                Shell is valued against its <strong>count</strong>, because NOS is its primary
                unit. The per-kilogram figure is shown for information only and is never used
                as a weighted average cost.
            </p>
        </section>

        <div class="grid gap-2">
            <Label for="prd_notes">Notes</Label>
            <textarea
                id="prd_notes"
                v-model="form.notes"
                rows="3"
                class="border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            />
            <InputError :message="form.errors.notes" />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing">{{ submitLabel }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">Cancel</a></Button>
        </div>
    </form>
</template>
