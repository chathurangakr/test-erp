<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PartyContactFields } from '@/types';

defineProps<{
    party?: Partial<PartyContactFields> | null;
    errors: Record<string, string | undefined>;
}>();

const textareaClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">Contact</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="contact_person">Contact person</Label>
                    <Input
                        id="contact_person"
                        name="contact_person"
                        :default-value="party?.contact_person ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.contact_person" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="party?.email ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="party?.phone ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="secondary_phone">Secondary phone</Label>
                    <Input
                        id="secondary_phone"
                        name="secondary_phone"
                        :default-value="party?.secondary_phone ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.secondary_phone" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">Address</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address_line_1">Address line 1</Label>
                    <Input
                        id="address_line_1"
                        name="address_line_1"
                        :default-value="party?.address_line_1 ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_1" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address_line_2">Address line 2</Label>
                    <Input
                        id="address_line_2"
                        name="address_line_2"
                        :default-value="party?.address_line_2 ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_2" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input
                        id="city"
                        name="city"
                        :default-value="party?.city ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="district">District</Label>
                    <Input
                        id="district"
                        name="district"
                        :default-value="party?.district ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.district" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">Registration</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="registration_number">Registration number</Label>
                    <Input
                        id="registration_number"
                        name="registration_number"
                        :default-value="party?.registration_number ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.registration_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="tax_number">Tax number</Label>
                    <Input
                        id="tax_number"
                        name="tax_number"
                        :default-value="party?.tax_number ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.tax_number" />
                </div>
            </div>
        </section>

        <div class="grid gap-2">
            <Label for="notes">Notes</Label>
            <textarea
                id="notes"
                name="notes"
                rows="3"
                :value="party?.notes ?? ''"
                :class="textareaClass"
            />
            <InputError :message="errors.notes" />
        </div>
    </div>
</template>
