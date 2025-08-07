<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <nav
                class="border-b border-gray-100 bg-white dark:border-gray-700 dark:bg-gray-800"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
                                </NavLink>
                                
                                <!-- Documents Menu -->
                                <Dropdown align="left" width="48">
                                    <template #trigger>
                                        <span class="inline-flex">
                                            <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-700 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-700 transition duration-150 ease-in-out">
                                                Documents
                                                <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('documents.index')">
                                            Mes Documents
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('documents.create')">
                                            Télécharger
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('conversions.index')">
                                            Conversions
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                                
                                <!-- Outils PDF Menu -->
                                <Dropdown align="left" width="48">
                                    <template #trigger>
                                        <span class="inline-flex">
                                            <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-700 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-700 transition duration-150 ease-in-out">
                                                Outils PDF
                                                <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('tools.merge')">
                                            Fusionner PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.split')">
                                            Diviser PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.rotate')">
                                            Rotation
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.compress')">
                                            Compresser
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.watermark')">
                                            Filigrane
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.encrypt')">
                                            Chiffrer PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.ocr')">
                                            OCR (Texte)
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.extract')">
                                            Extraire Pages
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                                
                                <!-- Administration Menu (conditionally shown for admins) -->
                                <Dropdown v-if="$page.props.auth.user.is_tenant_admin || $page.props.auth.user.is_super_admin" align="left" width="48">
                                    <template #trigger>
                                        <span class="inline-flex">
                                            <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-700 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-700 transition duration-150 ease-in-out">
                                                Administration
                                                <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <!-- Tenant Admin Routes -->
                                        <template v-if="$page.props.auth.user.is_tenant_admin">
                                            <DropdownLink :href="route('admin.dashboard')">
                                                Tableau de bord
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('admin.users.index')">
                                                Utilisateurs
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('admin.settings')">
                                                Paramètres
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('admin.activity')">
                                                Journal d'activité
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('admin.storage')">
                                                Gestion stockage
                                            </DropdownLink>
                                        </template>
                                        
                                        <!-- Super Admin Routes -->
                                        <template v-if="$page.props.auth.user.is_super_admin">
                                            <div class="border-t border-gray-100 dark:border-gray-600"></div>
                                            
                                            <DropdownLink :href="route('super-admin.dashboard')">
                                                Super Admin
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('super-admin.tenants.index')">
                                                Gestion Tenants
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('super-admin.users.index')">
                                                Tous les utilisateurs
                                            </DropdownLink>
                                            
                                            <DropdownLink :href="route('super-admin.system')">
                                                Système
                                            </DropdownLink>
                                        </template>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-300"
                                            >
                                                <div class="flex items-center">
                                                    {{ $page.props.auth.user.name }}
                                                    <span v-if="$page.props.auth.user.roles && $page.props.auth.user.roles.length > 0" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full dark:bg-blue-800 dark:text-blue-200">
                                                        {{ $page.props.auth.user.roles.join(', ') }}
                                                    </span>
                                                    <span v-else-if="$page.props.auth.user.role" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full dark:bg-blue-800 dark:text-blue-200">
                                                        {{ $page.props.auth.user.role }}
                                                    </span>
                                                </div>

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-900 dark:hover:text-gray-400 dark:focus:bg-gray-900 dark:focus:text-gray-400"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink :href="route('documents.index')">
                            Mes Documents
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink :href="route('documents.create')">
                            Télécharger
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink :href="route('conversions.index')">
                            Conversions
                        </ResponsiveNavLink>
                    </div>
                    
                    <!-- PDF Tools Section -->
                    <div class="border-t border-gray-200 dark:border-gray-600 pt-2 pb-2">
                        <div class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Outils PDF</div>
                        <ResponsiveNavLink :href="route('tools.merge')">Fusionner PDF</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.split')">Diviser PDF</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.rotate')">Rotation</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.compress')">Compresser</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.watermark')">Filigrane</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.encrypt')">Chiffrer PDF</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.ocr')">OCR (Texte)</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('tools.extract')">Extraire Pages</ResponsiveNavLink>
                    </div>
                    
                    <!-- Administration Section -->
                    <div v-if="$page.props.auth.user.is_tenant_admin || $page.props.auth.user.is_super_admin" class="border-t border-gray-200 dark:border-gray-600 pt-2 pb-2">
                        <div class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Administration</div>
                        
                        <!-- Tenant Admin Links -->
                        <template v-if="$page.props.auth.user.is_tenant_admin">
                            <ResponsiveNavLink :href="route('admin.dashboard')">Tableau de bord</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('admin.users.index')">Utilisateurs</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('admin.settings')">Paramètres</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('admin.activity')">Journal d'activité</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('admin.storage')">Gestion stockage</ResponsiveNavLink>
                        </template>
                        
                        <!-- Super Admin Links -->
                        <template v-if="$page.props.auth.user.is_super_admin">
                            <ResponsiveNavLink :href="route('super-admin.dashboard')">Super Admin</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('super-admin.tenants.index')">Gestion Tenants</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('super-admin.users.index')">Tous les utilisateurs</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('super-admin.system')">Système</ResponsiveNavLink>
                        </template>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4 dark:border-gray-600"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800 dark:text-gray-200"
                            >
                                {{ $page.props.auth.user.name }}
                                <div v-if="$page.props.auth.user.roles && $page.props.auth.user.roles.length > 0" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Rôle(s): {{ $page.props.auth.user.roles.join(', ') }}
                                </div>
                                <div v-else-if="$page.props.auth.user.role" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Rôle: {{ $page.props.auth.user.role }}
                                </div>
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow dark:bg-gray-800"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>