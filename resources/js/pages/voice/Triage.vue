<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ChevronLeft,
    CircleStop,
    Copy,
    Ellipsis,
    Mic,
    MicOff,
    ScrollText,
    Send,
    Share2,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    archive as archiveSessionRoute,
    message as messageSessionRoute,
    session as showSessionRoute,
    share as shareSessionRoute,
    speak as speakMessageRoute,
    store as storeSessionRoute,
} from '@/actions/App/Http/Controllers/VoiceTriageController';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { beginColdStartWatch, endColdStartWatch } from '@/lib/coldStartNotice';
import { triage } from '@/routes/voice';

type TriageMessage = {
    id: number;
    role: string;
    content: string;
    input_modality?: string;
    stt_engine?: string | null;
    created_at?: string | null;
};

type PendingTurn = {
    status: string;
    message?: string;
};

type TriageSession = {
    id: number;
    role_context: string;
    locale: string;
    status: string;
    urgency: string | null;
    chief_complaint: string | null;
    summary: string | null;
    shared_at: string | null;
    subject_user_id: number | null;
    subject_name?: string | null;
    owner_name?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    last_message_at?: string | null;
    pending_turn?: PendingTurn | null;
    messages?: TriageMessage[];
};

type TurnPhase = 'idle' | 'transcribing' | 'thinking' | 'speaking';

const props = defineProps<{
    sessions: TriageSession[];
    sharedSessions: TriageSession[];
    patients: Array<{ id: number; name: string }>;
    isPhysician: boolean;
    activeSessionId: number | null;
}>();

const page = usePage();
const ownSessions = ref<TriageSession[]>([...props.sessions]);
const sharedSessions = ref<TriageSession[]>([...props.sharedSessions]);
const active = ref<TriageSession | null>(null);
const draft = ref('');
const subjectUserId = ref<string>('__none__');
const swapping = ref(false);
const recording = ref(false);
const WAVE_BAR_COUNT = 36;
const waveLevels = ref<number[]>(
    Array.from({ length: WAVE_BAR_COUNT }, () => 0.12),
);
const autoplay = ref(false);
const summaryOpen = ref(false);
const audioByMessageId = ref<Record<number, string>>({});
const loadingSpeechMessageId = ref<number | null>(null);
const playingMessageId = ref<number | null>(null);
const openMessageActionsId = ref<number | null>(null);
const threadEnd = ref<HTMLElement | null>(null);

/** null = blank new-session draft */
const viewingSessionId = ref<number | null>(null);
const sessionCache = ref<Record<number, TriageSession>>({});
const phaseBySessionId = ref<Record<number, TurnPhase>>({});
const draftByKey = ref<Record<string, string>>({});

let mediaRecorder: MediaRecorder | null = null;
let mediaStream: MediaStream | null = null;
let waveStream: MediaStream | null = null;
const chunks: BlobPart[] = [];
let audioEl: HTMLAudioElement | null = null;
let audioPlaybackEpoch = 0;
let micPointerId: number | null = null;
let waveAudioContext: AudioContext | null = null;
let waveAnalyser: AnalyserNode | null = null;
let waveFrame = 0;
let waveSamples: Uint8Array<ArrayBuffer> | null = null;
let stoppingRecording = false;
let recordingStartedAt = 0;
/** Peak |sample-128| from the live waveform (0–128). Silence stays near 0. */
let recordingPeak = 0;
let micDownClientX = 0;
const cancelArmed = ref(false);

/** Whisper hallucinates on near-empty / silent clips; require a real hold + energy. */
const MIN_RECORDING_MS = 1000;
const MIN_AUDIO_BYTES = 1500;
const MIN_RECORDING_PEAK = 6;
/** Slide left past this while holding to discard on release. */
const CANCEL_SLIDE_PX = 72;

const AUTOPLAY_KEY = 'sihat.triage.autoplay';

if (typeof window !== 'undefined') {
    autoplay.value = window.localStorage.getItem(AUTOPLAY_KEY) === '1';
}

watch(autoplay, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(AUTOPLAY_KEY, value ? '1' : '0');
    }
});

const csrf = computed(
    () => (page.props as { csrf_token?: string }).csrf_token ?? '',
);

function draftStorageKey(sessionId: number | null): string {
    return sessionId && sessionId > 0 ? String(sessionId) : 'new';
}

function getPhase(sessionId: number): TurnPhase {
    return phaseBySessionId.value[sessionId] ?? 'idle';
}

function setPhase(sessionId: number, phase: TurnPhase) {
    phaseBySessionId.value = {
        ...phaseBySessionId.value,
        [sessionId]: phase,
    };
}

function putSessionCache(session: TriageSession) {
    if (!session.id || session.id <= 0) {
        return;
    }

    sessionCache.value = {
        ...sessionCache.value,
        [session.id]: {
            ...session,
            messages: session.messages ? [...session.messages] : [],
        },
    };
}

function stashCurrentView() {
    if (active.value && (active.value.id ?? 0) > 0) {
        putSessionCache(active.value);
    }

    draftByKey.value = {
        ...draftByKey.value,
        [draftStorageKey(viewingSessionId.value)]: draft.value,
    };
}

const activePhase = computed<TurnPhase>(() => {
    const id = viewingSessionId.value;

    if (id && id > 0) {
        return getPhase(id);
    }

    return getPhase(0);
});

const phaseLabel = computed(() => {
    switch (activePhase.value) {
        case 'transcribing':
            return 'Transcribing…';
        case 'thinking':
            return 'Thinking…';
        case 'speaking':
            return 'Speaking…';
        default:
            return '';
    }
});

const isActivePending = computed(() => activePhase.value !== 'idle');

watch(
    activePhase,
    (phase) => {
        if (phase === 'transcribing' || phase === 'thinking') {
            beginColdStartWatch();
        } else {
            endColdStartWatch();
        }
    },
);

const canCompose = computed(
    () =>
        (!active.value || active.value.status === 'active') &&
        !isActivePending.value,
);

const showUrgencyBanner = computed(() => {
    const urgency = active.value?.urgency;

    return urgency === 'urgent' || urgency === 'emergency';
});

