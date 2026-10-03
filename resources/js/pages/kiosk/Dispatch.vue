<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import KioskChoice from '@/components/kiosk/KioskChoice.vue';
import KioskErrors from '@/components/kiosk/KioskErrors.vue';
import KioskNumberPad from '@/components/kiosk/KioskNumberPad.vue';
import KioskStep from '@/components/kiosk/KioskStep.vue';
import { plainNumber, useKioskText } from '@/kiosk/text';
import type { KioskParty, KioskStockedProduct, KioskStore } from '@/kiosk/types';
import { home } from '@/routes/kiosk';
import { store as storeDispatch } from '@/routes/kiosk/dispatch';

/**
 * Dispatch — goods leaving to a customer. Done posts the delivery at once.
 *
 * Staff choose the batch (never assumed — our rule), type the quantity, the weight where the
 * product is weighed too, and the selling price. The cost of what leaves is decided by the
 * server at the weighted average; the screen never states a cost. "By count / by weight" only
 * appears for a product that has a second unit. Pooled stock has no batch to choose. A product
 * that deducts a raw material when it leaves (chips, cocopeat) asks how many raw husks it used.
 */
const props = defineProps<{ customers: KioskParty[]; products: KioskStockedProduct[]; warehouses: KioskStore[] }>();

const { t } = useKioskText();

type Line = {
    product_id: number;
    lot_id: number | null;
    quantity: string;
    secondary_quantity: string;
    pricing_basis: 'PRIMARY' | 'SECONDARY';
    unit_price: string;
    backflush_quantity: string;
};

const emptyLine = (): Line => ({
    product_id: 0,
    lot_id: null,
    quantity: '',
    secondary_quantity: '',
    pricing_basis: 'PRIMARY',
    unit_price: '',
    backflush_quantity: '',
});

const form = useForm({
    customer_id: null as number | null,
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    vehicle_number: '',
    lines: [] as Line[],
});

const draft = ref<Line>(emptyLine());
const productOf = (id: number) => props.products.find((p) => p.id === id);
const draftProduct = computed(() => productOf(draft.value.product_id) ?? null);

const inStore = (rows: { warehouse_id: number; quantity: string }[]) => rows.find((r) => r.warehouse_id === form.warehouse_id)?.quantity ?? null;

/** Products with stock in the chosen store. */
const productsHere = computed(() => props.products.filter((p) => inStore(p.stock) !== null));

/** The chosen product's batches that hold stock in the chosen store. */
const lotsHere = computed(() => (draftProduct.value?.lots ?? []).filter((lot) => inStore(lot.stock) !== null));

const steps = computed(() => {
    const list = ['customer', 'store', 'product'];

    if (lotsHere.value.length > 0) {
        list.push('lot');
    }

    list.push('count');

    if (draftProduct.value?.secondary_uom) {
        list.push('weight', 'basis');
    }

    if (draftProduct.value?.backflush) {
        list.push('husks');
    }

    list.push('price', 'lines', 'vehicle', 'review');

    return list;
});

const current = ref('customer');
const index = computed(() => steps.value.indexOf(current.value));

function go(offset: number): void {
    current.value = steps.value[index.value + offset] ?? current.value;
}

function back(): void {
    if (current.value === 'customer') {
        router.visit(home().url);

        return;
    }

    if (current.value === 'lines' && form.lines.length > 0) {
        draft.value = form.lines.pop() as Line;
    }

    go(-1);
}

function next(): void {
    if (current.value === 'price') {
        form.lines.push({ ...draft.value });
        draft.value = emptyLine();
    }

    if (current.value === 'review') {
        submit();

        return;
    }

    go(1);
}

function chooseProduct(id: number | string): void {
    draft.value = { ...emptyLine(), product_id: Number(id) };
    go(1);
}

function removeLine(position: number): void {
    form.lines.splice(position, 1);

    if (form.lines.length === 0) {
        current.value = 'product';
    }
}

const isPositive = (value: string) => value !== '' && Number(value) > 0;

