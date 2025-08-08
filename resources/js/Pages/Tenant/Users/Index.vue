<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Gestion des Utilisateurs
                </h2>
                <Link :href="route('tenant.users.create')" class="btn btn-primary">
                    <PlusIcon class="w-5 h-5 mr-2" />
                    Inviter un Utilisateur
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Total Utilisateurs</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">
                            {{ stats.total_users }} / {{ stats.max_users === -1 ? '∞' : stats.max_users }}
                        </div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Utilisateurs Actifs</div>
                        <div class="text-2xl font-bold text-green-600 mt-1">{{ stats.active_users }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Invitations en Attente</div>
                        <div class="text-2xl font-bold text-blue-600 mt-1">{{ stats.pending_invitations }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg p-6">
                        <div class="text-gray-500 text-sm">Places Disponibles</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">
                            {{ stats.max_users === -1 ? '∞' : Math.max(0, stats.max_users - stats.total_users) }}
                        </div>
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
                                    placeholder="Nom ou email..."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    @input="debounceSearch"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                                <select
                                    v-model="filters.role"
                                    @change="applyFilters"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Tous les rôles</option>
                                    <option v-for="(label, key) in roles" :key="key" :value="key">
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

                <!-- Invitations Tab -->
                <div v-if="invitations.length > 0" class="bg-white overflow-hidden shadow-sm rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Invitations en Attente</h3>
                        <div class="space-y-3">
                            <div
                                v-for="invitation in invitations"
                                :key="invitation.id"
                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg"
                            >
                                <div>
                                    <div class="font-medium text-gray-900">{{ invitation.email }}</div>
                                    <div class="text-sm text-gray-500">
                                        Rôle: {{ roles[invitation.role] }} • 
                                        Invité par {{ invitation.invitedBy?.name }} • 
                                        Expire le {{ formatDate(invitation.expires_at) }}
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button
                                        @click="resendInvitation(invitation)"
                                        class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                                    >
                                        Renvoyer
                                    </button>
                                    <button
                                        @click="cancelInvitation(invitation)"
                                        class="px-3 py-1 text-sm bg-red-600 text-white rounded hover:bg-red-700"
                                    >
                                        Annuler
                                    </button>
                                </div>
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
                                        Rôle
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Statut
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        2FA
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Dernière Connexion
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
                                <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <span class="text-gray-600 font-medium">
                                                        {{ user.name.charAt(0).toUpperCase() }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ user.name }}
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    {{ user.email }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="getRoleBadgeClass(user.roles[0]?.name)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ roles[user.roles[0]?.name] || user.roles[0]?.name }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="user.email_verified_at" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Actif
                                        </span>
                                        <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Non vérifié
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <ShieldCheckIcon v-if="user.two_factor_secret" class="w-5 h-5 text-green-600" />
                                        <ShieldExclamationIcon v-else class="w-5 h-5 text-gray-400" />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ user.last_login_at ? formatDate(user.last_login_at) : 'Jamais' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <Link
                                                :href="route('tenant.users.show', user.id)"
                                                class="text-blue-600 hover:text-blue-900"
                                            >
                                                <EyeIcon class="w-5 h-5" />
                                            </Link>
                                            <Link
                                                :href="route('tenant.users.edit', user.id)"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                <PencilIcon class="w-5 h-5" />
                                            </Link>
                                            <button
                                                @click="resetPassword(user)"
                                                class="text-yellow-600 hover:text-yellow-900"
                                            >
                                                <KeyIcon class="w-5 h-5" />
                                            </button>
                                            <button
                                                @click="deleteUser(user)"
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
    KeyIcon,
    ShieldCheckIcon,
    ShieldExclamationIcon
} from '@heroicons/vue/24/outline';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    users: Object,
    invitations: Array,
    stats: Object,
    filters: Object,
    roles: Object,
});

const filters = ref({
    search: props.filters.search || '',
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
    router.get(route('tenant.users.index'), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filters.value = {
        search: '',
        role: '',
        status: '',
    };
    applyFilters();
};

const resendInvitation = (invitation) => {
    router.post(route('tenant.invitations.resend', invitation.id));
};

const cancelInvitation = (invitation) => {
    if (confirm(`Êtes-vous sûr de vouloir annuler l'invitation pour ${invitation.email} ?`)) {
        router.delete(route('tenant.invitations.cancel', invitation.id));
    }
};

const resetPassword = (user) => {
    if (confirm(`Êtes-vous sûr de vouloir réinitialiser le mot de passe de ${user.name} ?`)) {
        router.post(route('tenant.users.reset-password', user.id));
    }
};

const deleteUser = (user) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${user.name} ? Cette action est irréversible.`)) {
        router.delete(route('tenant.users.destroy', user.id));
    }
};

const formatDate = (date) => {
    if (!date) return '';
    return format(new Date(date), 'dd MMM yyyy', { locale: fr });
};

const getRoleBadgeClass = (role) => {
    const classes = {
        'user': 'bg-gray-100 text-gray-800',
        'editor': 'bg-blue-100 text-blue-800',
        'manager': 'bg-purple-100 text-purple-800',
        'tenant_admin': 'bg-indigo-100 text-indigo-800',
    };
    return classes[role] || 'bg-gray-100 text-gray-800';
};
</script>