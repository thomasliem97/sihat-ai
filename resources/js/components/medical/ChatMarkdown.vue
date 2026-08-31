<script setup lang="ts">
import { computed } from 'vue';
import { chatMarkdownHtml } from '@/lib/chat-markdown';

const props = defineProps<{
    source: string;
}>();

const html = computed(() => chatMarkdownHtml(props.source));
</script>

<template>
    <div class="triage-md" v-html="html" />
</template>