function formatRelativeTime(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return '-';
    }

    const seconds = Math.round((then - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    if (abs < 60) {
        return rtf.format(seconds, 'second');
    }
    if (abs < 3600) {
        return rtf.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86400) {
        return rtf.format(Math.round(seconds / 3600), 'hour');
    }
    if (abs < 604800) {
        return rtf.format(Math.round(seconds / 86400), 'day');
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

function formatMessageTime(iso: string | null | undefined): string {
    return formatTimedDayLabel(iso, ', ');
}

function formatClusterLabel(iso: string | null | undefined): string {
    return formatTimedDayLabel(iso, ' ');
}

function formatTimedDayLabel(
    iso: string | null | undefined,
    dayTimeSeparator: string,
): string {
    if (!iso) {
        return '-';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    const time = date.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });

    const now = new Date();
    const startOfToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    );
    const startOfThatDay = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    );
    const dayDiff = Math.round(
        (startOfToday.getTime() - startOfThatDay.getTime()) / 86_400_000,
    );

    if (dayDiff === 0) {
        return `Today${dayTimeSeparator}${time}`;
    }

    if (dayDiff === 1) {
        return `Yesterday${dayTimeSeparator}${time}`;
    }

    if (dayDiff > 1 && dayDiff < 7) {
        const weekday = date.toLocaleDateString(undefined, {
            weekday: 'long',
        });

        return `${weekday}${dayTimeSeparator}${time}`;
    }

    const day = date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });

    return `${day}${dayTimeSeparator}${time}`;
}

const MESSAGE_CLUSTER_GAP_MS = 60 * 60 * 1000;

function isSameCalendarDay(a: Date, b: Date): boolean {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

function shouldStartMessageCluster(
    current: TriageMessage,
    previous: TriageMessage | null,
): boolean {
    if (!previous) {
        return true;
    }

    if (!current.created_at || !previous.created_at) {
        return false;
    }

    const currentAt = new Date(current.created_at);
    const previousAt = new Date(previous.created_at);

    if (
        Number.isNaN(currentAt.getTime()) ||
        Number.isNaN(previousAt.getTime())
    ) {
        return false;
    }

    if (currentAt.getTime() - previousAt.getTime() >= MESSAGE_CLUSTER_GAP_MS) {
        return true;
    }

    return !isSameCalendarDay(currentAt, previousAt);
}

const threadItems = computed(() => {
    const messages = active.value?.messages ?? [];

    return messages.map((message, index) => {
        const previous = index > 0 ? (messages[index - 1] ?? null) : null;
        const startsCluster = shouldStartMessageCluster(message, previous);

        return {
            message,
            clusterLabel: startsCluster
                ? formatClusterLabel(message.created_at)
                : null,
        };
    });
});

function jsonHeaders(): HeadersInit {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf.value,
    };
}

function revokeAudioMap() {
    Object.values(audioByMessageId.value).forEach((url) => {
        URL.revokeObjectURL(url);
    });
    audioByMessageId.value = {};
}

async function scrollThreadToEnd() {
    await nextTick();
    threadEnd.value?.scrollIntoView({ behavior: 'smooth', block: 'end' });
}

function stopMessageAudio() {
    audioPlaybackEpoch += 1;
    audioEl?.pause();
    if (audioEl) {
        audioEl.currentTime = 0;
    }
    playingMessageId.value = null;
}

function attachAudioLifecycle(messageId: number) {
    if (!audioEl) {
        return;
    }

    audioEl.onended = () => {
        if (playingMessageId.value === messageId) {
            playingMessageId.value = null;
        }
    };
}

async function playMessageAudioUrl(messageId: number, url: string) {
    if (recording.value) {
        return;
    }

    const epoch = audioPlaybackEpoch;
    audioEl?.pause();
    audioEl = new Audio(url);
    attachAudioLifecycle(messageId);
    playingMessageId.value = messageId;

    try {
        await audioEl.play();
        if (epoch !== audioPlaybackEpoch) {
            audioEl.pause();
            audioEl.currentTime = 0;
            playingMessageId.value = null;
        }
    } catch {
        playingMessageId.value = null;
    }
}

function storeMessageAudio(messageId: number, base64: string, play: boolean) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    const blob = new Blob([bytes], { type: 'audio/mpeg' });
    const url = URL.createObjectURL(blob);

    if (audioByMessageId.value[messageId]) {
        URL.revokeObjectURL(audioByMessageId.value[messageId]);
    }
    audioByMessageId.value = {
        ...audioByMessageId.value,
        [messageId]: url,
    };

    if (play) {
        void playMessageAudioUrl(messageId, url);
    } else {
        audioEl?.pause();
        audioEl = new Audio(url);
        attachAudioLifecycle(messageId);
    }
}

function onMessageActionsOpenChange(messageId: number, open: boolean) {
    openMessageActionsId.value = open ? messageId : null;
}

function onReplayVoiceSelect(event: Event, messageId: number) {
    if (playingMessageId.value === messageId) {
        stopMessageAudio();

        return;
    }

    if (audioByMessageId.value[messageId]) {
        void playMessageAudioUrl(messageId, audioByMessageId.value[messageId]);

        return;
    }

    event.preventDefault();
    void fetchAndPlayMessageAudio(messageId, { showMenu: true });
}

async function fetchAndPlayMessageAudio(
    messageId: number,
    options?: { showMenu?: boolean },
) {
    if (!active.value || loadingSpeechMessageId.value === messageId) {
        return;
    }

    const showMenu = options?.showMenu ?? false;
    const epoch = audioPlaybackEpoch;
    loadingSpeechMessageId.value = messageId;
    if (showMenu) {
        openMessageActionsId.value = messageId;
    }

    try {
        const response = await fetch(
            speakMessageRoute.url({
                session: active.value.id,
                message: messageId,
            }),
            {
                method: 'POST',
                headers: jsonHeaders(),
            },
        );
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.audio_base64) {
            toast.error(data.message || 'Could not play voice');

            return;
        }

        storeMessageAudio(messageId, data.audio_base64, false);
        const url = audioByMessageId.value[messageId];
        if (url && !recording.value && epoch === audioPlaybackEpoch) {
            await playMessageAudioUrl(messageId, url);
        }
        if (showMenu) {
            openMessageActionsId.value = null;
        }
    } catch {
        toast.error('Could not play voice');
    } finally {
        loadingSpeechMessageId.value = null;
    }
}

