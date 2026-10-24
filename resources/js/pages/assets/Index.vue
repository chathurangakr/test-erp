<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/assets';
import type { FixedAssetRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{ assets: FixedAssetRow[]; showAll: boolean; totalInUse: string; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Assets', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <Head :title="t('Assets')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Assets" description="Vehicles, machines and everything else the factory owns — what each cost, how it was paid for and what its repairs have cost." />
            <Button v-if="can('assets.manage')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Add an asset') }}</Link>
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Assets in use, at cost (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold">{{ money(totalInUse) }}</p>
            </div>
            <Button variant="ghost" size="sm" @click="router.get(index().url, showAll ? {} : { all: 1 })">
                {{ showAll ? t('Show only assets in use') : t('Show sold and reversed assets too') }}
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Asset') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Kind') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Bought') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Cost (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Paid for') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Repairs so far') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="asset in assets" :key="asset.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(asset.id)" class="font-medium hover:underline">{{ asset.name }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ asset.asset_number }}</span>
                        </td>
                        <td class="px-4 py-3">{{ asset.category_label }}</td>
                        <td class="px-4 py-3">{{ asset.purchase_date }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(asset.purchase_price) }}</td>
                        <td class="px-4 py-3">{{ asset.funding_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(asset.maintenance_cost ?? '0') }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="asset.status === 'REVERSED' ? 'destructive' : asset.status === 'SOLD' ? 'secondary' : 'default'">{{ asset.status_label }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="assets.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">{{ t('No assets yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
