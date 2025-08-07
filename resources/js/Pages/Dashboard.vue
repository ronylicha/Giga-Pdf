<template>
    <Head title="Dashboard" />
    
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
                <div class="text-sm text-gray-500">
                    Tenant: {{ $page.props.auth.tenant?.name || 'N/A' }}
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm">Total Documents</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ stats.documents_count }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm">Conversions</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ stats.conversions_count }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-purple-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.026a3 3 0 10-5.414-2.642m5.414 2.642a3 3 0 01-5.414-2.642m0 0a3 3 0 00-5.414 2.642m5.414-2.642l-5.414-2.642" />
                                </svg>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm">Documents Partagés</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ stats.shares_count }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-orange-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                </svg>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm">Stockage Utilisé</p>
                                <p class="text-2xl font-semibold text-gray-900">{{ formatFileSize(storage.used) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-lg shadow mb-8">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Actions Rapides</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <Link
                                :href="route('documents.create')"
                                class="flex flex-col items-center p-4 border-2 border-gray-200 rounded-lg hover:border-indigo-500 hover:bg-indigo-50 transition"
                            >
                                <svg class="w-8 h-8 text-indigo-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <span class="text-sm font-medium text-gray-900">Upload PDF</span>
                            </Link>
                            
                            <Link
                                :href="route('conversions.index')"
                                class="flex flex-col items-center p-4 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition"
                            >
                                <svg class="w-8 h-8 text-green-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                                <span class="text-sm font-medium text-gray-900">Convertir</span>
                            </Link>
                            
                            <button
                                @click="openMergeTool"
                                class="flex flex-col items-center p-4 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition"
                            >
                                <svg class="w-8 h-8 text-blue-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z" />
                                </svg>
                                <span class="text-sm font-medium text-gray-900">Fusionner</span>
                            </button>
                            
                            <button
                                @click="openSplitTool"
                                class="flex flex-col items-center p-4 border-2 border-gray-200 rounded-lg hover:border-purple-500 hover:bg-purple-50 transition"
                            >
                                <svg class="w-8 h-8 text-purple-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                <span class="text-sm font-medium text-gray-900">Diviser</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Documents -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Documents Récents</h3>
                            <Link
                                :href="route('documents.index')"
                                class="text-sm text-indigo-600 hover:text-indigo-900"
                            >
                                Voir tout →
                            </Link>
                        </div>
                        
                        <div v-if="recentDocuments.length > 0" class="space-y-3">
                            <div
                                v-for="document in recentDocuments"
                                :key="document.id"
                                class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <img
                                            v-if="document.thumbnail_url"
                                            :src="document.thumbnail_url"
                                            :alt="document.original_name"
                                            class="h-10 w-10 rounded object-cover"
                                        >
                                        <div v-else class="h-10 w-10 rounded bg-gray-200 flex items-center justify-center">
                                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-sm font-medium text-gray-900">{{ document.original_name }}</p>
                                        <p class="text-xs text-gray-500">{{ formatFileSize(document.size) }} • {{ formatDate(document.created_at) }}</p>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button
                                        @click="previewDocument(document)"
                                        class="text-gray-400 hover:text-gray-600"
                                        title="Aperçu"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                    <Link
                                        :href="route('documents.edit', document.id)"
                                        class="text-gray-400 hover:text-gray-600"
                                        title="Éditer"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </Link>
                                    <button
                                        @click="downloadDocument(document)"
                                        class="text-gray-400 hover:text-gray-600"
                                        title="Télécharger"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p class="mt-2 text-sm text-gray-500">Aucun document pour le moment</p>
                            <Link
                                :href="route('documents.create')"
                                class="mt-3 inline-flex items-center px-3 py-1 border border-transparent text-sm font-medium rounded-md text-indigo-600 bg-indigo-100 hover:bg-indigo-200"
                            >
                                Télécharger votre premier document
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Storage Usage -->
                <div class="mt-8 bg-white rounded-lg shadow">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Utilisation du Stockage</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Espace utilisé</span>
                                <span class="font-medium text-gray-900">
                                    {{ formatFileSize(storage.used) }} / {{ formatFileSize(storage.limit) }}
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div
                                    :style="`width: ${storagePercentage}%`"
                                    :class="[
                                        'h-2.5 rounded-full transition-all duration-300',
                                        storagePercentage > 90 ? 'bg-red-600' : 
                                        storagePercentage > 75 ? 'bg-yellow-500' : 'bg-green-500'
                                    ]"
                                />
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ storagePercentage }}% utilisé • {{ formatFileSize(storage.limit - storage.used) }} disponible
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            documents_count: 0,
            conversions_count: 0,
            shares_count: 0,
        })
    },
    recentDocuments: {
        type: Array,
        default: () => []
    },
    storage: {
        type: Object,
        default: () => ({
            used: 0,
            limit: 10737418240 // 10GB default
        })
    }
});

const storagePercentage = computed(() => {
    if (props.storage.limit === 0) return 0;
    return Math.round((props.storage.used / props.storage.limit) * 100);
});

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (date) => {
    return format(new Date(date), 'dd MMM yyyy', { locale: fr });
};

const previewDocument = (document) => {
    window.open(route('documents.preview', document.id), '_blank');
};

const downloadDocument = (document) => {
    window.location.href = route('documents.download', document.id);
};

const openMergeTool = () => {
    router.get(route('documents.index', { tool: 'merge' }));
};

const openSplitTool = () => {
    router.get(route('documents.index', { tool: 'split' }));
};
</script>