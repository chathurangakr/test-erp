<script setup lang="ts">
import { plainNumber, useKioskText } from '@/kiosk/text';

/**
 * How much of each product is in each store — quantities only, straight from the inventory
 * ledger. No money is shown here.
 */
defineProps<{
    products: {
        id: number;
        name: string;
        uom: string | null;
        secondary_uom: string | null;
        total: string;
        secondary_total: string | null;
        stores: { name: string; quantity: string }[];
    }[];
}>();

const { t } = useKioskText();
</script>

<template>
    <div class="flex flex-col gap-5">
        <h1 class="text-3xl font-bold">{{ t('stock.title') }}</h1>

        <p v-if="products.length === 0" class="bg-background rounded-2xl p-8 text-center text-xl">{{ t('stock.empty') }}</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="product in products" :key="product.id" class="bg-background border-sidebar-border/70 rounded-2xl border p-5">
                <p class="text-xl font-semibold">{{ product.name }}</p>
                <p class="mt-2 font-mono text-4xl font-bold tabular-nums">
                    {{ plainNumber(product.total) }} <span class="text-muted-foreground text-xl">{{ product.uom }}</span>
                </p>
                <p v-if="product.secondary_total !== null" class="text-muted-foreground font-mono text-xl tabular-nums">
                    {{ plainNumber(product.secondary_total) }} {{ product.secondary_uom }}
                </p>
                <ul class="mt-3 flex flex-col gap-1 text-base">
                    <li v-for="store in product.stores" :key="store.name" class="flex justify-between">
                        <span class="text-muted-foreground">{{ t('stock.inStore', { store: store.name }) }}</span>
                        <span class="font-mono tabular-nums">{{ plainNumber(store.quantity) }} {{ product.uom }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
