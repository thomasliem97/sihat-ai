<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FileText, House, LayoutGrid, LifeBuoy, Mic } from '@lucide/vue';
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
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { dashboard, home } from '@/routes';
import { dashboard as patientDashboard } from '@/routes/patient';
import { dashboard as physicianDashboard } from '@/routes/physician';
import { index as recordsIndex } from '@/routes/records';
import { triage as voiceTriage } from '@/routes/voice';
import type { NavItem } from '@/types';

const page = usePage();
const role = computed(
    () => (page.props.auth as { user?: { role?: string } })?.user?.role,
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Records',
        href: recordsIndex(),
        icon: FileText,
    },
    {
        title: 'Voice Triage',
        href: voiceTriage(),
        icon: Mic,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link
                            :href="
                                role === 'physician'
                                    ? physicianDashboard()
                                    : patientDashboard()
                            "
                        >
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
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="Contact support"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        <a href="mailto:thomasliem@veximus.com.my">
                            <LifeBuoy />
                            <span>Contact support</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="Back to home"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        <Link :href="home()">
                            <House />
                            <span>Back to home</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarSeparator class="mx-0" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
