<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Modifier le Tenant: {{ tenant.name }}
                </h2>
                <Link
                    :href="route('tenants.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    <ArrowLeftIcon class="w-4 h-4 mr-2" />
                    Retour
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit">
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-6">Informations du Tenant</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Tenant Name -->
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700">
                                        Nom du Tenant <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="name"
                                        v-model="form.name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.name }}
                                    </p>
                                </div>

                                <!-- Domain -->
                                <div>
                                    <label for="domain" class="block text-sm font-medium text-gray-700">
                                        Domaine personnalisé
                                    </label>
                                    <input
                                        id="domain"
                                        v-model="form.domain"
                                        type="text"
                                        placeholder="exemple.com"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <p v-if="form.errors.domain" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.domain }}
                                    </p>
                                </div>

                                <!-- Status -->
                                <div>
                                    <label for="is_active" class="block text-sm font-medium text-gray-700">
                                        Statut <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="is_active"
                                        v-model="form.is_active"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option :value="true">Actif</option>
                                        <option :value="false">Suspendu</option>
                                    </select>
                                </div>

                                <!-- Subscription Plan -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        Plan d'abonnement <span class="text-red-500">*</span>
                                    </label>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div 
                                            v-for="(plan, key) in plans" 
                                            :key="key"
                                            @click="form.subscription_plan = key"
                                            :class="[
                                                'border-2 rounded-lg p-4 cursor-pointer transition-all',
                                                form.subscription_plan === key 
                                                    ? 'border-indigo-500 bg-indigo-50' 
                                                    : 'border-gray-200 hover:border-gray-300'
                                            ]"
                                        >
                                            <div class="flex justify-between items-start mb-2">
                                                <h4 class="font-semibold text-lg">{{ plan.name }}</h4>
                                                <span class="text-2xl font-bold text-indigo-600">
                                                    {{ plan.price }}€
                                                </span>
                                            </div>
                                            <ul class="space-y-1 text-sm text-gray-600">
                                                <li>• {{ plan.storage }} GB stockage</li>
                                                <li>• {{ plan.users === 999999 ? 'Utilisateurs illimités' : plan.users + ' utilisateurs' }}</li>
                                                <li>• Fichiers jusqu'à {{ plan.file_size }} MB</li>
                                            </ul>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.subscription_plan" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.subscription_plan }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-6">Limites Personnalisées (Optionnel)</h3>
                            <p class="text-sm text-gray-600 mb-4">
                                Laissez vide pour utiliser les valeurs par défaut du plan
                            </p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <!-- Max Storage -->
                                <div>
                                    <label for="max_storage_gb" class="block text-sm font-medium text-gray-700">
                                        Stockage Max (GB)
                                    </label>
                                    <input
                                        id="max_storage_gb"
                                        v-model="form.max_storage_gb"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <!-- Max Users -->
                                <div>
                                    <label for="max_users" class="block text-sm font-medium text-gray-700">
                                        Utilisateurs Max
                                    </label>
                                    <input
                                        id="max_users"
                                        v-model="form.max_users"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <!-- Max File Size -->
                                <div>
                                    <label for="max_file_size_mb" class="block text-sm font-medium text-gray-700">
                                        Taille Fichier Max (MB)
                                    </label>
                                    <input
                                        id="max_file_size_mb"
                                        v-model="form.max_file_size_mb"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-6 py-3 flex justify-between">
                            <div class="text-sm text-gray-500">
                                Créé le: {{ formatDate(tenant.created_at) }}
                            </div>
                            <div class="flex space-x-3">
                                <Link
                                    :href="route('tenants.show', tenant.id)"
                                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:text-gray-500 focus:outline-none focus:border-blue-300 focus:ring focus:ring-blue-200 active:text-gray-800 active:bg-gray-50 disabled:opacity-25 transition"
                                >
                                    Annuler
                                </Link>
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring focus:ring-indigo-300 disabled:opacity-25 transition"
                                >
                                    <span v-if="!form.processing">Enregistrer les Modifications</span>
                                    <span v-else>Enregistrement...</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Error message -->
                <div v-if="form.errors.error" class="mt-4 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg">
                    {{ form.errors.error }}
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    tenant: Object,
    plans: Object,
});

const form = useForm({
    name: props.tenant.name,
    domain: props.tenant.domain,
    subscription_plan: props.tenant.subscription_plan,
    is_active: props.tenant.is_active,
    max_storage_gb: props.tenant.max_storage_gb,
    max_users: props.tenant.max_users,
    max_file_size_mb: props.tenant.max_file_size_mb,
});

const submit = () => {
    form.put(route('tenants.update', props.tenant.id), {
        preserveScroll: true,
    });
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>