<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    confidence: number;
}>();

const pct = computed(() => Math.round(props.confidence * 100));

const config = computed(() => {
    if (pct.value >= 80) {
        return {
            dot: 'bg-clinical-normal shadow-[0_0_0_4px_color-mix(in_srgb,var(--clinical-normal)_20%,transparent)]',
            label: 'Publish',
            class: 'text-clinical-normal border-clinical-normal/30 bg-clinical-normal/10',
        };
    }
    if (pct.value >= 50) {
        return {
            dot: 'bg-clinical-borderline shadow-[0_0_0_4px_color-mix(in_srgb,var(--clinical-borderline)_20%,transparent)]',
            label: 'Hedge',
            class: 'text-clinical-borderline border-clinical-borderline/30 bg-clinical-borderline/10',
        };
    }
    return {
        dot: 'bg-clinical-abnormal shadow-[0_0_0_4px_color-mix(in_srgb,var(--clinical-abnormal)_20%,transparent)]',
        label: 'Abstain',
        class: 'text-clinical-abnormal border-clinical-abnormal/30 bg-clinical-abnormal/10',
    };
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-2 rounded-full border px-2.5 py-1 font-mono text-xs font-semibold tracking-wide uppercase',
            config.class,
        ]"
    >
        <span
            class="size-2.5 shrink-0 rounded-full"
            :class="config.dot"
            aria-hidden="true"
        />
        <span class="tabular-nums">{{ pct }}%</span>
        <span class="text-ink-faint">·</span>
        <span>{{ config.label }}</span>
    </span>
</template>
