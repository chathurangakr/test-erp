<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    HuskChippingFormValues,
    HuskChippingRecipeInfo,
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
    recipe: HuskChippingRecipeInfo;
    wasteUoms: OptionRef[];
    inputStock: string;
    initial?: HuskChippingFormValues | null;
}>();

const today = new Date().toISOString().slice(0, 10);

/** One row per grade, pre-filled from the draft being edited. */
function initialOutputs(): { product_id: number; quantity: string }[] {
    return props.recipe.outputs.map((output) => ({
        product_id: output.product_id,
        quantity:
            props.initial?.outputs?.find((o) => o.product_id === output.product_id)?.quantity ??
            '',
    }));
}

const form = useForm({
    production_date: props.initial?.production_date ?? today,
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    lot_id: props.initial?.lot_id ? String(props.initial.lot_id) : '',
    quantity: props.initial?.quantity ?? '',
    outputs: initialOutputs(),
    waste_quantity: props.initial?.waste_quantity ?? '',
    waste_uom_id: props.initial?.waste_uom_id ? String(props.initial.waste_uom_id) : '',
    notes: props.initial?.notes ?? '',
});

/** Husk lots holding stock in the chosen warehouse. */
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

const inputQuantity = computed(() => {
    const parsed = Number(form.quantity);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
});

const exceedsLot = computed(() => {
    const available = Number(selectedLotQuantity.value ?? 0);

    return inputQuantity.value > 0 && available > 0 && inputQuantity.value > available;
});

function measured(productId: number): number {
    const parsed = Number(form.outputs.find((o) => o.product_id === productId)?.quantity);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const totalMeasured = computed(() =>
    props.recipe.outputs.reduce((sum, output) => sum + measured(output.product_id), 0),
);

const nothingProduced = computed(() => totalMeasured.value <= 0);

/**
 * The cost share each grade would receive.
 *
 * Only grades this batch actually produced take a share, so their configured percentages
 * are renormalised — exactly what the server does when posting. This is a preview of the
 * split, not of the money: the value itself is unknown until the ledger prices the husk.
 */
const allocationPreview = computed(() => {
    const producing = props.recipe.outputs.filter((o) => measured(o.product_id) > 0);
    const percentTotal = producing.reduce((sum, o) => sum + Number(o.allocation_percent), 0);

    return props.recipe.outputs.map((output) => {
        const produced = measured(output.product_id) > 0;

        return {
            ...output,
            produced,
            effective_percent:
                produced && percentTotal > 0
                    ? ((Number(output.allocation_percent) / percentTotal) * 100).toFixed(4)
                    : '0.0000',
        };
    });
});

const redistributed = computed(
    () =>
        !nothingProduced.value &&
        props.recipe.outputs.some((o) => measured(o.product_id) <= 0),
);

function outputError(index: number): string | undefined {
    return (
        (form.errors as Record<string, string | undefined>)[`outputs.${index}.quantity`] ??
        undefined
    );
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
                    <Label for="lot_id">Raw husk lot <span class="text-destructive">*</span></Label>
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
                        Available in this lot:
                        <span class="font-mono">{{ selectedLotQuantity }}</span>
                        {{ recipe.input_uom }}
                    </p>
                    <InputError :message="form.errors.lot_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="quantity">
                        Husk to process ({{ recipe.input_uom }})
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input id="quantity" v-model="form.quantity" type="number" step="1" min="0" required />
                    <p v-if="exceedsLot" class="text-destructive text-xs">
                        This exceeds the {{ selectedLotQuantity }} available in the selected lot.
                    </p>
                    <InputError :message="form.errors.quantity" />
                </div>
            </div>
        </section>

        <!-- Measured outputs — the operator types these -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Measured output weights</h3>

            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm">
                Weigh each grade and enter what the scale says. Chipping yields vary from
                batch to batch, so nothing here is calculated from the husk count — there is
                no husk-to-kilogram conversion in the system. Enter <strong>0</strong> for a
                grade this batch did not produce.
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div v-for="(output, index) in recipe.outputs" :key="output.product_id" class="grid gap-2">
                    <Label :for="`output-${output.product_id}`">
                        {{ output.code }} ({{ output.uom }})
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        :id="`output-${output.product_id}`"
                        v-model="form.outputs[index].quantity"
                        type="number"
                        step="0.001"
                        min="0"
                        required
                    />
                    <p class="text-muted-foreground text-xs">{{ output.name }}</p>
                    <InputError :message="outputError(index)" />
                </div>
            </div>

            <InputError :message="form.errors.outputs" />

            <p v-if="nothingProduced" class="text-destructive text-xs">
                At least one grade must have a weight greater than zero.
            </p>
            <p v-else class="text-muted-foreground text-xs">
                Total measured output:
                <span class="font-mono">{{ totalMeasured.toFixed(3) }}</span> KG
            </p>
        </section>

        <!-- Cost share preview -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Cost allocation</h3>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Grade</th>
                            <th class="px-4 py-3 text-right font-medium">Measured</th>
                            <th class="px-4 py-3 text-right font-medium">Configured share</th>
                            <th class="px-4 py-3 text-right font-medium">Share applied</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in allocationPreview"
                            :key="row.product_id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                            :class="{ 'text-muted-foreground': !row.produced }"
                        >
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ row.code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ row.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ measured(row.product_id).toFixed(3) }} {{ row.uom }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ Number(row.allocation_percent).toFixed(2) }}%
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-medium">
                                {{ Number(row.effective_percent).toFixed(2) }}%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="redistributed" class="text-muted-foreground text-xs">
                A grade with no weight takes no cost and gets no lot, so its share is spread
                over the grades that were produced — which is why the applied shares differ
                from the configured ones.
            </p>

            <p class="text-muted-foreground text-xs">
                The consumed husk value is divided between the grades in these proportions,
                so chipping neither creates nor destroys inventory value. The money itself is
                calculated when you post, from the weighted average cost in the ledger at
                that moment.
            </p>
        </section>

        <!-- Waste — information only -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Waste (optional)</h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="waste_quantity">Waste / loss</Label>
                    <Input id="waste_quantity" v-model="form.waste_quantity" type="number" step="0.001" min="0" />
                    <InputError :message="form.errors.waste_quantity" />
                </div>

                <div class="grid gap-2">
                    <Label for="waste_uom_id">Measured in</Label>
                    <select id="waste_uom_id" v-model="form.waste_uom_id" :class="selectClass">
                        <option value="">Not recorded</option>
                        <option v-for="u in wasteUoms" :key="u.id" :value="String(u.id)">
                            {{ u.code }} — {{ u.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.waste_uom_id" />
                </div>
            </div>

            <p class="text-muted-foreground text-xs">
                Waste is recorded for information only. It creates no stock, no lot and no
                product, and it takes no cost — the whole consumed value stays with the
                saleable grades. Leave it blank if it was not measured.
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
