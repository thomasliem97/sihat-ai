<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, LoaderCircle, Send } from '@lucide/vue';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import type { OverlayBox } from '@/components/medical/ImageOverlay.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { beginColdStartWatch, endColdStartWatch } from '@/lib/coldStartNotice';
import { readSse } from '@/lib/sse';
import { explain as explainRecord } from '@/routes/records';

export type ExplainerMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    finding_index: number | null;
    created_at: string | null;
};

type ExplainerHop = {
    hop: string;
    detail?: string;
};

const props = defineProps<{
    recordId: number;
    messages: ExplainerMessage[];
    findingIndex?: number | null;
    selectedBox?: OverlayBox | null;
    audience: 'physician' | 'patient';
    suggestions?: string[];
}>();

const page = usePage();
const csrf = computed(
    () => (page.props as { csrf_token?: string }).csrf_token ?? '',
);

const thread = ref<ExplainerMessage[]>([...props.messages]);
const draft = ref('');
const sending = ref(false);
const hops = ref<ExplainerHop[]>([]);
const chips = ref<string[]>([...(props.suggestions ?? [])]);
const inFlightAssistantId = ref<number | null>(null);
const threadEl = ref<HTMLElement | null>(null);
const busy = computed(() => sending.value);
const hopLog = computed(() => [...hops.value].reverse());

watch(
    () => props.messages,
    (next) => {
        if (!sending.value) {
            thread.value = [...next];
        }
    },
);

watch(
    () => props.suggestions,
    (next) => {
        if (!sending.value && next?.length) {
            chips.value = [...next];
        }
    },
);

const placeholder = computed(() =>
    props.findingIndex != null
        ? 'Ask about the selected finding'
        : 'Ask a question about this scan',
);

function jsonHeaders(accept: string): HeadersInit {
    return {
        Accept: accept,
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf.value,
    };
}

async function scrollThreadToEnd() {
    await nextTick();
    threadEl.value?.scrollTo({ top: threadEl.value.scrollHeight });
}

function asMessage(value: unknown): ExplainerMessage | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    if (typeof row.id !== 'number' || (row.role !== 'user' && row.role !== 'assistant')) {
        return null;
    }

    return {
        id: row.id,
        role: row.role,
        content: typeof row.content === 'string' ? row.content : '',
        finding_index:
            typeof row.finding_index === 'number' ? row.finding_index : null,
        created_at: typeof row.created_at === 'string' ? row.created_at : null,
    };
}

async function ask(
    question: string,
    options?: {
        restoreDraft?: boolean;
        findingIndex?: number | null;
        selectedBox?: OverlayBox | null;
    },
): Promise<void> {
    const trimmed = question.trim();
    if (!trimmed || sending.value) {
        return;
    }

    const restoreDraft = options?.restoreDraft ?? false;
    const findingIndex =
        options?.findingIndex !== undefined
            ? options.findingIndex
            : (props.findingIndex ?? null);
    const selectedBox =
        options?.selectedBox !== undefined
            ? options.selectedBox
            : (props.selectedBox ?? null);

    sending.value = true;
    hops.value = [];
    beginColdStartWatch();
    if (restoreDraft) {
        draft.value = '';
    }

    const tempUserId = -Date.now();
    const tempAssistantId = tempUserId - 1;
    inFlightAssistantId.value = tempAssistantId;
    thread.value = [
        ...thread.value,
        {
            id: tempUserId,
            role: 'user',
            content: trimmed,
            finding_index: findingIndex,
            created_at: null,
        },
        {
            id: tempAssistantId,
            role: 'assistant',
            content: '',
            finding_index: findingIndex,
            created_at: null,
        },
    ];
    await scrollThreadToEnd();

    const replaceTemp = (tempId: number, incoming: ExplainerMessage) => {
        thread.value = thread.value.map((message) =>
            message.id === tempId ? incoming : message,
        );
    };

    const appendToken = (token: string) => {
        thread.value = thread.value.map((message) =>
            message.id === tempAssistantId
                ? { ...message, content: message.content + token }
                : message,
        );
    };

    let gotAssistant = false;

    try {
        const response = await fetch(explainRecord.url(props.recordId), {
            method: 'POST',
            headers: jsonHeaders('text/event-stream'),
            body: JSON.stringify({
                question: trimmed,
                finding_index: findingIndex,
                selected_box: selectedBox,
            }),
        });

        if (!response.ok) {
            thread.value = thread.value.filter(
                (message) =>
                    message.id !== tempUserId && message.id !== tempAssistantId,
            );
            if (restoreDraft) {
                draft.value = trimmed;
            }
            toast.error('Could not ask the scan');

            return;
        }

        await readSse(response, (data) => {
            if (data.event === 'error' && typeof data.message === 'string') {
                toast.error(data.message);

                return;
            }

            if (data.event === 'user') {
                const incoming = asMessage(data.message);
                if (incoming) {
                    replaceTemp(tempUserId, incoming);
                }

                return;
            }

            if (data.event === 'hop' && typeof data.hop === 'string' && data.hop) {
                hops.value = [
                    ...hops.value,
                    {
                        hop: data.hop,
                        detail:
                            typeof data.detail === 'string' && data.detail
                                ? data.detail
                                : undefined,
                    },
                ];
                void scrollThreadToEnd();

                return;
            }

            if (data.event === 'token' && typeof data.token === 'string') {
                appendToken(data.token);
                void scrollThreadToEnd();

                return;
            }

            if (data.event === 'suggestions' && Array.isArray(data.suggestions)) {
                const next = data.suggestions.filter(
                    (item): item is string =>
                        typeof item === 'string' && item.trim() !== '',
                );
                if (next.length) {
                    chips.value = next;
                }

                return;
            }

            if (data.event === 'assistant') {
                gotAssistant = true;
                if (Array.isArray(data.suggestions)) {
                    const next = data.suggestions.filter(
                        (item): item is string =>
                            typeof item === 'string' && item.trim() !== '',
                    );
                    if (next.length) {
                        chips.value = next;
                    }
                }
                const incoming = asMessage(data.message);
                if (incoming) {
                    replaceTemp(tempAssistantId, incoming);
                    inFlightAssistantId.value = incoming.id;
                }
                void scrollThreadToEnd();
            }
        });

        if (!gotAssistant) {
            thread.value = thread.value.filter(
                (message) =>
                    message.id !== tempUserId && message.id !== tempAssistantId,
            );
            if (restoreDraft) {
                draft.value = trimmed;
            }
            toast.error('Could not ask the scan');
        }
    } catch {
        thread.value = thread.value.filter(
            (message) =>
                message.id !== tempUserId && message.id !== tempAssistantId,
        );
        if (restoreDraft) {
            draft.value = trimmed;
        }
        toast.error('Could not ask the scan');
    } finally {
        endColdStartWatch();
        hops.value = [];
        inFlightAssistantId.value = null;
        sending.value = false;
    }
}

