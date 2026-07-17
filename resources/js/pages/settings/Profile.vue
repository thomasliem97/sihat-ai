<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <form class="space-y-6" @submit.prevent>
            <div class="grid gap-2">
                <FieldLabel html-for="name">Name</FieldLabel>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    disabled
                    autocomplete="name"
                    placeholder="e.g. Ahmad bin Hassan"
                />
            </div>

            <div class="grid gap-2">
                <FieldLabel html-for="email">Email address</FieldLabel>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    disabled
                    autocomplete="username"
                    placeholder="name@example.com"
                />
            </div>

            <div class="space-y-3">
                <Button
                    type="button"
                    disabled
                    data-test="update-profile-button"
                >
                    Save
                </Button>
                <p class="text-sm text-muted-foreground">
                    Profile updates are disabled in demo mode.
                </p>
            </div>
        </form>
    </div>

    <DeleteUser />
</template>
