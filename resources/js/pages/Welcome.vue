<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    BookOpenText,
    BrainCircuit,
    EyeOff,
    FileText,
    HeartHandshake,
    Layers,
    LibraryBig,
    Menu,
    Mic,
    Route,
    ScanLine,
    ScrollText,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    Users,
    X,
} from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';

const mobileOpen = ref(false);
const activeSection = ref('hero');
const scrolled = ref(false);

const navItems = [
    { id: 'problem', label: 'Problem' },
    { id: 'product', label: 'Product' },
    { id: 'experience', label: 'Experience' },
    { id: 'pipeline', label: 'Pipeline' },
    { id: 'capabilities', label: 'Capabilities' },
    { id: 'safety', label: 'Safety' },
] as const;

const fractures = [
    {
        index: '01 / See',
        title: 'Imaging stays invisible',
        body: 'Text-first assistants cannot inspect a film or point to the anatomy behind a finding.',
        icon: ScanLine,
    },
    {
        index: '02 / Structure',
        title: 'Labs stay unstructured',
        body: 'Hb, WBC, and renal panels get summarized as text, not stored as data you can trend.',
        icon: FileText,
    },
    {
        index: '03 / Ground',
        title: 'Context goes missing',
        body: 'Generic answers ignore patient history, Malaysia MOH guidance, and local priors.',
        icon: BookOpenText,
    },
    {
        index: '04 / Explain',
        title: 'One voice fits nobody',
        body: 'Clinical language overwhelms patients; simplified output underserves doctors.',
        icon: HeartHandshake,
    },
] as const;

const agents = [
    { name: 'De-ID', detail: 'Scrub PII / PHI', code: 'safe_uri', icon: EyeOff },
    { name: 'Router', detail: 'Detect modality', code: 'route_confidence', icon: Route },
    {
        name: 'Specialists',
        detail: 'Imaging / document',
        code: 'partial_findings',
        icon: BrainCircuit,
    },
    { name: 'Retriever', detail: 'Hybrid RAG', code: 'citations[]', icon: LibraryBig },
    {
        name: 'Guardrail',
        detail: 'Veto + escalate',
        code: 'ALLOW | WARN',
        icon: ShieldAlert,
    },
    { name: 'Composer', detail: 'Audience adapt', code: 'reports{}', icon: ScrollText },
] as const;

const advantages = [
    {
        title: 'Understands more',
        body: 'X-rays, CT, labs, PDFs and voice route through one pipeline. Modality detection sends each artifact to the right specialist.',
        tags: ['X-ray · CT · MRI', 'Lab PDFs', 'Voice intake'],
        icon: Layers,
    },
    {
        title: 'Grounded in Malaysia',
        body: 'MOH Clinical Practice Guidelines, traceable citations, and a localized adapter keep every claim grounded.',
        tags: ['MOH CPG', 'Citations', 'BM · EN'],
        icon: BookOpenText,
    },
    {
        title: 'Fails safely',
        body: 'De-ID runs before inference. Confidence bands decide when to publish, hedge, or abstain. Guardrails can veto unsafe output.',
        tags: ['De-ID first', 'Abstention', 'Veto + escalate'],
        icon: ShieldCheck,
    },
    {
        title: 'One truth, two audiences',
        body: 'MedGemma runs once to produce a single findings object. That same truth becomes physician and patient reports.',
        tags: ['Physician report', 'Patient report', 'Shared findings'],
        icon: Users,
    },
] as const;

const safetyPrinciples = [
    {
        n: '01',
        title: 'De-identify first',
        body: 'DICOM tags, document PII, and metadata are scrubbed before inference.',
    },
    {
        n: '02',
        title: 'Calibrate uncertainty',
        body: 'Publish ≥ .80 · hedge .50–.80 · abstain below .50.',
    },
    {
        n: '03',
        title: 'Constrain claims',
        body: 'Structured DDx with confidence, never unsupported certainty.',
    },
    {
        n: '04',
        title: 'Preserve oversight',
        body: 'Physicians inspect evidence, boxes, and citations before sign-off.',
    },
] as const;

let observer: IntersectionObserver | null = null;
let sectionObserver: IntersectionObserver | null = null;

function scrollToId(id: string) {
    mobileOpen.value = false;
    const el = document.getElementById(id);
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function scrollToTop() {
    mobileOpen.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function onScroll() {
    scrolled.value = window.scrollY > 12;
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                }
            }
        },
        { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
    );

    document
        .querySelectorAll(
            '.welcome-reveal, .welcome-reveal-left, .welcome-reveal-scale',
        )
        .forEach((el) => observer?.observe(el));

    requestAnimationFrame(() => {
        document
            .querySelectorAll(
                '#hero .welcome-reveal, #hero .welcome-reveal-left, #hero .welcome-reveal-scale',
            )
            .forEach((el) => el.classList.add('is-visible'));
    });

    sectionObserver = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((e) => e.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio);

            if (visible[0]?.target.id) {
                activeSection.value = visible[0].target.id;
            }
        },
        { threshold: [0.25, 0.45], rootMargin: '-20% 0px -45% 0px' },
    );

    navItems.forEach(({ id }) => {
        const el = document.getElementById(id);

        if (el) {
            sectionObserver?.observe(el);
        }
    });

    const hero = document.getElementById('hero');

    if (hero) {
        sectionObserver?.observe(hero);
    }
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    observer?.disconnect();
    sectionObserver?.disconnect();
});
</script>

