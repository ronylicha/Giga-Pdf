<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des Tenants
                </h2>
                <Link :href="route('super-admin.tenants.create')" class="btn btn-primary">
                    <PlusIcon class="w-5 h-5 mr-2" />
                    Nouveau Tenant
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Total Tenants</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total_tenants }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Tenants Actifs</div>
                        <div class="text-2xl font-bold text-green-600 mt-1">{{ stats.active_tenants }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Total Utilisateurs</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total_users }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Total Documents</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total_documents }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Stockage Total</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ formatBytes(stats.total_storage) }}</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white overflow-hidden shadow-sm rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rechercher</label>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    placeholder="Nom, domaine, slug..."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    @input="debounceSearch"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                                <select
                                    v-model="filters.plan"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Tous les plans</option>
                                    <option v-for="(label, key) in plans" :key="key" :value="key">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                                <select
                                    v-model="filters.status"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Tous les statuts</option>
                                    <option value="active">Actif</option>
                                    <option value="expired">Expiré</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button
                                    @click="resetFilters"
                                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300"
                                >
                                    Réinitialiser
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tenants Table -->
                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tenant
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Plan
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Utilisateurs
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Documents
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Statut
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Créé le
                                    </th>
                                    <th class="relative px-6 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="tenant in tenants.data" :key="tenant.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ tenant.name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ tenant.domain || tenant.slug }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="getPlanBadgeClass(tenant.subscription_plan)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ plans[tenant.subscription_plan] || tenant.subscription_plan }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ tenant.users_count }} / {{ tenant.max_users === -1 ? '∞' : tenant.max_users }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ tenant.documents_count }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="tenant.is_suspended" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            Suspendu
                                        </span>
                                        <span v-else-if="isExpired(tenant)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Expiré
                                        </span>
                                        <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Actif
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ formatDate(tenant.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <Link
                                                :href="route('super-admin.tenants.show', tenant.id)"
                                                class="text-blue-600 hover:text-blue-900"
                                            >
                                                <EyeIcon class="w-5 h-5" />
                                            </Link>
                                            <Link
                                                :href="route('super-admin.tenants.edit', tenant.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                <PencilIcon class="w-5 h-5" />
                                            </Link>
                                            <button
                                                v-if="!tenant.is_suspended"
                                                @click="suspendTenant(tenant)"
                                                class="text-yellow-600 hover:text-yellow-900"
                                            >
                                                <PauseIcon class="w-5 h-5" />
                                            </button>
                                            <button
                                                v-else
                                                @click="reactivateTenant(tenant)"
                                                class="text-green-600 hover:text-green-900"
                                            >
                                                <PlayIcon class="w-5 h-5" />
                                            </button>
                                            <button
                                                @click="deleteTenant(tenant)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                <TrashIcon class="w-5 h-5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="tenants.links.length > 3" class="px-6 py-4 border-t">
                        <Pagination :links="tenants.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { 
    PlusIcon, 
    EyeIcon, 
    PencilIcon, 
    TrashIcon, 
    PauseIcon, 
    PlayIcon 
} from '@heroicons/vue/24/outline';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    tenants: Object,
    stats: Object,
    filters: Object,
    plans: Object,
});

const filters = ref({
    search: props.filters.search || '',
    plan: props.filters.plan || '',
    status: props.filters.status || '',
});

let searchTimeout = null;

const debounceSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
};

const applyFilters = () => {
    router.get(route('super-admin.tenants.index'), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filters.value = {
        search: '',
        plan: '',
        status: '',
    };
    applyFilters();
};

const suspendTenant = (tenant) => {
    if (confirm(`Êtes-vous sûr de vouloir suspendre ${tenant.name} ?`)) {
        router.post(route('super-admin.tenants.suspend', tenant.id), {
            reason: prompt('Raison de la suspension :')
        });
    }
};

const reactivateTenant = (tenant) => {
    if (confirm(`Êtes-vous sûr de vouloir réactiver ${tenant.name} ?`)) {
        router.post(route('super-admin.tenants.reactivate', tenant.id));
    }
};

const deleteTenant = (tenant) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${tenant.name} ? Cette action est irréversible.`)) {
        router.delete(route('super-admin.tenants.destroy', tenant.id));
    }
};

const formatDate = (date) => {
    return format(new Date(date), 'dd MMM yyyy', { locale: fr });
};

const formatBytes = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const isExpired = (tenant) => {
    if (!tenant.subscription_expires_at) return false;
    return new Date(tenant.subscription_expires_at) < new Date();
};

const getPlanBadgeClass = (plan) => {
    const classes = {
        'free': 'bg-gray-100 text-gray-800',
        'starter': 'bg-blue-100 text-blue-800',
        'professional': 'bg-purple-100 text-purple-800',
        'enterprise': 'bg-indigo-100 text-indigo-800',
    };
    return classes[plan] || 'bg-gray-100 text-gray-800';
};
</script>