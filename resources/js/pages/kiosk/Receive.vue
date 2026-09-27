<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import KioskChoice from '@/components/kiosk/KioskChoice.vue';
import KioskErrors from '@/components/kiosk/KioskErrors.vue';
import KioskNumberPad from '@/components/kiosk/KioskNumberPad.vue';
import KioskStep from '@/components/kiosk/KioskStep.vue';
import { plainNumber, useKioskText } from '@/kiosk/text';
import type { KioskParty, KioskProduct, KioskStore } from '@/kiosk/types';
import { home } from '@/routes/kiosk';
import { store as storeReceive } from '@/routes/kiosk/receive';

/**
 * Receive goods — one question per screen, then Done posts the goods receipt at once.
 *
 * Staff type the count, the weight where the product has one, and the price. Everything else —
 * landed cost allocation, final unit cost, the lot, stock — is calculated by the server's own
 * goods-receipt actions when Done is pressed. Nothing here calculates cost.
 */
const props = defineProps<{ suppliers: KioskParty[]; products: KioskProduct[]; warehouses: KioskStore[] }>();

const { t } = useKioskText();

type Line = { product_id: number; quantity: string; secondary_quantity: string; unit_price: string };

const form = useForm({
    supplier_id: null as number | null,
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    transport_cost: '',
    loading_cost: '',
    lines: [] as Line[],
});

const draft = ref<Line>({ product_id: 0, quantity: '', secondary_quantity: '', unit_price: '' });

const draftProduct = computed(() => props.products.find((p) => p.id === draft.value.product_id) ?? null);
const productOf = (id: number) => props.products.find((p) => p.id === id);

/** The questions, in order. The weight question only appears for products weighed too. */
const steps = computed(() => {
    const list = ['supplier', 'store', 'product', 'count'];

    if (draftProduct.value?.secondary_uom) {
        list.push('weight');
    }

    list.push('price', 'lines', 'transport', 'loading', 'review');

    return list;
});

const current = ref('supplier');
const index = computed(() => steps.value.indexOf(current.value));

function go(offset: number): void {
    const next = steps.value[index.value + offset];

    if (next === undefined) {
        router.visit(home().url);

        return;
    }

    current.value = next;
}

function back(): void {
    if (current.value === 'supplier') {
        router.visit(home().url);

        return;
    }

    // Stepping back from the list reopens the last product so it can be corrected.
    if (current.value === 'lines' && form.lines.length > 0) {
        draft.value = form.lines.pop() as Line;
    }

    go(-1);
}

function next(): void {
    if (current.value === 'price') {
        form.lines.push({ ...draft.value });
        draft.value = { product_id: 0, quantity: '', secondary_quantity: '', unit_price: '' };
    }

    if (current.value === 'review') {
        submit();

        return;
    }

    go(1);
}

function addAnother(): void {
    current.value = 'product';
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
        case 'supplier':
            return form.supplier_id !== null;
        case 'store':
            return form.warehouse_id !== null;
        case 'product':
            return draft.value.product_id !== 0;
        case 'count':
            return isPositive(draft.value.quantity);
        case 'price':
            return draft.value.unit_price !== '';
        case 'lines':
            return form.lines.length > 0;
        default:
            return true;
    }
});

const supplierName = computed(() => props.suppliers.find((s) => s.id === form.supplier_id)?.name);
const storeName = computed(() => props.warehouses.find((w) => w.id === form.warehouse_id)?.name);

function submit(): void {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            product_id: line.product_id,
            quantity: line.quantity,
            secondary_quantity: line.secondary_quantity === '' ? null : line.secondary_quantity,
            unit_price: line.unit_price,
        })),
    })).post(storeReceive().url);
}
</script>

