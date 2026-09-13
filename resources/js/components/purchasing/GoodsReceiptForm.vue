<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OptionRef, ProductOption, PurchaseOrderOption } from '@/types';

type LineInput = {
    product_id: string;
    purchase_order_line_id: string;
    quantity: string;
    secondary_quantity: string;
    unit_price: string;
    supplier_lot_number: string;
};

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    suppliers: OptionRef[];
    warehouses: OptionRef[];
    products: ProductOption[];
    purchaseOrders: PurchaseOrderOption[];
    initial?: {
        supplier_id: number | null;
        purchase_order_id: number | null;
        warehouse_id: number | null;
        receipt_date: string | null;
        supplier_delivery_reference: string | null;
        transport_cost: string;
        loading_cost: string;
        other_cost: string;
        notes: string | null;
        lines: {
            product_id: number;
            purchase_order_line_id: number | null;
            quantity: string;
            secondary_quantity: string | null;
            unit_price: string;
            supplier_lot_number: string | null;
        }[];
    } | null;
}>();

const today = new Date().toISOString().slice(0, 10);

function emptyLine(): LineInput {
    return {
        product_id: '',
        purchase_order_line_id: '',
        quantity: '',
        secondary_quantity: '',
        unit_price: '',
        supplier_lot_number: '',
    };
}

const form = useForm({
    supplier_id: props.initial?.supplier_id ? String(props.initial.supplier_id) : '',
    purchase_order_id: props.initial?.purchase_order_id
        ? String(props.initial.purchase_order_id)
        : '',
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    receipt_date: props.initial?.receipt_date ?? today,
    supplier_delivery_reference: props.initial?.supplier_delivery_reference ?? '',
    transport_cost: props.initial?.transport_cost ?? '0',
    loading_cost: props.initial?.loading_cost ?? '0',
    other_cost: props.initial?.other_cost ?? '0',
    notes: props.initial?.notes ?? '',
    lines: (props.initial?.lines ?? []).map(
        (line): LineInput => ({
            product_id: String(line.product_id),
            purchase_order_line_id: line.purchase_order_line_id
                ? String(line.purchase_order_line_id)
                : '',
            quantity: line.quantity,
            secondary_quantity: line.secondary_quantity ?? '',
            unit_price: line.unit_price,
            supplier_lot_number: line.supplier_lot_number ?? '',
        }),
    ),
});

if (form.lines.length === 0) {
    form.lines.push(emptyLine());
}

const selectedOrder = computed(() =>
    props.purchaseOrders.find((o) => String(o.id) === form.purchase_order_id),
);

/**
 * Selecting a purchase order pre-fills the receipt from its outstanding lines, so the
 * common case needs no retyping. Values remain editable — a delivery may differ.
 */
