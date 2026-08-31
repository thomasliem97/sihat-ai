<script setup lang="ts">
import { Mic, Sparkles } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    feature: 'records' | 'triage';
}>();

const copy = {
    records: {
        tag: 'Specimen 01',
        title: 'Simulated records analysis',
        lead: 'Upload a chest X-ray, CT slice, lab PDF, or document. SihatAI de-identifies first, detects the modality, and sends the artifact to the right specialist.',
        body: [
            'MedGemma writes one findings object: observations, localization, and confidence. Hybrid RAG cites MOH clinical practice guidelines. That same truth becomes a physician report and a patient explanation.',
            'Open a record to inspect overlays, Ask the scan, similar cases, and sign-off. Patients see the plain-language report after a physician signs.',
        ],
        cta: 'Continue to records',
    },
    triage: {
        tag: 'Specimen 02',
        title: 'Simulated voice intake',
        lead: 'Describe symptoms by voice or text. The agent transcribes, asks follow-ups, and structures a chief complaint with an urgency rating.',
        body: [
            'MedGemma runs the interview: history, red flags, and a plan you can hand a clinician. Physicians can link a patient, share the thread, and continue from the same intake.',
            'Start a new session or pick up an existing one. Hold the mic to talk, or type if the microphone is unavailable.',
        ],
        cta: 'Continue to triage',
    },
} as const;

const content = copy[props.feature];
const open = ref(false);

onMounted(() => {
    open.value = true;
});

function onOpenChange(value: boolean): void {
    open.value = value;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="gap-0 overflow-hidden rounded-3xl border-border bg-paper p-0 shadow-atlas sm:max-w-4xl"
        >
            <div class="grid md:grid-cols-[minmax(0,0.9fr)_1.15fr]">
                <div
                    class="atlas-field relative hidden min-h-80 items-center justify-center overflow-hidden p-8 md:flex"
                    aria-hidden="true"
                >
                    <div
                        v-if="feature === 'records'"
                        class="relative h-64 w-56"
                    >
                        <div
                            class="absolute top-2 left-0 w-44 -rotate-6 rounded-2xl border border-border bg-paper p-3 shadow-atlas"
                        >
                            <div class="flex items-center gap-2">
                                <IconDisc size="sm">
                                    <Sparkles class="size-4" />
                                </IconDisc>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div
                                        class="h-2 w-20 rounded-full bg-primary/70"
                                    />
                                    <div
                                        class="h-1.5 w-14 rounded-full bg-line"
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute top-16 left-8 w-48 rotate-3 rounded-2xl border border-border bg-paper p-3 shadow-atlas"
                        >
                            <div
                                class="viewer-surface relative mb-3 aspect-5/4 overflow-hidden rounded-xl"
                            >
                                <svg
                                    viewBox="0 0 160 128"
                                    class="size-full"
                                    fill="none"
                                >
                                    <ellipse
                                        cx="58"
                                        cy="70"
                                        rx="28"
                                        ry="42"
                                        class="fill-paper/20"
                                    />
                                    <ellipse
                                        cx="102"
                                        cy="70"
                                        rx="28"
                                        ry="42"
                                        class="fill-paper/20"
                                    />
                                    <rect
                                        x="22"
                                        y="48"
                                        width="42"
                                        height="38"
                                        rx="4"
                                        class="stroke-chart-4"
                                        stroke-width="2"
                                    />
                                </svg>
                                <span
                                    class="absolute right-1.5 bottom-1.5 font-mono text-xs tracking-wide text-paper uppercase"
                                    >PA · overlay</span
                                >
                            </div>
                            <div class="space-y-1.5">
                                <div
                                    class="h-2 w-full rounded-full bg-primary/80"
                                />
                                <div
                                    class="h-2 w-4/5 rounded-full bg-primary/50"
                                />
                                <div
                                    class="h-2 w-3/5 rounded-full border border-line"
                                />
                            </div>
                            <p
                                class="mt-2 font-mono text-xs tracking-wide text-ink-faint uppercase"
                            >
                                Findings · 88%
                            </p>
                        </div>
                    </div>

                    <div v-else class="relative h-64 w-56">
                        <div
                            class="absolute top-4 left-0 w-44 -rotate-6 rounded-2xl border border-border bg-paper p-3 shadow-atlas"
                        >
                            <div class="flex items-center gap-2">
                                <IconDisc size="sm">
                                    <Mic class="size-4" />
                                </IconDisc>
                                <div
                                    class="flex h-7 flex-1 items-end justify-center gap-0.5"
                                >
                                    <span
                                        v-for="h in [
                                            40, 70, 45, 90, 55, 80, 35, 65,
                                        ]"
                                        :key="h"
                                        class="w-1 rounded-full bg-primary/80"
                                        :style="{ height: `${h}%` }"
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute top-20 left-8 w-48 rotate-3 space-y-2 rounded-2xl border border-border bg-paper p-3 shadow-atlas"
                        >
                            <div class="flex justify-end">
                                <div
                                    class="max-w-[85%] rounded-2xl rounded-br-md bg-paper-blue px-2.5 py-1.5"
                                >
                                    <div
                                        class="h-1.5 w-16 rounded-full bg-primary/60"
                                    />
                                    <div
                                        class="mt-1 h-1.5 w-10 rounded-full bg-line-strong/70"
                                    />
                                </div>
                            </div>
                            <div class="flex items-start gap-2">
                                <IconDisc size="sm">
                                    <Sparkles class="size-4" />
                                </IconDisc>
                                <div
                                    class="flex-1 space-y-1.5 rounded-2xl rounded-tl-md border border-border px-2.5 py-2"
                                >
                                    <div
                                        class="h-1.5 w-full rounded-full bg-primary/70"
                                    />
                                    <div
                                        class="h-1.5 w-4/5 rounded-full bg-primary/40"
                                    />
                                    <div
                                        class="h-1.5 w-2/3 rounded-full border border-line"
                                    />
                                </div>
                            </div>
                            <AnnotationPill variant="amber"
                                >Urgency · review</AnnotationPill
                            >
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-5 p-6 pr-12 md:p-8 md:pr-14">
                    <div class="space-y-3">
                        <SectionTag>{{ content.tag }}</SectionTag>
                        <DialogTitle
                            class="text-2xl font-bold tracking-tight md:text-3xl"
                        >
                            {{ content.title }}
                        </DialogTitle>
                        <DialogDescription
                            class="max-w-prose text-sm leading-relaxed text-ink-soft md:text-base"
                        >
                            {{ content.lead }}
                        </DialogDescription>
                    </div>

                    <div
                        class="max-w-prose space-y-3 text-sm leading-relaxed text-ink-soft"
                    >
                        <p v-for="paragraph in content.body" :key="paragraph">
                            {{ paragraph }}
                        </p>
                    </div>

                    <p
                        class="mt-auto flex flex-wrap items-start gap-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        <AnnotationPill variant="amber"
                            >Disclaimer</AnnotationPill
                        >
                        <span class="min-w-0 flex-1">
                            This walkthrough is a live product demo for
                            evaluation, not a finished or approved medical
                            device. Outputs are not a diagnosis and need
                            clinician review.
                        </span>
                    </p>

                    <div class="flex justify-end">
                        <Button type="button" @click="onOpenChange(false)">
                            {{ content.cta }}
                        </Button>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
