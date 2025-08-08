<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Gestion du Stockage
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Storage Overview -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Vue d'ensemble</h3>
                    
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Utilisé: {{ formatBytes(storageStats.total_used) }}</span>
                            <span>Limite: {{ formatBytes(storageStats.total_limit) }}</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-4">
                            <div 
                                class="bg-indigo-600 h-4 rounded-full" 
                                :style="`width: ${storageStats.percentage_used}%`"
                            ></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ storageStats.percentage_used.toFixed(1) }}% utilisé
                        </p>
                    </div>

                    <button 
                        @click="cleanupStorage"
                        class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-900 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        Nettoyer le stockage
                    </button>
                </div>

                <!-- Storage by User -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Stockage par Utilisateur</h3>
                    
                    <div class="space-y-2">
                        <div 
                            v-for="user in userStorage" 
                            :key="user.id"
                            class="flex justify-between items-center py-2 border-b"
                        >
                            <div>
                                <div class="font-medium text-gray-900">{{ user.name }}</div>
                                <div class="text-sm text-gray-500">{{ user.email }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-gray-900">
                                    {{ formatBytes(user.documents_sum_size || 0) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Large Files -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Fichiers Volumineux</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Fichier
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Utilisateur
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Taille
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="file in largeFiles" :key="file.id">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ file.original_name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ file.user?.name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ formatBytes(file.size) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    storageStats: Object,
    userStorage: Array,
    typeStorage: Array,
    largeFiles: Array,
});

const formatBytes = (bytes) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const cleanupStorage = () => {
    if (confirm('Êtes-vous sûr de vouloir nettoyer le stockage ?')) {
        router.post(route('tenant.storage.cleanup'));
    }
};
</script>