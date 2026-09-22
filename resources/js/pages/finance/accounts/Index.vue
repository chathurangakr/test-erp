<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/accounts';
import type { AccountRow, Paginated, StatusOption } from '@/types';

const props = defineProps<{
    accounts: Paginated<AccountRow>;
    filters: { search: string; type: string; status: string };
    types: StatusOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Chart of accounts', href: index() }] } });

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const status = ref(props.filters.status ?? 'all');

function applyFilters(): void {
    router.get(
        index().url,
        {
            search: search.value || undefined,
            type: type.value || undefined,
            status: status.value === 'all' ? undefined : status.value,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([type, status], applyFilters);

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Chart of accounts" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Chart of accounts"
                :description="`The accounts the books are kept in. Base currency ${currency}.`"
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />New account</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1.5">
                <label for="f-search" class="text-sm font-medium">Search</label>
                <input
                    id="f-search"
                    v-model="search"
                    type="search"
                    placeholder="Code or name"
                    :class="controlClass"
                    @keyup.enter="applyFilters"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-type" class="text-sm font-medium">Type</label>
                <select id="f-type" v-model="type" :class="controlClass">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">Status</label>
                <select id="f-status" v-model="status" :class="controlClass">
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div
            v-if="accounts.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No accounts found</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Code</th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Normal balance</th>
                        <th class="px-4 py-3 font-medium">Control</th>
                        <th class="px-4 py-3 font-medium">Manual posting</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in accounts.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">{{ row.code }}</Link>
                        </td>
                        <td class="px-4 py-3">
                            {{ row.name }}
                            <span v-if="row.parent_code" class="text-muted-foreground block text-xs">
                                under {{ row.parent_code }} {{ row.parent_name }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ row.account_type_label }}</td>
                        <td class="px-4 py-3">{{ row.normal_balance_label }}</td>
                        <td class="px-4 py-3">
                            <Badge v-if="row.is_control_account" variant="secondary">Control</Badge>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="row.accepts_manual_posting ? '' : 'text-muted-foreground'">
                                {{ row.accepts_manual_posting ? 'Allowed' : 'Blocked' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.is_active ? 'default' : 'destructive'">
                                {{ row.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="accounts.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-muted-foreground text-sm">
                Showing {{ accounts.from }}–{{ accounts.to }} of {{ accounts.total }}
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    v-for="link in accounts.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                    v-html="link.label"
                />
            </div>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Accounts are deactivated, never deleted — historic journal lines must keep referring to
            something meaningful. A <strong>control account</strong> is reconciled from a
            subsidiary ledger, so it is posted to by the system rather than by hand.
        </p>
    </div>
</template>
