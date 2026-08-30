<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

export type OverlayLayer = 'findings' | 'anatomy' | 'both';

export type OverlayBox = {
    label: string;
    x: number;
    y: number;
    width: number;
    height: number;
    confidence?: number;
    kind?: 'finding' | 'anatomy';
    finding_index?: number | null;
    image_index?: number | null;
};

const props = defineProps<{
    imageUrl?: string | null;
    boxes: OverlayBox[];
    selectedFindingIndex?: number | null;
    anatomyToggle?: boolean;
}>();

const emit = defineEmits<{
    select: [findingIndex: number | null];
}>();

const loadFailed = ref(false);
const layer = ref<OverlayLayer>(props.anatomyToggle ? 'both' : 'findings');
const hasImage = computed(() => !!props.imageUrl && !loadFailed.value);

const visibleBoxes = computed(() => {
    if (!props.anatomyToggle) {
        return props.boxes.filter((box) => boxKind(box) === 'finding');
    }

    if (layer.value === 'both') {
        return props.boxes;
    }

    return props.boxes.filter(
        (box) =>
            boxKind(box) === (layer.value === 'findings' ? 'finding' : 'anatomy'),
    );
});

watch(
    () => props.imageUrl,
    () => {
        loadFailed.value = false;
    },
);

function boxKind(box: OverlayBox): 'finding' | 'anatomy' {
    return box.kind === 'anatomy' ? 'anatomy' : 'finding';
}

function isSelected(box: OverlayBox): boolean {
    return (
        boxKind(box) === 'finding' &&
        box.finding_index != null &&
        props.selectedFindingIndex === box.finding_index
    );
}

function onFindingClick(box: OverlayBox): void {
    if (boxKind(box) !== 'finding' || box.finding_index == null) {
        return;
    }

    emit(
        'select',
        props.selectedFindingIndex === box.finding_index
            ? null
            : box.finding_index,
    );
}

function setLayer(next: OverlayLayer): void {
    layer.value = next;
}
</script>

<template>
    <div
        class="viewer-surface flex min-h-72 flex-col overflow-hidden rounded-2xl border border-line-strong"
    >
        <div
            class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-white/10 px-6 py-3 font-mono text-xs tracking-wide uppercase"
        >
            <span>Scan viewer</span>
            <div class="flex flex-wrap items-center gap-2">
                <div
                    v-if="anatomyToggle"
                    class="flex items-center gap-1 normal-case"
                >
                    <Button
                        type="button"
                        size="sm"
                        :variant="layer === 'findings' ? 'default' : 'outline'"
                        class="h-7 px-2 font-mono text-[0.65rem] tracking-wide uppercase"
                        :aria-pressed="layer === 'findings'"
                        @click="setLayer('findings')"
                    >
                        Findings
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="layer === 'anatomy' ? 'default' : 'outline'"
                        class="h-7 px-2 font-mono text-[0.65rem] tracking-wide uppercase"
                        :aria-pressed="layer === 'anatomy'"
                        @click="setLayer('anatomy')"
                    >
                        Anatomy
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="layer === 'both' ? 'default' : 'outline'"
                        class="h-7 px-2 font-mono text-[0.65rem] tracking-wide uppercase"
                        :aria-pressed="layer === 'both'"
                        @click="setLayer('both')"
                    >
                        Both
                    </Button>
                </div>
                <span class="text-ink-faint">
                    {{
                        visibleBoxes.length
                            ? `${visibleBoxes.length} overlay(s)`
                            : 'No overlays'
                    }}
                </span>
            </div>
        </div>
        <div class="flex min-h-0 flex-1 items-center justify-center">
            <div v-if="hasImage" class="relative w-full">
                <img
                    :src="imageUrl!"
                    alt="Medical scan with annotated findings"
                    class="w-full object-contain"
                    @error="loadFailed = true"
                />
                <template v-for="(box, i) in visibleBoxes" :key="i">
                    <button
                        v-if="boxKind(box) === 'finding'"
                        type="button"
                        class="absolute border-2 border-coral"
                        :class="
                            isSelected(box)
                                ? 'z-10 ring-2 ring-coral/80'
                                : ''
                        "
                        :style="{
                            left: `${box.x * 100}%`,
                            top: `${box.y * 100}%`,
                            width: `${box.width * 100}%`,
                            height: `${box.height * 100}%`,
                        }"
                        :aria-pressed="isSelected(box)"
                        :aria-label="`Finding: ${box.label}`"
                        @click="onFindingClick(box)"
                    >
                        <span
                            class="absolute -top-7 left-0 rounded bg-coral px-2 py-1 font-mono text-[0.65rem] font-bold tracking-wide whitespace-nowrap text-white uppercase"
                        >
                            {{ box.label }}
                        </span>
                    </button>
                    <div
                        v-else
                        class="pointer-events-none absolute border-2 border-dashed border-line-strong"
                        :style="{
                            left: `${box.x * 100}%`,
                            top: `${box.y * 100}%`,
                            width: `${box.width * 100}%`,
                            height: `${box.height * 100}%`,
                        }"
                    >
                        <span
                            class="absolute -top-7 left-0 rounded border border-line-strong bg-paper px-2 py-1 font-mono text-[0.65rem] font-bold tracking-wide whitespace-nowrap text-ink-soft uppercase"
                        >
                            {{ box.label }}
                        </span>
                    </div>
                </template>
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