<template>
    <Head title="SihatAI: Multimodal clinical intelligence" />

    <div class="welcome-page atlas-field min-h-screen">
        <!-- Nav -->
        <header
            class="sticky top-0 z-50 border-b transition-[background,box-shadow,border-color] duration-300"
            :class="
                scrolled
                    ? 'border-border/80 bg-paper/85 shadow-sm backdrop-blur-md'
                    : 'border-transparent bg-transparent'
            "
        >
            <div
                class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 md:px-6"
            >
                <a
                    href="#"
                    class="flex items-center gap-2.5"
                    @click.prevent="scrollToTop"
                >
                    <AppLogoIcon class="size-9 rounded-xl" />
                    <div class="leading-tight">
                        <span class="block text-lg font-bold tracking-tight"
                            >Sihat<span class="text-primary">AI</span></span
                        >
                        <span
                            class="hidden font-mono text-[0.65rem] font-semibold tracking-wider text-ink-faint uppercase sm:block"
                            >Clinical field atlas</span
                        >
                    </div>
                </a>

                <nav
                    class="hidden items-center gap-5 lg:flex"
                    aria-label="Page sections"
                >
                    <a
                        v-for="item in navItems"
                        :key="item.id"
                        :href="`#${item.id}`"
                        class="welcome-nav-link"
                        :class="{ 'is-active': activeSection === item.id }"
                        @click.prevent="scrollToId(item.id)"
                    >
                        {{ item.label }}
                    </a>
                </nav>

                <div class="flex items-center gap-2">
                    <template v-if="$page.props.auth.user">
                        <Button as-child size="sm">
                            <Link :href="dashboard()">Dashboard</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button as-child size="sm">
                            <Link :href="login()">View Demo</Link>
                        </Button>
                    </template>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="lg:hidden"
                        :aria-expanded="mobileOpen"
                        aria-controls="welcome-mobile-nav"
                        aria-label="Toggle menu"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <X v-if="mobileOpen" class="size-5" />
                        <Menu v-else class="size-5" />
                    </Button>
                </div>
            </div>

            <div
                v-show="mobileOpen"
                id="welcome-mobile-nav"
                class="border-t border-border/70 bg-paper/95 px-4 py-4 backdrop-blur-md lg:hidden"
            >
                <nav class="flex flex-col gap-1" aria-label="Mobile sections">
                    <a
                        v-for="item in navItems"
                        :key="item.id"
                        :href="`#${item.id}`"
                        class="rounded-xl px-3 py-2.5 font-mono text-xs font-semibold tracking-wider text-ink-soft uppercase hover:bg-muted"
                        @click.prevent="scrollToId(item.id)"
                    >
                        {{ item.label }}
                    </a>
                    <div
                        v-if="!$page.props.auth.user"
                        class="mt-2 flex gap-2 border-t border-border/70 pt-3"
                    >
                        <Button as-child class="flex-1">
                            <Link :href="login()">View Demo</Link>
                        </Button>
                    </div>
                </nav>
            </div>
        </header>

        <main>
            <!-- Hero -->
            <section
                id="hero"
                class="relative overflow-hidden border-b border-border/60"
            >
                <div
                    class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 md:px-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:gap-12 lg:py-20"
                >
                    <div class="relative z-10 space-y-7">
                        <div
                            class="welcome-reveal-left flex items-center gap-3"
                            style="--reveal-delay: 0ms"
                        >
                            <span
                                class="size-3 rounded-full bg-coral shadow-[0_0_0_8px_color-mix(in_srgb,var(--coral)_13%,transparent)]"
                                aria-hidden="true"
                            />
                            <span
                                class="font-mono text-xs font-semibold tracking-wider text-primary uppercase"
                            >
                                Multimodal medical intelligence
                            </span>
                        </div>

                        <div
                            class="welcome-reveal-left space-y-3"
                            style="--reveal-delay: 80ms"
                        >
                            <p
                                class="font-mono text-xs font-semibold tracking-[0.18em] text-ink-faint uppercase"
                            >
                                MAIC Nexus · T2 Healthcare
                            </p>
                            <h1
                                class="text-5xl font-bold tracking-tight text-foreground sm:text-6xl md:text-7xl"
                            >
                                Sihat<span class="text-primary">AI</span>
                            </h1>
                        </div>

                        <h2
                            class="welcome-reveal-left max-w-xl text-2xl font-semibold tracking-tight text-ink-soft sm:text-3xl md:text-4xl"
                            style="--reveal-delay: 160ms"
                        >
                            From any medical artifact to actual
                            <span class="text-primary">clinical report.</span>
                        </h2>

                        <p
                            class="welcome-reveal max-w-lg text-base leading-relaxed text-muted-foreground md:text-lg"
                            style="--reveal-delay: 240ms"
                        >
                            One agentic engine understands imaging, labs,
                            documents and voice, then composes grounded outputs
                            for physicians and patients.
                        </p>

                        <div
                            class="welcome-reveal flex flex-wrap gap-3"
                            style="--reveal-delay: 320ms"
                        >
                            <Button
                                v-if="!$page.props.auth.user"
                                as-child
                                size="lg"
                            >
                                <Link :href="login()">View Demo</Link>
                            </Button>
                            <Button
                                v-else
                                as-child
                                size="lg"
                            >
                                <Link :href="dashboard()">Open dashboard</Link>
                            </Button>
                            <Button
                                as-child
                                variant="outline"
                                size="lg"
                            >
                                <a
                                    href="#product"
                                    @click.prevent="scrollToId('product')"
                                >
                                    Learn more
                                </a>
                            </Button>
                        </div>

                        <div
                            class="welcome-reveal flex flex-wrap gap-2 pt-1"
                            style="--reveal-delay: 400ms"
                        >
                            <AnnotationPill>Multimodal reasoning</AnnotationPill>
                            <AnnotationPill variant="teal"
                                >Agentic orchestration</AnnotationPill
                            >
                            <AnnotationPill variant="coral"
                                >Malaysia-localized</AnnotationPill
                            >
                        </div>
                    </div>

                    <div
                        class="welcome-reveal-scale relative space-y-4"
                        style="--reveal-delay: 180ms"
                    >
                        <div
                            class="welcome-hero-stage relative aspect-4/5 overflow-hidden rounded-[1.75rem] sm:aspect-5/6 lg:aspect-auto lg:h-112"
                        >
                            <div
                                class="absolute inset-x-0 top-0 z-20 flex items-center justify-between border-b border-white/10 bg-black/35 px-4 py-2.5 font-mono text-[0.65rem] tracking-wider text-white/75 uppercase backdrop-blur-sm"
                            >
                                <span>PA chest · 2048 × 2048</span>
                                <span>Overlay on · 91%</span>
                            </div>

                            <img
                                src="/images/chest-xray.png"
                                alt="PA chest radiograph with AI localization overlay"
                                class="absolute inset-0 size-full object-cover object-center"
                            />

                            <div
                                class="welcome-finding-box left-[11%] bottom-[12%] h-[36%] w-[27%]"
                            >
                                <span
                                    class="absolute -top-7 left-0 whitespace-nowrap bg-clinical-borderline px-2.5 py-1 font-mono text-[0.65rem] font-bold tracking-wide text-ink uppercase"
                                >
                                    RLL opacity · 91%
                                </span>
                            </div>

                            <div
                                class="absolute inset-x-0 bottom-0 z-20 space-y-2 border-t border-white/10 bg-linear-to-t from-black/75 via-black/45 to-transparent px-4 pb-4 pt-10"
                            >
                                <div class="flex items-end justify-between gap-3">
                                    <div>
                                        <p
                                            class="font-mono text-[0.65rem] tracking-wider text-white/60 uppercase"
                                        >
                                            Overall confidence
                                        </p>
                                        <p
                                            class="text-2xl font-bold tabular-nums text-white"
                                        >
                                            88%
                                        </p>
                                    </div>
                                    <AnnotationPill variant="coral"
                                        >Abnormal</AnnotationPill
                                    >
                                </div>
                                <p class="text-sm text-white/85">
                                    Right lower lobe opacity: patchy airspace
                                    change localized to the right lower zone.
                                </p>
                                <div
                                    class="citation-stamp border-dashed border-primary/60 bg-paper/10 text-white/90"
                                >
                                    <span>[01]</span>
                                    <span>MOH CPG · Community Acquired Pneumonia</span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="hidden gap-3 sm:grid sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <div
                                class="welcome-float flex items-center gap-2.5 rounded-2xl border border-border bg-paper/95 p-3 shadow-atlas"
                            >
                                <IconDisc size="sm">
                                    <ScanLine class="size-4" />
                                </IconDisc>
                                <div class="leading-tight">
                                    <p class="text-sm font-semibold">
                                        Chest X-ray
                                    </p>
                                    <p
                                        class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                    >
                                        Vision + localization
                                    </p>
                                </div>
                            </div>
                            <div
                                class="welcome-float-delay flex items-center gap-2.5 rounded-2xl border border-border bg-paper/95 p-3 shadow-atlas"
                            >
                                <IconDisc size="sm">
                                    <FileText class="size-4" />
                                </IconDisc>
                                <div class="leading-tight">
                                    <p class="text-sm font-semibold">
                                        Lab panel
                                    </p>
                                    <p
                                        class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                    >
                                        Biomarker extraction
                                    </p>
                                </div>
                            </div>
                            <div
                                class="welcome-float hidden items-center gap-2.5 rounded-2xl border border-border bg-paper/95 p-3 shadow-atlas lg:flex"
                            >
                                <IconDisc size="sm">
                                    <Mic class="size-4" />
                                </IconDisc>
                                <div class="leading-tight">
                                    <p class="text-sm font-semibold">
                                        Voice intake
                                    </p>
                                    <p
                                        class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                    >
                                        Triage structuring
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Problem -->
            <section id="problem" class="scroll-mt-24 border-b border-border/60">
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div
                        class="mb-12 flex flex-col gap-6 md:flex-row md:items-end md:justify-between"
                    >
                        <div class="max-w-3xl space-y-4">
                            <SectionTag class="welcome-reveal"
                                >01 · The gap</SectionTag
                            >
                            <h2
                                class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                            >
                                Medical data is multimodal.
                                <span class="text-ink-soft"
                                    >Every handoff breaks the chain.</span
                                >
                            </h2>
                            <p
                                class="welcome-reveal max-w-2xl text-base leading-relaxed text-muted-foreground md:text-lg"
                            >
                                Four gaps stand between what clinicians upload
                                and what patients and doctors can safely act on.
                            </p>
                        </div>
                        <AnnotationPill
                            variant="coral"
                            class="welcome-reveal-scale w-fit"
                            >4 disconnected moments</AnnotationPill
                        >
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article
                            v-for="(item, i) in fractures"
                            :key="item.index"
                            class="welcome-reveal-scale paper-panel space-y-4 p-5 md:p-6"
                            :style="{ '--reveal-delay': `${i * 90}ms` }"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span
                                    class="font-mono text-[0.65rem] font-semibold tracking-wider text-ink-faint uppercase"
                                    >{{ item.index }}</span
                                >
                                <IconDisc size="sm">
                                    <component :is="item.icon" class="size-4" />
                                </IconDisc>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight">
                                {{ item.title }}
                            </h3>
                            <p
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ item.body }}
                            </p>
                        </article>
                    </div>

                    <div
                        class="welcome-reveal mt-8 flex flex-col gap-3 rounded-3xl border border-border bg-paper-blue/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="space-y-1">
                            <p
                                class="font-mono text-[0.65rem] font-semibold tracking-wider text-primary uppercase"
                            >
                                The opportunity
                            </p>
                            <p class="text-sm font-medium md:text-base">
                                Replace four handoffs with one safe, grounded
                                source of truth.
                            </p>
                        </div>
                        <AnnotationPill>Built for Malaysia</AnnotationPill>
                    </div>
                </div>
            </section>

            <!-- Product: dual voices -->
            <section id="product" class="scroll-mt-24 border-b border-border/60 bg-paper/40">
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div class="mb-12 max-w-3xl space-y-4">
                        <SectionTag class="welcome-reveal"
                            >02 · The product</SectionTag
                        >
                        <h2
                            class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                        >
                            One clinical truth.
                            <span class="text-primary">Two useful voices.</span>
                        </h2>
                        <p
                            class="welcome-reveal text-base leading-relaxed text-muted-foreground md:text-lg"
                        >
                            SihatAI routes any artifact into one canonical
                            findings object, then adapts the explanation, not
                            the evidence.
                        </p>
                    </div>

                    <div
                        class="grid items-stretch gap-4 lg:grid-cols-[1fr_auto_1fr] lg:gap-6"
                    >
                        <article
                            class="welcome-reveal-scale paper-panel space-y-5 p-6"
                        >
                            <div class="flex items-center gap-3">
                                <IconDisc>
                                    <Stethoscope class="size-6" />
                                </IconDisc>
                                <div>
                                    <p
                                        class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >
                                        Dense + inspectable
                                    </p>
                                    <h3 class="text-lg font-semibold">
                                        Physician view
                                    </h3>
                                </div>
                            </div>
                            <ul class="space-y-3">
                                <li
                                    class="flex items-center justify-between gap-3 rounded-2xl border border-border/80 bg-muted/40 px-4 py-3"
                                >
                                    <div>
                                        <p class="text-sm font-semibold">
                                            RLL opacity
                                        </p>
                                        <p
                                            class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                        >
                                            Localized · MOH citation [01]
                                        </p>
                                    </div>
                                    <span
                                        class="size-2.5 rounded-full bg-clinical-abnormal"
                                        aria-hidden="true"
                                    />
                                </li>
                                <li
                                    class="flex items-center justify-between gap-3 rounded-2xl border border-border/80 bg-muted/40 px-4 py-3"
                                >
                                    <div>
                                        <p class="text-sm font-semibold">
                                            Mild cardiomegaly
                                        </p>
                                        <p
                                            class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                        >
                                            Review recommended
                                        </p>
                                    </div>
                                    <span
                                        class="size-2.5 rounded-full bg-clinical-borderline"
                                        aria-hidden="true"
                                    />
                                </li>
                                <li
                                    class="flex items-center justify-between gap-3 rounded-2xl border border-border/80 bg-muted/40 px-4 py-3"
                                >
                                    <div>
                                        <p class="text-sm font-semibold">
                                            Confidence + DDx
                                        </p>
                                        <p
                                            class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                        >
                                            Raw scores remain visible
                                        </p>
                                    </div>
                                    <AnnotationPill>88%</AnnotationPill>
                                </li>
                            </ul>
                        </article>

                        <div
                            class="welcome-reveal welcome-core-pulse flex flex-col items-center justify-center gap-2 py-4"
                        >
                            <div
                                class="flex size-24 flex-col items-center justify-center rounded-full border-2 border-dashed border-primary/40 bg-paper text-center shadow-offset"
                            >
                                <span class="text-xl font-bold text-primary"
                                    >{ }</span
                                >
                                <span
                                    class="mt-1 font-mono text-[0.6rem] font-semibold tracking-wider text-ink-faint uppercase"
                                    >Findings</span
                                >
                            </div>
                            <code
                                class="mt-3 font-mono text-[0.65rem] text-primary"
                                >schema_valid: true</code
                            >
                        </div>

                        <article
                            class="welcome-reveal-scale paper-panel paper-panel--focal space-y-5 p-6"
                            style="--reveal-delay: 120ms"
                        >
                            <div class="flex items-center gap-3">
                                <IconDisc>
                                    <HeartHandshake class="size-6" />
                                </IconDisc>
                                <div>
                                    <p
                                        class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >
                                        Plain + actionable
                                    </p>
                                    <h3 class="text-lg font-semibold">
                                        Patient view
                                    </h3>
                                </div>
                            </div>
                            <p
                                class="text-sm leading-relaxed text-muted-foreground md:text-base"
                            >
                                “There is a change in the
                                <strong class="text-foreground"
                                    >lower part of your right lung</strong
                                >. Your doctor should review it together with
                                your symptoms.”
                            </p>
                            <div
                                class="rounded-2xl border border-dashed border-primary/35 bg-primary/5 px-4 py-3 text-sm"
                            >
                                <span class="font-semibold">Next step</span>
                                · Bring this report and your symptom history to
                                your clinician.
                            </div>
                        </article>
                    </div>

                    <div
                        class="welcome-reveal mt-10 flex flex-wrap items-center justify-center gap-2 font-mono text-xs font-semibold tracking-wider text-ink-soft uppercase"
                    >
                        <span
                            v-for="(step, i) in [
                                'Ingest',
                                'Route',
                                'Analyze',
                                'Ground',
                                'Guard',
                                'Compose',
                            ]"
                            :key="step"
                            class="contents"
                        >
                            <span
                                class="rounded-full border border-border bg-paper px-3 py-1.5"
                                >{{ step }}</span
                            >
                            <span
                                v-if="i < 5"
                                class="text-ink-faint"
                                aria-hidden="true"
                                >→</span
                            >
                        </span>
                    </div>
                </div>
            </section>

            <!-- Experience cutaway -->
            <section
                id="experience"
                class="scroll-mt-24 border-b border-border/60"
            >
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div
                        class="mb-10 flex flex-col gap-4 md:flex-row md:items-end md:justify-between"
                    >
                        <div class="max-w-2xl space-y-4">
                            <SectionTag class="welcome-reveal"
                                >03 · Product experience</SectionTag
                            >
                            <h2
                                class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                            >
                                The evidence stays
                                <span class="text-primary">in the room.</span>
                            </h2>
                        </div>
                        <AnnotationPill class="welcome-reveal w-fit"
                            >Conceptual product view</AnnotationPill
                        >
                    </div>

                    <div
                        class="welcome-reveal-scale overflow-hidden rounded-[1.75rem] border border-border bg-paper shadow-atlas"
                    >
                        <div class="flex min-h-112 flex-col md:flex-row">
                            <aside
                                class="flex items-center gap-3 border-b border-border bg-stage px-4 py-3 md:w-16 md:flex-col md:border-r md:border-b-0 md:py-5"
                            >
                                <AppLogoIcon class="size-9" />
                                <div
                                    class="flex flex-1 gap-2 md:flex-col md:items-center"
                                >
                                    <span
                                        class="flex size-9 items-center justify-center rounded-lg bg-paper text-primary"
                                        ><ScanLine class="size-4"
                                    /></span>
                                    <span
                                        class="flex size-9 items-center justify-center rounded-lg text-ink-faint"
                                        ><FileText class="size-4"
                                    /></span>
                                    <span
                                        class="flex size-9 items-center justify-center rounded-lg text-ink-faint"
                                        ><Activity class="size-4"
                                    /></span>
                                    <span
                                        class="flex size-9 items-center justify-center rounded-lg text-ink-faint"
                                        ><Mic class="size-4"
                                    /></span>
                                </div>
                            </aside>

                            <div class="flex min-w-0 flex-1 flex-col">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5"
                                >
                                    <div>
                                        <p
                                            class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                        >
                                            Medical record / MR-2048
                                        </p>
                                        <p class="font-semibold">
                                            Chest X-ray analysis
                                        </p>
                                    </div>
                                    <div
                                        class="flex rounded-full border border-border bg-muted/50 p-1 font-mono text-[0.65rem] font-semibold uppercase"
                                    >
                                        <span
                                            class="rounded-full bg-primary px-3 py-1 text-primary-foreground"
                                            >Physician</span
                                        >
                                        <span
                                            class="px-3 py-1 text-ink-soft"
                                            >Patient</span
                                        >
                                    </div>
                                </div>

                                <div
                                    class="grid flex-1 lg:grid-cols-[1.15fr_0.85fr]"
                                >
                                    <div
                                        class="viewer-surface relative min-h-64 overflow-hidden"
                                    >
                                        <div
                                            class="absolute inset-x-0 top-0 z-10 flex justify-between px-3 py-2 font-mono text-[0.6rem] tracking-wider text-white/65 uppercase"
                                        >
                                            <span>PA chest · overlay on</span>
                                            <span>100%</span>
                                        </div>
                                        <img
                                            src="/images/chest-xray.png"
                                            alt="Chest X-ray viewer with finding box"
                                            class="size-full object-cover"
                                        />
                                        <div
                                            class="welcome-finding-box left-[12%] bottom-[14%] h-[34%] w-[26%]"
                                        >
                                            <span
                                                class="absolute -top-7 left-0 whitespace-nowrap bg-clinical-borderline px-2 py-1 font-mono text-[0.6rem] font-bold text-ink uppercase"
                                            >
                                                RLL opacity · 91%
                                            </span>
                                        </div>
                                    </div>

                                    <div
                                        class="space-y-4 border-t border-border p-4 md:p-5 lg:border-t-0 lg:border-l"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <div>
                                                <p
                                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                                >
                                                    Overall confidence
                                                </p>
                                                <p
                                                    class="text-2xl font-bold tabular-nums"
                                                >
                                                    88%
                                                </p>
                                            </div>
                                            <AnnotationPill
                                                >Analysis complete</AnnotationPill
                                            >
                                        </div>
                                        <div
                                            class="space-y-2 rounded-2xl border border-border p-4"
                                        >
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <p class="text-sm font-semibold">
                                                    Right lower lobe opacity
                                                </p>
                                                <AnnotationPill variant="coral"
                                                    >Abnormal</AnnotationPill
                                                >
                                            </div>
                                            <p
                                                class="text-xs leading-relaxed text-muted-foreground"
                                            >
                                                Patchy airspace opacity localized
                                                to the right lower zone.
                                            </p>
                                            <div
                                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                            >
                                                <div
                                                    class="h-full w-[91%] rounded-full bg-primary"
                                                />
                                            </div>
                                        </div>
                                        <div
                                            class="space-y-2 rounded-2xl border border-border p-4"
                                        >
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <p class="text-sm font-semibold">
                                                    Mild cardiomegaly
                                                </p>
                                                <AnnotationPill variant="amber"
                                                    >Review</AnnotationPill
                                                >
                                            </div>
                                            <p
                                                class="text-xs leading-relaxed text-muted-foreground"
                                            >
                                                Cardiothoracic ratio is mildly
                                                increased.
                                            </p>
                                            <div
                                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                            >
                                                <div
                                                    class="h-full w-[84%] rounded-full bg-primary"
                                                />
                                            </div>
                                        </div>
                                        <div class="citation-stamp w-full">
                                            <span>MOH CPG</span>
                                            <span class="flex-1"
                                                >Community Acquired
                                                Pneumonia</span
                                            >
                                            <b>[01]</b>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="welcome-reveal mt-6 flex flex-wrap gap-2"
                    >
                        <AnnotationPill
                            v-for="label in [
                                'Bounding-box localization',
                                'Confidence calibration',
                                'Inline citations',
                                'Longitudinal comparison',
                            ]"
                            :key="label"
                        >
                            {{ label }}
                        </AnnotationPill>
                    </div>
                </div>
            </section>

            <!-- Pipeline -->
            <section id="pipeline" class="scroll-mt-24 border-b border-border/60 bg-paper/40">
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div class="mb-12 max-w-3xl space-y-4">
                        <SectionTag class="welcome-reveal"
                            >04 · How it works</SectionTag
                        >
                        <h2
                            class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                        >
                            Laravel orchestrates.
                            <span class="text-primary">Python reasons.</span>
                        </h2>
                        <p
                            class="welcome-reveal text-base leading-relaxed text-muted-foreground md:text-lg"
                        >
                            Six specialists. One inspectable relay. A clean async
                            contract lets product engineering and model inference
                            scale independently.
                        </p>
                    </div>

                    <div
                        class="welcome-reveal mb-10 grid gap-3 md:grid-cols-3"
                    >
                        <div class="paper-panel space-y-2 p-5">
                            <p
                                class="font-mono text-[0.65rem] font-semibold tracking-wider text-primary uppercase"
                            >
                                Application line
                            </p>
                            <p class="font-semibold">Inertia + Vue → Laravel 13 → Queue</p>
                            <p class="text-sm text-muted-foreground">
                                Dual-persona UI, auth, policy, retries, and signed
                                webhooks.
                            </p>
                        </div>
                        <div
                            class="paper-panel flex flex-col items-center justify-center gap-2 p-5 text-center"
                        >
                            <code
                                class="rounded-lg bg-muted px-3 py-1.5 font-mono text-xs text-primary"
                                >POST /analyze</code
                            >
                            <div
                                class="welcome-pipeline-rail h-px w-16"
                                aria-hidden="true"
                            />
                            <code
                                class="rounded-lg bg-muted px-3 py-1.5 font-mono text-xs text-primary"
                                >signed webhook</code
                            >
                        </div>
                        <div class="paper-panel space-y-2 p-5">
                            <p
                                class="font-mono text-[0.65rem] font-semibold tracking-wider text-secondary-foreground uppercase"
                            >
                                AI intelligence line
                            </p>
                            <p class="font-semibold">
                                FastAPI → Agent graph → MedGemma 1.5
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Pydantic contracts, typed state machine, 4B
                                multimodal reasoning.
                            </p>
                        </div>
                    </div>

                    <div class="relative">
                        <div
                            class="welcome-pipeline-rail absolute top-10 right-8 left-8 hidden h-px lg:block"
                            aria-hidden="true"
                        />
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                            <article
                                v-for="(agent, i) in agents"
                                :key="agent.name"
                                class="welcome-reveal-scale paper-panel relative space-y-3 p-4 text-center"
                                :style="{ '--reveal-delay': `${i * 70}ms` }"
                            >
                                <IconDisc class="mx-auto">
                                    <component :is="agent.icon" class="size-5" />
                                </IconDisc>
                                <h3 class="text-sm font-semibold">
                                    {{ agent.name }}
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    {{ agent.detail }}
                                </p>
                                <code
                                    class="block font-mono text-[0.6rem] text-primary"
                                    >{{ agent.code }}</code
                                >
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Capabilities -->
            <section
                id="capabilities"
                class="scroll-mt-24 border-b border-border/60"
            >
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div class="mb-12 max-w-3xl space-y-4">
                        <SectionTag class="welcome-reveal"
                            >05 · Multimodal intelligence</SectionTag
                        >
                        <h2
                            class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                        >
                            Not a chatbot.
                            <span class="text-primary"
                                >A clinical perception stack.</span
                            >
                        </h2>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                        <article
                            class="welcome-reveal-scale paper-panel flex flex-col overflow-hidden"
                        >
                            <div
                                class="flex items-center justify-between border-b border-border px-4 py-3"
                            >
                                <span
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >01 · Vision</span
                                >
                                <span
                                    class="font-mono text-[0.65rem] font-bold text-primary"
                                    >IMAGE</span
                                >
                            </div>
                            <div class="space-y-3 p-4">
                                <h3 class="text-lg font-semibold">
                                    See the finding
                                </h3>
                                <div
                                    class="viewer-surface relative aspect-4/3 overflow-hidden rounded-2xl"
                                >
                                    <img
                                        src="/images/chest-xray.png"
                                        alt="Full PA chest radiograph"
                                        class="size-full object-cover"
                                    />
                                    <div
                                        class="welcome-finding-box left-[14%] bottom-[16%] h-[32%] w-[28%]"
                                    />
                                </div>
                                <p
                                    class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                >
                                    Pixel tensor → structured observations[]
                                </p>
                            </div>
                        </article>

                        <article
                            class="welcome-reveal-scale paper-panel flex flex-col overflow-hidden"
                            style="--reveal-delay: 80ms"
                        >
                            <div
                                class="flex items-center justify-between border-b border-border px-4 py-3"
                            >
                                <span
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >02 · Grounding</span
                                >
                                <span
                                    class="font-mono text-[0.65rem] font-bold text-primary"
                                    >WHERE</span
                                >
                            </div>
                            <div class="space-y-3 p-4">
                                <h3 class="text-lg font-semibold">
                                    Point to anatomy
                                </h3>
                                <div
                                    class="viewer-surface relative aspect-4/3 overflow-hidden rounded-2xl"
                                >
                                    <img
                                        src="/images/specimen-xray.png"
                                        alt="Zoomed right lower lobe with bounding box"
                                        class="size-full object-cover"
                                    />
                                    <div
                                        class="welcome-finding-box left-[22%] top-[18%] h-[48%] w-[42%]"
                                    />
                                    <code
                                        class="absolute right-2 bottom-2 rounded bg-black/55 px-2 py-1 font-mono text-[0.6rem] text-white"
                                        >[x, y, w, h]</code
                                    >
                                </div>
                                <p
                                    class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                >
                                    Normalized bbox → viewport overlay
                                </p>
                            </div>
                        </article>

                        <article
                            class="welcome-reveal-scale paper-panel flex flex-col overflow-hidden"
                            style="--reveal-delay: 160ms"
                        >
                            <div
                                class="flex items-center justify-between border-b border-border px-4 py-3"
                            >
                                <span
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >03 · Document AI</span
                                >
                                <span
                                    class="font-mono text-[0.65rem] font-bold text-primary"
                                    >DATA</span
                                >
                            </div>
                            <div class="space-y-3 p-4">
                                <h3 class="text-lg font-semibold">
                                    Structure the PDF
                                </h3>
                                <div
                                    class="flex aspect-4/3 flex-col gap-1.5 rounded-2xl border border-border bg-muted/30 p-3 font-mono text-xs"
                                >
                                    <div
                                        v-for="row in [
                                            ['Hb', '9.2', 'LOW'],
                                            ['WBC', '3.8', 'LOW'],
                                            ['PLT', '85', 'LOW'],
                                            ['CRP', '48', 'HIGH'],
                                        ]"
                                        :key="row[0]"
                                        class="flex flex-1 items-center justify-between gap-2 rounded-lg bg-paper px-2.5"
                                    >
                                        <span class="text-ink-soft">{{
                                            row[0]
                                        }}</span>
                                        <span class="font-bold tabular-nums">{{
                                            row[1]
                                        }}</span>
                                        <span
                                            class="text-[0.65rem] font-semibold"
                                            :class="
                                                row[2] === 'HIGH'
                                                    ? 'text-clinical-abnormal'
                                                    : 'text-clinical-borderline'
                                            "
                                            >{{ row[2] }}</span
                                        >
                                    </div>
                                </div>
                                <p
                                    class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                >
                                    PDF / OCR → schema → MySQL
                                </p>
                            </div>
                        </article>

                        <article
                            class="welcome-reveal-scale paper-panel flex flex-col overflow-hidden"
                            style="--reveal-delay: 240ms"
                        >
                            <div
                                class="flex items-center justify-between border-b border-border px-4 py-3"
                            >
                                <span
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                    >04 · Temporal</span
                                >
                                <span
                                    class="font-mono text-[0.65rem] font-bold text-primary"
                                    >CHANGE</span
                                >
                            </div>
                            <div class="space-y-3 p-4">
                                <h3 class="text-lg font-semibold">
                                    Reason over time
                                </h3>
                                <div
                                    class="flex aspect-4/3 items-center rounded-2xl border border-border bg-muted/30 p-3"
                                >
                                    <svg
                                        viewBox="0 0 320 160"
                                        class="h-full w-full text-primary"
                                        aria-label="Declining biomarker trend"
                                        preserveAspectRatio="xMidYMid meet"
                                    >
                                        <polyline
                                            points="10,28 70,42 125,52 185,88 245,118 310,145"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                        <g fill="var(--coral)">
                                            <circle cx="10" cy="28" r="5" />
                                            <circle cx="70" cy="42" r="5" />
                                            <circle cx="125" cy="52" r="5" />
                                            <circle cx="185" cy="88" r="5" />
                                            <circle cx="245" cy="118" r="5" />
                                            <circle cx="310" cy="145" r="5" />
                                        </g>
                                    </svg>
                                </div>
                                <p
                                    class="font-mono text-[0.65rem] text-ink-faint uppercase"
                                >
                                    Current ⊕ prior → new | stable | resolved
                                </p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Malaysia grounding -->
            <section
                id="grounding"
                class="scroll-mt-24 border-b border-border/60 bg-paper/40"
            >
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div
                        class="mb-12 flex flex-col gap-4 md:flex-row md:items-end md:justify-between"
                    >
                        <div class="max-w-3xl space-y-4">
                            <SectionTag class="welcome-reveal"
                                >06 · Grounding + localization</SectionTag
                            >
                            <h2
                                class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                            >
                                Grounded in evidence.
                                <span class="text-primary"
                                    >Fluent in Malaysia.</span
                                >
                            </h2>
                        </div>
                        <div class="welcome-reveal flex flex-wrap gap-2">
                            <AnnotationPill>BM</AnnotationPill>
                            <AnnotationPill>EN</AnnotationPill>
                            <AnnotationPill>中文</AnnotationPill>
                            <AnnotationPill>தமிழ்</AnnotationPill>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="welcome-reveal-scale paper-panel space-y-4 p-6">
                            <div
                                class="rounded-2xl border border-dashed border-primary/40 bg-primary/5 px-4 py-3"
                            >
                                <p
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                >
                                    Retrieval query
                                </p>
                                <p class="font-semibold">
                                    “RLL opacity + fever + cough”
                                </p>
                            </div>
                            <div class="space-y-3">
                                <div
                                    v-for="step in [
                                        [
                                            '01',
                                            'Hybrid search',
                                            'Dense vectors + lexical BM25',
                                        ],
                                        [
                                            '02',
                                            'MMR rerank',
                                            'Relevance × diversity',
                                        ],
                                        [
                                            '03',
                                            'Confidence gate',
                                            'Abstain below threshold',
                                        ],
                                    ]"
                                    :key="step[0]"
                                    class="flex gap-3"
                                >
                                    <span
                                        class="font-mono text-sm font-bold text-primary"
                                        >{{ step[0] }}</span
                                    >
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ step[1] }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ step[2] }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-2 border-t border-border pt-4">
                                <div
                                    v-for="src in [
                                        [
                                            '#1',
                                            'MOH CPG · Community Acquired Pneumonia',
                                            '0.89',
                                        ],
                                        [
                                            '#2',
                                            'Patient history · respiratory episode',
                                            '0.82',
                                        ],
                                        [
                                            '#3',
                                            'MOH CPG · Tuberculosis management',
                                            '0.76',
                                        ],
                                    ]"
                                    :key="src[0]"
                                    class="flex items-center gap-3 rounded-xl border border-border bg-muted/30 px-3 py-2 text-sm"
                                >
                                    <b class="font-mono text-primary">{{
                                        src[0]
                                    }}</b>
                                    <span class="flex-1 text-xs md:text-sm">{{
                                        src[1]
                                    }}</span>
                                    <em
                                        class="font-mono text-xs not-italic text-ink-faint"
                                        >{{ src[2] }}</em
                                    >
                                </div>
                            </div>
                        </div>

                        <article
                            class="welcome-reveal-scale paper-panel paper-panel--focal relative space-y-4 overflow-hidden p-6"
                            style="--reveal-delay: 100ms"
                        >
                            <div
                                class="flex w-fit gap-1 rounded-full border border-border bg-muted/40 p-1 font-mono text-[0.65rem] font-semibold uppercase"
                            >
                                <span class="px-3 py-1 text-ink-soft">English</span>
                                <span
                                    class="rounded-full bg-primary px-3 py-1 text-primary-foreground"
                                    >Bahasa Melayu</span
                                >
                            </div>
                            <p
                                class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                            >
                                Ringkasan untuk pesakit
                            </p>
                            <h3 class="text-xl font-semibold tracking-tight">
                                Terdapat perubahan pada bahagian bawah paru-paru
                                kanan.
                            </h3>
                            <p
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                Imej menunjukkan kawasan legap yang mungkin
                                berkaitan dengan jangkitan. Dapatan ini perlu
                                dinilai bersama gejala dan pemeriksaan doktor.
                            </p>
                            <div class="citation-stamp w-full">
                                <span>[MOH-CPG-01]</span>
                                <span>Garis Panduan Pneumonia</span>
                                <i class="not-italic opacity-70"
                                    >89% relevance</i
                                >
                            </div>
                            <div
                                class="flex items-start gap-3 rounded-2xl border border-border bg-secondary/40 p-3"
                            >
                                <AnnotationPill>MY-LoRA</AnnotationPill>
                                <div>
                                    <p class="text-sm font-semibold">
                                        QLoRA adapter
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        BM medical register · local disease
                                        priors
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Safety -->
            <section id="safety" class="scroll-mt-24 border-b border-border/60">
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div class="mb-12 max-w-3xl space-y-4">
                        <SectionTag class="welcome-reveal"
                            >07 · Responsible AI</SectionTag
                        >
                        <h2
                            class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                        >
                            Safety is a control plane.
                            <span class="text-coral">Not a disclaimer.</span>
                        </h2>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                        <div
                            class="welcome-reveal-scale paper-panel relative space-y-4 overflow-hidden p-6"
                        >
                            <div
                                class="rounded-2xl border border-border bg-muted/40 px-4 py-3"
                            >
                                <p
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                >
                                    Finding input
                                </p>
                                <p class="text-lg font-bold tabular-nums">
                                    K⁺ 6.8 mmol/L
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Confidence 0.96 · critical &gt; 6.5
                                </p>
                            </div>
                            <div
                                class="flex items-center justify-center gap-3 py-2"
                            >
                                <div
                                    class="h-px flex-1 bg-linear-to-r from-transparent to-coral"
                                />
                                <div
                                    class="flex flex-col items-center rounded-2xl border-2 border-coral bg-coral/10 px-4 py-3 text-center"
                                >
                                    <ShieldAlert class="mb-1 size-5 text-coral" />
                                    <p class="text-sm font-bold text-coral">
                                        Escalate
                                    </p>
                                    <p
                                        class="font-mono text-[0.6rem] tracking-wider text-ink-faint uppercase"
                                    >
                                        Policy + rules
                                    </p>
                                </div>
                                <div
                                    class="h-px flex-1 bg-linear-to-l from-transparent to-coral"
                                />
                            </div>
                            <div
                                class="rounded-2xl border border-border bg-muted/40 px-4 py-3"
                            >
                                <p
                                    class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                                >
                                    Control actions
                                </p>
                                <p class="font-semibold">Critical alert</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <AnnotationPill variant="coral"
                                        >Notify clinician</AnnotationPill
                                    >
                                    <AnnotationPill variant="amber"
                                        >Audit event</AnnotationPill
                                    >
                                </div>
                            </div>
                            <div
                                class="absolute top-4 right-4 rotate-[-8deg] rounded border-2 border-coral px-2 py-1 font-mono text-[0.65rem] font-bold tracking-wider text-coral uppercase"
                            >
                                Patient copy withheld
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <article
                                v-for="(item, i) in safetyPrinciples"
                                :key="item.n"
                                class="welcome-reveal-scale paper-panel space-y-2 p-5"
                                :style="{ '--reveal-delay': `${i * 80}ms` }"
                            >
                                <span
                                    class="font-mono text-sm font-bold text-primary"
                                    >{{ item.n }}</span
                                >
                                <h3 class="text-base font-semibold">
                                    {{ item.title }}
                                </h3>
                                <p
                                    class="text-sm leading-relaxed text-muted-foreground"
                                >
                                    {{ item.body }}
                                </p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Advantage + proof -->
            <section
                id="advantage"
                class="scroll-mt-24 border-b border-border/60 bg-paper/40"
            >
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-24">
                    <div class="mb-12 max-w-3xl space-y-4">
                        <SectionTag class="welcome-reveal"
                            >08 · Why SihatAI</SectionTag
                        >
                        <h2
                            class="welcome-reveal-left text-3xl font-bold tracking-tight md:text-4xl"
                        >
                            Our advantage is not one model.
                            <span class="text-primary"
                                >It is the system around it.</span
                            >
                        </h2>
                    </div>

                    <div class="mb-12 grid gap-5 md:grid-cols-2">
                        <article
                            v-for="(item, i) in advantages"
                            :key="item.title"
                            class="welcome-reveal-scale paper-panel space-y-4 p-6"
                            :style="{ '--reveal-delay': `${i * 80}ms` }"
                        >
                            <div class="flex items-center justify-between">
                                <IconDisc>
                                    <component :is="item.icon" class="size-6" />
                                </IconDisc>
                                <span
                                    class="font-mono text-xs font-bold text-ink-faint"
                                    >0{{ i + 1 }}</span
                                >
                            </div>
                            <h3 class="text-lg font-semibold">{{ item.title }}</h3>
                            <p
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ item.body }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <AnnotationPill
                                    v-for="tag in item.tags"
                                    :key="tag"
                                    >{{ tag }}</AnnotationPill
                                >
                            </div>
                        </article>
                    </div>

                    <div class="welcome-reveal grid gap-4 sm:grid-cols-3">
                        <div
                            class="paper-panel flex flex-col items-center gap-3 p-6 text-center"
                        >
                            <div class="welcome-gauge-ring" style="--score: 68.4">
                                <strong class="text-xl tabular-nums"
                                    >68.4%</strong
                                >
                            </div>
                            <p
                                class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                            >
                                Medical reasoning
                            </p>
                            <p class="text-sm font-semibold">MedQA subset</p>
                        </div>
                        <div
                            class="paper-panel flex flex-col items-center gap-3 p-6 text-center"
                        >
                            <div class="welcome-gauge-ring" style="--score: 84">
                                <strong class="text-xl tabular-nums"
                                    >4.2/5</strong
                                >
                            </div>
                            <p
                                class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                            >
                                Report quality
                            </p>
                            <p class="text-sm font-semibold">Judge rubric</p>
                        </div>
                        <div
                            class="paper-panel flex flex-col items-center gap-3 p-6 text-center"
                        >
                            <div class="welcome-gauge-ring" style="--score: 96.5">
                                <strong class="text-xl tabular-nums"
                                    >96.5%</strong
                                >
                            </div>
                            <p
                                class="font-mono text-[0.65rem] tracking-wider text-ink-faint uppercase"
                            >
                                Safety compliance
                            </p>
                            <p class="text-sm font-semibold">Red-team set</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- CTA + demo -->
            <section id="try" class="scroll-mt-24">
                <div class="mx-auto max-w-7xl px-4 py-20 md:px-6 md:py-28">
                    <div
                        class="welcome-reveal-scale paper-panel paper-panel--focal relative px-6 py-14 text-center md:px-12"
                    >
                        <div
                            class="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit]"
                            aria-hidden="true"
                        >
                            <div class="welcome-orbit opacity-40" />
                            <div class="welcome-orbit-inner opacity-30" />
                        </div>

                        <div class="relative z-10 mx-auto max-w-3xl space-y-6">
                            <SectionTag class="justify-center"
                                >Open the clinical field atlas</SectionTag
                            >
                            <h2
                                class="text-3xl font-bold tracking-tight md:text-5xl"
                            >
                                Safe, grounded
                                <span class="text-primary"
                                    >clinical intelligence</span
                                >
                                you can inspect.
                            </h2>
                            <p
                                class="mx-auto max-w-2xl text-base leading-relaxed text-muted-foreground md:text-lg"
                            >
                                SihatAI combines multimodal perception, agentic
                                reasoning, local evidence, and audience-adaptive
                                generation in one inspectable pipeline.
                            </p>

                            <div
                                class="flex flex-wrap items-center justify-center gap-3 pt-2"
                            >
                                <Button
                                    v-if="!$page.props.auth.user"
                                    as-child
                                    size="lg"
                                >
                                    <Link :href="login()">View Demo</Link>
                                </Button>
                                <Button v-else as-child size="lg">
                                    <Link :href="dashboard()"
                                        >Open dashboard</Link
                                    >
                                </Button>
                            </div>

                            <div
                                class="grid gap-3 pt-6 text-left sm:grid-cols-2 md:grid-cols-4"
                            >
                                <div
                                    v-for="pillar in [
                                        [
                                            '01',
                                            'Understands',
                                            'imaging · labs · documents · voice',
                                        ],
                                        [
                                            '02',
                                            'Reasons',
                                            'agents · RAG · longitudinal context',
                                        ],
                                        [
                                            '03',
                                            'Protects',
                                            'de-ID · abstention · escalation',
                                        ],
                                        [
                                            '04',
                                            'Adapts',
                                            'physician · patient · multilingual',
                                        ],
                                    ]"
                                    :key="pillar[0]"
                                    class="rounded-2xl border border-border/80 bg-muted/30 px-5 py-4"
                                >
                                    <p
                                        class="font-mono text-[0.65rem] text-primary"
                                    >
                                        {{ pillar[0] }}
                                    </p>
                                    <p class="text-sm font-semibold">
                                        {{ pillar[1] }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ pillar[2] }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t border-border/80 bg-paper/70">
            <div class="mx-auto max-w-7xl px-4 py-12 md:px-6">
                <div
                    class="grid gap-10 md:grid-cols-[1.2fr_1fr_1fr] md:gap-8"
                >
                    <div class="space-y-4">
                        <Link :href="home()" class="inline-flex items-center gap-2.5">
                            <AppLogoIcon class="size-9 rounded-xl" />
                            <span class="text-lg font-bold tracking-tight"
                                >Sihat<span class="text-primary">AI</span></span
                            >
                        </Link>
                        <p
                            class="max-w-sm text-sm leading-relaxed text-muted-foreground"
                        >
                            Multimodal clinical intelligence for Malaysian
                            imaging and labs: MedGemma analysis, RAG citations,
                            and dual physician / patient reports.
                        </p>
                        <div class="flex items-center gap-2">
                            <Shield class="size-4 text-primary" />
                            <p class="text-xs text-muted-foreground">
                                AI assist only, not a substitute for clinical
                                judgment.
                            </p>
                        </div>
                    </div>

                    <div>
                        <p
                            class="mb-3 font-mono text-xs font-semibold tracking-wider text-ink-faint uppercase"
                        >
                            Navigate
                        </p>
                        <ul class="space-y-2 text-sm">
                            <li v-for="item in navItems" :key="item.id">
                                <a
                                    :href="`#${item.id}`"
                                    class="text-muted-foreground hover:text-foreground"
                                    @click.prevent="scrollToId(item.id)"
                                >
                                    {{ item.label }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <p
                            class="mb-3 font-mono text-xs font-semibold tracking-wider text-ink-faint uppercase"
                        >
                            Connect
                        </p>
                        <ul class="space-y-2 text-sm">
                            <li v-if="!$page.props.auth.user">
                                <Link
                                    :href="login()"
                                    class="text-muted-foreground hover:text-foreground"
                                    >View Demo</Link
                                >
                            </li>
                            <li v-if="$page.props.auth.user">
                                <Link
                                    :href="dashboard()"
                                    class="text-muted-foreground hover:text-foreground"
                                    >Dashboard</Link
                                >
                            </li>
                            <li>
                                <a
                                    href="https://github.com/thomasliem97/sihat-ai"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-muted-foreground hover:text-foreground"
                                    >GitHub</a
                                >
                            </li>
                            <li>
                                <a
                                    href="mailto:thomasliem@veximus.com.my"
                                    class="text-muted-foreground hover:text-foreground"
                                    >Get in touch</a
                                >
                            </li>
                            <li>
                                <a
                                    href="https://maicnexus.com/en"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-muted-foreground hover:text-foreground"
                                    >MAIC Nexus</a
                                >
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    class="mt-10 flex flex-col gap-3 border-t border-border/70 pt-6 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="font-mono text-xs text-ink-faint">
                        © {{ new Date().getFullYear() }} SihatAI · MAIC Nexus
                        Challenge
                    </p>
                    <p
                        class="text-xs leading-relaxed text-muted-foreground sm:whitespace-nowrap"
                    >
                        For demonstration and research. Not for clinical
                        decision-making without qualified clinician review.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