function sendMessage() {
    void ask(draft.value, { restoreDraft: true });
}

function sendSuggestion(question: string) {
    void ask(question, { restoreDraft: false });
}

function onComposerKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

onUnmounted(() => {
    endColdStartWatch();
});

defineExpose({ ask, busy });
</script>

<template>
    <div class="flex min-h-72 flex-col">
        <div
            ref="threadEl"
            class="flex max-h-80 min-h-40 flex-1 flex-col gap-3 overflow-y-auto pr-1"
        >
            <p
                v-if="!thread.length"
                class="text-sm leading-relaxed text-muted-foreground"
            >
                {{
                    audience === 'physician'
                        ? 'Ask a follow-up about this study. Answers stay grounded in the scan and findings on this page.'
                        : 'Ask a question about this scan. Answers stay with what your signed report already covers.'
                }}
            </p>
            <div v-for="message in thread" :key="message.id">
                <div
                    class="w-fit max-w-[min(100%,36rem)] space-y-2"
                    :class="
                        message.role === 'user' ? 'ml-auto' : 'max-w-prose'
                    "
                >
                    <p
                        class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                        :class="message.role === 'user' ? 'text-right' : ''"
                    >
                        {{ message.role === 'user' ? 'You' : 'SihatAI' }}
                    </p>
                    <ol
                        v-if="
                            message.role === 'assistant' &&
                            sending &&
                            message.id === inFlightAssistantId &&
                            hopLog.length
                        "
                        class="space-y-2 rounded-2xl border border-border bg-muted/40 px-3.5 py-2.5"
                        aria-live="polite"
                    >
                        <li
                            v-for="(step, i) in hopLog"
                            :key="`${i}-${step.hop}`"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    i === 0
                                        ? 'border-primary bg-primary/15 text-primary'
                                        : 'border-line-strong bg-paper-blue text-ink-soft'
                                "
                            >
                                <LoaderCircle
                                    v-if="i === 0"
                                    class="size-3.5 animate-spin"
                                    aria-hidden="true"
                                />
                                <Check
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </span>
                            <span>
                                <span
                                    class="block text-sm font-semibold"
                                    :class="
                                        i === 0
                                            ? 'text-primary'
                                            : 'text-ink'
                                    "
                                >
                                    {{ step.hop }}
                                </span>
                                <span
                                    v-if="step.detail"
                                    class="mt-0.5 block text-xs leading-relaxed text-muted-foreground"
                                >
                                    {{ step.detail }}
                                </span>
                            </span>
                        </li>
                    </ol>
                    <div
                        v-if="
                            message.role === 'user' ||
                            message.content ||
                            !(
                                sending &&
                                message.id === inFlightAssistantId &&
                                hopLog.length
                            )
                        "
                        class="w-fit max-w-full rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap"
                        :class="
                            message.role === 'user'
                                ? 'ml-auto bg-primary text-primary-foreground'
                                : 'border border-border bg-card text-card-foreground'
                        "
                    >
                        {{
                            message.role === 'assistant' &&
                            sending &&
                            message.content === ''
                                ? '…'
                                : message.content
                        }}
                    </div>
                </div>
            </div>
        </div>
        <div
            v-if="chips.length"
            class="mt-4 flex flex-wrap gap-2"
        >
            <Button
                v-for="(suggestion, i) in chips"
                :key="`${i}-${suggestion}`"
                type="button"
                variant="outline"
                size="sm"
                class="h-auto max-w-full whitespace-normal px-3 py-1.5 text-left text-xs"
                :disabled="sending"
                @click="sendSuggestion(suggestion)"
            >
                {{ suggestion }}
            </Button>
        </div>
        <div class="mt-4 flex items-end gap-2">
            <Textarea
                v-model="draft"
                :rows="1"
                class="max-h-36 min-h-11 flex-1 resize-none py-2.5 leading-6 md:text-sm"
                :placeholder="placeholder"
                :disabled="sending"
                @keydown="onComposerKeydown"
            />
            <Button
                type="button"
                size="icon"
                class="size-11 shrink-0"
                :disabled="sending || !draft.trim()"
                aria-label="Send question"
                @click="sendMessage()"
            >
                <Spinner v-if="sending" class="size-4" />
                <Send v-else class="size-4" />
            </Button>
        </div>
    </div>
</template>
