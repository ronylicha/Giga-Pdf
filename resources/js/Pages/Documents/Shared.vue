<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Documents Partagés
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Documents partagés avec moi -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Partagés avec moi
                        </h3>
                        
                        <div v-if="sharedWithMe.length === 0" class="text-center py-8">
                            <ShareIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500">
                                Aucun document n'a été partagé avec vous.
                            </p>
                        </div>
                        
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Document
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Partagé par
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Permissions
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date de partage
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Expiration
                                        </th>
                                        <th class="relative px-6 py-3">
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="share in sharedWithMe" :key="share.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <DocumentIcon class="h-5 w-5 text-gray-400 mr-2" />
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ share.document?.original_name || 'Document' }}
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        {{ formatFileSize(share.document?.size || 0) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                {{ share.sharedBy?.name || 'Utilisateur' }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ share.sharedBy?.email }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex space-x-1">
                                                <span v-if="share.permissions?.includes('view')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    Lecture
                                                </span>
                                                <span v-if="share.permissions?.includes('download')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                    Téléchargement
                                                </span>
                                                <span v-if="share.permissions?.includes('edit')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                    Édition
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(share.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span v-if="share.expires_at" 
                                                :class="isExpired(share.expires_at) ? 'text-red-600' : 'text-gray-500'"
                                                class="text-sm">
                                                {{ formatDate(share.expires_at) }}
                                            </span>
                                            <span v-else class="text-sm text-gray-500">
                                                Jamais
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <Link
                                                :href="route('documents.show', share.document?.id)"
                                                class="text-indigo-600 hover:text-indigo-900 mr-3"
                                            >
                                                Voir
                                            </Link>
                                            <button
                                                v-if="share.permissions?.includes('download')"
                                                @click="downloadDocument(share.document?.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                Télécharger
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Documents que j'ai partagés -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            Mes partages
                        </h3>
                        
                        <div v-if="sharedByMe.length === 0" class="text-center py-8">
                            <ShareIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500">
                                Vous n'avez partagé aucun document.
                            </p>
                        </div>
                        
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Document
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Partagé avec
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type de partage
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Permissions
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date de partage
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Expiration
                                        </th>
                                        <th class="relative px-6 py-3">
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="share in sharedByMe" :key="share.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <DocumentIcon class="h-5 w-5 text-gray-400 mr-2" />
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ share.document?.original_name || 'Document' }}
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        {{ formatFileSize(share.document?.size || 0) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div v-if="share.sharedWith">
                                                <div class="text-sm text-gray-900">
                                                    {{ share.sharedWith.name }}
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    {{ share.sharedWith.email }}
                                                </div>
                                            </div>
                                            <div v-else class="text-sm text-gray-500">
                                                {{ getShareTypeLabel(share.type) }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="getShareTypeClass(share.type)"
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                                {{ getShareTypeLabel(share.type) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex space-x-1">
                                                <span v-if="share.permissions?.includes('view')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    Lecture
                                                </span>
                                                <span v-if="share.permissions?.includes('download')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                    Téléchargement
                                                </span>
                                                <span v-if="share.permissions?.includes('edit')" 
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                    Édition
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(share.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span v-if="share.expires_at" 
                                                :class="isExpired(share.expires_at) ? 'text-red-600' : 'text-gray-500'"
                                                class="text-sm">
                                                {{ formatDate(share.expires_at) }}
                                            </span>
                                            <span v-else class="text-sm text-gray-500">
                                                Jamais
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button
                                                @click="copyShareLink(share)"
                                                class="text-indigo-600 hover:text-indigo-900 mr-3"
                                            >
                                                Copier le lien
                                            </button>
                                            <button
                                                @click="revokeShare(share)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                Révoquer
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { DocumentIcon, ShareIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    sharedWithMe: Array,
    sharedByMe: Array,
});

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const isExpired = (date) => {
    if (!date) return false;
    return new Date(date) < new Date();
};

const getShareTypeLabel = (type) => {
    switch (type) {
        case 'public':
            return 'Lien public';
        case 'password':
            return 'Lien protégé';
        case 'user':
            return 'Utilisateur spécifique';
        default:
            return type;
    }
};

const getShareTypeClass = (type) => {
    switch (type) {
        case 'public':
            return 'bg-yellow-100 text-yellow-800';
        case 'password':
            return 'bg-orange-100 text-orange-800';
        case 'user':
            return 'bg-green-100 text-green-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const downloadDocument = (documentId) => {
    if (documentId) {
        window.location.href = route('documents.download', documentId);
    }
};

const copyShareLink = (share) => {
    const url = route('share.show', share.token);
    navigator.clipboard.writeText(window.location.origin + url).then(() => {
        alert('Lien copié dans le presse-papiers');
    });
};

const revokeShare = (share) => {
    if (confirm('Êtes-vous sûr de vouloir révoquer ce partage ?')) {
        // Implémenter la révocation du partage
        router.delete(route('shares.destroy', share.id), {
            preserveState: true,
            preserveScroll: true,
        });
    }
};
</script>