async function copyMessage(content: string) {
    try {
        await navigator.clipboard.writeText(content);
        toast.success('Copied');
    } catch {
        toast.error('Could not copy');
    }
}

function toggleAutoplay() {
    autoplay.value = !autoplay.value;
    stopMessageAudio();
    loadingSpeechMessageId.value = null;
}

function startNewTriage() {
    stashCurrentView();

    micPointerId = null;
    if (recording.value) {
        mediaRecorder?.stop();
        recording.value = false;
        stopMediaTracks();
    }
    stopMessageAudio();
    viewingSessionId.value = null;
    active.value = null;
    draft.value = draftByKey.value.new ?? '';
    summaryOpen.value = false;
    openMessageActionsId.value = null;
    loadingSpeechMessageId.value = null;
    swapping.value = false;
}

async function ensureActiveSession(
    preferred?: TriageSession | null,
): Promise<TriageSession | null> {
    const current = preferred ?? active.value;

    if (current?.status === 'active' && (current.id ?? 0) > 0) {
        return current;
    }

    if (current && (current.id ?? 0) > 0) {
        return null;
    }

    const body: Record<string, unknown> = {};
    if (
        props.isPhysician &&
        subjectUserId.value !== '__none__' &&
        subjectUserId.value !== ''
    ) {
        body.subject_user_id = Number(subjectUserId.value);
    }

    const response = await fetch(storeSessionRoute.url(), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        toast.error(data.message || 'Could not start triage');

        return null;
    }

    const pendingMessages = current?.messages ?? [];
    const session: TriageSession = {
        ...data.session,
        messages:
            pendingMessages.length > 0
                ? pendingMessages
                : (data.session.messages ?? []),
    };

    putSessionCache(session);
    ownSessions.value = [
        data.session,
        ...ownSessions.value.filter((s) => s.id !== data.session.id),
    ];

    if (viewingSessionId.value === null) {
        viewingSessionId.value = session.id;
        active.value = session;
        draftByKey.value = {
            ...draftByKey.value,
            [String(session.id)]: draftByKey.value.new ?? '',
            new: '',
        };
    }

    return session;
}