<template>
    <KioskStep
        :title="
            {
                supplier: t('pick.supplier'),
                store: t('pick.store'),
                product: t('pick.product'),
                count: draftProduct?.name ?? '',
                weight: draftProduct?.name ?? '',
                price: draftProduct?.name ?? '',
                lines: t('task.receive'),
                transport: t('enter.transport'),
                loading: t('enter.loading'),
                review: t('common.check'),
            }[current] ?? ''
        "
        :hint="['transport', 'loading', 'weight'].includes(current) ? t('common.optional') : null"
        :step="index + 1"
        :total="steps.length"
        :can-next="canNext"
        :final="current === 'review'"
        :busy="form.processing"
        @back="back"
        @next="next"
    >
        <KioskChoice
            v-if="current === 'supplier'"
            v-model="form.supplier_id"
            :items="suppliers.map((s) => ({ id: s.id, title: s.name, subtitle: s.code }))"
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
            :items="products.map((p) => ({ id: p.id, title: p.name, badge: p.uom }))"
            @update:model-value="(id) => (draft.product_id = Number(id))"
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

        <KioskNumberPad
            v-else-if="current === 'price'"
            v-model="draft.unit_price"
            :label="t('enter.price', { uom: draftProduct?.uom ?? '' })"
            :decimals="2"
        />

        <div v-else-if="current === 'lines'" class="flex flex-col gap-3">
            <div v-for="(line, position) in form.lines" :key="position" class="bg-background border-sidebar-border/70 flex items-center gap-3 rounded-2xl border p-4">
                <div class="flex-1">
                    <p class="text-xl font-semibold">{{ productOf(line.product_id)?.name }}</p>
                    <p class="font-mono text-lg tabular-nums">
                        {{ plainNumber(line.quantity) }} {{ productOf(line.product_id)?.uom }}
                        <template v-if="line.secondary_quantity">· {{ plainNumber(line.secondary_quantity) }} {{ productOf(line.product_id)?.secondary_uom }}</template>
                        · Rs. {{ plainNumber(line.unit_price) }} {{ t('common.each') }}
                    </p>
                </div>
                <button type="button" class="flex min-h-12 min-w-12 items-center justify-center rounded-xl text-red-700" :aria-label="t('common.remove')" @click="removeLine(position)">
                    <Trash2 class="size-6" />
                </button>
            </div>
            <button type="button" class="border-sidebar-border flex min-h-16 items-center justify-center gap-2 rounded-2xl border-2 border-dashed text-xl font-semibold" @click="addAnother">
                <Plus class="size-6" />
                {{ t('common.addAnother') }}
            </button>
        </div>

        <KioskNumberPad v-else-if="current === 'transport'" v-model="form.transport_cost" :label="t('enter.transport')" :decimals="2" />

        <KioskNumberPad v-else-if="current === 'loading'" v-model="form.loading_cost" :label="t('enter.loading')" :decimals="2" />

        <div v-else-if="current === 'review'" class="flex flex-col gap-4">
            <KioskErrors :errors="form.errors" />
            <div class="bg-background border-sidebar-border/70 rounded-2xl border p-5 text-xl">
                <p><span class="text-muted-foreground">{{ t('pick.supplier') }}</span> <strong>{{ supplierName }}</strong></p>
                <p><span class="text-muted-foreground">{{ t('common.store') }}:</span> <strong>{{ storeName }}</strong></p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li v-for="(line, position) in form.lines" :key="position" class="bg-muted/50 rounded-xl p-3">
                        <strong>{{ productOf(line.product_id)?.name }}</strong>:
                        <span class="font-mono tabular-nums">{{ plainNumber(line.quantity) }} {{ productOf(line.product_id)?.uom }}</span>
                        <span v-if="line.secondary_quantity" class="font-mono tabular-nums"> · {{ plainNumber(line.secondary_quantity) }} {{ productOf(line.product_id)?.secondary_uom }}</span>
                        · Rs. {{ plainNumber(line.unit_price) }} {{ t('common.each') }}
                    </li>
                </ul>
                <p v-if="form.transport_cost" class="mt-3">{{ t('enter.transport') }}: Rs. {{ plainNumber(form.transport_cost) }}</p>
                <p v-if="form.loading_cost">{{ t('enter.loading') }}: Rs. {{ plainNumber(form.loading_cost) }}</p>
            </div>
        </div>
    </KioskStep>
</template>
