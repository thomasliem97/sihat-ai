<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Mic, MicOff, Send } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import ClinicalBadge from '@/components/medical/ClinicalBadge.vue';
import MedicalDisclaimer from '@/components/medical/MedicalDisclaimer.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { triage } from '@/routes/voice';
import { transcribe } from '@/routes/voice/triage';

const page = usePage();
const transcript = ref('');
const result = ref<Record<string, unknown> | null>(null);
const loading = ref(false);
const recording = ref(false);
const audioBlob = ref<Blob | null>(null);
let mediaRecorder: MediaRecorder | null = null;
const chunks: BlobPart[] = [];

async function toggleRecording() {
    if (recording.value) {
        mediaRecorder?.stop();
        recording.value = false;
        return;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    chunks.length = 0;
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (e) => {
        if (e.data.size > 0) {
            chunks.push(e.data);
        }
    };
    mediaRecorder.onstop = () => {
        audioBlob.value = new Blob(chunks, { type: 'audio/webm' });
        stream.getTracks().forEach((t) => t.stop());
    };
    mediaRecorder.start();
    recording.value = true;
}

async function submitTriage() {
    loading.value = true;
    try {
        const csrf =
            (page.props as { csrf_token?: string }).csrf_token ??
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

        const form = new FormData();
        if (transcript.value.trim()) {
            form.append('transcript', transcript.value.trim());
        }
        if (audioBlob.value) {
            form.append('audio', audioBlob.value, 'triage.webm');
        }

        const response = await fetch(transcribe.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf ?? '',
            },
            body: form,
        });

        const data = await response.json();
        if (!response.ok) {
            return;
        }
        if (data.transcript) {
            transcript.value = data.transcript;
        }
        result.value = data.triage;
    } finally {
        loading.value = false;
    }
}

onUnmounted(() => {
    if (recording.value) {
        mediaRecorder?.stop();
    }
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Voice Triage', href: triage() }],
    },
});
</script>

<template>
    <Head title="Voice Triage" />

    <div class="atlas-field mx-auto max-w-2xl space-y-6 p-4 md:p-6">
        <PageHeader
            tag="STT triage"
            title="Voice Triage"
            description="Record or type symptoms — STT + structured urgency guidance"
        />

        <Card class="paper-panel--focal border-0 shadow-offset">
            <CardHeader class="space-y-2">
                <SectionTag>Intake</SectionTag>
                <CardTitle class="flex items-center gap-2 text-lg">
                    <Mic class="size-4 text-primary" />
                    Describe your symptoms
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="toggleRecording"
                    >
                        <MicOff v-if="recording" class="size-4" />
                        <Mic v-else class="size-4" />
                        {{ recording ? 'Stop recording' : 'Record audio' }}
                    </Button>
                    <span
                        v-if="audioBlob"
                        class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Audio captured
                    </span>
                </div>
                <Textarea
                    v-model="transcript"
                    placeholder="e.g. I have had a cough and mild fever for 3 days..."
                    rows="4"
                />
                <Button
                    :disabled="
                        loading || (!transcript.trim() && !audioBlob)
                    "
                    @click="submitTriage"
                >
                    <Send class="size-4" />
                    Analyze symptoms
                </Button>
            </CardContent>
        </Card>

        <Card v-if="result">
            <CardHeader class="space-y-2">
                <SectionTag>Result</SectionTag>
                <CardTitle class="text-lg">Triage result</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm leading-relaxed">
                <div class="flex items-center gap-2">
                    <span
                        class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >Urgency</span
                    >
                    <ClinicalBadge
                        :status="
                            result.urgency === 'emergency'
                                ? 'critical'
                                : result.urgency === 'urgent'
                                  ? 'abnormal'
                                  : 'normal'
                        "
                    />
                </div>
                <p>
                    <span class="text-muted-foreground">Chief complaint:</span>
                    {{ result.chief_complaint }}
                </p>
                <div v-if="result.suggested_questions">
                    <p
                        class="mb-1 font-mono text-xs font-semibold tracking-wide uppercase"
                    >
                        Suggested follow-up questions
                    </p>
                    <ul class="list-inside list-disc text-muted-foreground">
                        <li
                            v-for="(q, i) in result.suggested_questions as string[]"
                            :key="i"
                        >
                            {{ q }}
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>

        <MedicalDisclaimer />
    </div>
</template>
