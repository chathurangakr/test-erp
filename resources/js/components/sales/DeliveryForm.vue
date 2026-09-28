<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type {
    LotOption,
    OptionRef,
    SalesOrderOption,
    SalesProductOption,
    StatusOption,
} from '@/types';
import { businessToday } from '@/lib/businessDate';

const { t } = useT();

type LineInput = {
    product_id: string;
    sales_order_line_id: string;
    lot_id: string;
    quantity: string;
    secondary_quantity: string;
    pricing_basis: string;
    unit_price: string;
};

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    customers: OptionRef[];
    warehouses: OptionRef[];
    products: SalesProductOption[];
    lots: LotOption[];
    salesOrders: SalesOrderOption[];
    pricingBases: StatusOption[];
    initial?: {
        customer_id: number | null;
        sales_order_id: number | null;
        warehouse_id: number | null;
        delivery_date: string | null;
        customer_reference: string | null;
        vehicle_number: string | null;
        driver_name: string | null;
        notes: string | null;
        lines: {
            product_id: number;
            sales_order_line_id: number | null;
            lot_id: number | null;
            quantity: string;
            secondary_quantity: string | null;
            pricing_basis: string;
            unit_price: string;
        }[];
    } | null;
}>();

const today = businessToday();

function emptyLine(): LineInput {
    return {
        product_id: '',
        sales_order_line_id: '',
        lot_id: '',
        quantity: '',
        secondary_quantity: '',
        pricing_basis: 'PRIMARY',
        unit_price: '',
    };
}

const form = useForm({
    customer_id: props.initial?.customer_id ? String(props.initial.customer_id) : '',
    sales_order_id: props.initial?.sales_order_id ? String(props.initial.sales_order_id) : '',
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    delivery_date: props.initial?.delivery_date ?? today,
    customer_reference: props.initial?.customer_reference ?? '',
    vehicle_number: props.initial?.vehicle_number ?? '',
    driver_name: props.initial?.driver_name ?? '',
    notes: props.initial?.notes ?? '',
    lines: (props.initial?.lines ?? []).map(
        (line): LineInput => ({
            product_id: String(line.product_id),
            sales_order_line_id: line.sales_order_line_id ? String(line.sales_order_line_id) : '',
            lot_id: line.lot_id ? String(line.lot_id) : '',
            quantity: line.quantity,
            secondary_quantity: line.secondary_quantity ?? '',
            pricing_basis: line.pricing_basis,
            unit_price: line.unit_price,
        }),
    ),
});

if (form.lines.length === 0) {
    form.lines.push(emptyLine());
}

const selectedOrder = computed(() =>
    props.salesOrders.find((o) => String(o.id) === form.sales_order_id),
);

/** Selecting a sales order pre-fills the lines from its outstanding quantities. */
watch(
    () => form.sales_order_id,
    (value) => {
        if (!value) {
            return;
        }

        const order = props.salesOrders.find((o) => String(o.id) === value);

        if (!order) {
            return;
        }

        form.customer_id = String(order.customer_id);
        form.warehouse_id = String(order.warehouse_id);

        if (order.lines.length > 0) {
            form.lines = order.lines.map((line) => ({
                product_id: String(line.product_id),
                sales_order_line_id: String(line.id),
                lot_id: '',
                quantity: line.outstanding_quantity,
                secondary_quantity: '',
                pricing_basis: line.pricing_basis,
                unit_price: line.unit_price,
            }));
        }
    },
);

