<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/customer-bags';
import { useT } from '@/lib/i18n';

type Kind = 'GIVEN' | 'RETURNED' | 'LOST';

const props = defineProps<{
    kinds: { value: Kind; label: string }[];
    kind: Kind;
    customerId: number | null;
    customers: { id: number; code: string; name: string; is_active: boolean }[];
    products: { id: number; code: string; name: string; uom: string | null; stock: { warehouse_id: number; quantity: string }[] }[];
    holdings: { customer_id: number; product_id: number; quantity: string }[];
    warehouses: { id: number; code: string; name: string; is_default: boolean }[];
    today: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer bags', href: index() }, { title: 'Record bags' }] } });

const { t } = useT();

const form = useForm({
    kind: props.kind,
    movement_date: props.today,
    customer_id: props.customerId,
    product_id: props.products.length === 1 ? props.products[0].id : (null as number | null),
    warehouse_id: (props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null,
    quantity: '',
    note: '',
});

const isLost = computed(() => form.kind === 'LOST');

/** Bags can be given only to an active customer; any customer can bring bags back. */
const customerOptions = computed(() =>
    props.customers
        .filter((c) => form.kind !== 'GIVEN' || c.is_active)
        .map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })),
);

const inStore = computed(() => props.products.find((p) => p.id === form.product_id)?.stock.find((s) => s.warehouse_id === form.warehouse_id)?.quantity ?? '0');
const held = computed(() => props.holdings.find((h) => h.customer_id === form.customer_id && h.product_id === form.product_id)?.quantity ?? '0');

const tooMany = computed(() => {
    const quantity = Number(form.quantity);
    const limit = Number(form.kind === 'GIVEN' ? inStore.value : held.value);

    return quantity > 0 && form.product_id !== null && quantity > limit;
});

const title = computed(() => ({ GIVEN: 'Give bags', RETURNED: 'Bags returned', LOST: 'Mark bags lost' })[form.kind]);
const description = computed(
    () =>
        ({
            GIVEN: 'The bags leave the store with the customer and are counted against them until they come back.',
            RETURNED: 'The bags come back into the store and off what the customer holds.',
            LOST: 'Bags the customer will not bring back. They are written off as a loss.',
        })[form.kind],
);

function submit(): void {
    form.transform((data) => ({ ...data, warehouse_id: isLost.value ? undefined : data.warehouse_id })).submit(store());
}
</script>

<template>
    <Head :title="t(title)" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="title" :description="description" />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-2" role="radiogroup" :aria-label="t('What happened')">
                <button
                    v-for="option in kinds"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="form.kind === option.value"
                    class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                    :class="form.kind === option.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    @click="form.kind = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <InputError :message="form.errors.kind" />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="customer_id">{{ t('Customer') }}</Label>
                    <SearchableSelect id="customer_id" v-model="form.customer_id" required :options="customerOptions" :placeholder="t('Select a customer…')" />
                    <InputError :message="form.errors.customer_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="movement_date">{{ t('Date') }}</Label>
                    <Input id="movement_date" v-model="form.movement_date" type="date" required />
                    <InputError :message="form.errors.movement_date" />
                </div>
                <div v-if="!isLost" class="grid gap-2">
                    <Label for="warehouse_id">{{ form.kind === 'GIVEN' ? t('From store') : t('Back to store') }}</Label>
                    <SearchableSelect id="warehouse_id" v-model="form.warehouse_id" required :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))" />
                    <InputError :message="form.errors.warehouse_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="product_id">{{ t('Item') }}</Label>
                    <SearchableSelect id="product_id" v-model="form.product_id" required :options="products.map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` }))" :placeholder="t('Select an item…')" />
                    <InputError :message="form.errors.product_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="quantity">{{ t('Number of bags') }}</Label>
                    <Input id="quantity" v-model="form.quantity" required inputmode="numeric" />
                    <p v-if="form.product_id" class="text-xs" :class="tooMany ? 'text-destructive' : 'text-muted-foreground'">
                        <template v-if="form.kind === 'GIVEN'">{{ t('In this store: :quantity', { quantity: Number(inStore) }) }}</template>
                        <template v-else-if="form.customer_id">{{ t('This customer holds: :quantity', { quantity: Number(held) }) }}</template>
                    </p>
                    <InputError :message="form.errors.quantity" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="note">{{ t('Note') }}</Label>
                    <Input id="note" v-model="form.note" maxlength="255" :placeholder="t('e.g. Delivery DN-20261024-000003')" />
                    <InputError :message="form.errors.note" />
                </div>
            </div>

            <InputError :message="(form.errors as Record<string, string | undefined>).stock_loss_account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Save') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
