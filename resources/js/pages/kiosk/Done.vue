<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import { computed } from 'vue';
import type { KioskTextKey } from '@/kiosk/text';
import { plainNumber, useKioskText } from '@/kiosk/text';
import { copra, dehusking, dispatch, home, huskChipping, receive } from '@/routes/kiosk';
import { money } from '@/lib/money';

/**
 * The green "Saved!" screen, built from the POSTED document — so what staff see is what the
 * system actually recorded (for production, the outputs it calculated), not what was typed.
 */
const props = defineProps<{
    task: string;
    document: {
        number: string;
        date: string | null;
        party: string | null;
        store: string | null;
        input?: { product: string | null; quantity: string; uom: string | null; lot: string | null } | null;
        lines: {
            product: string | null;
            quantity: string | null;
            uom: string | null;
            secondary_quantity: string | null;
            secondary_uom: string | null;
            lot?: string | null;
            amount: string | null;
        }[];
    };
}>();

const { t } = useKioskText();

const again = computed(
    () => ({ receive: receive(), dispatch: dispatch(), dehusking: dehusking(), 'husk-chipping': huskChipping(), copra: copra() })[props.task] ?? home(),
);
</script>

<template>
    <div class="flex flex-col items-center gap-6 py-4 text-center">
        <CheckCircle2 class="size-28 text-green-600" />
        <div>
            <h1 class="text-4xl font-bold">{{ t('done.title') }}</h1>
            <p class="text-muted-foreground mt-1 text-xl">{{ t(`task.${task}` as KioskTextKey) }}</p>
        </div>

        <div class="bg-background border-sidebar-border/70 w-full rounded-2xl border p-5 text-left">
            <p class="text-muted-foreground text-base">{{ t('done.number') }}</p>
            <p class="font-mono text-3xl font-bold">{{ document.number }}</p>
            <p class="text-muted-foreground mt-2">
                {{ document.date }}<template v-if="document.party"> · {{ document.party }}</template>
                <template v-if="document.store"> · {{ document.store }}</template>
            </p>

            <div v-if="document.input" class="border-sidebar-border/70 mt-4 border-t pt-4">
                <p class="text-lg font-semibold">
                    {{ document.input.product }}: {{ plainNumber(document.input.quantity) }} {{ document.input.uom }}
                </p>
                <p v-if="document.input.lot" class="text-muted-foreground font-mono text-sm">{{ document.input.lot }}</p>
                <p class="text-muted-foreground mt-3 text-base">{{ t('prod.result') }}</p>
            </div>

            <ul class="mt-3 flex flex-col gap-3">
                <li v-for="(line, index) in document.lines" :key="index" class="bg-muted/50 rounded-xl p-3">
                    <p class="text-lg font-semibold">{{ line.product }}</p>
                    <p class="font-mono text-2xl tabular-nums">
                        {{ plainNumber(line.quantity) }} {{ line.uom }}
                        <span v-if="line.secondary_quantity" class="text-muted-foreground text-lg">
                            · {{ plainNumber(line.secondary_quantity) }} {{ line.secondary_uom }}
                        </span>
                    </p>
                    <p v-if="line.lot" class="text-muted-foreground font-mono text-sm">{{ line.lot }}</p>
                    <p v-if="line.amount" class="text-muted-foreground">Rs. {{ money(line.amount) }}</p>
                </li>
            </ul>
        </div>

        <div class="grid w-full gap-3 sm:grid-cols-2">
            <Link :href="again" class="bg-foreground text-background flex min-h-16 items-center justify-center rounded-2xl text-xl font-semibold">
                {{ t('done.another') }}
            </Link>
            <Link :href="home()" class="bg-background border-sidebar-border/70 flex min-h-16 items-center justify-center rounded-2xl border-2 text-xl font-semibold">
                {{ t('done.home') }}
            </Link>
        </div>
    </div>
</template>