watch(
    () => form.purchase_order_id,
    (value) => {
        if (!value) {
            return;
        }

        const order = props.purchaseOrders.find((o) => String(o.id) === value);

        if (!order) {
            return;
        }

        form.supplier_id = String(order.supplier_id);
        form.warehouse_id = String(order.warehouse_id);

        if (order.lines.length > 0) {
            form.lines = order.lines.map((line) => ({
                product_id: String(line.product_id),
                purchase_order_line_id: String(line.id),
                quantity: line.outstanding_quantity,
                secondary_quantity: '',
                unit_price: line.unit_price,
                supplier_lot_number: '',
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

function productFor(productId: string): ProductOption | undefined {
    return props.products.find((p) => String(p.id) === productId);
}

function materialFor(line: LineInput): number {
    const quantity = Number(line.quantity);
    const price = Number(line.unit_price);

    return Number.isFinite(quantity) && Number.isFinite(price) ? quantity * price : 0;
}

const materialTotal = computed(() =>
    form.lines.reduce((carry, line) => carry + materialFor(line), 0),
);

const landedTotal = computed(
    () =>
        Number(form.transport_cost || 0) +
        Number(form.loading_cost || 0) +
        Number(form.other_cost || 0),
);

const grandTotal = computed(() => materialTotal.value + landedTotal.value);

/**
 * Preview of the allocation the server will perform: landed cost is shared out in
 * proportion to each line's material value, never by quantity. Display only — the posting
 * service recalculates this authoritatively, and the user can never type it in.
 */
function allocatedFor(line: LineInput): number {
    if (materialTotal.value === 0) {
        return 0;
    }

    return landedTotal.value * (materialFor(line) / materialTotal.value);
}

function finalUnitCostFor(line: LineInput): string {
    const quantity = Number(line.quantity);

    if (!Number.isFinite(quantity) || quantity === 0) {
        return '—';
    }

    return ((materialFor(line) + allocatedFor(line)) / quantity).toFixed(6);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            ...line,
            purchase_order_line_id: line.purchase_order_line_id || null,
            secondary_quantity: line.secondary_quantity === '' ? null : line.secondary_quantity,
            supplier_lot_number: line.supplier_lot_number || null,
        })),
    }));

    form.submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Receipt details</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="purchase_order_id">Purchase order (optional)</Label>
                    <select id="purchase_order_id" v-model="form.purchase_order_id" :class="selectClass">
                        <option value="">Direct purchase — no purchase order</option>
                        <option v-for="o in purchaseOrders" :key="o.id" :value="String(o.id)">
                            {{ o.po_number }}
                        </option>
                    </select>
                    <p class="text-muted-foreground text-xs">
                        Selecting an order fills the lines from its outstanding quantities.
                    </p>
                    <InputError :message="form.errors.purchase_order_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="grn_supplier">Supplier <span class="text-destructive">*</span></Label>
                    <select
                        id="grn_supplier"
                        v-model="form.supplier_id"
                        required
                        :disabled="!!selectedOrder"
                        :class="selectClass"
                    >
                        <option value="" disabled>Select a supplier…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
                            {{ s.code }} — {{ s.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="grn_warehouse">Warehouse <span class="text-destructive">*</span></Label>
                    <select
                        id="grn_warehouse"
                        v-model="form.warehouse_id"
                        required
                        :class="selectClass"
                    >
                        <option value="" disabled>Select a warehouse…</option>
                        <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                            {{ w.code }} — {{ w.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.warehouse_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="receipt_date">Receipt date <span class="text-destructive">*</span></Label>
                    <Input id="receipt_date" v-model="form.receipt_date" type="date" required />
                    <InputError :message="form.errors.receipt_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="delivery_ref">Supplier delivery reference</Label>
                    <Input id="delivery_ref" v-model="form.supplier_delivery_reference" autocomplete="off" />
                    <InputError :message="form.errors.supplier_delivery_reference" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">Lines</h3>
                <Button type="button" variant="outline" size="sm" @click="addLine">
                    <Plus class="size-4" />
                    Add line
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
                        <div class="grid gap-1.5 sm:col-span-4">
                            <Label :for="`grn-product-${index}`" class="text-xs">Product</Label>
                            <select
                                :id="`grn-product-${index}`"
                                v-model="line.product_id"
                                required
                                :class="selectClass"
                            >
                                <option value="" disabled>Select…</option>
                                <option v-for="p in products" :key="p.id" :value="String(p.id)">
                                    {{ p.code }} — {{ p.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors[`lines.${index}.product_id`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`grn-qty-${index}`" class="text-xs">
                                Quantity ({{ productFor(line.product_id)?.uom ?? '—' }})
                            </Label>
                            <Input
                                :id="`grn-qty-${index}`"
                                v-model="line.quantity"
                                type="number"
                                step="0.001"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors[`lines.${index}.quantity`]" />
                        </div>

                        <div
                            v-if="productFor(line.product_id)?.secondary_uom"
                            class="grid gap-1.5 sm:col-span-2"
                        >
                            <Label :for="`grn-sec-${index}`" class="text-xs">
                                {{ productFor(line.product_id)?.secondary_uom }}
                            </Label>
                            <Input
                                :id="`grn-sec-${index}`"
                                v-model="line.secondary_quantity"
                                type="number"
                                step="0.001"
                                min="0"
                            />
                            <InputError :message="form.errors[`lines.${index}.secondary_quantity`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`grn-price-${index}`" class="text-xs">Unit price</Label>
                            <Input
                                :id="`grn-price-${index}`"
                                v-model="line.unit_price"
                                type="number"
                                step="0.000001"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors[`lines.${index}.unit_price`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`grn-lot-${index}`" class="text-xs">Supplier lot</Label>
                            <Input :id="`grn-lot-${index}`" v-model="line.supplier_lot_number" />
                        </div>

                        <div class="sm:col-span-12">
                            <div
                                class="text-muted-foreground flex flex-wrap items-center justify-between gap-3 text-xs"
                            >
                                <span>
                                    Material:
                                    <span class="font-mono">{{ materialFor(line).toFixed(2) }}</span>
                                    · Landed share:
                                    <span class="font-mono">{{ allocatedFor(line).toFixed(2) }}</span>
                                    · Final unit cost:
                                    <span class="text-foreground font-mono font-medium">
                                        {{ finalUnitCostFor(line) }}
                                    </span>
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.lines.length === 1"
                                    aria-label="Remove line"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">Landed costs</h3>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="transport_cost">Transport</Label>
                    <Input id="transport_cost" v-model="form.transport_cost" type="number" step="0.01" min="0" required />
                    <InputError :message="form.errors.transport_cost" />
                </div>
                <div class="grid gap-2">
                    <Label for="loading_cost">Loading</Label>
                    <Input id="loading_cost" v-model="form.loading_cost" type="number" step="0.01" min="0" required />
                    <InputError :message="form.errors.loading_cost" />
                </div>
                <div class="grid gap-2">
                    <Label for="other_cost">Other</Label>
                    <Input id="other_cost" v-model="form.other_cost" type="number" step="0.01" min="0" required />
                    <InputError :message="form.errors.other_cost" />
                </div>
            </div>

            <div
                class="border-sidebar-border/70 dark:border-sidebar-border grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-3"
            >
                <p>
                    Material total:
                    <span class="ml-1 font-mono font-medium">{{ materialTotal.toFixed(2) }}</span>
                </p>
                <p>
                    Landed costs:
                    <span class="ml-1 font-mono font-medium">{{ landedTotal.toFixed(2) }}</span>
                </p>
                <p>
                    Grand landed cost:
                    <span class="ml-1 font-mono font-medium">{{ grandTotal.toFixed(2) }}</span>
                </p>
            </div>

            <p class="text-muted-foreground text-xs">
                Landed costs are shared across lines in proportion to each line's material
                value, never by quantity. These figures are a preview — the final cost
                posted to inventory is calculated by the server when the receipt is posted.
            </p>
        </section>

        <div class="grid gap-2">
            <Label for="grn_notes">Notes</Label>
            <textarea
                id="grn_notes"
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
