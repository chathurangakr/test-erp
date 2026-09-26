<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/accounts';
import { index as ledgerIndex } from '@/routes/general-ledger';
import type { AccountDetail } from '@/types';

const props = defineProps<{ account: AccountDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Chart of accounts', href: index() }] } });

const { can } = usePermissions();
</script>

<template>
    <Head :title="account.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="`${account.code} — ${account.name}`" :description="account.account_type_label" />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="account.is_active ? 'default' : 'destructive'">
                        {{ account.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                    <Badge v-if="account.is_control_account" variant="secondary">Control account</Badge>
                    <Badge variant="outline">{{ account.normal_balance_label }} normal</Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${ledgerIndex().url}?account_id=${account.id}`">View ledger</Link>
                </Button>
                <Button v-if="can('finance.accounts_manage')" variant="outline" as-child>
                    <Link :href="`${index().url}/${account.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total debits</p>
                <p class="mt-1 font-mono text-lg">{{ account.debit_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total credits</p>
                <p class="mt-1 font-mono text-lg">{{ account.credit_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">
                    Balance ({{ account.normal_balance_label.toLowerCase() }} normal, {{ currency }})
                </p>
                <p class="mt-1 font-mono text-lg font-medium">{{ account.balance }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Parent</dt>
                    <dd class="mt-1 text-sm">
                        {{ account.parent_code ? `${account.parent_code} ${account.parent_name}` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Manual posting</dt>
                    <dd class="mt-1 text-sm">{{ account.accepts_manual_posting ? 'Allowed' : 'Blocked' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Journal history</dt>
                    <dd class="mt-1 text-sm">{{ account.has_history ? 'Has postings' : 'None yet' }}</dd>
                </div>
                <div v-if="account.description" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Description</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ account.description }}</dd>
                </div>
            </dl>
        </div>

        <div
            v-if="account.children.length"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">Child accounts</h3>
            <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                <li v-for="child in account.children" :key="child.id" class="text-sm">
                    <Link :href="`${index().url}/${child.id}`" class="hover:underline">
                        <span class="font-mono text-xs">{{ child.code }}</span> {{ child.name }}
                    </Link>
                    <span v-if="!child.is_active" class="text-muted-foreground text-xs"> (inactive)</span>
                </li>
            </ul>
        </div>

        <p v-if="account.has_history" class="text-muted-foreground max-w-3xl text-xs">
            This account has journal history, so it can no longer be deleted. Deactivate it instead
            — the history must keep referring to something meaningful. Its balance is derived from
            those postings and is never stored.
        </p>
    </div>
</template>
