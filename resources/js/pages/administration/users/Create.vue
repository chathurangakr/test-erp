<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/users';
import type { RoleOption } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ roles: RoleOption[]; passwordRules: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    start_in_simple_mode: false,
    role_ids: [] as number[],
});

function submit(): void {
    form.submit(store(), { onError: () => form.reset('password', 'password_confirmation') });
}

const { t } = useT();
</script>

<template>
    <Head :title="t('Add user')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Add user"
            description="Give the person their email and this starting password. They can change the password in their own settings."
        />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">{{ t('Name') }}</Label>
                    <Input id="name" v-model="form.name" required autocomplete="off" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">{{ t('Email address') }}</Label>
                    <Input id="email" v-model="form.email" type="email" required autocomplete="off" />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">{{ t('Starting password') }}</Label>
                    <PasswordInput id="password" v-model="form.password" required autocomplete="new-password" :passwordrules="passwordRules" />
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ t('Confirm password') }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        required
                        autocomplete="new-password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>
            </div>

            <label class="flex items-start gap-3">
                <input v-model="form.start_in_simple_mode" type="checkbox" class="mt-1 size-4" />
                <span>
                    <span class="block text-sm font-medium">{{ t('Start in simple mode') }}</span>
                    <span class="text-muted-foreground block text-xs">
                        {{ t('After signing in, open the big-button screens for factory work instead of the full ERP. It only changes the start page — what they may do still comes from their roles.') }}
                    </span>
                </span>
            </label>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium">{{ t('Roles') }}</legend>
                <p class="text-muted-foreground text-xs">{{ t("A user's permissions are everything their active roles grant, combined.") }}</p>
                <label
                    v-for="role in roles"
                    :key="role.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border flex items-start gap-3 rounded-lg border p-3"
                >
                    <input v-model="form.role_ids" type="checkbox" :value="role.id" class="mt-1 size-4" />
                    <span>
                        <span class="block text-sm font-medium">
                            {{ t(role.name) }}
                            <span class="text-muted-foreground font-mono text-xs">{{ role.code }}</span>
                            <span v-if="!role.is_active" class="text-muted-foreground text-xs"> — {{ t('inactive, grants nothing') }}</span>
                        </span>
                        <span v-if="role.description" class="text-muted-foreground block text-xs">{{ t(role.description) }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.role_ids" />
            </fieldset>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Add user') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