const canNext = computed(() => {
    switch (current.value) {
        case 'customer':
            return form.customer_id !== null;
        case 'store':
            return form.warehouse_id !== null;
        case 'product':
            return draft.value.product_id !== 0;
        case 'lot':
            return draft.value.lot_id !== null;
        case 'count':
            return isPositive(draft.value.quantity);
        case 'weight':
            return draft.value.pricing_basis === 'PRIMARY' || isPositive(draft.value.secondary_quantity);
        case 'basis':
            return draft.value.pricing_basis === 'PRIMARY' || isPositive(draft.value.secondary_quantity);
        case 'husks':
            return isPositive(draft.value.backflush_quantity);
        case 'price':
            return draft.value.unit_price !== '';
        case 'lines':
            return form.lines.length > 0;
        default:
            return true;
    }
});

const customerName = computed(() => props.customers.find((c) => c.id === form.customer_id)?.name);
const storeName = computed(() => props.warehouses.find((w) => w.id === form.warehouse_id)?.name);

const lotOf = (line: Line) => productOf(line.product_id)?.lots.find((l) => l.id === line.lot_id)?.lot_number;
const priceUnit = (line: Line) => (line.pricing_basis === 'SECONDARY' ? productOf(line.product_id)?.secondary_uom : productOf(line.product_id)?.uom);

function submit(): void {
    form.transform((data) => ({
        ...data,
        vehicle_number: data.vehicle_number || null,
        lines: data.lines.map((line) => ({
            ...line,
            secondary_quantity: line.secondary_quantity === '' ? null : line.secondary_quantity,
            backflush_quantity: productOf(line.product_id)?.backflush ? line.backflush_quantity : null,
        })),
    })).post(storeDispatch().url);
}
</script>

