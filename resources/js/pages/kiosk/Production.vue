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
 *
 * A recipe that takes no input now (`consumes_input` false — husk chipping) asks for no
 * batch and no count: the worker picks what was made and measures only those; the raw husk
 * is deducted later, at dispatch. A recipe with `labour` (dehusking) asks for the labour per
 * coconut, pre-filled from its default.
 */
const props = defineProps<{
    task: 'dehusking' | 'husk-chipping' | 'copra';
    recipe: KioskRecipe;
    warehouses: KioskStore[];
}>();

const { t } = useKioskText();

type Measure = { output: KioskRecipeOutput; field: 'quantity' | 'secondary_quantity' };

/** For a run with flexible outputs: which products the worker says were made. */
const made = ref<number[]>([]);
const flexible = computed(() => !props.recipe.consumes_input);

function toggleMade(id: number): void {
    made.value = made.value.includes(id) ? made.value.filter((m) => m !== id) : [...made.value, id];
}

/** Every value the worker must weigh, one screen each. */
const measures = computed<Measure[]>(() =>
    props.recipe.outputs.filter((output) => !flexible.value || made.value.includes(output.id)).flatMap((output) => [
        ...(output.derived ? [] : [{ output, field: 'quantity' as const }]),
        ...(output.requires_secondary_quantity ? [{ output, field: 'secondary_quantity' as const }] : []),
    ]),
);

const form = useForm({
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    lot_id: null as number | null,
    quantity: '',
    // "4.000000" from the server becomes "4", so the keypad can still type into it.
    labour_rate: (props.recipe.labour?.default_rate ?? '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''),
});

/** Measured values keyed "productId:field". */
const values = ref<Record<string, string>>({});
const key = (m: Measure) => `${m.output.id}:${m.field}`;

const inStore = (rows: { warehouse_id: number; quantity: string }[]) => rows.find((r) => r.warehouse_id === form.warehouse_id)?.quantity ?? null;
const lotsHere = computed(() => props.recipe.lots.filter((lot) => inStore(lot.stock) !== null));
const chosenLot = computed(() => props.recipe.lots.find((l) => l.id === form.lot_id) ?? null);

const steps = computed(() => [
    'store',
    ...(props.recipe.consumes_input && props.recipe.input_tracks_lots ? ['lot'] : []),
    ...(props.recipe.consumes_input ? ['count'] : []),
    ...(props.recipe.labour ? ['labour'] : []),
    ...(flexible.value ? ['made'] : []),
    ...measures.value.map((m) => `measure:${key(m)}`),
    'review',
]);
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

    if (current.value === 'labour') {
        return form.labour_rate !== '' && Number(form.labour_rate) >= 0;
    }

    if (current.value === 'made') {
        return made.value.length > 0;
    }

    if (currentMeasure.value) {
        const value = values.value[key(currentMeasure.value)] ?? '';

        // A flexible run only lists what was made, so every amount must be above zero.
        return value !== '' && (!flexible.value || Number(value) > 0);
    }

    return true;
});

function measureLabel(m: Measure): string {
    const unit = m.field === 'quantity' ? m.output.uom : m.output.secondary_uom;

    return `${m.output.name} — ${t('enter.weight', { uom: unit ?? '' })}`;
}

const storeName = computed(() => props.warehouses.find((w) => w.id === form.warehouse_id)?.name);

function submit(): void {
    const outputs = props.recipe.outputs.filter((output) => !flexible.value || made.value.includes(output.id)).map((output) => ({
        product_id: output.id,
        quantity: output.derived ? null : (values.value[`${output.id}:quantity`] ?? null),
        secondary_quantity: output.requires_secondary_quantity ? (values.value[`${output.id}:secondary_quantity`] ?? null) : null,
    }));

    const url = { dehusking: storeDehusking(), 'husk-chipping': storeHuskChipping(), copra: storeCopra() }[props.task].url;

    // Dehusking outputs are all derived, so it sends none — exactly like the full ERP form. A
    // run that takes no input sends no batch and no count; only dehusking sends a labour rate.
    form.transform((data) => {
        const payload: Record<string, unknown> = { warehouse_id: data.warehouse_id };

        if (props.recipe.consumes_input) {
            payload.quantity = data.quantity;
            payload.lot_id = props.recipe.input_tracks_lots ? data.lot_id : null;
        }

        if (props.recipe.labour) {
            payload.labour_rate = data.labour_rate;
        }

        return props.task === 'dehusking' ? payload : { ...payload, outputs };
    }).post(url);
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
                    : current === 'labour'
                      ? t('prod.labour')
                      : current === 'made'
                        ? t('prod.made')
                        : current === 'review'
                          ? t('common.check')
                          : t('prod.outputs')
        "
        :hint="
            current === 'lot'
                ? t('pick.lot.hint')
                : current === 'labour'
                  ? t('prod.labour.hint')
                  : current === 'made'
                    ? `${t('prod.made.hint')} ${t('prod.huskLater')}`
                    : currentMeasure && flexible
                      ? t('prod.measure.hint')
                      : null
        "
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
            v-else-if="current === 'labour'"
            v-model="form.labour_rate"
            :label="t('prod.labour')"
            :decimals="2"
        />

        <div v-else-if="current === 'made'" class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="output in recipe.outputs"
                :key="output.id"
                type="button"
                class="rounded-2xl border-2 p-5 text-left text-xl"
                :class="made.includes(output.id) ? 'border-blue-700 bg-blue-50 dark:bg-blue-950' : 'border-sidebar-border/70 bg-background'"
                @click="toggleMade(output.id)"
            >
                <span class="font-medium">{{ output.name }}</span>
                <span class="text-muted-foreground block text-base">{{ output.uom }}</span>
            </button>
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
                <p v-if="recipe.consumes_input" class="mt-2">
                    <strong>{{ recipe.input.name }}</strong>:
                    <span class="font-mono tabular-nums">{{ plainNumber(form.quantity) }} {{ recipe.input.uom }}</span>
                </p>
                <p v-else class="text-muted-foreground mt-2 text-base">{{ t('prod.huskLater') }}</p>
                <p v-if="recipe.labour" class="mt-1">
                    {{ t('prod.labour') }}: <span class="font-mono tabular-nums">{{ plainNumber(form.labour_rate) }}</span>
                </p>
                <p v-if="chosenLot" class="text-muted-foreground font-mono text-sm">{{ chosenLot.lot_number }}</p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li
                        v-for="output in recipe.outputs.filter((o) => !flexible || made.includes(o.id))"
                        :key="output.id" class="bg-muted/50 rounded-xl p-3">
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
