<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
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
        <div class="paper-panel paper-panel--focal w-full max-w-md p-8 md:p-10">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-3 font-medium"
                    >
                        <div
                            class="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                        >
                            <AppLogoIcon class="size-6 fill-current" />
                        </div>
                        <span
                            class="font-mono text-[0.65rem] font-semibold tracking-wider text-ink-faint uppercase"
                        >
                            Sihat AI
                        </span>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <SectionTag v-if="title" class="justify-center">
                            Access
                        </SectionTag>
                        <h1 class="text-2xl font-bold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm leading-relaxed text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
