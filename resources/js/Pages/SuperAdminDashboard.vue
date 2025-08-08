<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard Super Admin
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Tenants</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.tenants_count }}
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            <span class="text-green-600">{{ stats.active_tenants }} actifs</span>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Utilisateurs</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.total_users }}
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
                        <div class="text-sm font-medium text-gray-500">Tenants Suspendus</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.suspended_tenants }}
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Stockage Global</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">
                            {{ formatBytes(storage.used) }}
                        </div>
                        <div class="text-xs text-gray-500">
                            / {{ formatBytes(storage.limit) }}
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Recent Tenants -->
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Tenants Récents</h3>
                                <Link 
                                    :href="route('tenants.index')"
                                    class="text-sm text-indigo-600 hover:text-indigo-900"
                                >
                                    Voir tous →
                                </Link>
                            </div>
                            
                            <div class="space-y-3">
                                <div 
                                    v-for="tenant in recentTenants" 
                                    :key="tenant.id"
                                    class="flex justify-between items-center p-3 hover:bg-gray-50 rounded-lg"
                                >
                                    <div>
                                        <Link 
                                            :href="route('tenants.show', tenant.id)"
                                            class="font-medium text-gray-900 hover:text-indigo-600"
                                        >
                                            {{ tenant.name }}
                                        </Link>
                                        <div class="text-sm text-gray-500">
                                            {{ tenant.users_count }} utilisateurs · {{ tenant.documents_count }} documents
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span 
                                            :class="[
                                                'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                                                tenant.is_active 
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-red-100 text-red-800'
                                            ]"
                                        >
                                            {{ tenant.is_active ? 'Actif' : 'Suspendu' }}
                                        </span>
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ tenant.subscription_plan }}
                                        </div>
                                    </div>
                                </div>
                                
                                <div v-if="recentTenants.length === 0" class="text-center py-4 text-gray-500">
                                    Aucun tenant créé
                                </div>
                            </div>
                        </div>
                    </div>

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
                                        <span class="font-medium">{{ activity.user }}</span>
                                        <span class="text-gray-500"> · {{ activity.action }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ activity.tenant }} · {{ formatDate(activity.created_at) }}
                                    </div>
                                </div>
                                
                                <div v-if="recentActivity.length === 0" class="text-center py-4 text-gray-500">
                                    Aucune activité récente
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
                            :href="route('tenants.create')"
                            class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <PlusIcon class="w-4 h-4 mr-2" />
                            Nouveau Tenant
                        </Link>
                        
                        <Link
                            :href="route('tenants.index')"
                            class="inline-flex items-center justify-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <BuildingOfficeIcon class="w-4 h-4 mr-2" />
                            Gérer Tenants
                        </Link>
                        
                        <a
                            href="/horizon"
                            target="_blank"
                            class="inline-flex items-center justify-center px-4 py-2 bg-purple-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-purple-700 focus:bg-purple-700 active:bg-purple-900 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <ChartBarIcon class="w-4 h-4 mr-2" />
                            Laravel Horizon
                        </a>
                        
                        <button
                            @click="clearCache"
                            class="inline-flex items-center justify-center px-4 py-2 bg-orange-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-orange-700 focus:bg-orange-700 active:bg-orange-900 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <ArrowPathIcon class="w-4 h-4 mr-2" />
                            Vider Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
    PlusIcon, 
    BuildingOfficeIcon, 
    ChartBarIcon, 
    ArrowPathIcon 
} from '@heroicons/vue/24/outline';

const props = defineProps({
    stats: Object,
    recentTenants: Array,
    storage: Object,
    recentActivity: Array,
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

const clearCache = () => {
    if (confirm('Êtes-vous sûr de vouloir vider le cache ?')) {
        // You would need to create an API endpoint for this
        router.post('/api/clear-cache', {}, {
            onSuccess: () => {
                alert('Cache vidé avec succès');
            },
            onError: () => {
                alert('Erreur lors du vidage du cache');
            }
        });
    }
};
</script>