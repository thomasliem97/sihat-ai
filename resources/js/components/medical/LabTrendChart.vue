<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    data: Array<{
        value: number;
        collected_at: string;
        status: string;
    }>;
    name: string;
    unit: string;
    referenceLow?: number | null;
    referenceHigh?: number | null;
}>();

const maxVal = computed(() => {
    const vals = props.data.map((d) => d.value);
    const refHigh = props.referenceHigh ?? 0;

    return Math.max(...vals, refHigh) * 1.1;
});

function barHeight(value: number): string {
    return `${(value / maxVal.value) * 100}%`;
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm font-semibold tracking-tight">{{ name }}</span>
            <span
                class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase tabular-nums"
            >
                {{ unit }}
            </span>
        </div>
        <div class="flex h-24 items-end gap-1.5">
            <div
                v-for="(point, i) in data"
                :key="i"
                class="flex flex-1 flex-col items-center gap-1.5"
            >
                <div class="flex h-20 w-full items-end justify-center">
                    <div
                        class="w-full max-w-8 rounded-t bg-chart-1"
                        :class="{
                            'bg-clinical-abnormal': point.status === 'abnormal',
                            'bg-clinical-borderline':
                                point.status === 'borderline',
                            'bg-clinical-critical': point.status === 'critical',
                            'bg-clinical-normal': point.status === 'normal',
                        }"
                        :style="{ height: barHeight(point.value) }"
                    />
                </div>
                <span
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                >
                    {{
                        new Date(point.collected_at).toLocaleDateString(
                            'en-MY',
                            { month: 'short' },
                        )
                    }}
                </span>
            </div>
        </div>
        <div
            v-if="referenceLow != null && referenceHigh != null"
            class="font-mono text-xs tracking-wide text-ink-faint uppercase"
        >
            Reference {{ referenceLow }}–{{ referenceHigh }} {{ unit }}
        </div>
    </div>
</template>
