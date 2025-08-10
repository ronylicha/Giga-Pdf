<script setup>
import { ref, onMounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

// Debug pour voir le rôle de l'utilisateur
onMounted(() => {
    const page = usePage();
    console.log('User role:', page.props.auth.user?.role);
    console.log('User data:', page.props.auth.user);
});
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
                                
                                <!-- Documents Menu (not for super admin) -->
                                <Dropdown v-if="$page.props.auth.user.role !== 'super-admin'" align="left" width="48">
                                    <template #trigger>
                                        <button class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                            Documents
                                            <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('documents.index')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Mes Documents
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('documents.create')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                            </svg>
                                            Télécharger
                                        </DropdownLink>
                                        
                                        <div class="border-t border-gray-200 dark:border-gray-600"></div>
                                        
                                        <DropdownLink :href="route('documents.conversions')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                            </svg>
                                            Conversions
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('documents.shared')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.026a9.001 9.001 0 01-7.432 0m9.032-4.026A9.001 9.001 0 0112 3c-4.474 0-8.268 3.12-9.032 7.326m0 0A9.001 9.001 0 0012 21c4.474 0 8.268-3.12 9.032-7.326" />
                                            </svg>
                                            Documents Partagés
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                                
                                <!-- Outils PDF Menu (not for super admin) -->
                                <Dropdown v-if="$page.props.auth.user.role !== 'super-admin'" align="left" width="48">
                                    <template #trigger>
                                        <button class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                            Outils PDF
                                            <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('tools.merge')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z" />
                                            </svg>
                                            Fusionner PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.split')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                            Diviser PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.rotate')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                            Rotation
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.compress')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Compresser
                                        </DropdownLink>
                                        
                                        <div class="border-t border-gray-200 dark:border-gray-600"></div>
                                        
                                        <DropdownLink :href="route('tools.watermark')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                                            </svg>
                                            Filigrane
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.encrypt')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                            </svg>
                                            Chiffrer PDF
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.ocr')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                            OCR (Texte)
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tools.extract')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                                            </svg>
                                            Extraire Pages
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                                
                                <!-- Super Admin Menu -->
                                <Dropdown v-if="$page.props.auth.user.role === 'super-admin'" align="left" width="48">
                                    <template #trigger>
                                        <button class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                            Super Admin
                                            <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('tenants.index')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                            Gérer Tenants
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tenants.create')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                            </svg>
                                            Nouveau Tenant
                                        </DropdownLink>
                                        
                                        <div class="border-t border-gray-200 dark:border-gray-600"></div>
                                        
                                        <a href="/horizon" target="_blank" class="block px-4 py-2 text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                            Laravel Horizon
                                        </a>
                                    </template>
                                </Dropdown>
                                
                                <!-- Tenant Admin Menu (if user is tenant admin only) -->
                                <Dropdown v-if="$page.props.auth.user.role === 'tenant-admin'" align="left" width="48">
                                    <template #trigger>
                                        <button class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                            Administration
                                            <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <DropdownLink :href="route('tenant.users.index')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                            </svg>
                                            Utilisateurs
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tenant.activity')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Journal d'Activité
                                        </DropdownLink>
                                        
                                        <DropdownLink :href="route('tenant.storage')">
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                            </svg>
                                            Stockage
                                        </DropdownLink>
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
                                                    <span v-if="$page.props.auth.user.roles" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full dark:bg-blue-800 dark:text-blue-200">
                                                        {{ Array.isArray($page.props.auth.user.roles) ? $page.props.auth.user.roles.join(', ') : $page.props.auth.user.roles }}
                                                    </span>
                                                    <span v-else-if="$page.props.auth.user.role" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full dark:bg-blue-800 dark:text-blue-200">
                                                        {{ $page.props.auth.user.role.replace('_', ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ') }}
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
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                            Mon Profil
                                        </DropdownLink>
                                        
                                        <!-- Super Admin Dashboard Link -->
                                        <DropdownLink
                                            v-if="$page.props.auth.user?.is_super_admin"
                                            :href="route('super-admin.dashboard')"
                                        >
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                                            </svg>
                                            Super Admin
                                        </DropdownLink>
                                        
                                        <DropdownLink
                                            :href="route('settings')"
                                        >
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            Paramètres
                                        </DropdownLink>
                                        
                                        <div class="border-t border-gray-200 dark:border-gray-600"></div>
                                        
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            <svg class="w-4 h-4 me-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                            </svg>
                                            Déconnexion
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
                        
                        <!-- Documents Section -->
                        <div class="border-t border-gray-200 dark:border-gray-600 pt-2 pb-2">
                            <div class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Documents</div>
                            <ResponsiveNavLink :href="route('documents.index')">Mes Documents</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('documents.create')">Télécharger</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('documents.conversions')">Conversions</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('documents.shared')">Documents Partagés</ResponsiveNavLink>
                        </div>
                        
                        <!-- PDF Tools Section -->
                        <div class="border-t border-gray-200 dark:border-gray-600 pt-2 pb-2">
                            <div class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Outils PDF</div>
                            <ResponsiveNavLink :href="route('tools.merge')">Fusionner PDF</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.split')">Diviser PDF</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.rotate')">Rotation</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.compress')">Compresser</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.watermark')">Filigrane</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.encrypt')">Chiffrer PDF</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.ocr')">OCR (Texte)</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tools.extract')">Extraire Pages</ResponsiveNavLink>
                        </div>
                        
                        <!-- Admin Section -->
                        <div v-if="$page.props.auth.user.role === 'tenant_admin' || $page.props.auth.user.role === 'super_admin'" class="border-t border-gray-200 dark:border-gray-600 pt-2 pb-2">
                            <div class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Administration</div>
                            <ResponsiveNavLink :href="route('tenant.users.index')">Utilisateurs</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tenant.activity')">Journal d'Activité</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('tenant.storage')">Stockage</ResponsiveNavLink>
                        </div>
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
                                <div v-if="$page.props.auth.user.roles" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Rôle(s): {{ Array.isArray($page.props.auth.user.roles) ? $page.props.auth.user.roles.join(', ') : $page.props.auth.user.roles }}
                                </div>
                                <div v-else-if="$page.props.auth.user.role" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Rôle: {{ $page.props.auth.user.role.replace('_', ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ') }}
                                </div>
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Mon Profil
                            </ResponsiveNavLink>
                            
                            <!-- Super Admin Dashboard Link (only for super_admin) -->
                            <ResponsiveNavLink 
                                v-if="$page.props.auth.user.role === 'super_admin'"
                                :href="route('super-admin.dashboard')"
                            >
                                Super Admin Dashboard
                            </ResponsiveNavLink>
                            
                            <ResponsiveNavLink :href="route('settings')">
                                Paramètres
                            </ResponsiveNavLink>
                            
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Déconnexion
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