async function openSession(id: number) {
    if (
        viewingSessionId.value === id &&
        active.value?.id === id &&
        !swapping.value &&
        (active.value.messages?.length ?? 0) > 0
    ) {
        void resumePendingVoiceTurn(active.value);

        return;
    }

    stashCurrentView();

    if (recording.value) {
        mediaRecorder?.stop();
        recording.value = false;
        stopMediaTracks();
    }
    stopMessageAudio();

    viewingSessionId.value = id;
    draft.value = draftByKey.value[String(id)] ?? '';
    openMessageActionsId.value = null;

    const cached = sessionCache.value[id];
    const listed =
        ownSessions.value.find((s) => s.id === id) ??
        sharedSessions.value.find((s) => s.id === id) ??
        null;
    const cachedMessages = cached?.messages;

    if (cachedMessages && cachedMessages.length > 0) {
        active.value = cached;
        swapping.value = false;
        await scrollThreadToEnd();
    } else {
        active.value = cached
            ? { ...cached, messages: cachedMessages ?? [] }
            : {
                  ...(listed ?? {
                      id,
                      role_context: props.isPhysician
                          ? 'physician'
                          : 'patient',
                      locale: '',
                      status: 'active',
                      urgency: null,
                      chief_complaint: null,
                      summary: null,
                      shared_at: null,
                      subject_user_id: null,
                  }),
                  messages: [],
              };
        swapping.value = true;
    }

    try {
        const response = await fetch(showSessionRoute.url(id), {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf.value,
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            toast.error(data.message || 'Could not open session');

            return;
        }

        const serverSession = data.session as TriageSession;
        const pending = getPhase(id) !== 'idle';
        const cachedDuringFetch = sessionCache.value[id];

        if (
            pending &&
            cachedDuringFetch?.messages &&
            (cachedDuringFetch.messages.length ?? 0) >=
                (serverSession.messages?.length ?? 0)
        ) {
            const merged: TriageSession = {
                ...serverSession,
                messages: cachedDuringFetch.messages,
            };
            putSessionCache(merged);
            if (viewingSessionId.value === id) {
                active.value = merged;
            }
            void resumePendingVoiceTurn(merged);
        } else {
            putSessionCache(serverSession);
            if (viewingSessionId.value === id) {
                active.value = serverSession;
                await scrollThreadToEnd();
            }
            void resumePendingVoiceTurn(serverSession);
        }
    } finally {
        if (viewingSessionId.value === id) {
            swapping.value = false;
        }
    }
}

function stopMediaTracks() {
    stopWaveform();
    mediaStream?.getTracks().forEach((track) => track.stop());
    mediaStream = null;
}

function stopWaveform() {
    if (waveFrame) {
        cancelAnimationFrame(waveFrame);
        waveFrame = 0;
    }
    waveAnalyser = null;
    waveSamples = null;
    if (waveAudioContext) {
        void waveAudioContext.close().catch(() => {});
        waveAudioContext = null;
    }
    waveStream?.getTracks().forEach((track) => track.stop());
    waveStream = null;
    waveLevels.value = Array.from({ length: WAVE_BAR_COUNT }, () => 0.12);
}

function startWaveform(stream: MediaStream) {
    stopWaveform();
    waveStream = stream;

    const context = new AudioContext();
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    analyser.fftSize = 256;
    analyser.smoothingTimeConstant = 0.85;
    source.connect(analyser);

    waveAudioContext = context;
    waveAnalyser = analyser;
    waveSamples = new Uint8Array(analyser.fftSize);

    const tick = () => {
        if (!waveAnalyser || !waveSamples) {
            return;
        }

        waveAnalyser.getByteTimeDomainData(waveSamples);
        const bucket = Math.floor(waveSamples.length / WAVE_BAR_COUNT);
        const next: number[] = [];

        for (let i = 0; i < WAVE_BAR_COUNT; i++) {
            let peak = 0;
            const start = i * bucket;
            const end = Math.min(start + bucket, waveSamples.length);
            for (let j = start; j < end; j++) {
                peak = Math.max(peak, Math.abs((waveSamples[j] ?? 128) - 128));
            }
            recordingPeak = Math.max(recordingPeak, peak);
            next.push(Math.max(0.1, Math.min(1, (peak / 128) * 3.4)));
        }

        waveLevels.value = next;
        waveFrame = requestAnimationFrame(tick);
    };

    if (context.state === 'suspended') {
        void context.resume();
    }
    waveFrame = requestAnimationFrame(tick);
}

async function startRecording() {
    if (recording.value || !canCompose.value) {
        return;
    }

    stopMessageAudio();
    loadingSpeechMessageId.value = null;

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    mediaStream = stream;
    chunks.length = 0;

    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : '';

    mediaRecorder = mimeType
        ? new MediaRecorder(stream, { mimeType })
        : new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (e) => {
        if (e.data.size > 0) {
            chunks.push(e.data);
        }
    };
    // Timeslice keeps chunks flowing; final chunk still arrives on stop.
    mediaRecorder.start(250);
    recordingStartedAt = Date.now();
    recordingPeak = 0;

    try {
        startWaveform(stream.clone());
    } catch {
        // Clone unavailable: skip live waveform rather than starve the recorder.
        recordingPeak = MIN_RECORDING_PEAK;
    }

    recording.value = true;
}

function stopRecordingBlob(): Promise<Blob | null> {
    return new Promise((resolve) => {
        if (!mediaRecorder || mediaRecorder.state === 'inactive') {
            recording.value = false;
            stopMediaTracks();
            resolve(null);
            return;
        }

        const recorder = mediaRecorder;
        const recordedType = recorder.mimeType || 'audio/webm';

        recorder.onstop = () => {
            const blob =
                chunks.length > 0 ? new Blob(chunks, { type: recordedType }) : null;
            recording.value = false;
            stopMediaTracks();
            resolve(blob);
        };

        try {
            if (recorder.state === 'recording') {
                recorder.requestData();
            }
        } catch {
            // Some browsers reject requestData outside recording; stop is enough.
        }

        recorder.stop();
    });
}

async function discardRecording() {
    if (stoppingRecording) {
        return;
    }

    stoppingRecording = true;
    cancelArmed.value = false;
    recordingStartedAt = 0;
    recordingPeak = 0;

    try {
        await stopRecordingBlob();
    } finally {
        stoppingRecording = false;
    }
}

async function stopRecordingAndSend() {
    if (stoppingRecording) {
        return;
    }

    stoppingRecording = true;
    cancelArmed.value = false;

    try {
        const heldMs = recordingStartedAt > 0 ? Date.now() - recordingStartedAt : 0;
        const peak = recordingPeak;
        recordingStartedAt = 0;
        recordingPeak = 0;

        const blob = await stopRecordingBlob();
        if (
            !blob ||
            blob.size < MIN_AUDIO_BYTES ||
            heldMs < MIN_RECORDING_MS ||
            peak < MIN_RECORDING_PEAK
        ) {
            toast.error(
                peak < MIN_RECORDING_PEAK
                    ? 'No speech heard. Speak closer to the mic and try again.'
                    : 'Hold the mic and speak before releasing.',
            );

            return;
        }
        await sendMessage({ audio: blob });
    } finally {
        stoppingRecording = false;
    }
}

async function onMicPointerDown(event: PointerEvent) {
    if (event.button !== 0) {
        return;
    }

    if (!canCompose.value || recording.value) {
        return;
    }

    event.preventDefault();
    const target = event.currentTarget;
    if (target instanceof HTMLElement) {
        target.setPointerCapture(event.pointerId);
    }
    micPointerId = event.pointerId;
    micDownClientX = event.clientX;
    cancelArmed.value = false;
    await startRecording();
}

function onMicPointerMove(event: PointerEvent) {
    if (!recording.value || event.pointerId !== micPointerId) {
        return;
    }

    cancelArmed.value = micDownClientX - event.clientX >= CANCEL_SLIDE_PX;
}

async function onMicPointerUp(event: PointerEvent) {
    if (micPointerId !== null && event.pointerId !== micPointerId) {
        return;
    }

    const shouldCancel = cancelArmed.value;
    micPointerId = null;
    cancelArmed.value = false;
    const target = event.currentTarget;
    if (target instanceof HTMLElement && target.hasPointerCapture(event.pointerId)) {
        target.releasePointerCapture(event.pointerId);
    }

    if (!recording.value || stoppingRecording) {
        return;
    }

    if (shouldCancel) {
        await discardRecording();

        return;
    }

    await stopRecordingAndSend();
}

function onRecordingKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape' || !recording.value) {
        return;
    }

    event.preventDefault();
    micPointerId = null;
    void discardRecording();
}

watch(recording, (isRecording) => {
    if (isRecording) {
        window.addEventListener('keydown', onRecordingKeydown);
    } else {
        window.removeEventListener('keydown', onRecordingKeydown);
        cancelArmed.value = false;
    }
});

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function rollbackOptimisticMessage(
    sessionId: number,
    pendingId: number,
    restoreText?: string,
) {
    const current =
        sessionCache.value[sessionId] ??
        (viewingSessionId.value === sessionId ? active.value : null);

    if (!current) {
        return;
    }

    const rolledBack: TriageSession = {
        ...current,
        messages: (current.messages ?? []).filter((m) => m.id !== pendingId),
    };
    putSessionCache(rolledBack);
    if (viewingSessionId.value === sessionId) {
        active.value = rolledBack;
        if (restoreText) {
            draft.value = restoreText;
        }
    }
}

const pollingSessionIds = new Set<number>();

async function pollVoiceTurn(
    sessionId: number,
    seed?: TriageSession,
): Promise<TriageSession> {
    const deadline = Date.now() + 5 * 60_000;
    let nextSeed = seed ?? null;

    while (Date.now() < deadline) {
        let session: TriageSession;

        if (nextSeed) {
            session = nextSeed;
            nextSeed = null;
        } else {
            const response = await fetch(showSessionRoute.url(sessionId), {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf.value,
                },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Could not load triage session');
            }
            session = data.session as TriageSession;
        }

        const pending = session.pending_turn;
        if (!pending || pending.status !== 'processing') {
            if (pending?.status === 'failed') {
                throw new Error(
                    pending.message ||
                        'No speech heard. Speak closer to the mic and try again.',
                );
            }

            return session;
        }

        putSessionCache(session);
        if (viewingSessionId.value === sessionId) {
            setPhase(sessionId, 'transcribing');
        }
        await sleep(1000);
    }

    throw new Error('Voice processing timed out. Please try again.');
}

