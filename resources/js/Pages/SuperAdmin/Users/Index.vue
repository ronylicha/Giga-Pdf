<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des Utilisateurs
                </h2>
                <Link :href="route('super-admin.users.create')" class="btn btn-primary">
                    <PlusIcon class="w-5 h-5 mr-2" />
                    Nouvel Utilisateur
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Total Utilisateurs</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total_users }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Super Admins</div>
                        <div class="text-2xl font-bold text-purple-600 mt-1">{{ stats.super_admins }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Tenant Admins</div>
                        <div class="text-2xl font-bold text-blue-600 mt-1">{{ stats.tenant_admins }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Vérifiés</div>
                        <div class="text-2xl font-bold text-green-600 mt-1">{{ stats.verified_users }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Avec 2FA</div>
                        <div class="text-2xl font-bold text-indigo-600 mt-1">{{ stats.users_with_2fa }}</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white overflow-hidden shadow-sm rounded-lg mb-6">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rechercher</label>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    placeholder="Nom, email..."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    @input="debounceSearch"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tenant</label>
                                <select
                                    v-model="filters.tenant_id"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Tous les tenants</option>
                                    <option value="null">Sans tenant (Super Admin)</option>
                                    <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                        {{ tenant.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                                <select
                                    v-model="filters.role"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Tous les rôles</option>
                                    <option v-for="role in roles" :key="role" :value="role">
                                        {{ formatRole(role) }}
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
                                    <option value="active">Actif (vérifié)</option>
                                    <option value="inactive">Inactif</option>
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

                <!-- Users Table -->
                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Utilisateur
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tenant
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Rôle
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Statut
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        2FA
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Inscrit le
                                    </th>
                                    <th class="relative px-6 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ user.name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ user.email }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="user.tenant" class="text-sm text-gray-900">
                                            {{ user.tenant.name }}
                                        </span>
                                        <span v-else class="text-sm text-gray-500 italic">
                                            Aucun (Super Admin)
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="user.role_display" :class="getRoleBadgeClass(user.role || user.roles[0])" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ user.role_display }}
                                        </span>
                                        <span v-else class="text-gray-500 italic text-sm">
                                            Aucun rôle
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="user.email_verified_at" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Vérifié
                                        </span>
                                        <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Non vérifié
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <ShieldCheckIcon v-if="user.is_2fa_enabled" class="w-5 h-5 text-green-600" />
                                        <ShieldExclamationIcon v-else class="w-5 h-5 text-gray-400" />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <Link
                                                :href="route('super-admin.users.show', user.id)"
                                                class="text-blue-600 hover:text-blue-900"
                                                title="Voir"
                                            >
                                                <EyeIcon class="w-5 h-5" />
                                            </Link>
                                            <Link
                                                :href="route('super-admin.users.edit', user.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                                title="Modifier"
                                            >
                                                <PencilIcon class="w-5 h-5" />
                                            </Link>
                                            <button
                                                @click="impersonateUser(user)"
                                                class="text-purple-600 hover:text-purple-900"
                                                title="Se connecter en tant que"
                                                :disabled="user.id === $page.props.auth.user.id"
                                            >
                                                <UserIcon class="w-5 h-5" />
                                            </button>
                                            <button
                                                @click="deleteUser(user)"
                                                class="text-red-600 hover:text-red-900"
                                                title="Supprimer"
                                                :disabled="user.id === $page.props.auth.user.id"
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
                    <div v-if="users.links.length > 3" class="px-6 py-4 border-t">
                        <Pagination :links="users.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { 
    PlusIcon, 
    EyeIcon, 
    PencilIcon, 
    TrashIcon, 
    UserIcon,
    ShieldCheckIcon,
    ShieldExclamationIcon
} from '@heroicons/vue/24/outline';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    users: Object,
    stats: Object,
    tenants: Array,
    roles: Array,
    filters: Object,
});

const filters = ref({
    search: props.filters.search || '',
    tenant_id: props.filters.tenant_id || '',
    role: props.filters.role || '',
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
    router.get(route('super-admin.users.index'), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filters.value = {
        search: '',
        tenant_id: '',
        role: '',
        status: '',
    };
    applyFilters();
};

const impersonateUser = (user) => {
    if (user.id === props.$page.props.auth.user.id) {
        alert('Vous ne pouvez pas vous impersonner vous-même');
        return;
    }
    
    if (confirm(`Voulez-vous vous connecter en tant que ${user.name} ?`)) {
        router.post(route('super-admin.users.impersonate', user.id));
    }
};

const deleteUser = (user) => {
    if (user.id === props.$page.props.auth.user.id) {
        alert('Vous ne pouvez pas supprimer votre propre compte');
        return;
    }
    
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${user.name} ? Cette action est irréversible.`)) {
        router.delete(route('super-admin.users.destroy', user.id));
    }
};

const formatDate = (date) => {
    return format(new Date(date), 'dd MMM yyyy', { locale: fr });
};

const formatRole = (role) => {
    return role.split('-').map(word => 
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
};

const getRoleBadgeClass = (role) => {
    const classes = {
        'super-admin': 'bg-purple-100 text-purple-800',
        'tenant-admin': 'bg-blue-100 text-blue-800',
        'manager': 'bg-indigo-100 text-indigo-800',
        'editor': 'bg-green-100 text-green-800',
        'viewer': 'bg-gray-100 text-gray-800',
    };
    return classes[role] || 'bg-gray-100 text-gray-800';
};
</script>