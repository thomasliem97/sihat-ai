<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        data-slot="app-logo-icon"
        role="img"
        aria-label="SihatAI"
        :class="
            cn(
                'inline-flex aspect-square shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground',
                props.class,
            )
        "
        v-bind="$attrs"
    >
        S
    </div>
</template>