async function awaitPendingVoiceTurn(
    sessionId: number,
    seed?: TriageSession,
): Promise<TriageSession> {
    if (pollingSessionIds.has(sessionId)) {
        while (pollingSessionIds.has(sessionId)) {
            await sleep(250);
        }

        const response = await fetch(showSessionRoute.url(sessionId), {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf.value,
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || 'Could not load triage session');
        }

        return data.session as TriageSession;
    }

    pollingSessionIds.add(sessionId);
    setPhase(sessionId, 'transcribing');

    try {
        return await pollVoiceTurn(sessionId, seed);
    } finally {
        pollingSessionIds.delete(sessionId);
    }
}

async function resumePendingVoiceTurn(session: TriageSession): Promise<void> {
    const sessionId = session.id;
    if (
        !sessionId ||
        sessionId <= 0 ||
        session.pending_turn?.status !== 'processing'
    ) {
        return;
    }

    try {
        const result = await awaitPendingVoiceTurn(sessionId, session);
        if (viewingSessionId.value === sessionId) {
            setPhase(sessionId, 'speaking');
        }
        await applyCompletedTurn(result, sessionId);
    } catch (error) {
        if (viewingSessionId.value === sessionId) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Could not process voice message';
            toast.error(message);
        }
    } finally {
        setPhase(sessionId, 'idle');
    }
}

async function applyCompletedTurn(
    session: TriageSession,
    requestSessionId: number,
    options?: { audioBase64?: string | null; assistantId?: number },
) {
    putSessionCache(session);
    ownSessions.value = ownSessions.value.map((s) =>
        s.id === session.id ? { ...s, ...session, messages: undefined } : s,
    );

    const assistantId =
        options?.assistantId ??
        [...(session.messages ?? [])]
            .reverse()
            .find((m) => m.role === 'assistant')?.id;

    if (viewingSessionId.value === requestSessionId) {
        active.value = session;
        await scrollThreadToEnd();

        if (options?.audioBase64 && assistantId) {
            storeMessageAudio(assistantId, options.audioBase64, autoplay.value);
        } else if (autoplay.value && assistantId) {
            void fetchAndPlayMessageAudio(assistantId);
        }
    } else if (options?.audioBase64 && assistantId) {
        storeMessageAudio(assistantId, options.audioBase64, false);
    }
}

async function sendMessage(options?: { audio?: Blob; text?: string }) {
    if (isActivePending.value) {
        return;
    }

    if (active.value && active.value.status !== 'active') {
        return;
    }

    const text = (options?.text ?? draft.value).trim();
    const audio = options?.audio ?? null;
    if (!text && !audio) {
        return;
    }

    const pendingId = -Date.now();
    const modality = audio ? 'voice' : 'text';
    const optimisticContent = text || (audio ? '…' : '');
    let requestSessionId = 0;
    let optimisticAttached = false;

    const optimisticMessage: TriageMessage = {
        id: pendingId,
        role: 'user',
        content: optimisticContent,
        input_modality: modality,
        created_at: new Date().toISOString(),
    };

    try {
        if (!active.value) {
            active.value = {
                id: 0,
                role_context: props.isPhysician ? 'physician' : 'patient',
                locale: '',
                status: 'active',
                urgency: null,
                chief_complaint: null,
                summary: null,
                shared_at: null,
                subject_user_id:
                    props.isPhysician &&
                    subjectUserId.value !== '__none__' &&
                    subjectUserId.value !== ''
                        ? Number(subjectUserId.value)
                        : null,
                messages: [optimisticMessage],
            };
            viewingSessionId.value = null;
        } else {
            active.value = {
                ...active.value,
                messages: [
                    ...(active.value.messages ?? []),
                    optimisticMessage,
                ],
            };
            if ((active.value.id ?? 0) > 0) {
                putSessionCache(active.value);
            }
        }

        optimisticAttached = true;
        draft.value = '';
        draftByKey.value = {
            ...draftByKey.value,
            [draftStorageKey(viewingSessionId.value)]: '',
        };

        const turnSession = active.value;
        const provisionalKey =
            turnSession?.id && turnSession.id > 0 ? turnSession.id : 0;
        setPhase(provisionalKey, audio ? 'transcribing' : 'thinking');
        await scrollThreadToEnd();

        const ensured = await ensureActiveSession(turnSession);

        if (!ensured?.id) {
            setPhase(provisionalKey, 'idle');
            if (optimisticAttached) {
                if (viewingSessionId.value === null) {
                    startNewTriage();
                } else if (
                    viewingSessionId.value === provisionalKey &&
                    active.value
                ) {
                    active.value = {
                        ...active.value,
                        messages: (active.value.messages ?? []).filter(
                            (m) => m.id !== pendingId,
                        ),
                    };
                    if ((active.value.id ?? 0) > 0) {
                        putSessionCache(active.value);
                    }
                }
                if (text && viewingSessionId.value === provisionalKey) {
                    draft.value = text;
                }
            }

            return;
        }

        requestSessionId = ensured.id;
        if (provisionalKey === 0) {
            setPhase(
                requestSessionId,
                getPhase(0) === 'idle'
                    ? audio
                        ? 'transcribing'
                        : 'thinking'
                    : getPhase(0),
            );
            setPhase(0, 'idle');
        }

        putSessionCache(ensured);

        const form = new FormData();
        if (text) {
            form.append('text', text);
        }
        if (audio) {
            form.append('audio', audio, 'triage.webm');
        }

        const response = await fetch(
            messageSessionRoute.url(requestSessionId),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf.value,
                },
                body: form,
            },
        );

        const data = await response.json().catch(() => ({}));

        if (response.status === 202) {
            try {
                const session = await awaitPendingVoiceTurn(
                    requestSessionId,
                    data.session as TriageSession | undefined,
                );
                setPhase(requestSessionId, 'speaking');
                await applyCompletedTurn(session, requestSessionId);
            } catch (error) {
                const message =
                    error instanceof Error
                        ? error.message
                        : 'Could not process voice message';
                toast.error(message);
                rollbackOptimisticMessage(requestSessionId, pendingId);
            }

            return;
        }

        if (!response.ok) {
            toast.error(data.message || 'Could not send message');
            rollbackOptimisticMessage(
                requestSessionId,
                pendingId,
                text || undefined,
            );

            return;
        }

        if (audio) {
            setPhase(requestSessionId, 'thinking');
        }

        await applyCompletedTurn(data.session as TriageSession, requestSessionId, {
            audioBase64: data.audio_base64,
            assistantId: data.assistant_message?.id as number | undefined,
        });
    } finally {
        if (requestSessionId > 0) {
            setPhase(requestSessionId, 'idle');
        } else {
            setPhase(0, 'idle');
        }
    }
}

function onComposerKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void sendMessage();
    }
}

async function archiveSession() {
    if (!active.value) {
        return;
    }
    const response = await fetch(archiveSessionRoute.url(active.value.id), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf.value,
        },
    });
    const data = await response.json();
    if (response.ok) {
        const archivedId = data.session.id as number;
        ownSessions.value = ownSessions.value.filter((s) => s.id !== archivedId);
        const { [archivedId]: _removed, ...rest } = sessionCache.value;
        sessionCache.value = rest;
        setPhase(archivedId, 'idle');
        startNewTriage();
        toast.success('Triage archived');
    }
}

async function shareSession() {
    if (!active.value) {
        return;
    }
    const response = await fetch(shareSessionRoute.url(active.value.id), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf.value,
        },
    });
    const data = await response.json();
    if (response.ok) {
        putSessionCache(data.session);
        if (viewingSessionId.value === data.session.id) {
            active.value = data.session;
        }
        ownSessions.value = ownSessions.value.map((s) =>
            s.id === data.session.id
                ? { ...data.session, messages: undefined }
                : s,
        );
        toast.success('Shared with doctors');
    }
}

onUnmounted(() => {
    endColdStartWatch();
    window.removeEventListener('keydown', onRecordingKeydown);
    micPointerId = null;
    if (recording.value) {
        mediaRecorder?.stop();
    }
    stopMediaTracks();
    audioEl?.pause();
    revokeAudioMap();
});

