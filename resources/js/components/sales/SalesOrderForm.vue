<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useT } from '@/lib/i18n';
import type { OptionRef, SalesProductOption, StatusOption } from '@/types';
import { businessToday } from '@/lib/businessDate';
import { money, moneyInput } from '@/lib/money';

const { t } = useT();

type LineInput = {
    product_id: string;
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
    pricingBases: StatusOption[];
    initial?: {
        customer_id: number | null;
        warehouse_id: number | null;
        order_date: string | null;
        expected_delivery_date: string | null;
        customer_reference: string | null;
        notes: string | null;
        lines: {
            product_id: number;
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
        quantity: '',
        secondary_quantity: '',
        pricing_basis: 'PRIMARY',
        unit_price: '',
    };
}

const form = useForm({
    customer_id: props.initial?.customer_id ? String(props.initial.customer_id) : '',
    warehouse_id: props.initial?.warehouse_id ? String(props.initial.warehouse_id) : '',
    order_date: props.initial?.order_date ?? today,
    expected_delivery_date: props.initial?.expected_delivery_date ?? '',
    customer_reference: props.initial?.customer_reference ?? '',
    notes: props.initial?.notes ?? '',
    lines: (props.initial?.lines ?? []).map(
        (line): LineInput => ({
            product_id: String(line.product_id),
            quantity: line.quantity,
            secondary_quantity: line.secondary_quantity ?? '',
            pricing_basis: line.pricing_basis,
            unit_price: moneyInput(line.unit_price),
        }),
    ),
});

if (form.lines.length === 0) {
    form.lines.push(emptyLine());
}

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

/** Only a product with a secondary unit may be priced on it. */
function supportsSecondary(productId: string): boolean {
    return !!productFor(productId)?.secondary_uom;
}

/**
 * The priced quantity follows the pricing basis — it is never typed separately.
 */
function sellingQuantity(line: LineInput): number {
    const value =
        line.pricing_basis === 'SECONDARY' ? line.secondary_quantity : line.quantity;
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

function lineTotal(line: LineInput): string {
    const price = Number(line.unit_price);

    return (sellingQuantity(line) * (Number.isFinite(price) ? price : 0)).toFixed(2);
}

const salesTotal = computed(() =>
    form.lines.reduce((carry, line) => carry + Number(lineTotal(line)), 0).toFixed(2),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            ...line,
            // A product without a secondary unit can never be priced on one.
            pricing_basis: supportsSecondary(line.product_id) ? line.pricing_basis : 'PRIMARY',
            secondary_quantity: line.secondary_quantity === '' ? null : line.secondary_quantity,
        })),
    }));

    form.submit(props.method, props.action, { preserveScroll: true });
}

</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Order details') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="customer_id">{{ t('Customer') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="customer_id"
                        v-model="form.customer_id"
                        required
                        :options="[...(customers).map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
                        :placeholder="t('Select a customer…')"
                    />
                    <InputError :message="form.errors.customer_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="warehouse_id">{{ t('Ship from warehouse') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="warehouse_id"
                        v-model="form.warehouse_id"
                        required
                        :options="[...(warehouses).map((w) => ({ value: String(w.id), label: `${w.code} — ${w.name}` }))]"
                        :placeholder="t('Select a warehouse…')"
                    />
                    <InputError :message="form.errors.warehouse_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="order_date">{{ t('Order date') }} <span class="text-destructive">*</span></Label>
                    <Input id="order_date" v-model="form.order_date" type="date" required />
                    <InputError :message="form.errors.order_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="expected_delivery_date">{{ t('Expected delivery date') }}</Label>
                    <Input id="expected_delivery_date" v-model="form.expected_delivery_date" type="date" />
                    <InputError :message="form.errors.expected_delivery_date" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="customer_reference">{{ t('Customer reference') }}</Label>
                    <Input id="customer_reference" v-model="form.customer_reference" autocomplete="off" />
                    <InputError :message="form.errors.customer_reference" />
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
                        <div class="grid gap-1.5 sm:col-span-4">
                            <Label :for="`so-product-${index}`" class="text-xs">{{ t('Product') }}</Label>
                            <SearchableSelect
                                :id="`so-product-${index}`"
                                v-model="line.product_id"
                                required
                                :options="[...(products).map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
                                :placeholder="t('Select…')"
                            />
                            <InputError :message="form.errors[`lines.${index}.product_id`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`so-qty-${index}`" class="text-xs">
                                {{ t('Quantity (:uom)', { uom: productFor(line.product_id)?.uom ?? '—' }) }}
                            </Label>
                            <Input :id="`so-qty-${index}`" v-model="line.quantity" type="number" step="0.001" min="0" required />
                            <InputError :message="form.errors[`lines.${index}.quantity`]" />
                        </div>

                        <div v-if="supportsSecondary(line.product_id)" class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`so-sec-${index}`" class="text-xs">
                                {{ productFor(line.product_id)?.secondary_uom }}
                            </Label>
                            <Input :id="`so-sec-${index}`" v-model="line.secondary_quantity" type="number" step="0.001" min="0" />
                            <InputError :message="form.errors[`lines.${index}.secondary_quantity`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`so-basis-${index}`" class="text-xs">{{ t('Priced per') }}</Label>
                            <SearchableSelect
                                :id="`so-basis-${index}`"
                                v-model="line.pricing_basis"
                                :disabled="!supportsSecondary(line.product_id)"
                                :options="[{ value: 'PRIMARY', label: `${(productFor(line.product_id)?.uom ?? 'Primary unit') ?? ''}` }, ...(supportsSecondary(line.product_id) ? [{ value: 'SECONDARY', label: `${(productFor(line.product_id)?.secondary_uom) ?? ''}` }] : [])]"
                            />
                            <InputError :message="form.errors[`lines.${index}.pricing_basis`]" />
                        </div>

                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label :for="`so-price-${index}`" class="text-xs">{{ t('Unit price') }}</Label>
                            <Input :id="`so-price-${index}`" v-model="line.unit_price" type="number" step="0.01" min="0" required />
                            <InputError :message="form.errors[`lines.${index}.unit_price`]" />
                        </div>

                        <div class="sm:col-span-12">
                            <div class="text-muted-foreground flex flex-wrap items-center justify-between gap-3 text-xs">
                                <span>
                                    {{ t('Priced on') }}
                                    <span class="font-mono">{{ sellingQuantity(line) }}</span>
                                    {{
                                        line.pricing_basis === 'SECONDARY'
                                            ? productFor(line.product_id)?.secondary_uom
                                            : productFor(line.product_id)?.uom
                                    }}
                                    · {{ t('Line total:') }}
                                    <span class="text-foreground font-mono font-medium">{{ money(lineTotal(line)) }}</span>
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
                    {{ t('Order total:') }}
                    <span class="ml-2 font-mono font-medium">{{ money(salesTotal) }}</span>
                </p>
            </div>
        </section>

        <div class="grid gap-2">
            <Label for="so_notes">{{ t('Notes') }}</Label>
            <textarea
                id="so_notes"
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
