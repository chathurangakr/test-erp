<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Letterhead from '@/components/print/Letterhead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { edit, update } from '@/routes/company';
import type { CompanyLetterhead } from '@/types';

const props = defineProps<{ profile: CompanyLetterhead }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Company details', href: edit() }] } });

const { t } = useT();

const form = useForm({
    name: props.profile.name ?? '',
    address: props.profile.address ?? '',
    phone: props.profile.phone ?? '',
    email: props.profile.email ?? '',
    registration_number: props.profile.registration_number ?? '',
    logo: null as File | null,
    remove_logo: false,
});

const chosenLogo = ref<string | null>(null);

const logoPreview = computed(() => {
    if (chosenLogo.value) return chosenLogo.value;
    if (form.remove_logo) return null;
    return props.profile.logo_url;
});

function chooseLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    chosenLogo.value = file ? URL.createObjectURL(file) : null;
}

function removeLogo(): void {
    form.logo = null;
    form.remove_logo = true;
    chosenLogo.value = null;
}

function submit(): void {
    // A file travels as multipart form data, which only POST carries; Laravel reads _method.
    form.transform((data) => ({ ...data, _method: 'put' })).post(update().url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.remove_logo = false;
            chosenLogo.value = null;
        },
    });
}
</script>

<template>
    <Head :title="t('Company details')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Company details"
            description="The name, address and logo printed at the top of invoices and reports."
        />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">{{ t('Company name') }}</Label>
                    <Input id="name" v-model="form.name" required maxlength="150" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address">{{ t('Address') }}</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        rows="3"
                        maxlength="500"
                        class="border-input bg-background focus-visible:ring-ring/50 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                    />
                    <InputError :message="form.errors.address" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">{{ t('Phone') }}</Label>
                    <Input id="phone" v-model="form.phone" maxlength="100" />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">{{ t('Email address') }}</Label>
                    <Input id="email" v-model="form.email" type="email" maxlength="150" />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="registration_number">{{ t('Business registration number') }}</Label>
                    <Input id="registration_number" v-model="form.registration_number" maxlength="100" />
                    <InputError :message="form.errors.registration_number" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="logo">{{ t('Logo') }}</Label>
                <div class="flex flex-wrap items-center gap-4">
                    <img v-if="logoPreview" :src="logoPreview" alt="" class="h-24 w-auto rounded border bg-white object-contain p-1" />
                    <p v-else class="text-muted-foreground text-sm">{{ t('No logo yet.') }}</p>
                    <Input id="logo" type="file" accept="image/png,image/jpeg,image/webp" class="max-w-xs" @change="chooseLogo" />
                    <Button v-if="logoPreview" type="button" variant="outline" @click="removeLogo">{{ t('Remove logo') }}</Button>
                </div>
                <p class="text-muted-foreground text-xs">{{ t('PNG, JPG or WebP, up to 2 MB.') }}</p>
                <InputError :message="form.errors.logo" />
            </div>

            <Button type="submit" :disabled="form.processing">{{ t('Save') }}</Button>
        </form>

        <div class="max-w-3xl">
            <p class="text-muted-foreground mb-2 text-sm font-medium">{{ t('How printouts begin (after saving)') }}</p>
            <div class="rounded-xl border bg-white p-4 text-neutral-900">
                <Letterhead />
            </div>
        </div>
    </div>
</template>
