<script setup lang="ts">
const year = new Date().getFullYear();
</script>

<template>
    <footer class="shrink-0 border-t border-border/70 bg-paper/80">
        <div
            class="mx-auto grid w-full max-w-7xl grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 md:px-6"
        >
            <p
                class="font-mono text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                SihatAI
            </p>
            <p
                class="truncate text-center text-xs leading-none text-muted-foreground"
            >
                AI can make mistakes. Always confirm with clinical judgment.
            </p>
            <p
                class="font-mono text-xs tracking-wide text-muted-foreground/70 tabular-nums"
            >
                © {{ year }}
            </p>
        </div>
    </footer>
</template>
