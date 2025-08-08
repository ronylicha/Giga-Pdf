<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Créer un nouveau Tenant
                </h2>
                <Link :href="route('super-admin.tenants.index')" class="text-gray-600 hover:text-gray-900">
                    ← Retour à la liste
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Informations du Tenant -->
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Informations du Tenant</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Nom du Tenant <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        @input="generateSlug"
                                        required
                                    />
                                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.name }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Slug <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="form.slug"
                                        type="text"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        pattern="[a-z0-9-]+"
                                        required
                                    />
                                    <p class="mt-1 text-xs text-gray-500">
                                        Uniquement lettres minuscules, chiffres et tirets
                                    </p>
                                    <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.slug }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Domaine personnalisé
                                    </label>
                                    <input
                                        v-model="form.domain"
                                        type="text"
                                        placeholder="exemple.com"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.domain" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.domain }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Date d'expiration
                                    </label>
                                    <input
                                        v-model="form.subscription_expires_at"
                                        type="date"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.subscription_expires_at" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.subscription_expires_at }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Plan et Limites -->
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Plan et Limites</h3>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Plan d'abonnement <span class="text-red-500">*</span>
                                </label>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <div
                                        v-for="(plan, key) in plans"
                                        :key="key"
                                        @click="selectPlan(key)"
                                        :class="[
                                            'border-2 rounded-lg p-4 cursor-pointer transition-all',
                                            form.subscription_plan === key
                                                ? 'border-blue-500 bg-blue-50'
                                                : 'border-gray-200 hover:border-gray-300'
                                        ]"
                                    >
                                        <div class="font-medium text-gray-900">{{ plan.name }}</div>
                                        <div class="text-sm text-gray-500 mt-2">
                                            <div>{{ plan.max_users === -1 ? 'Illimité' : plan.max_users }} utilisateurs</div>
                                            <div>{{ plan.max_storage_gb }} GB stockage</div>
                                            <div>{{ plan.max_file_size_mb }} MB par fichier</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Utilisateurs max <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model.number="form.max_users"
                                        type="number"
                                        min="-1"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                    <p class="mt-1 text-xs text-gray-500">-1 pour illimité</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Stockage max (GB) <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model.number="form.max_storage_gb"
                                        type="number"
                                        min="0.1"
                                        step="0.1"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Taille fichier max (MB) <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model.number="form.max_file_size_mb"
                                        type="number"
                                        min="1"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Administrateur du Tenant -->
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Administrateur du Tenant</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Nom de l'administrateur <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="form.admin_name"
                                        type="text"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                    <p v-if="form.errors.admin_name" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.admin_name }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Email de l'administrateur <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="form.admin_email"
                                        type="email"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                    <p v-if="form.errors.admin_email" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.admin_email }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Mot de passe <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input
                                            v-model="form.admin_password"
                                            :type="showPassword ? 'text' : 'password'"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                            required
                                        />
                                        <button
                                            type="button"
                                            @click="showPassword = !showPassword"
                                            class="absolute right-2 top-2 text-gray-500 hover:text-gray-700"
                                        >
                                            <EyeIcon v-if="!showPassword" class="w-5 h-5" />
                                            <EyeSlashIcon v-else class="w-5 h-5" />
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        @click="generatePassword"
                                        class="mt-2 text-sm text-blue-600 hover:text-blue-800"
                                    >
                                        Générer un mot de passe
                                    </button>
                                    <p v-if="form.errors.admin_password" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.admin_password }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-4">
                        <Link
                            :href="route('super-admin.tenants.index')"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300"
                        >
                            Annuler
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                        >
                            <span v-if="form.processing">Création...</span>
                            <span v-else>Créer le Tenant</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    plans: Object,
});

const showPassword = ref(false);

const form = useForm({
    name: '',
    slug: '',
    domain: '',
    subscription_plan: 'free',
    max_users: 3,
    max_storage_gb: 1,
    max_file_size_mb: 10,
    subscription_expires_at: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

const generateSlug = () => {
    form.slug = form.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
};

const selectPlan = (planKey) => {
    form.subscription_plan = planKey;
    const plan = props.plans[planKey];
    form.max_users = plan.max_users;
    form.max_storage_gb = plan.max_storage_gb;
    form.max_file_size_mb = plan.max_file_size_mb;
};

const generatePassword = () => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < 16; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    form.admin_password = password;
    showPassword.value = true;
};

const submit = () => {
    form.post(route('super-admin.tenants.store'));
};
</script>