if (props.activeSessionId) {
    void openSession(props.activeSessionId);
} else {
    const pendingSession = [
        ...props.sessions,
        ...props.sharedSessions,
    ].find((session) => session.pending_turn?.status === 'processing');

    if (pendingSession) {
        void openSession(pendingSession.id);
    }
}

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
            description="Conversational symptom intake with voice."
        />

        <div class="grid items-start gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
            <Card class="max-h-[min(70vh,44rem)] gap-3 self-start">
                <CardHeader class="space-y-2">
                    <SectionTag>Sessions</SectionTag>
                    <CardTitle class="text-lg">Your triages</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <Button
                        type="button"
                        class="w-full"
                        @click="startNewTriage"
                    >
                        New session
                    </Button>

                    <div v-if="isPhysician" class="space-y-2">
                        <FieldLabel>Patient</FieldLabel>
                        <Select v-model="subjectUserId">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Select patient"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">
                                    No patient linked
                                </SelectItem>
                                <SelectItem
                                    v-for="p in patients"
                                    :key="p.id"
                                    :value="String(p.id)"
                                >
                                    {{ p.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        class="mt-4 max-h-64 space-y-3 overflow-y-auto border-t border-border pt-4"
                    >
                        <ul class="space-y-3">
                            <li v-for="s in ownSessions" :key="s.id">
                                <button
                                    type="button"
                                    class="w-full rounded-xl border border-border px-3.5 py-3 text-left text-sm hover:bg-muted/40"
                                    :class="
                                        active?.id === s.id
                                            ? 'border-primary bg-muted/50'
                                            : ''
                                    "
                                    @click="openSession(s.id)"
                                >
                                    <span class="block truncate font-medium">
                                        {{
                                            s.chief_complaint ||
                                            s.subject_name ||
                                            `Session ${s.id}`
                                        }}
                                    </span>
                                    <span
                                        class="mt-1.5 flex items-center text-xs text-muted-foreground/80"
                                    >
                                        <Spinner
                                            v-if="
                                                (phaseBySessionId[s.id] ??
                                                    'idle') !== 'idle'
                                            "
                                            class="mr-1.5 size-3"
                                        />
                                        {{
                                            formatRelativeTime(
                                                s.last_message_at ||
                                                    s.created_at,
                                            )
                                        }}
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <div
                            v-if="isPhysician && sharedSessions.length"
                            class="space-y-2"
                        >
                            <p
                                class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Shared with doctors
                            </p>
                            <ul class="space-y-3">
                                <li
                                    v-for="s in sharedSessions"
                                    :key="`shared-${s.id}`"
                                >
                                    <button
                                        type="button"
                                        class="w-full rounded-xl border border-border px-3.5 py-3 text-left text-sm hover:bg-muted/40"
                                        :class="
                                            active?.id === s.id
                                                ? 'border-primary bg-muted/50'
                                                : ''
                                        "
                                        @click="openSession(s.id)"
                                    >
                                        <span class="block truncate font-medium">
                                            {{
                                                s.chief_complaint ||
                                                s.owner_name ||
                                                `Session ${s.id}`
                                            }}
                                        </span>
                                        <span
                                            class="mt-1.5 block text-xs text-muted-foreground/80"
                                        >
                                            {{
                                                formatRelativeTime(
                                                    s.last_message_at ||
                                                        s.created_at,
                                                )
                                            }}
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="min-w-0">
            <Card
                class="paper-panel--focal flex h-[min(85vh,56rem)] max-h-[min(85vh,56rem)] flex-col gap-0 overflow-hidden border-0 py-0 shadow-offset"
            >
                <CardHeader
                    class="flex flex-row items-start justify-between gap-3 space-y-0 border-b border-border/70 py-4"
                >
                    <div class="min-w-0 space-y-2">
                        <SectionTag>Conversation</SectionTag>
                        <CardTitle class="truncate text-lg">
                            {{
                                active
                                    ? active.chief_complaint ||
                                      `Session ${active.id}`
                                    : 'New session'
                            }}
                        </CardTitle>
                        <div
                            v-if="active"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <AnnotationPill
                                :variant="
                                    active.status === 'archived'
                                        ? 'coral'
                                        : 'teal'
                                "
                            >
                                {{ active.status }}
                            </AnnotationPill>
                            <AnnotationPill
                                v-if="active.locale"
                                variant="teal"
                            >
                                {{ active.locale }}
                            </AnnotationPill>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-pressed="autoplay"
                            :aria-label="
                                autoplay
                                    ? 'Autoplay voice on'
                                    : 'Autoplay voice off'
                            "
                            @click="toggleAutoplay"
                        >
                            <Volume2 v-if="autoplay" class="size-4" />
                            <VolumeX v-else class="size-4" />
                        </Button>

                        <DropdownMenu v-if="active">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Session actions"
                                >
                                    <Ellipsis class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    :disabled="!active.summary"
                                    @click="summaryOpen = true"
                                >
                                    <ScrollText />
                                    View summary
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    v-if="
                                        !isPhysician &&
                                        active.status === 'active'
                                    "
                                    @click="shareSession"
                                >
                                    <Share2 />
                                    Share with doctor
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="active.status === 'active'"
                                    variant="destructive"
                                    @click="archiveSession"
                                >
                                    <Archive />
                                    Archive
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </CardHeader>

                <div
                    v-if="showUrgencyBanner"
                    class="sticky top-0 z-20 border-b px-4 py-2.5 text-sm"
                    :class="
                        active?.urgency === 'emergency'
                            ? 'border-clinical-critical/30 bg-clinical-critical/10 text-clinical-critical'
                            : 'border-clinical-borderline/40 bg-clinical-borderline/15 text-foreground'
                    "
                >
                    <p class="font-medium">
                        {{
                            active?.urgency === 'emergency'
                                ? 'Possible emergency'
                                : 'Urgent symptoms'
                        }}
                    </p>
                    <p class="mt-0.5 text-xs leading-relaxed opacity-90">
                        {{
                            active?.urgency === 'emergency'
                                ? 'If this reflects real symptoms, get emergency care now. This tool is not emergency dispatch.'
                                : 'Consider same-day clinical care if symptoms worsen or you are unsure.'
                        }}
                    </p>
                </div>

                <CardContent
                    class="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0"
                >
                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4 md:px-6"
                    >
                        <p
                            v-if="
                                swapping &&
                                active &&
                                !(active.messages || []).length
                            "
                            class="text-center text-sm text-muted-foreground"
                        >
                            Loading session…
                        </p>

                        <p
                            v-else-if="threadItems.length === 0 && !phaseLabel"
                            class="text-center text-sm text-muted-foreground"
                        >
                            Describe symptoms by text or voice to begin.
                        </p>

                        <template
                            v-for="item in threadItems"
                            :key="item.message.id"
                        >
                            <p
                                v-if="item.clusterLabel"
                                class="py-3 text-center text-xs text-muted-foreground"
                            >
                                {{ item.clusterLabel }}
                            </p>

                            <div
                                class="w-fit max-w-[min(100%,36rem)] space-y-2"
                                :class="
                                    item.message.role === 'user'
                                        ? 'ml-auto'
                                        : 'max-w-prose'
                                "
                            >
                                <p
                                    class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                                    :class="
                                        item.message.role === 'user'
                                            ? 'text-right'
                                            : ''
                                    "
                                >
                                    {{
                                        item.message.role === 'user'
                                            ? 'You'
                                            : 'SihatAI'
                                    }}
                                    <template
                                        v-if="
                                            item.message.input_modality ===
                                            'voice'
                                        "
                                    >
                                        · voice
                                    </template>
                                </p>
                                <div
                                    class="w-fit max-w-full rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap"
                                    :class="
                                        item.message.role === 'user'
                                            ? 'ml-auto bg-primary text-primary-foreground'
                                            : 'border border-border bg-card text-card-foreground'
                                    "
                                >
                                    {{ item.message.content }}
                                </div>
                                <div
                                    v-if="item.message.role === 'assistant'"
                                    class="flex items-center gap-0.5 pt-0.5"
                                >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-muted-foreground hover:text-foreground"
                                        aria-label="Copy reply"
                                        @click="
                                            copyMessage(item.message.content)
                                        "
                                    >
                                        <Copy class="size-3.5" />
                                    </Button>
                                    <DropdownMenu
                                        :open="
                                            openMessageActionsId ===
                                            item.message.id
                                        "
                                        @update:open="
                                            onMessageActionsOpenChange(
                                                item.message.id,
                                                $event,
                                            )
                                        "
                                    >
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                class="size-7 text-muted-foreground hover:text-foreground data-[state=open]:bg-muted data-[state=open]:text-foreground"
                                                aria-label="More actions"
                                            >
                                                <Ellipsis class="size-3.5" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            side="top"
                                            :side-offset="6"
                                            class="min-w-52 rounded-xl p-1.5 shadow-lg"
                                        >
                                            <DropdownMenuLabel
                                                class="px-2.5 py-1.5 text-xs font-normal text-muted-foreground"
                                            >
                                                {{
                                                    formatMessageTime(
                                                        item.message.created_at,
                                                    )
                                                }}
                                            </DropdownMenuLabel>
                                            <DropdownMenuItem
                                                :disabled="
                                                    loadingSpeechMessageId ===
                                                    item.message.id
                                                "
                                                @select="
                                                    onReplayVoiceSelect(
                                                        $event,
                                                        item.message.id,
                                                    )
                                                "
                                            >
                                                <Spinner
                                                    v-if="
                                                        loadingSpeechMessageId ===
                                                        item.message.id
                                                    "
                                                    class="size-4"
                                                />
                                                <CircleStop
                                                    v-else-if="
                                                        playingMessageId ===
                                                        item.message.id
                                                    "
                                                />
                                                <Volume2 v-else />
                                                {{
                                                    loadingSpeechMessageId ===
                                                    item.message.id
                                                        ? 'Loading voice…'
                                                        : playingMessageId ===
                                                            item.message.id
                                                          ? 'Stop voice'
                                                          : 'Replay voice'
                                                }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </template>

                        <div
                            v-if="phaseLabel"
                            class="w-full max-w-prose space-y-2"
                        >
                            <p
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                SihatAI
                            </p>
                            <div
                                class="flex items-center gap-2 rounded-2xl border border-dashed border-border bg-muted/30 px-3.5 py-2.5 text-sm text-muted-foreground"
                            >
                                <Spinner class="size-4" />
                                <span
                                    class="font-mono text-xs tracking-wide uppercase"
                                >
                                    {{ phaseLabel }}
                                </span>
                            </div>
                        </div>

                        <div ref="threadEnd" class="h-px" />
                    </div>

                    <div
                        class="sticky bottom-0 z-10 border-t border-border/80 bg-card/95 px-4 py-3 backdrop-blur-sm md:px-6"
                    >
                        <div
                            v-if="!active || active.status === 'active'"
                            class="flex items-end gap-2"
                        >
                            <div
                                v-if="recording"
                                class="triage-listen-shell"
                                :class="
                                    cancelArmed
                                        ? 'triage-listen-shell--cancel'
                                        : ''
                                "
                                aria-live="polite"
                            >
                                <span class="triage-listen-rec">Listening</span>
                                <div
                                    class="triage-wave triage-wave--live"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="(level, index) in waveLevels"
                                        :key="index"
                                        :style="{
                                            transform: `scaleY(${level})`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="triage-listen-cue"
                                    :class="
                                        cancelArmed
                                            ? 'triage-listen-cue--armed'
                                            : ''
                                    "
                                >
                                    <ChevronLeft
                                        class="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="triage-listen-cue-label">{{
                                        cancelArmed ? 'Release' : 'Cancel'
                                    }}</span>
                                </span>
                            </div>
                            <Textarea
                                v-else
                                v-model="draft"
                                rows="1"
                                class="max-h-36 min-h-11 flex-1 resize-none py-2.5 leading-6 md:text-sm"
                                placeholder="Describe symptoms, or use the mic"
                                :disabled="isActivePending"
                                @keydown="onComposerKeydown"
                            />
                            <div class="triage-mic-wrap">
                                <span
                                    v-if="recording"
                                    class="triage-mic-ring"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="recording"
                                    class="triage-mic-ring-delay"
                                    aria-hidden="true"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-11 shrink-0 touch-none select-none"
                                    :class="
                                        recording
                                            ? cancelArmed
                                                ? 'border-coral bg-coral/10 text-coral'
                                                : 'border-primary bg-primary/10 text-primary'
                                            : ''
                                    "
                                    :disabled="isActivePending && !recording"
                                    :aria-pressed="recording"
                                    :aria-label="
                                        recording
                                            ? cancelArmed
                                                ? 'Release to cancel'
                                                : 'Release to send, or slide left to cancel'
                                            : 'Hold to talk'
                                    "
                                    @contextmenu.prevent
                                    @pointerdown="onMicPointerDown"
                                    @pointermove="onMicPointerMove"
                                    @pointerup="onMicPointerUp"
                                    @pointercancel="onMicPointerUp"
                                    @lostpointercapture="onMicPointerUp"
                                >
                                    <MicOff v-if="recording" class="size-4" />
                                    <Mic v-else class="size-4" />
                                </Button>
                            </div>
                            <Button
                                type="button"
                                size="icon"
                                class="size-11 shrink-0"
                                :disabled="
                                    recording || !canCompose || !draft.trim()
                                "
                                aria-label="Send message"
                                @click="sendMessage()"
                            >
                                <Spinner
                                    v-if="isActivePending"
                                    class="size-4"
                                />
                                <Send v-else class="size-4" />
                            </Button>
                        </div>
                        <p
                            v-else
                            class="text-sm text-muted-foreground"
                        >
                            This triage is archived. Start a new one to continue
                            chatting.
                        </p>
                    </div>
                </CardContent>
            </Card>
            </div>
        </div>

        <Sheet v-model:open="summaryOpen">
            <SheetContent
                side="right"
                class="gap-0 border-l border-border bg-card p-0 sm:max-w-md"
            >
                <SheetHeader
                    class="space-y-3 border-b border-border/80 p-0 px-6 py-5 text-left"
                >
                    <SectionTag>Physician handoff</SectionTag>
                    <div class="space-y-1.5 pr-8">
                        <SheetTitle class="text-xl font-bold tracking-tight">
                            {{
                                active?.chief_complaint || 'Handoff summary'
                            }}
                        </SheetTitle>
                        <SheetDescription class="text-sm leading-relaxed">
                            Running English summary for clinical handoff.
                        </SheetDescription>
                    </div>
                    <div
                        v-if="active"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <AnnotationPill
                            v-if="active.urgency"
                            :variant="
                                active.urgency === 'emergency'
                                    ? 'coral'
                                    : active.urgency === 'urgent'
                                      ? 'amber'
                                      : 'teal'
                            "
                        >
                            {{ active.urgency }}
                        </AnnotationPill>
                        <AnnotationPill
                            :variant="
                                active.status === 'archived' ? 'coral' : 'teal'
                            "
                        >
                            {{ active.status }}
                        </AnnotationPill>
                        <AnnotationPill
                            v-if="active.locale"
                            variant="teal"
                        >
                            {{ active.locale }}
                        </AnnotationPill>
                    </div>
                </SheetHeader>

                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                    <div
                        class="rounded-2xl border border-border bg-paper p-4 shadow-atlas"
                    >
                        <p
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Summary
                        </p>
                        <p
                            class="mt-2 text-sm leading-relaxed whitespace-pre-wrap text-foreground"
                        >
                            {{ active?.summary || '-' }}
                        </p>
                    </div>

                    <dl
                        v-if="active"
                        class="grid gap-3 rounded-2xl border border-dashed border-border/80 bg-muted/20 px-4 py-3"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <dt
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Updated
                            </dt>
                            <dd
                                class="text-right text-xs text-muted-foreground"
                            >
                                {{
                                    formatRelativeTime(
                                        active.last_message_at ||
                                            active.updated_at,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="active.subject_name"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Subject
                            </dt>
                            <dd class="truncate text-right text-sm">
                                {{ active.subject_name }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Session
                            </dt>
                            <dd
                                class="font-mono text-xs tabular-nums text-muted-foreground"
                            >
                                #{{ active.id }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </SheetContent>
        </Sheet>
    </div>
</template>
