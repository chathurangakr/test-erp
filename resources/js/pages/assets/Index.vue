<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/assets';
import type { FixedAssetRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ assets: FixedAssetRow[]; showAll: boolean; totalInUse: string; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Assets', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
const { filters, visible, pageCount, range, sortBy, clear } = useClientList(() => props.assets, {
    search: (asset) => `${asset.name} ${asset.asset_number} ${asset.category_label} ${asset.funding_label}`,
    date: (asset) => asset.purchase_date,
    sorts: {
        asset: (asset) => asset.name,
        kind: (asset) => asset.category_label,
        bought: (asset) => asset.purchase_date,
        cost: (asset) => Number(asset.purchase_price),
        funding: (asset) => asset.funding_label,
        repairs: (asset) => Number(asset.maintenance_cost ?? 0),
        status: (asset) => asset.status_label,
    },
    defaultSort: 'bought',
    defaultDirection: 'desc',
});
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

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search asset, number or kind…')" class="pl-9" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Asset" column="asset" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Kind" column="kind" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Bought" column="bought" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Cost" column="cost" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Cost (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Paid for" column="funding" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Repairs so far" column="repairs" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="asset in visible" :key="asset.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
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
                    <tr v-if="visible.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">{{ t('No assets yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="filters.page" :range="range" :page-count="pageCount" />
    </div>
</template>