<template>
    <KioskStep
        :title="
            {
                customer: t('pick.customer'),
                store: t('pick.store'),
                product: t('pick.product'),
                lot: t('pick.lot'),
                count: draftProduct?.name ?? '',
                weight: draftProduct?.name ?? '',
                basis: t('price.by'),
                husks: draftProduct?.name ?? '',
                price: draftProduct?.name ?? '',
                lines: t('task.dispatch'),
                vehicle: t('enter.vehicle'),
                review: t('common.check'),
            }[current] ?? ''
        "
        :hint="
            current === 'lot'
                ? t('pick.lot.hint')
                : current === 'vehicle'
                  ? t('common.optional')
                  : current === 'husks'
                    ? t('dispatch.husks.hint')
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
            v-if="current === 'customer'"
            v-model="form.customer_id"
            :items="customers.map((c) => ({ id: c.id, title: c.name, subtitle: c.code }))"
            @chosen="go(1)"
        />

        <KioskChoice
            v-else-if="current === 'store'"
            v-model="form.warehouse_id"
            :items="warehouses.map((w) => ({ id: w.id, title: w.name, subtitle: w.code }))"
        />

        <KioskChoice
            v-else-if="current === 'product'"
            :model-value="draft.product_id || null"
            :items="
                productsHere.map((p) => ({
                    id: p.id,
                    title: p.name,
                    badge: `${plainNumber(inStore(p.stock))} ${p.uom ?? ''}`,
                }))
            "
            @chosen="chooseProduct"
        />

        <KioskChoice
            v-else-if="current === 'lot'"
            :model-value="draft.lot_id"
            :items="
                lotsHere.map((lot) => ({
                    id: lot.id,
                    title: lot.received_at ? `${t('lot.received')} ${lot.received_at}` : lot.lot_number,
                    subtitle: lot.lot_number,
                    badge: `${plainNumber(inStore(lot.stock))} ${t('lot.left')}`,
                }))
            "
            @update:model-value="(id) => (draft.lot_id = Number(id))"
            @chosen="go(1)"
        />

        <KioskNumberPad
            v-else-if="current === 'count'"
            v-model="draft.quantity"
            :label="t('enter.count', { uom: draftProduct?.uom ?? '' })"
            :decimals="draftProduct?.decimals ?? 3"
        />

        <KioskNumberPad
            v-else-if="current === 'weight'"
            v-model="draft.secondary_quantity"
            :label="t('enter.weight', { uom: draftProduct?.secondary_uom ?? '' })"
            :decimals="draftProduct?.secondary_decimals ?? 3"
        />

        <div v-else-if="current === 'basis'" class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="basis in (['PRIMARY', 'SECONDARY'] as const)"
                :key="basis"
                type="button"
                class="bg-background min-h-24 rounded-2xl border-2 px-5 text-2xl font-semibold"
                :class="draft.pricing_basis === basis ? 'border-green-600 ring-2 ring-green-600/30' : 'border-sidebar-border/70'"
                @click="draft.pricing_basis = basis"
            >
                {{ basis === 'PRIMARY' ? t('price.byCount') : t('price.byWeight') }}
            </button>
        </div>

        <KioskNumberPad
            v-else-if="current === 'husks'"
            v-model="draft.backflush_quantity"
            :label="t('dispatch.husks', { uom: draftProduct?.backflush?.uom ?? '' })"
            :decimals="draftProduct?.backflush?.decimals ?? 0"
        />

        <KioskNumberPad
            v-else-if="current === 'price'"
            v-model="draft.unit_price"
            :label="draft.pricing_basis === 'SECONDARY' ? t('enter.priceKg') : t('enter.price', { uom: draftProduct?.uom ?? '' })"
            :decimals="2"
        />

        <div v-else-if="current === 'lines'" class="flex flex-col gap-3">
            <div v-for="(line, position) in form.lines" :key="position" class="bg-background border-sidebar-border/70 flex items-center gap-3 rounded-2xl border p-4">
                <div class="flex-1">
                    <p class="text-xl font-semibold">{{ productOf(line.product_id)?.name }}</p>
                    <p class="font-mono text-lg tabular-nums">
                        {{ plainNumber(line.quantity) }} {{ productOf(line.product_id)?.uom }}
                        <template v-if="line.secondary_quantity">· {{ plainNumber(line.secondary_quantity) }} {{ productOf(line.product_id)?.secondary_uom }}</template>
                        · Rs. {{ plainNumber(line.unit_price) }} / {{ priceUnit(line) }}
                    </p>
                    <p v-if="line.lot_id" class="text-muted-foreground font-mono text-sm">{{ lotOf(line) }}</p>
                    <p v-if="line.backflush_quantity" class="text-muted-foreground text-base">
                        {{ t('dispatch.husks', { uom: productOf(line.product_id)?.backflush?.uom ?? '' }) }}: {{ plainNumber(line.backflush_quantity) }}
                    </p>
                </div>
                <button type="button" class="flex min-h-12 min-w-12 items-center justify-center rounded-xl text-red-700" :aria-label="t('common.remove')" @click="removeLine(position)">
                    <Trash2 class="size-6" />
                </button>
            </div>
            <button type="button" class="border-sidebar-border flex min-h-16 items-center justify-center gap-2 rounded-2xl border-2 border-dashed text-xl font-semibold" @click="current = 'product'">
                <Plus class="size-6" />
                {{ t('common.addAnother') }}
            </button>
        </div>

        <input
            v-else-if="current === 'vehicle'"
            v-model="form.vehicle_number"
            type="text"
            autocomplete="off"
            maxlength="50"
            class="bg-background border-sidebar-border/70 min-h-20 w-full rounded-2xl border-2 px-5 font-mono text-3xl uppercase"
            placeholder="WP LX-1234"
        />

        <div v-else-if="current === 'review'" class="flex flex-col gap-4">
            <KioskErrors :errors="form.errors" />
            <div class="bg-background border-sidebar-border/70 rounded-2xl border p-5 text-xl">
                <p><span class="text-muted-foreground">{{ t('pick.customer') }}</span> <strong>{{ customerName }}</strong></p>
                <p><span class="text-muted-foreground">{{ t('common.store') }}:</span> <strong>{{ storeName }}</strong></p>
                <p v-if="form.vehicle_number"><span class="text-muted-foreground">{{ t('enter.vehicle') }}:</span> <strong class="font-mono uppercase">{{ form.vehicle_number }}</strong></p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li v-for="(line, position) in form.lines" :key="position" class="bg-muted/50 rounded-xl p-3">
                        <strong>{{ productOf(line.product_id)?.name }}</strong>:
                        <span class="font-mono tabular-nums">{{ plainNumber(line.quantity) }} {{ productOf(line.product_id)?.uom }}</span>
                        <span v-if="line.secondary_quantity" class="font-mono tabular-nums"> · {{ plainNumber(line.secondary_quantity) }} {{ productOf(line.product_id)?.secondary_uom }}</span>
                        · Rs. {{ plainNumber(line.unit_price) }} / {{ priceUnit(line) }}
                        <span v-if="line.backflush_quantity" class="text-muted-foreground block text-base">
                            {{ t('dispatch.husks', { uom: productOf(line.product_id)?.backflush?.uom ?? '' }) }}: {{ plainNumber(line.backflush_quantity) }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </KioskStep>
</template>
