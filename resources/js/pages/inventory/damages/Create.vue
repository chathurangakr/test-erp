<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/stock-damages';
import { useT } from '@/lib/i18n';

type ProductOption = { id: number; code: string; name: string; uom: string | null; tracks_lots: boolean };
type LotOption = { id: number; lot_number: string; product_id: number; warehouses: { warehouse_id: number; quantity: string }[] };

const props = defineProps<{
    warehouses: { id: number; code: string; name: string; is_default: boolean }[];
    products: ProductOption[];
    balances: { product_id: number; warehouse_id: number; quantity: string }[];
    lots: LotOption[];
    today: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Damaged stock', href: index() }, { title: 'Write off damaged stock' }] } });

const { t } = useT();

const form = useForm({
    damage_date: props.today,
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    reason: '',
    notes: '',
    lines: [{ product_id: null as number | null, lot_id: null as number | null, quantity: '' }],
});

const errors = form.errors as Record<string, string | undefined>;

function productFor(id: number | null): ProductOption | undefined {
    return props.products.find((p) => p.id === id);
}

/** Only products that hold stock in the chosen warehouse can be written off from it. */
function productsInStock(): ProductOption[] {
    const held = new Set(props.balances.filter((b) => b.warehouse_id === form.warehouse_id).map((b) => b.product_id));

    return props.products.filter((p) => held.has(p.id));
}

function onHand(productId: number | null): string | null {
    return props.balances.find((b) => b.product_id === productId && b.warehouse_id === form.warehouse_id)?.quantity ?? null;
}

function lotsFor(productId: number | null): LotOption[] {
    return props.lots.filter((lot) => lot.product_id === productId && lot.warehouses.some((w) => w.warehouse_id === form.warehouse_id));
}

function lotQuantity(lot: LotOption): string {
    return lot.warehouses.find((w) => w.warehouse_id === form.warehouse_id)?.quantity ?? '0';
}

function addLine(): void {
    form.lines.push({ product_id: null, lot_id: null, quantity: '' });
}

function removeLine(position: number): void {
    form.lines.splice(position, 1);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({ ...line, lot_id: productFor(line.product_id)?.tracks_lots ? line.lot_id : null })),
    })).submit(store());
}
</script>

<template>
    <Head :title="t('Write off damaged stock')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Write off damaged stock" description="The stock leaves now and its value is recorded as a loss. A mistake can be reversed afterwards." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-4xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="damage_date">{{ t('Date') }}</Label>
                    <Input id="damage_date" v-model="form.damage_date" type="date" required />
                    <InputError :message="form.errors.damage_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="warehouse_id">{{ t('Warehouse') }}</Label>
                    <SearchableSelect
                        id="warehouse_id"
                        v-model="form.warehouse_id"
                        required
                        :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))"
                    />
                    <InputError :message="form.errors.warehouse_id" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="reason">{{ t('Reason') }}</Label>
                    <Input id="reason" v-model="form.reason" required maxlength="255" :placeholder="t('e.g. Bags torn in the store, coconuts rotten')" />
                    <InputError :message="form.errors.reason" />
                </div>
            </div>

            <div class="space-y-3">
                <h2 class="text-sm font-medium">{{ t('Damaged products') }}</h2>
                <div v-for="(line, position) in form.lines" :key="position" class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[2fr_1.5fr_1fr_auto] sm:items-start">
                    <div class="grid gap-1">
                        <Label :for="`product-${position}`" class="text-xs">{{ t('Product') }}</Label>
                        <SearchableSelect
                            :id="`product-${position}`"
                            v-model="line.product_id"
                            required
                            :options="productsInStock().map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` }))"
                            :placeholder="t('Select a product…')"
                            @update:model-value="line.lot_id = null"
                        />
                        <span v-if="line.product_id" class="text-muted-foreground text-xs">
                            {{ t('In stock here: :quantity :uom', { quantity: onHand(line.product_id) ?? '0', uom: productFor(line.product_id)?.uom ?? '' }) }}
                        </span>
                        <InputError :message="errors[`lines.${position}.product_id`]" />
                    </div>
                    <div class="grid gap-1">
                        <template v-if="productFor(line.product_id)?.tracks_lots">
                            <Label :for="`lot-${position}`" class="text-xs">{{ t('Lot') }}</Label>
                            <SearchableSelect
                                :id="`lot-${position}`"
                                v-model="line.lot_id"
                                required
                                :options="lotsFor(line.product_id).map((lot) => ({ value: lot.id, label: `${lot.lot_number} (${lotQuantity(lot)})` }))"
                                :placeholder="t('Select a lot…')"
                            />
                        </template>
                        <InputError :message="errors[`lines.${position}.lot_id`]" />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`quantity-${position}`" class="text-xs">{{ t('Quantity (:uom)', { uom: productFor(line.product_id)?.uom ?? '' }) }}</Label>
                        <Input :id="`quantity-${position}`" v-model="line.quantity" required inputmode="decimal" />
                        <InputError :message="errors[`lines.${position}.quantity`]" />
                    </div>
                    <Button v-if="form.lines.length > 1" type="button" variant="ghost" size="icon" class="sm:mt-5" :aria-label="t('Remove')" @click="removeLine(position)">
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addLine"><Plus class="size-4" />{{ t('Add product') }}</Button>
                <InputError :message="form.errors.lines" />
            </div>

            <div class="grid gap-2">
                <Label for="notes">{{ t('Notes') }}</Label>
                <Input id="notes" v-model="form.notes" maxlength="2000" />
                <InputError :message="form.errors.notes" />
            </div>

            <InputError :message="errors.stock_loss_account_id" />
            <InputError :message="errors.account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Write off') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
