<script setup lang="ts">
import { computed, ref, watch } from 'vue';

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

const loadFailed = ref(false);
const hasImage = computed(() => !!props.imageUrl && !loadFailed.value);

watch(
    () => props.imageUrl,
    () => {
        loadFailed.value = false;
    },
);
</script>

<template>
    <div
        class="viewer-surface flex min-h-72 flex-col overflow-hidden rounded-2xl border border-line-strong"
    >
        <div
            class="flex shrink-0 items-center justify-between border-b border-white/10 px-6 py-3 font-mono text-xs tracking-wide uppercase"
        >
            <span>Scan viewer</span>
            <span class="text-ink-faint">
                {{
                    boxes.length ? `${boxes.length} finding(s)` : 'No overlays'
                }}
            </span>
        </div>
        <div class="flex min-h-0 flex-1 items-center justify-center">
            <div v-if="hasImage" class="relative w-full">
                <img
                    :src="imageUrl!"
                    alt="Medical scan with annotated findings"
                    class="w-full object-contain"
                    @error="loadFailed = true"
                />
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
                        class="absolute -top-7 left-0 rounded bg-clinical-borderline px-2 py-1 font-mono text-[0.65rem] font-bold tracking-wide whitespace-nowrap text-ink uppercase"
                    >
                        {{ box.label }}
                    </span>
                </div>
            </div>
            <div
                v-else
                class="flex aspect-video w-full items-center justify-center font-mono text-sm text-ink-faint"
            >
                {{ imageUrl ? 'Scan preview unavailable' : 'No scan attached' }}
            </div>
        </div>
    </div>
</template>
