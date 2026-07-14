<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="atlas-field flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link
                :href="home()"
                class="flex items-center gap-2.5 self-center font-medium"
            >
                <AppLogoIcon class="size-9 rounded-xl" />
                <span class="font-bold tracking-tight">Sihat AI</span>
            </Link>

            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="px-10 pt-8 pb-0 text-center">
                    <SectionTag class="mb-3 justify-center">Access</SectionTag>
                    <CardTitle class="text-2xl font-bold tracking-tight">
                        {{ title }}
                    </CardTitle>
                    <CardDescription class="leading-relaxed">
                        {{ description }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="px-10 py-8">
                    <slot />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
