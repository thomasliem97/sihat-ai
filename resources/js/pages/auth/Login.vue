<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

const demoAccounts = [
    {
        email: 'physician@sihat-ai.vxms.dev',
        label: 'Physician · Dr. Aisha Rahman',
    },
    {
        email: 'patient@sihat-ai.vxms.dev',
        label: 'Patient · Ahmad bin Hassan',
    },
] as const;

const selectedEmail = ref<string>(demoAccounts[0].email);

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Choose a demo account to continue',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-clinical-normal"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <FieldLabel required>Demo account</FieldLabel>
                <Select v-model="selectedEmail" required>
                    <SelectTrigger
                        id="email"
                        class="w-full"
                        autofocus
                        :tabindex="1"
                        aria-label="Demo account"
                    >
                        <SelectValue placeholder="Select demo account" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="account in demoAccounts"
                            :key="account.email"
                            :value="account.email"
                        >
                            {{ account.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <input type="hidden" name="email" :value="selectedEmail" />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="password"
                    default-value="password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>
    </Form>
</template>
