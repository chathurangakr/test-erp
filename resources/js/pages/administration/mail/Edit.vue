<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Mail, Send } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { edit, test, update } from '@/routes/mail-settings';

const props = defineProps<{
    settings: {
        is_enabled: boolean;
        host: string;
        port: number;
        encryption: string;
        username: string | null;
        has_password: boolean;
        from_address: string | null;
        from_name: string | null;
    };
    encryptions: string[];
    testAddress: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Email settings', href: edit() }] } });

const { t } = useT();

const form = useForm({
    is_enabled: props.settings.is_enabled,
    host: props.settings.host,
    port: props.settings.port,
    encryption: props.settings.encryption,
    username: props.settings.username ?? '',
    // Never filled from the server: blank keeps the saved password.
    password: '',
    from_address: props.settings.from_address ?? '',
    from_name: props.settings.from_name ?? '',
});

const testForm = useForm<{ test?: string }>({});

function submit(): void {
    form.put(update().url, { preserveScroll: true, onSuccess: () => form.reset('password') });
}

function sendTest(): void {
    testForm.post(test().url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Email settings')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Email settings"
            description="The email account the system sends from — used for the weekly update every Sunday at 8 PM."
        />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="flex items-center justify-between gap-4 rounded-lg border p-4">
                <div class="flex items-start gap-3">
                    <Mail class="mt-0.5 h-5 w-5 text-emerald-600" />
                    <div class="space-y-1">
                        <p class="text-sm font-medium">{{ t('Send email') }}</p>
                        <p class="text-muted-foreground text-sm">{{ t('Switch on once the account below is filled in.') }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.is_enabled"
                    :aria-label="t('Send email')"
                    :class="[
                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        form.is_enabled ? 'bg-emerald-600' : 'bg-neutral-300 dark:bg-neutral-600',
                    ]"
                    @click="form.is_enabled = !form.is_enabled"
                >
                    <span :class="['inline-block h-5 w-5 rounded-full bg-white shadow transition-transform', form.is_enabled ? 'translate-x-5' : 'translate-x-0.5']" />
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="username">{{ t('Gmail address (sign-in)') }}</Label>
                    <Input id="username" v-model="form.username" type="email" maxlength="150" autocomplete="off" placeholder="name@gmail.com" />
                    <InputError :message="form.errors.username" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="password">{{ t('App password') }}</Label>
                    <PasswordInput
                        id="password"
                        v-model="form.password"
                        autocomplete="new-password"
                        :placeholder="settings.has_password ? t('Saved — leave blank to keep it') : t('16 letters from Google')"
                    />
                    <p class="text-muted-foreground text-xs">
                        {{ t('Not your normal Gmail password. In your Google Account open Security → 2-Step Verification → App passwords, create one named ERP and paste the 16 letters here.') }}
                    </p>
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label for="from_name">{{ t('Sender name') }}</Label>
                    <Input id="from_name" v-model="form.from_name" maxlength="150" :placeholder="t('Blank = the system name')" />
                    <InputError :message="form.errors.from_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="from_address">{{ t('Sender address') }}</Label>
                    <Input id="from_address" v-model="form.from_address" type="email" maxlength="150" :placeholder="t('Blank = the Gmail address')" />
                    <InputError :message="form.errors.from_address" />
                </div>
            </div>

            <details class="rounded-lg border p-4">
                <summary class="cursor-pointer text-sm font-medium">{{ t('Server (already set for Gmail)') }}</summary>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="host">{{ t('Server') }}</Label>
                        <Input id="host" v-model="form.host" maxlength="150" />
                        <InputError :message="form.errors.host" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="port">{{ t('Port') }}</Label>
                        <Input id="port" v-model.number="form.port" type="number" min="1" max="65535" />
                        <InputError :message="form.errors.port" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="encryption">{{ t('Security') }}</Label>
                        <select
                            id="encryption"
                            v-model="form.encryption"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm shadow-xs"
                        >
                            <option v-for="option in encryptions" :key="option" :value="option">{{ option.toUpperCase() }}</option>
                        </select>
                        <InputError :message="form.errors.encryption" />
                    </div>
                </div>
            </details>

            <Button type="submit" :disabled="form.processing">{{ t('Save') }}</Button>
        </form>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-3 rounded-xl border p-4 sm:p-6">
            <p class="text-sm font-medium">{{ t('Send a test') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t("Sends last week's update to :address using the saved settings. Save first if you changed anything.", { address: testAddress }) }}
            </p>
            <Button type="button" variant="outline" :disabled="testForm.processing" @click="sendTest">
                <Send class="size-4" />
                {{ t('Send test email') }}
            </Button>
            <InputError :message="testForm.errors.test" />
        </div>
    </div>
</template>
