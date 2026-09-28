<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import WarehouseDefaultController from '@/actions/App/Http/Controllers/Warehouses/WarehouseDefaultController';
import WarehouseStatusController from '@/actions/App/Http/Controllers/Warehouses/WarehouseStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/warehouses';
import type { Warehouse } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    warehouse: Warehouse;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Warehouses', href: index() }],
    },
});

const { can } = usePermissions();

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

const { t } = useT();
</script>

<template>
    <Head :title="warehouse.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="warehouse.name" :description="warehouse.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge v-if="warehouse.is_default" variant="default">{{ t('Default warehouse') }}</Badge>
                    <Badge :variant="warehouse.is_active ? 'default' : 'secondary'">
                        {{ warehouse.is_active ? t('Active') : t('Inactive') }}
                    </Badge>
                </div>
            </div>

            <div v-if="can('warehouses.manage')" class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${warehouse.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>
                <Form
                    v-if="!warehouse.is_default && warehouse.is_active"
                    v-bind="WarehouseDefaultController.update.form(warehouse.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Make default') }}
                    </Button>
                </Form>
                <Form v-bind="WarehouseStatusController.update.form(warehouse.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ warehouse.is_active ? t('Deactivate') : t('Activate') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Code') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ warehouse.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Name') }}</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Description') }}</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.description ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Address') }}</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Default warehouse') }}</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.is_default ? t('Yes') : t('No') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(warehouse.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Last updated') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(warehouse.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Stock held in this warehouse is not shown here. It is derived from stock transactions — see Stock on Hand or the Stock Ledger.') }}
        </p>
    </div>
</template>
