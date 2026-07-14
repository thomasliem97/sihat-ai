<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Mic, MicOff, Send, Volume2 } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import ClinicalBadge from '@/components/medical/ClinicalBadge.vue';
import MedicalDisclaimer from '@/components/medical/MedicalDisclaimer.vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { triage } from '@/routes/voice';
import { transcribe } from '@/routes/voice/triage';

const interviewQuestions = [
    'What is the main symptom that brought you here today?',
    'How long have you had these symptoms?',
    'Do you have chest pain, shortness of breath, or fever?',
    'Are you taking any medications or do you have chronic conditions?',
];

const page = usePage();
const transcript = ref('');
const result = ref<Record<string, unknown> | null>(null);
const sttEngine = ref<string | null>(null);
const loading = ref(false);
const recording = ref(false);
const interviewIndex = ref(0);
const audioBlob = ref<Blob | null>(null);
let mediaRecorder: MediaRecorder | null = null;
const chunks: BlobPart[] = [];

function speakQuestion(index = interviewIndex.value) {
    if (typeof window === 'undefined' || !window.speechSynthesis) {
        return;
    }
    const text = interviewQuestions[index] ?? interviewQuestions[0];
    window.speechSynthesis.cancel();
    const utter = new SpeechSynthesisUtterance(text);
    utter.rate = 0.95;
    window.speechSynthesis.speak(utter);
}

function nextInterviewQuestion() {
    interviewIndex.value = (interviewIndex.value + 1) % interviewQuestions.length;
    speakQuestion(interviewIndex.value);
}

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
        sttEngine.value = data.engine ?? null;
        result.value = data.triage;
    } finally {
        loading.value = false;
    }
}

onUnmounted(() => {
    if (recording.value) {
        mediaRecorder?.stop();
    }
    if (typeof window !== 'undefined' && window.speechSynthesis) {
        window.speechSynthesis.cancel();
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

    <div class="space-y-6">
        <PageHeader
            tag="MedASR triage"
            title="Voice Triage"
            description="Preclinical interview prompts (browser TTS), then MedASR or Whisper STT + urgency guidance"
        />

        <Card class="paper-panel--focal border-0 shadow-offset">
            <CardHeader class="space-y-2">
                <SectionTag>Preclinical interview</SectionTag>
                <CardTitle class="text-lg">Spoken prompts</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-sm leading-relaxed text-muted-foreground">
                    {{ interviewQuestions[interviewIndex] }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" @click="speakQuestion()">
                        <Volume2 class="size-4" />
                        Speak question
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="nextInterviewQuestion"
                    >
                        Next question
                    </Button>
                </div>
            </CardContent>
        </Card>

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
                    <AnnotationPill v-if="sttEngine" variant="teal">
                        STT: {{ sttEngine }}
                    </AnnotationPill>
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
