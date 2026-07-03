<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="atlas-field relative hidden h-full flex-col p-10 lg:flex dark:border-r"
        >
            <Link
                :href="home()"
                class="relative z-20 flex items-center gap-2.5 text-lg font-bold tracking-tight"
            >
                <div
                    class="flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </div>
                {{ name }}
            </Link>
            <div class="relative z-20 mt-auto space-y-3">
                <SectionTag>Clinical field atlas</SectionTag>
                <p class="max-w-sm text-sm leading-relaxed text-muted-foreground">
                    Multimodal medical intelligence with cobalt annotation ink
                    and specimen-sheet clarity.
                </p>
            </div>
        </div>
        <div class="bg-card lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1
                        class="text-2xl font-bold tracking-tight"
                        v-if="title"
                    >
                        {{ title }}
                    </h1>
                    <p
                        class="text-sm leading-relaxed text-muted-foreground"
                        v-if="description"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
