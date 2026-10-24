<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useT } from '@/lib/i18n';
import type { OptionRef, ProductionRecipeOption } from '@/types';
import { businessToday } from '@/lib/businessDate';
import { money, moneyInput } from '@/lib/money';

const { t } = useT();

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    warehouses: OptionRef[];
    recipes: ProductionRecipeOption[];
    initial?: {
        production_date: string | null;
        warehouse_id: number | null;
        input_product_id?: number | null;
        lot_id: number | null;
        quantity: string | null;
        labour_rate?: string | null;
        notes: string | null;
    } | null;
}>();

const today = businessToday();

const form = useForm({
    production_date: props.initial?.production_date ?? today,
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    // Which coconuts are dehusked: one recipe per size. Chosen by the operator when there are several.
    input_product_id: String(props.initial?.input_product_id ?? (props.recipes.length === 1 ? props.recipes[0].input_product_id : '')),
    lot_id: props.initial?.lot_id ? String(props.initial.lot_id) : '',
    quantity: props.initial?.quantity ?? '',
    // "4.000000" from the server shows as "4.00": rupees and cents.
    labour_rate: moneyInput(props.initial?.labour_rate ?? props.recipes[0]?.default_labour_rate),
    notes: props.initial?.notes ?? '',
});

/** The chosen size's recipe; the first until one is chosen, so the preview has something to show. */
const recipe = computed(() => props.recipes.find((r) => String(r.input_product_id) === form.input_product_id) ?? props.recipes[0]);
const inputStock = computed(() => recipe.value.input_stock);

/** Pooled coconut has no lots: the lot picker only appears for lot-tracked input. */
const usesLots = computed(() => recipe.value.input_tracks_lots !== false);

const labourCost = computed(() => {
    const rate = Number(form.labour_rate);

    return Number.isFinite(rate) && rate >= 0 ? money(inputQuantity.value * rate) : money(0);
});

/** Lots holding coconut in the chosen warehouse. */
const availableLots = computed(() => {
    if (!form.warehouse_id) {
        return [];
    }

    return recipe.value.lots.filter((lot) =>
        lot.warehouses.some((w) => String(w.warehouse_id) === form.warehouse_id),
    );
});

const selectedLotQuantity = computed(() => {
    const lot = recipe.value.lots.find((l) => String(l.id) === form.lot_id);

    return (
        lot?.warehouses.find((w) => String(w.warehouse_id) === form.warehouse_id)?.quantity ??
        null
    );
});

const inputQuantity = computed(() => {
    const parsed = Number(form.quantity);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
});

/**
 * Output preview. Quantities come from the recipe's ratio, which is what encodes the
 * 1:1:1 rule — they are shown read-only and are recalculated server-side at posting.
 */
const outputPreview = computed(() =>
    recipe.value.outputs.map((output) => ({
        ...output,
        quantity: (inputQuantity.value * Number(output.ratio)).toFixed(3),
    })),
);

const exceedsLot = computed(() => {
    const available = Number(selectedLotQuantity.value ?? 0);

    return inputQuantity.value > 0 && available > 0 && inputQuantity.value > available;
});

function submit(): void {
    form.transform((data) => ({ ...data, lot_id: usesLots.value ? data.lot_id || null : null })).submit(
        props.method,
        props.action,
        { preserveScroll: true },
    );
}

</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Production details') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="production_date">
                        {{ t('Production date') }} <span class="text-destructive">*</span>
                    </Label>
                    <Input id="production_date" v-model="form.production_date" type="date" required />
                    <InputError :message="form.errors.production_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="warehouse_id">{{ t('Warehouse') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="warehouse_id"
                        v-model="form.warehouse_id"
                        required
                        :options="[...(warehouses).map((w) => ({ value: String(w.id), label: `${w.code} — ${w.name}` }))]"
                        :placeholder="t('Select a warehouse…')"
                    />
                    <InputError :message="form.errors.warehouse_id" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div v-if="recipes.length > 1" class="grid gap-2 sm:max-w-md">
                <Label for="input_product_id">{{ t('Coconuts to dehusk') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    id="input_product_id"
                    v-model="form.input_product_id"
                    required
                    :options="recipes.map((r) => ({ value: String(r.input_product_id), label: `${r.input_name} — ${r.input_stock} ${r.input_uom ?? ''}` }))"
                    :auto-select-single="false"
                    @update:model-value="form.lot_id = ''"
                />
                <InputError :message="form.errors.input_product_id" />
            </div>

            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">
                    {{ t('Input — :code', { code: recipe.input_code }) }}
                </h3>
                <p class="text-muted-foreground text-xs">
                    {{ t('Company stock on hand:') }}
                    <span class="font-mono">{{ inputStock }}</span> {{ recipe.input_uom }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-if="usesLots" class="grid gap-2">
                    <Label for="lot_id">{{ t('Coconut lot') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="lot_id"
                        v-model="form.lot_id"
                        required
                        :disabled="!form.warehouse_id"
                        :options="[...(availableLots).map((lot) => ({ value: String(lot.id), label: `${(lot.lot_number) ?? ''}` }))]"
                        :placeholder="form.warehouse_id ? t('Select a lot…') : t('Choose a warehouse first')"
                        :auto-select-single="false"
                    />
                    <p v-if="selectedLotQuantity" class="text-muted-foreground text-xs">
                        {{ t('Available in this lot:') }}
                        <span class="font-mono">{{ selectedLotQuantity }}</span>
                        {{ recipe.input_uom }}
                    </p>
                    <InputError :message="form.errors.lot_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="quantity">
                        {{ t('Quantity to process (:uom)', { uom: recipe.input_uom ?? '' }) }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="quantity"
                        v-model="form.quantity"
                        type="number"
                        step="0.001"
                        min="0"
                        required
                    />
                    <p v-if="exceedsLot" class="text-destructive text-xs">
                        {{ t('This exceeds the :quantity available in the selected lot.', { quantity: selectedLotQuantity ?? '' }) }}
                    </p>
                    <InputError :message="form.errors.quantity" />
                </div>

                <div class="grid gap-2">
                    <Label for="labour_rate">
                        {{ t('Labour per coconut (Rs.)') }} <span class="text-destructive">*</span>
                    </Label>
                    <Input id="labour_rate" v-model="form.labour_rate" type="number" step="0.01" min="0" required />
                    <p class="text-muted-foreground text-xs">
                        {{ t('Labour cost for this run: Rs. :amount — this becomes the cost of the raw husk.', { amount: labourCost }) }}
                    </p>
                    <InputError :message="form.errors.labour_rate" />
                </div>
            </div>
        </section>

        <!-- Derived outputs — read only -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Outputs') }}</h3>

            <div
                class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('UOM') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Cost share') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="output in outputPreview"
                            :key="output.code ?? ''"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ output.code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ output.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ output.quantity }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ output.uom }}</td>
                            <td class="px-4 py-3 text-right font-mono">
                                <template v-if="output.absorbs_labour">{{ t('Labour Rs. :amount', { amount: labourCost }) }}</template>
                                <template v-else>{{ Number(output.allocation_percent).toFixed(2) }}%</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-muted-foreground text-xs">
                {{ t('Output quantities are derived from the input — one coconut yields one dehusked coconut and one husk — and cannot be edited. The dehusked coconut carries the whole coconut cost; the raw husk carries only the dehusking labour. Actual costs are calculated by the ledger when you post.') }}
            </p>
        </section>

        <div class="grid gap-2">
            <Label for="prd_notes">{{ t('Notes') }}</Label>
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
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </form>
</template>
