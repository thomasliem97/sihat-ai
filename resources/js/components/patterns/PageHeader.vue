<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { cn } from '@/lib/utils';

defineProps<{
    tag?: string;
    title: string;
    description?: string;
    meta?: string;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <header :class="cn('space-y-2', $props.class)">
        <SectionTag v-if="tag">{{ tag }}</SectionTag>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-2xl font-bold tracking-tight md:text-3xl">
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="max-w-prose text-sm leading-relaxed text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <p
                v-if="meta"
                class="font-mono text-xs font-semibold tracking-wider text-ink-faint uppercase"
            >
                {{ meta }}
            </p>
        </div>
        <slot />
    </header>
</template>
