import { toast } from 'vue-sonner';

const DELAY_MS = 15_000;

let timer: ReturnType<typeof setTimeout> | null = null;
let toastId: string | number | undefined;
let watching = false;

/**
 * Shared dismissable notice when GPU work (triage / analysis) exceeds 15s.
 * Intended for cold starts; safe to show on any long wait.
 */
export function beginColdStartWatch(): void {
    if (watching) {
        return;
    }

    watching = true;
    timer = setTimeout(() => {
        if (!watching) {
            return;
        }

        toastId = toast.message('Warming up the GPU server', {
            description:
                'The first request after idle can take a few minutes while models load. Later requests on a warm server are much faster.',
            duration: Number.POSITIVE_INFINITY,
            action: {
                label: 'Dismiss',
                onClick: () => {
                    if (toastId !== undefined) {
                        toast.dismiss(toastId);
                        toastId = undefined;
                    }
                },
            },
        });
    }, DELAY_MS);
}

export function endColdStartWatch(): void {
    watching = false;

    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    if (toastId !== undefined) {
        toast.dismiss(toastId);
        toastId = undefined;
    }
}
