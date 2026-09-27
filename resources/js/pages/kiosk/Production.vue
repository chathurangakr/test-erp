<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KioskChoice from '@/components/kiosk/KioskChoice.vue';
import KioskErrors from '@/components/kiosk/KioskErrors.vue';
import KioskNumberPad from '@/components/kiosk/KioskNumberPad.vue';
import KioskStep from '@/components/kiosk/KioskStep.vue';
import type { KioskTextKey } from '@/kiosk/text';
import { plainNumber, useKioskText } from '@/kiosk/text';
import type { KioskRecipe, KioskRecipeOutput, KioskStore } from '@/kiosk/types';
import { home } from '@/routes/kiosk';
import { store as storeCopra } from '@/routes/kiosk/copra';
import { store as storeDehusking } from '@/routes/kiosk/dehusking';
import { store as storeHuskChipping } from '@/routes/kiosk/husk-chipping';

/**
 * Dehusking, husk chipping and copra — one screen, driven by the recipe the server sends.
 *
 * Which amounts the worker types is read from the recipe, never from a product code:
 *  - an output with a ratio is DERIVED (dehusking's 1:1:1, copra's shell count) and is never
 *    asked for — the server calculates it;
 *  - an output without one is MEASURED (chip grades, copra KG) and must be weighed;
 *  - an output flagged requires_secondary_quantity also needs its weight (shell KG).
 * No conversion between count and weight exists here, or anywhere.
 */
const props = defineProps<{
    task: 'dehusking' | 'husk-chipping' | 'copra';
    recipe: KioskRecipe;
    warehouses: KioskStore[];
}>();

const { t } = useKioskText();

type Measure = { output: KioskRecipeOutput; field: 'quantity' | 'secondary_quantity' };

/** Every value the worker must weigh, one screen each. */
const measures = computed<Measure[]>(() =>
    props.recipe.outputs.flatMap((output) => [
        ...(output.derived ? [] : [{ output, field: 'quantity' as const }]),
        ...(output.requires_secondary_quantity ? [{ output, field: 'secondary_quantity' as const }] : []),
    ]),
);

const form = useForm({
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    lot_id: null as number | null,
    quantity: '',
});

/** Measured values keyed "productId:field". */
const values = ref<Record<string, string>>({});
const key = (m: Measure) => `${m.output.id}:${m.field}`;

const inStore = (rows: { warehouse_id: number; quantity: string }[]) => rows.find((r) => r.warehouse_id === form.warehouse_id)?.quantity ?? null;
const lotsHere = computed(() => props.recipe.lots.filter((lot) => inStore(lot.stock) !== null));
const chosenLot = computed(() => props.recipe.lots.find((l) => l.id === form.lot_id) ?? null);

const steps = computed(() => ['store', 'lot', 'count', ...measures.value.map((m) => `measure:${key(m)}`), 'review']);
const current = ref('store');
const index = computed(() => steps.value.indexOf(current.value));
const currentMeasure = computed(() => measures.value.find((m) => `measure:${key(m)}` === current.value) ?? null);

function go(offset: number): void {
    current.value = steps.value[index.value + offset] ?? current.value;
}

function back(): void {
    if (current.value === 'store') {
        router.visit(home().url);

        return;
    }

    go(-1);
}

function next(): void {
    if (current.value === 'review') {
        submit();

        return;
    }

    go(1);
}

const canNext = computed(() => {
    if (current.value === 'store') {
        return form.warehouse_id !== null;
    }

    if (current.value === 'lot') {
        return form.lot_id !== null;
    }

    if (current.value === 'count') {
        return form.quantity !== '' && Number(form.quantity) > 0;
    }

    if (currentMeasure.value) {
        // 0 is a valid weight for a grade that was not produced; empty is not.
        return (values.value[key(currentMeasure.value)] ?? '') !== '';
    }

    return true;
});

function measureLabel(m: Measure): string {
    const unit = m.field === 'quantity' ? m.output.uom : m.output.secondary_uom;

    return `${m.output.name} — ${t('enter.weight', { uom: unit ?? '' })}`;
}

const storeName = computed(() => props.warehouses.find((w) => w.id === form.warehouse_id)?.name);