function addLine(): void {
    form.lines.push(emptyLine());
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

function productFor(productId: string): SalesProductOption | undefined {
    return props.products.find((p) => String(p.id) === productId);
}

function supportsSecondary(productId: string): boolean {
    return !!productFor(productId)?.secondary_uom;
}

/**
 * Lots holding stock of this product in the chosen warehouse.
 *
 * The server requires an explicit lot whenever any lot holds stock, so the picker only
 * offers real, issuable sources rather than inviting a guess.
 */
function lotsFor(productId: string): LotOption[] {
    if (!productId || !form.warehouse_id) {
        return [];
    }

    return props.lots.filter(
        (lot) =>
            String(lot.product_id) === productId &&
            lot.warehouses.some((w) => String(w.warehouse_id) === form.warehouse_id),
    );
}

function lotQuantity(lot: LotOption): string {
    return (
        lot.warehouses.find((w) => String(w.warehouse_id) === form.warehouse_id)?.quantity ?? '0'
    );
}

/** Preselect when exactly one lot is eligible; posting still validates it. */
watch(
    () => form.lines.map((line) => `${line.product_id}:${form.warehouse_id}`).join('|'),
    () => {
        form.lines.forEach((line) => {
            const eligible = lotsFor(line.product_id);

            if (eligible.length === 1 && line.lot_id === '') {
                line.lot_id = String(eligible[0].id);
            }
        });
    },
);

function sellingQuantity(line: LineInput): number {
    const value = line.pricing_basis === 'SECONDARY' ? line.secondary_quantity : line.quantity;
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

function salesValue(line: LineInput): string {
    const price = Number(line.unit_price);

    return (sellingQuantity(line) * (Number.isFinite(price) ? price : 0)).toFixed(2);
}

const salesTotal = computed(() =>
    form.lines.reduce((carry, line) => carry + Number(salesValue(line)), 0).toFixed(2),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        sales_order_id: data.sales_order_id || null,
        lines: data.lines.map((line) => ({
            ...line,
            sales_order_line_id: line.sales_order_line_id || null,
            lot_id: line.lot_id || null,
            pricing_basis: supportsSecondary(line.product_id) ? line.pricing_basis : 'PRIMARY',
            secondary_quantity: line.secondary_quantity === '' ? null : line.secondary_quantity,
        })),
    }));

    form.submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Delivery details') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="sales_order_id">{{ t('Sales order (optional)') }}</Label>
                    <select id="sales_order_id" v-model="form.sales_order_id" :class="selectClass">
                        <option value="">{{ t('Direct delivery — no sales order') }}</option>
                        <option v-for="o in salesOrders" :key="o.id" :value="String(o.id)">
                            {{ o.so_number }}
                        </option>
                    </select>
                    <p class="text-muted-foreground text-xs">
                        {{ t('Selecting an order fills the lines from its outstanding quantities.') }}
                    </p>
                    <InputError :message="form.errors.sales_order_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="dn_customer">{{ t('Customer') }} <span class="text-destructive">*</span></Label>
                    <select
                        id="dn_customer"
                        v-model="form.customer_id"
                        required
                        :disabled="!!selectedOrder"
                        :class="selectClass"
                    >
                        <option value="" disabled>{{ t('Select a customer…') }}</option>
                        <option v-for="c in customers" :key="c.id" :value="String(c.id)">
                            {{ c.code }} — {{ c.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.customer_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="dn_warehouse">{{ t('Warehouse') }} <span class="text-destructive">*</span></Label>
                    <select id="dn_warehouse" v-model="form.warehouse_id" required :class="selectClass">
                        <option value="" disabled>{{ t('Select a warehouse…') }}</option>
                        <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                            {{ w.code }} — {{ w.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.warehouse_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="delivery_date">{{ t('Delivery date') }} <span class="text-destructive">*</span></Label>
                    <Input id="delivery_date" v-model="form.delivery_date" type="date" required />
                    <InputError :message="form.errors.delivery_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="dn_customer_ref">{{ t('Customer reference') }}</Label>
                    <Input id="dn_customer_ref" v-model="form.customer_reference" autocomplete="off" />
                    <InputError :message="form.errors.customer_reference" />
                </div>

                <div class="grid gap-2">
                    <Label for="vehicle_number">{{ t('Vehicle number') }}</Label>
                    <Input id="vehicle_number" v-model="form.vehicle_number" autocomplete="off" />
                    <InputError :message="form.errors.vehicle_number" />
                </div>

                <div class="grid gap-2">
                    <Label for="driver_name">{{ t('Driver name') }}</Label>
                    <Input id="driver_name" v-model="form.driver_name" autocomplete="off" />
                    <InputError :message="form.errors.driver_name" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">{{ t('Lines') }}</h3>
                <Button type="button" variant="outline" size="sm" @click="addLine">
                    <Plus class="size-4" />
                    {{ t('Add line') }}
                </Button>
            </div>

            <InputError :message="form.errors.lines" />

            <div class="space-y-3">
                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3"
                >
                    <div class="grid gap-3 sm:grid-cols-12 sm:items-end">
                        <div class="grid gap-1.5 sm:col-span-3">
                            <Label :for="`dn-product-${index}`" class="text-xs">{{ t('Product') }}</Label>
                            <select :id="`dn-product-${index}`" v-model="line.product_id" required :class="selectClass">
                                <option value="" disabled>{{ t('Select…') }}</option>
                                <option v-for="p in products" :key="p.id" :value="String(p.id)">
                                    {{ p.code }} — {{ p.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors[`lines.${index}.product_id`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-3">
                            <Label :for="`dn-lot-${index}`" class="text-xs">{{ t('Lot') }}</Label>
                            <select :id="`dn-lot-${index}`" v-model="line.lot_id" :class="selectClass">
                                <option value="">
                                    {{ lotsFor(line.product_id).length ? 'Select a lot…' : 'No lot' }}
                                </option>
                                <option v-for="lot in lotsFor(line.product_id)" :key="lot.id" :value="String(lot.id)">
                                    {{ lot.lot_number }} ({{ lotQuantity(lot) }})
                                </option>
                            </select>
                            <InputError :message="form.errors[`lines.${index}.lot_id`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`dn-qty-${index}`" class="text-xs">
                                {{ t('Quantity (:uom)', { uom: productFor(line.product_id)?.uom ?? '—' }) }}
                            </Label>
                            <Input :id="`dn-qty-${index}`" v-model="line.quantity" type="number" step="0.001" min="0" required />
                            <InputError :message="form.errors[`lines.${index}.quantity`]" />
                        </div>

                        <div v-if="supportsSecondary(line.product_id)" class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`dn-sec-${index}`" class="text-xs">
                                {{ productFor(line.product_id)?.secondary_uom }}
                            </Label>
                            <Input :id="`dn-sec-${index}`" v-model="line.secondary_quantity" type="number" step="0.001" min="0" />
                            <InputError :message="form.errors[`lines.${index}.secondary_quantity`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`dn-price-${index}`" class="text-xs">{{ t('Unit price') }}</Label>
                            <Input :id="`dn-price-${index}`" v-model="line.unit_price" type="number" step="0.000001" min="0" required />
                            <InputError :message="form.errors[`lines.${index}.unit_price`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-3">
                            <Label :for="`dn-basis-${index}`" class="text-xs">{{ t('Priced per') }}</Label>
                            <select
                                :id="`dn-basis-${index}`"
                                v-model="line.pricing_basis"
                                :disabled="!supportsSecondary(line.product_id)"
                                :class="selectClass"
                            >
                                <option value="PRIMARY">
                                    {{ productFor(line.product_id)?.uom ?? 'Primary unit' }}
                                </option>
                                <option v-if="supportsSecondary(line.product_id)" value="SECONDARY">
                                    {{ productFor(line.product_id)?.secondary_uom }}
                                </option>
                            </select>
                            <InputError :message="form.errors[`lines.${index}.pricing_basis`]" />
                        </div>

                        <div class="sm:col-span-12">
                            <div class="text-muted-foreground flex flex-wrap items-center justify-between gap-3 text-xs">
                                <span>
                                    {{ t('Sales value:') }}
                                    <span class="text-foreground font-mono font-medium">{{ salesValue(line) }}</span>
                                    {{ t('· Inventory cost is determined by the ledger when this delivery is posted.') }}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.lines.length === 1"
                                    :aria-label="t('Remove line')"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <p class="text-sm">
                    {{ t('Sales total:') }}
                    <span class="ml-2 font-mono font-medium">{{ salesTotal }}</span>
                </p>
            </div>
        </section>

        <div class="grid gap-2">
            <Label for="dn_notes">{{ t('Notes') }}</Label>
            <textarea
                id="dn_notes"
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
