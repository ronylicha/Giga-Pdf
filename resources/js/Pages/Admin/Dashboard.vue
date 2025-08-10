<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard Administration
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Utilisateurs</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.total_users }}
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            <span class="text-green-600">{{ stats.active_users_today }} actifs aujourd'hui</span>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Documents</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.total_documents }}
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Conversions</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.total_conversions }}
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Stockage</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">
                            {{ formatBytes(stats.storage_used) }}
                        </div>
                        <div class="text-xs text-gray-500">
                            / {{ formatBytes(stats.storage_limit) }}
                        </div>
                        <div class="mt-2">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div 
                                    class="bg-indigo-600 h-2 rounded-full" 
                                    :style="`width: ${(stats.storage_used / stats.storage_limit) * 100}%`"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Recent Activity -->
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Activité Récente</h3>
                            
                            <div class="space-y-2 max-h-96 overflow-y-auto">
                                <div 
                                    v-for="activity in recentActivity" 
                                    :key="activity.id"
                                    class="border-l-4 border-gray-200 pl-3 py-2"
                                >
                                    <div class="text-sm text-gray-900">
                                        <span class="font-medium">{{ activity.user?.name }}</span>
                                        <span class="text-gray-500"> · {{ activity.action }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ formatDate(activity.created_at) }}
                                    </div>
                                </div>
                                
                                <div v-if="recentActivity.length === 0" class="text-center py-4 text-gray-500">
                                    Aucune activité récente
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Users by Storage -->
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Utilisateurs par Stockage</h3>
                            
                            <div class="space-y-3">
                                <div 
                                    v-for="user in topUsers" 
                                    :key="user.id"
                                    class="flex justify-between items-center"
                                >
                                    <div>
                                        <div class="font-medium text-gray-900">{{ user.name }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ user.documents_count }} documents
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-medium text-gray-900">
                                            {{ formatBytes(user.documents_sum_size || 0) }}
                                        </div>
                                    </div>
                                </div>
                                
                                <div v-if="topUsers.length === 0" class="text-center py-4 text-gray-500">
                                    Aucun utilisateur
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="mt-6 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Actions Rapides</h3>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <Link
                            :href="route('tenant.users.index')"
                            class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            Gérer Utilisateurs
                        </Link>
                        
                        <Link
                            :href="route('tenant.activity')"
                            class="inline-flex items-center justify-center px-4 py-2 bg-purple-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-purple-700 focus:bg-purple-700 active:bg-purple-900 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            Journal d'Activité
                        </Link>
                        
                        <Link
                            :href="route('tenant.storage')"
                            class="inline-flex items-center justify-center px-4 py-2 bg-orange-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-orange-700 focus:bg-orange-700 active:bg-orange-900 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            Stockage
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    stats: Object,
    recentActivity: Array,
    topUsers: Array,
    tenant: Object,
});

const formatBytes = (bytes) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (date) => {
    const d = new Date(date);
    const now = new Date();
    const diff = now - d;
    
    if (diff < 60000) return 'À l\'instant';
    if (diff < 3600000) return `Il y a ${Math.floor(diff / 60000)} min`;
    if (diff < 86400000) return `Il y a ${Math.floor(diff / 3600000)}h`;
    
    return d.toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>