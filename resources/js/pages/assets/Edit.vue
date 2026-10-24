<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show, update } from '@/routes/assets';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    asset: { id: number; asset_number: string; name: string; category: string; description: string | null };
    categories: { value: string; label: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Assets', href: index() }] } });

const { t } = useT();

const form = useForm({ name: props.asset.name, category: props.asset.category, description: props.asset.description ?? '' });
</script>

<template>
    <Head :title="t('Edit :reference', { reference: asset.asset_number })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :reference', { reference: asset.asset_number })" description="Correct the name, kind and description. The cost is corrected by reversing." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="form.submit(update(asset.id))">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">{{ t('Name') }}</Label>
                    <Input id="name" v-model="form.name" required maxlength="150" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="category">{{ t('Kind') }}</Label>
                    <SearchableSelect id="category" v-model="form.category" :options="categories" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">{{ t('Description') }}</Label>
                    <Input id="description" v-model="form.description" maxlength="2000" />
                </div>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Save changes') }}</Button>
                <Button variant="outline" as-child><Link :href="show(asset.id)">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