function submit(): void {
    const outputs = props.recipe.outputs.map((output) => ({
        product_id: output.id,
        quantity: output.derived ? null : (values.value[`${output.id}:quantity`] ?? null),
        secondary_quantity: output.requires_secondary_quantity ? (values.value[`${output.id}:secondary_quantity`] ?? null) : null,
    }));

    const url = { dehusking: storeDehusking(), 'husk-chipping': storeHuskChipping(), copra: storeCopra() }[props.task].url;

    // Dehusking outputs are all derived, so it sends none — exactly like the full ERP form.
    form.transform((data) => (props.task === 'dehusking' ? data : { ...data, outputs })).post(url);
}
</script>

<template>
    <KioskStep
        :title="
            current === 'store'
                ? t('pick.store')
                : current === 'lot'
                  ? t('pick.lot')
                  : current === 'count'
                    ? t('prod.input')
                    : current === 'review'
                      ? t('common.check')
                      : t('prod.outputs')
        "
        :hint="current === 'lot' ? t('pick.lot.hint') : currentMeasure && task === 'husk-chipping' ? t('prod.outputs.hint') : null"
        :step="index + 1"
        :total="steps.length"
        :can-next="canNext"
        :final="current === 'review'"
        :busy="form.processing"
        @back="back"
        @next="next"
    >
        <KioskChoice
            v-if="current === 'store'"
            v-model="form.warehouse_id"
            :items="warehouses.map((w) => ({ id: w.id, title: w.name, subtitle: w.code }))"
            @update:model-value="form.lot_id = null"
        />

        <KioskChoice
            v-else-if="current === 'lot'"
            v-model="form.lot_id"
            :items="
                lotsHere.map((lot) => ({
                    id: lot.id,
                    title: lot.received_at ? `${t('lot.received')} ${lot.received_at}` : lot.lot_number,
                    subtitle: `${recipe.input.name} · ${lot.lot_number}`,
                    badge: `${plainNumber(inStore(lot.stock))} ${t('lot.left')}`,
                }))
            "
            @chosen="go(1)"
        />

        <div v-else-if="current === 'count'" class="flex flex-col gap-3">
            <p v-if="chosenLot" class="text-muted-foreground text-base">
                {{ t('common.available') }}: <span class="font-mono">{{ plainNumber(inStore(chosenLot.stock)) }} {{ recipe.input.uom }}</span>
            </p>
            <KioskNumberPad
                v-model="form.quantity"
                :label="`${recipe.input.name} — ${t('enter.count', { uom: recipe.input.uom ?? '' })}`"
                :decimals="recipe.input.decimals"
            />
        </div>

        <KioskNumberPad
            v-else-if="currentMeasure"
            :key="current"
            :model-value="values[`${currentMeasure.output.id}:${currentMeasure.field}`] ?? ''"
            :label="measureLabel(currentMeasure)"
            :decimals="currentMeasure.field === 'quantity' ? currentMeasure.output.decimals : currentMeasure.output.secondary_decimals"
            @update:model-value="(value) => (values[`${currentMeasure!.output.id}:${currentMeasure!.field}`] = value)"
        />

        <div v-else-if="current === 'review'" class="flex flex-col gap-4">
            <KioskErrors :errors="form.errors" />
            <div class="bg-background border-sidebar-border/70 rounded-2xl border p-5 text-xl">
                <p class="text-muted-foreground">{{ t(`task.${task}` as KioskTextKey) }} · {{ storeName }}</p>
                <p class="mt-2">
                    <strong>{{ recipe.input.name }}</strong>:
                    <span class="font-mono tabular-nums">{{ plainNumber(form.quantity) }} {{ recipe.input.uom }}</span>
                </p>
                <p v-if="chosenLot" class="text-muted-foreground font-mono text-sm">{{ chosenLot.lot_number }}</p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li v-for="output in recipe.outputs" :key="output.id" class="bg-muted/50 rounded-xl p-3">
                        <strong>{{ output.name }}</strong>:
                        <template v-if="output.derived">
                            <span class="text-muted-foreground"> {{ t('prod.auto') }}</span>
                        </template>
                        <span v-else class="font-mono tabular-nums"> {{ plainNumber(values[`${output.id}:quantity`]) }} {{ output.uom }}</span>
                        <span v-if="output.requires_secondary_quantity" class="font-mono tabular-nums">
                            · {{ plainNumber(values[`${output.id}:secondary_quantity`]) }} {{ output.secondary_uom }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </KioskStep>
</template>
