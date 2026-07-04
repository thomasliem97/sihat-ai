<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    imageUrl?: string | null;
    boxes: Array<{
        label: string;
        x: number;
        y: number;
        width: number;
        height: number;
        confidence?: number;
    }>;
}>();

const hasImage = computed(() => !!props.imageUrl);
</script>

<template>
    <div class="viewer-surface relative overflow-hidden rounded-2xl border border-line-strong">
        <div
            class="flex items-center justify-between border-b border-white/10 px-4 py-3 font-mono text-xs tracking-wide uppercase"
        >
            <span>Scan viewer</span>
            <span class="text-ink-faint">{{ boxes.length }} finding(s)</span>
        </div>
        <img
            v-if="hasImage"
            :src="imageUrl!"
            alt="Medical scan with annotated findings"
            class="w-full object-contain"
        />
        <div
            v-else
            class="flex aspect-video items-center justify-center font-mono text-sm text-ink-faint"
        >
            Scan preview unavailable
        </div>
        <div
            v-for="(box, i) in boxes"
            :key="i"
            class="pointer-events-none absolute border-2 border-clinical-borderline"
            :style="{
                left: `${box.x * 100}%`,
                top: `${box.y * 100}%`,
                width: `${box.width * 100}%`,
                height: `${box.height * 100}%`,
            }"
        >
            <span
                class="absolute -top-7 left-0 rounded bg-clinical-borderline px-2 py-1 font-mono text-[0.65rem] font-bold tracking-wide text-ink uppercase whitespace-nowrap"
            >
                {{ box.label }}
            </span>
        </div>
    </div>
</template>
