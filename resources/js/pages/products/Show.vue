<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import ProductStatusController from '@/actions/App/Http/Controllers/Products/ProductStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/products';
import type { Product } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    product: Product;
}>();

const { can } = usePermissions();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Products', href: index() }],
    },
});

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

const { t } = useT();
</script>

<template>
    <Head :title="product.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex flex-col gap-2">
                <Heading :title="product.name" :description="product.code" />
                <Badge
                    :variant="product.is_active ? 'default' : 'secondary'"
                    class="w-fit"
                >
                    {{ product.is_active ? t('Active') : t('Inactive') }}
                </Badge>
            </div>

            <div v-if="can('products.manage')" class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${product.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>
                <Form
                    v-bind="ProductStatusController.update.form(product.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                    >
                        {{ product.is_active ? t('Deactivate') : t('Activate') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6"
        >
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Product code') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">{{ product.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Product name') }}
                    </dt>
                    <dd class="mt-1 text-sm">{{ product.name }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Primary unit of measure') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ product.primary_uom?.code ?? '—' }}
                        <span
                            v-if="product.primary_uom?.name"
                            class="text-muted-foreground"
                        >
                            — {{ product.primary_uom.name }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Secondary unit of measure') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        <template v-if="product.secondary_uom">
                            {{ product.secondary_uom.code }}
                            <span class="text-muted-foreground">
                                — {{ product.secondary_uom.name }}
                            </span>
                        </template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Description') }}
                    </dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        <template v-if="product.description">
                            {{ product.description }}
                        </template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Created') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ formatDate(product.created_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Last updated') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ formatDate(product.updated_at) }}
                    </dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Stock on hand is not shown here. It is derived from stock transactions — see Stock on Hand or the Stock Ledger.') }}
        </p>
    </div>
</template>
