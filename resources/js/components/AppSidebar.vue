<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Mic, ScanLine } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard as patientDashboard } from '@/routes/patient';
import { dashboard as physicianDashboard } from '@/routes/physician';
import { index as recordsIndex } from '@/routes/records';
import { triage as voiceTriage } from '@/routes/voice';
import type { NavItem } from '@/types';

const page = usePage();
const role = computed(() => page.props.auth.user?.role);
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const dashboardHref = computed(() =>
    role.value === 'physician' ? physicianDashboard() : patientDashboard(),
);

const mainNavItems = computed<NavItem[]>(() => {
    const isPhysician = role.value === 'physician';

    return [
        {
            title: 'Dashboard',
            description: isPhysician
                ? 'Your caseload at a glance: waiting patients, urgent flags, and new scans'
                : 'Your health at a glance: recent results, signed reports, and lab trends',
            href: dashboardHref.value,
            icon: LayoutDashboard,
            isActive: isCurrentUrl(dashboardHref.value),
        },
        {
            title: 'Records',
            description: isPhysician
                ? 'AI analyzes X-rays, CT, MRI, and labs. You review findings and sign the report'
                : 'Upload a scan, or open a result in plain language after your doctor signs',
            href: recordsIndex(),
            icon: ScanLine,
            isActive: isCurrentOrParentUrl(recordsIndex()),
        },
        {
            title: 'Voice Triage',
            description: isPhysician
                ? 'A symptom interview by voice or text. AI asks follow-ups and rates urgency'
                : 'Speak or type how you feel. AI asks follow-ups and rates how urgent it is',
            href: voiceTriage(),
            icon: Mic,
            isActive: isCurrentOrParentUrl(voiceTriage()),
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="offcanvas" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
