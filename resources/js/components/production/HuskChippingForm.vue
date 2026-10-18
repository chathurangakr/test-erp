<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { businessToday } from '@/lib/businessDate';
import { useT } from '@/lib/i18n';
import type { HuskChippingFormValues, HuskChippingRecipeInfo, OptionRef } from '@/types';

const { t } = useT();

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    warehouses: OptionRef[];
    recipe: HuskChippingRecipeInfo;
    wasteUoms: OptionRef[];
    inputStock: string;
    initial?: HuskChippingFormValues | null;
}>();

const today = businessToday();

type Row = { product_id: string; quantity: string };

/** The draft's rows, or one empty row to start with. */
function initialRows(): Row[] {
    const rows = (props.initial?.outputs ?? []).map((o) => ({
        product_id: String(o.product_id),
        quantity: o.quantity ?? '',
    }));

    return rows.length > 0 ? rows : [{ product_id: '', quantity: '' }];
}

const form = useForm({
    production_date: props.initial?.production_date ?? today,
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    outputs: initialRows(),
    waste_quantity: props.initial?.waste_quantity ?? '',
    waste_uom_id: props.initial?.waste_uom_id ? String(props.initial.waste_uom_id) : '',
    notes: props.initial?.notes ?? '',
});

function product(productId: string) {
    return props.recipe.outputs.find((o) => String(o.product_id) === productId);
}

/** Each product once: a row offers what the other rows have not taken. */
function choicesFor(index: number) {
    const taken = form.outputs.filter((_, i) => i !== index).map((row) => row.product_id);

    return props.recipe.outputs.filter((o) => !taken.includes(String(o.product_id)));
}

const canAdd = computed(() => form.outputs.length < props.recipe.outputs.length);

function addRow(): void {
    form.outputs.push({ product_id: '', quantity: '' });
}

function removeRow(index: number): void {
    form.outputs.splice(index, 1);
}

function rowError(index: number, field: 'product_id' | 'quantity'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`outputs.${index}.${field}`];
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        outputs: data.outputs.map((row) => ({ product_id: Number(row.product_id) || null, quantity: row.quantity })),
    })).submit(props.method, props.action, { preserveScroll: true });
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

        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-medium">{{ t('Raw husk is not deducted here.') }}</p>
            <p class="mt-1">
                {{ t('Raw husks are taken from stock when the chips or cocopeat are delivered — the delivery asks how many husks were used. Until then these outputs carry no husk cost: raw husk material cost pending backflush.') }}
            </p>
            <p class="mt-1 text-xs">
                {{ t('Raw husk in stock now:') }}
                <span class="font-mono">{{ inputStock }}</span> {{ recipe.input_uom }}
            </p>
        </div>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('What was produced') }}</h3>
            <p class="text-muted-foreground text-xs">
                {{ t('Add a row for each product made and enter what was measured. Litres and kilograms are separate stock — choose the line that matches how you measured. Leave out anything not produced.') }}
            </p>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                            <th class="w-48 px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                            <th class="w-20 px-4 py-3 font-medium">{{ t('Unit') }}</th>
                            <th class="w-24 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in form.outputs"
                            :key="index"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t align-top"
                        >
                            <td class="px-4 py-3">
                                <SearchableSelect
                                    :id="`output-product-${index}`"
                                    v-model="row.product_id"
                                    required
                                    :aria-label="t('Product')"
                                    :options="[...(choicesFor(index)).map((o) => ({ value: String(o.product_id), label: `${o.name} — ${o.uom}` }))]"
                                    :placeholder="t('Select a product…')"
                                />
                                <InputError :message="rowError(index, 'product_id')" />
                            </td>
                            <td class="px-4 py-3">
                                <Input
                                    :id="`output-quantity-${index}`"
                                    v-model="row.quantity"
                                    type="number"
                                    :step="product(row.product_id)?.decimals === 0 ? '1' : '0.001'"
                                    min="0"
                                    required
                                    class="text-right"
                                    :aria-label="t('Quantity')"
                                />
                                <InputError :message="rowError(index, 'quantity')" />
                            </td>
                            <td class="text-muted-foreground px-4 py-3 pt-5 font-mono text-xs">
                                {{ product(row.product_id)?.uom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.outputs.length === 1"
                                    @click="removeRow(index)"
                                >
                                    {{ t('Remove') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Button type="button" variant="outline" size="sm" :disabled="!canAdd" @click="addRow">
                {{ t('Add output') }}
            </Button>
            <InputError :message="form.errors.outputs" />

            <p class="text-muted-foreground text-xs">
                {{ t('Nothing here is calculated from a husk count — there is no husk-to-kilogram or husk-to-litre conversion in the system.') }}
            </p>
        </section>

        <!-- Waste — information only -->
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Waste (optional)') }}</h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="waste_quantity">{{ t('Waste / loss') }}</Label>
                    <Input id="waste_quantity" v-model="form.waste_quantity" type="number" step="0.001" min="0" />
                    <InputError :message="form.errors.waste_quantity" />
                </div>

                <div class="grid gap-2">
                    <Label for="waste_uom_id">{{ t('Measured in') }}</Label>
                    <SearchableSelect
                        id="waste_uom_id"
                        v-model="form.waste_uom_id"
                        :options="[{ value: '', label: t('Not recorded') }, ...(wasteUoms).map((u) => ({ value: String(u.id), label: `${u.code} — ${u.name}` }))]"
                    />
                    <InputError :message="form.errors.waste_uom_id" />
                </div>
            </div>

            <p class="text-muted-foreground text-xs">
                {{ t('Waste is recorded for information only. It creates no stock, no lot and no product, and it takes no cost. Leave it blank if it was not measured.') }}
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
