<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Détails du Tenant: {{ tenant.name }}
                </h2>
                <div class="flex space-x-2">
                    <Link
                        :href="route('tenants.edit', tenant.id)"
                        class="inline-flex items-center px-4 py-2 bg-yellow-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-700 focus:bg-yellow-700 active:bg-yellow-900 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        <PencilIcon class="w-4 h-4 mr-2" />
                        Modifier
                    </Link>
                    <Link
                        :href="route('tenants.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        <ArrowLeftIcon class="w-4 h-4 mr-2" />
                        Retour
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 mb-1">Utilisateurs</div>
                        <div class="text-2xl font-bold text-gray-900">
                            {{ stats.users_count }} / {{ tenant.max_users }}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ Math.round((stats.users_count / tenant.max_users) * 100) }}% utilisé
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 mb-1">Stockage</div>
                        <div class="text-2xl font-bold text-gray-900">
                            {{ stats.storage_used.toFixed(2) }} GB
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            sur {{ tenant.max_storage_gb }} GB
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 mb-1">Documents</div>
                        <div class="text-2xl font-bold text-gray-900">
                            {{ stats.documents_count }}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ stats.conversions_count }} conversions
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 mb-1">Statut</div>
                        <div class="flex items-center">
                            <span v-if="tenant.is_active" class="px-3 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                                Actif
                            </span>
                            <span v-else class="px-3 py-1 text-sm font-semibold rounded-full bg-red-100 text-red-800">
                                Suspendu
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ tenant.subscription_plan }}
                        </div>
                    </div>
                </div>

                <!-- Tenant Information -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Informations Générales</h3>
                        
                        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Nom</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ tenant.name }}</dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Slug</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ tenant.slug }}</dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Domaine</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ tenant.domain || 'Non configuré' }}</dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Plan</dt>
                                <dd class="mt-1">
                                    <span :class="getPlanBadgeClass(tenant.subscription_plan)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                        {{ tenant.subscription_plan }}
                                    </span>
                                </dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Créé le</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ formatDate(tenant.created_at) }}</dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Dernière activité</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ stats.last_activity ? formatDate(stats.last_activity) : 'Aucune activité' }}
                                </dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Taille max fichier</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ tenant.max_file_size_mb }} MB</dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Expire le</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ tenant.subscription_expires_at ? formatDate(tenant.subscription_expires_at) : 'Pas d\'expiration' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Features -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Fonctionnalités Activées</h3>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div v-for="feature in tenant.features" :key="feature" class="flex items-center">
                                <CheckCircleIcon class="w-5 h-5 text-green-500 mr-2" />
                                <span class="text-sm text-gray-700">{{ formatFeature(feature) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users List -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Utilisateurs ({{ tenant.users.length }})</h3>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Nom
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Email
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Rôle
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Statut
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Créé le
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="user in tenant.users" :key="user.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ user.name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ user.email }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span v-for="role in user.roles" :key="role.id" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 mr-1">
                                                {{ role.name }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span v-if="user.is_active" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Actif
                                            </span>
                                            <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Inactif
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(user.created_at) }}
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
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
    ArrowLeftIcon, 
    PencilIcon, 
    CheckCircleIcon 
} from '@heroicons/vue/24/outline';

const props = defineProps({
    tenant: Object,
    stats: Object,
    plans: Object,
});

const getPlanBadgeClass = (plan) => {
    switch (plan) {
        case 'enterprise':
            return 'bg-purple-100 text-purple-800';
        case 'professional':
            return 'bg-blue-100 text-blue-800';
        case 'basic':
            return 'bg-gray-100 text-gray-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatFeature = (feature) => {
    return feature
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase());
};
</script>