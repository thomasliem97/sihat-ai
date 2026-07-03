<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    FileText,
    LayoutGrid,
    Mic,
    Plus,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { dashboard } from '@/routes';
import { index as evalIndex } from '@/routes/evaluation';
import { create as recordsCreate, index as recordsIndex } from '@/routes/records';
import { triage as voiceTriage } from '@/routes/voice';
import { dashboard as physicianDashboard } from '@/routes/physician';
import { dashboard as patientDashboard } from '@/routes/patient';
import type { NavItem } from '@/types';
import { computed } from 'vue';

const page = usePage();
const role = computed(() => (page.props.auth as { user?: { role?: string } })?.user?.role);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
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
            title: 'Upload',
            href: recordsCreate(),
            icon: Plus,
        },
    ];

    if (role.value === 'physician') {
        items.push({
            title: 'Evaluation',
            href: evalIndex(),
            icon: BarChart3,
        });
    }

    items.push({
        title: 'Voice Triage',
        href: voiceTriage(),
        icon: Mic,
    });

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="role === 'physician' ? physicianDashboard() : patientDashboard()">
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
