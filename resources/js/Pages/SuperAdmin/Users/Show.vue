<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Détails de l'utilisateur
                </h2>
                <div class="flex space-x-2">
                    <Link :href="route('super-admin.users.edit', user.id)" class="btn btn-primary">
                        <PencilIcon class="w-5 h-5 mr-2" />
                        Modifier
                    </Link>
                    <button 
                        @click="impersonateUser"
                        class="btn btn-secondary"
                        :disabled="user.id === $page.props.auth.user.id"
                    >
                        <UserIcon class="w-5 h-5 mr-2" />
                        Impersonner
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- User Info Card -->
                    <div class="lg:col-span-2">
                        <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Informations Utilisateur</h3>
                                
                                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Nom</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ user.name }}</dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Email</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ user.email }}</dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Tenant</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            <span v-if="user.tenant">{{ user.tenant.name }}</span>
                                            <span v-else class="text-gray-500 italic">Aucun (Super Admin)</span>
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Rôles</dt>
                                        <dd class="mt-1">
                                            <span v-for="role in user.roles" :key="role.id" 
                                                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mr-2">
                                                {{ role.display_name || role.name }}
                                            </span>
                                            <span v-if="!user.roles || user.roles.length === 0" class="text-gray-500 italic">
                                                Aucun rôle assigné
                                            </span>
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Email Vérifié</dt>
                                        <dd class="mt-1">
                                            <span v-if="user.email_verified_at" class="text-green-600 flex items-center">
                                                <CheckCircleIcon class="w-5 h-5 mr-1" />
                                                {{ formatDate(user.email_verified_at) }}
                                            </span>
                                            <button v-else @click="verifyEmail" class="text-yellow-600 hover:text-yellow-800">
                                                Non vérifié - Cliquer pour vérifier
                                            </button>
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">2FA</dt>
                                        <dd class="mt-1">
                                            <span v-if="user.is_2fa_enabled" class="text-green-600 flex items-center">
                                                <ShieldCheckIcon class="w-5 h-5 mr-1" />
                                                Activé
                                                <button @click="disable2FA" class="ml-2 text-red-600 hover:text-red-800 text-xs">
                                                    (Désactiver)
                                                </button>
                                            </span>
                                            <span v-else class="text-gray-500">
                                                Non activé
                                            </span>
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Inscrit le</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ formatDate(user.created_at) }}</dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Dernière connexion</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ user.last_login_at ? formatDate(user.last_login_at) : 'Jamais' }}
                                        </dd>
                                    </div>
                                </dl>
                                
                                <div class="mt-6 flex space-x-3">
                                    <button @click="resetPassword" class="btn btn-warning">
                                        Réinitialiser le mot de passe
                                    </button>
                                    <button @click="deleteUser" class="btn btn-danger">
                                        Supprimer l'utilisateur
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Card -->
                    <div>
                        <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Statistiques</h3>
                                
                                <dl class="space-y-4">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Documents</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                            {{ user.documents_count }}
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Conversions</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                            {{ user.conversions_count }}
                                        </dd>
                                    </div>
                                    
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Stockage utilisé</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                            {{ formatBytes(user.storage_usage) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Log -->
                <div class="mt-6 bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Dernières Activités</h3>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Action
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Détails
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="activity in activities" :key="activity.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ activity.description || activity.log_name || 'Action' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            {{ formatActivityDetails(activity.properties) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(activity.created_at) }}
                                        </td>
                                    </tr>
                                    <tr v-if="activities.length === 0">
                                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
                                            Aucune activité récente
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
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
    PencilIcon, 
    UserIcon,
    CheckCircleIcon,
    ShieldCheckIcon
} from '@heroicons/vue/24/outline';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const props = defineProps({
    user: Object,
    activities: Array,
});

const impersonateUser = () => {
    if (confirm(`Voulez-vous vous connecter en tant que ${props.user.name} ?`)) {
        router.post(route('super-admin.users.impersonate', props.user.id));
    }
};

const resetPassword = () => {
    if (confirm('Êtes-vous sûr de vouloir réinitialiser le mot de passe de cet utilisateur ?')) {
        router.post(route('super-admin.users.reset-password', props.user.id));
    }
};

const verifyEmail = () => {
    if (confirm('Marquer cet email comme vérifié ?')) {
        router.post(route('super-admin.users.verify-email', props.user.id));
    }
};

const disable2FA = () => {
    if (confirm('Êtes-vous sûr de vouloir désactiver le 2FA pour cet utilisateur ?')) {
        router.post(route('super-admin.users.disable-2fa', props.user.id));
    }
};

const deleteUser = () => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${props.user.name} ? Cette action est irréversible.`)) {
        router.delete(route('super-admin.users.destroy', props.user.id));
    }
};

const formatDate = (date) => {
    if (!date) return '';
    return format(new Date(date), 'dd MMM yyyy HH:mm', { locale: fr });
};

const formatBytes = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatActivityDetails = (properties) => {
    try {
        const data = typeof properties === 'string' ? JSON.parse(properties) : properties;
        return Object.entries(data).map(([key, value]) => `${key}: ${value}`).join(', ');
    } catch {
        return properties || '';
    }
};
</script>