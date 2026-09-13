<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OptionRef, ProductOption } from '@/types';

type LineInput = {
    product_id: string;
    quantity: string;
    unit_price: string;
};

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    suppliers: OptionRef[];
    warehouses: OptionRef[];
    products: ProductOption[];
    initial?: {
        supplier_id: number | null;
        warehouse_id: number | null;
        order_date: string | null;
        expected_date: string | null;
        supplier_reference: string | null;
        notes: string | null;
        lines: { product_id: number; quantity: string; unit_price: string }[];
    } | null;
}>();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    supplier_id: props.initial?.supplier_id ? String(props.initial.supplier_id) : '',
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    order_date: props.initial?.order_date ?? today,
    expected_date: props.initial?.expected_date ?? '',
    supplier_reference: props.initial?.supplier_reference ?? '',
    notes: props.initial?.notes ?? '',
    lines: (props.initial?.lines ?? []).map(
        (line): LineInput => ({
            product_id: String(line.product_id),
            quantity: line.quantity,
            unit_price: line.unit_price,
        }),
    ),
});

if (form.lines.length === 0) {
    form.lines.push({ product_id: '', quantity: '', unit_price: '' });
}

function addLine(): void {
    form.lines.push({ product_id: '', quantity: '', unit_price: '' });
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

function uomFor(productId: string): string {
    return props.products.find((p) => String(p.id) === productId)?.uom ?? '—';
}

function lineTotal(line: LineInput): string {
    const quantity = Number(line.quantity);
    const price = Number(line.unit_price);

    if (!Number.isFinite(quantity) || !Number.isFinite(price)) {
        return '0.00';
    }

    return (quantity * price).toFixed(2);
}

/** Display only. The server recalculates every figure it stores. */
const materialTotal = computed(() =>
    form.lines
        .reduce((carry, line) => carry + Number(lineTotal(line)), 0)
        .toFixed(2),
);

function submit(): void {
    form.submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Order details</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="supplier_id">Supplier <span class="text-destructive">*</span></Label>
                    <select id="supplier_id" v-model="form.supplier_id" required :class="selectClass">
                        <option value="" disabled>Select a supplier…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
                            {{ s.code }} — {{ s.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="warehouse_id">Deliver to warehouse <span class="text-destructive">*</span></Label>
                    <select id="warehouse_id" v-model="form.warehouse_id" required :class="selectClass">
                        <option value="" disabled>Select a warehouse…</option>
                        <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                            {{ w.code }} — {{ w.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.warehouse_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="order_date">Order date <span class="text-destructive">*</span></Label>
                    <Input id="order_date" v-model="form.order_date" type="date" required />
                    <InputError :message="form.errors.order_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="expected_date">Expected date</Label>
                    <Input id="expected_date" v-model="form.expected_date" type="date" />
                    <InputError :message="form.errors.expected_date" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="supplier_reference">Supplier reference</Label>
                    <Input id="supplier_reference" v-model="form.supplier_reference" autocomplete="off" />
                    <InputError :message="form.errors.supplier_reference" />
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
                        <div class="grid gap-1.5 sm:col-span-5">
                            <Label :for="`line-product-${index}`" class="text-xs">Product</Label>
                            <select
                                :id="`line-product-${index}`"
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
                            <Label :for="`line-qty-${index}`" class="text-xs">
                                Quantity ({{ uomFor(line.product_id) }})
                            </Label>
                            <Input
                                :id="`line-qty-${index}`"
                                v-model="line.quantity"
                                type="number"
                                step="0.001"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors[`lines.${index}.quantity`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`line-price-${index}`" class="text-xs">Unit price</Label>
                            <Input
                                :id="`line-price-${index}`"
                                v-model="line.unit_price"
                                type="number"
                                step="0.000001"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors[`lines.${index}.unit_price`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label class="text-xs">Line total</Label>
                            <p class="h-9 px-1 py-2 text-right font-mono text-sm">
                                {{ lineTotal(line) }}
                            </p>
                        </div>

                        <div class="sm:col-span-1 sm:justify-self-end">
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

            <div class="flex justify-end">
                <p class="text-sm">
                    Order total:
                    <span class="ml-2 font-mono font-medium">{{ materialTotal }}</span>
                </p>
            </div>
        </section>

        <div class="grid gap-2">
            <Label for="notes">Notes</Label>
            <textarea
                id="notes"
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
