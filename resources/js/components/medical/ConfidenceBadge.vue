<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    confidence: number;
}>();

const pct = computed(() => Math.round(props.confidence * 100));

const config = computed(() => {
    if (pct.value >= 80) {
        return {
            dot: 'bg-clinical-normal',
            class: 'border-border text-ink-soft',
        };
    }
    if (pct.value >= 50) {
        return {
            dot: 'bg-clinical-borderline',
            class: 'border-border text-ink-soft',
        };
    }
    return {
        dot: 'bg-clinical-abnormal',
        class: 'border-border text-ink-soft',
    };
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-full border bg-transparent px-2 py-0.5 font-mono text-xs font-medium tracking-wide uppercase',
            config.class,
        ]"
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :class="config.dot"
            aria-hidden="true"
        />
        <span class="tabular-nums">{{ pct }}%</span>
        <span>Confidence</span>
    </span>
</template>
