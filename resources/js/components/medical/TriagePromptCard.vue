<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

export type TriagePromptOption = {
    id: string;
    label: string;
};

export type TriagePrompt = {
    id: string;
    question: string;
    allow_multiple: boolean;
    options: TriagePromptOption[];
};

const props = withDefaults(
    defineProps<{
        prompts: TriagePrompt[];
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const emit = defineEmits<{
    submit: [text: string];
}>();

const selected = ref<Record<string, string[]>>({});

watch(
    () => props.prompts.map((prompt) => prompt.id).join(','),
    () => {
        selected.value = Object.fromEntries(
            props.prompts.map((prompt) => [prompt.id, []]),
        );
    },
    { immediate: true },
);

function isChosen(promptId: string, optionId: string): boolean {
    return (selected.value[promptId] ?? []).includes(optionId);
}

function choose(prompt: TriagePrompt, optionId: string): void {
    if (props.disabled) {
        return;
    }

    const current = selected.value[prompt.id] ?? [];

    if (prompt.allow_multiple) {
        selected.value = {
            ...selected.value,
            [prompt.id]: current.includes(optionId)
                ? current.filter((id) => id !== optionId)
                : [...current, optionId],
        };

        return;
    }

    selected.value = {
        ...selected.value,
        [prompt.id]: [optionId],
    };
}

const ready = computed(() =>
    props.prompts.every(
        (prompt) => (selected.value[prompt.id] ?? []).length > 0,
    ),
);

function labelFor(prompt: TriagePrompt, optionId: string): string {
    return (
        prompt.options.find((option) => option.id === optionId)?.label ??
        optionId
    );
}

function submit(): void {
    if (!ready.value || props.disabled) {
        return;
    }

    const lines = props.prompts.flatMap((prompt) => {
        const labels = (selected.value[prompt.id] ?? []).map((id) =>
            labelFor(prompt, id),
        );

        return labels.length
            ? [`${prompt.question}: ${labels.join(', ')}`]
            : [];
    });

    emit('submit', lines.join('\n'));
}
</script>

<template>
    <section
        class="w-full max-w-prose rounded-2xl border border-border bg-paper-blue/60 p-3.5"
        aria-label="Follow-up questions"
    >
        <p
            class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
        >
            A few details
        </p>

        <div v-for="(prompt, index) in prompts" :key="prompt.id" class="mt-3">
            <p class="text-sm font-semibold text-ink">
                <span class="font-mono text-xs text-ink-soft tabular-nums"
                    >{{ index + 1 }}.</span
                >
                {{ prompt.question }}
            </p>
            <div
                class="mt-2 space-y-1.5"
                role="group"
                :aria-label="prompt.question"
            >
                <button
                    v-for="option in prompt.options"
                    :key="option.id"
                    type="button"
                    class="flex w-full items-center gap-2.5 rounded-xl border px-3 py-2 text-left text-sm leading-snug transition-colors"
                    :class="
                        isChosen(prompt.id, option.id)
                            ? 'border-primary bg-primary/10 text-foreground'
                            : 'border-border bg-paper text-ink hover:border-line-strong'
                    "
                    :aria-pressed="isChosen(prompt.id, option.id)"
                    :disabled="disabled"
                    @click="choose(prompt, option.id)"
                >
                    <span
                        class="flex size-4 shrink-0 items-center justify-center border"
                        :class="[
                            prompt.allow_multiple
                                ? 'rounded-sm'
                                : 'rounded-full',
                            isChosen(prompt.id, option.id)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-line-strong bg-paper',
                        ]"
                    >
                        <Check
                            v-if="isChosen(prompt.id, option.id)"
                            class="size-3"
                            aria-hidden="true"
                        />
                    </span>
                    {{ option.label }}
                </button>
            </div>
        </div>

        <Button
            type="button"
            class="mt-3.5 w-full"
            :disabled="disabled || !ready"
            @click="submit"
        >
            Continue
        </Button>
    </section>
</template>
