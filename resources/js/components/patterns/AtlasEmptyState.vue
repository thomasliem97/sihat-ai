<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import type { Component } from 'vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import { cn } from '@/lib/utils';

defineProps<{
    icon?: Component;
    title: string;
    description?: string;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center gap-4 px-6 py-12 text-center',
                $props.class,
            )
        "
    >
        <IconDisc v-if="icon">
            <component :is="icon" class="size-6" />
        </IconDisc>
        <div class="space-y-2">
            <h2 class="text-lg font-semibold tracking-tight">{{ title }}</h2>
            <p
                v-if="description"
                class="mx-auto max-w-sm text-sm leading-relaxed text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.action" class="pt-1">
            <slot name="action" />
        </div>
    </div>
</template>
