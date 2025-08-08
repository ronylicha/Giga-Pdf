<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Modifier le Rôle : {{ role.name }}
                </h2>
                <Link 
                    :href="route('roles.index')"
                    class="text-gray-600 hover:text-gray-900"
                >
                    <ArrowLeftIcon class="w-5 h-5" />
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <form @submit.prevent="updateRole" class="p-6">
                        <!-- Alert for System Role -->
                        <div v-if="isSystemRole" class="mb-6 p-4 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded">
                            <p class="font-medium">Rôle Système</p>
                            <p class="text-sm mt-1">
                                Les rôles système ont des restrictions. Vous pouvez uniquement modifier les permissions.
                            </p>
                        </div>

                        <!-- Basic Information -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Informations de Base</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                                        Nom du Rôle *
                                    </label>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        id="name"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        :disabled="isSystemRole"
                                        required
                                    />
                                    <div v-if="form.errors.name" class="text-red-600 text-sm mt-1">
                                        {{ form.errors.name }}
                                    </div>
                                </div>

                                <div>
                                    <label for="slug" class="block text-sm font-medium text-gray-700 mb-1">
                                        Slug (Identifiant unique)
                                    </label>
                                    <input
                                        :value="role.slug"
                                        type="text"
                                        id="slug"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100"
                                        disabled
                                    />
                                    <p class="text-xs text-gray-500 mt-1">
                                        Le slug ne peut pas être modifié
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                                    Description
                                </label>
                                <textarea
                                    v-model="form.description"
                                    id="description"
                                    rows="3"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    :disabled="isSystemRole"
                                />
                                <div v-if="form.errors.description" class="text-red-600 text-sm mt-1">
                                    {{ form.errors.description }}
                                </div>
                            </div>

                            <div class="mt-4">
                                <label for="level" class="block text-sm font-medium text-gray-700 mb-1">
                                    Niveau Hiérarchique *
                                </label>
                                <input
                                    v-model.number="form.level"
                                    type="number"
                                    id="level"
                                    min="0"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    :disabled="isSystemRole"
                                    required
                                />
                                <div v-if="form.errors.level" class="text-red-600 text-sm mt-1">
                                    {{ form.errors.level }}
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Plus le niveau est bas, plus le rôle a d'autorité
                                </p>
                            </div>
                        </div>

                        <!-- Permissions -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Permissions</h3>
                            
                            <div class="space-y-4">
                                <div v-for="(categoryPermissions, category) in permissions" :key="category">
                                    <div class="border rounded-lg p-4">
                                        <div class="flex items-center justify-between mb-3">
                                            <h4 class="font-medium text-gray-800 capitalize">
                                                {{ getCategoryLabel(category) }}
                                            </h4>
                                            <button
                                                type="button"
                                                @click="toggleCategory(category)"
                                                class="text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                {{ isCategorySelected(category) ? 'Désélectionner tout' : 'Sélectionner tout' }}
                                            </button>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                            <label 
                                                v-for="permission in categoryPermissions" 
                                                :key="permission.slug"
                                                class="flex items-start"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :value="permission.slug"
                                                    v-model="form.permissions"
                                                    class="mt-1 mr-2"
                                                />
                                                <div>
                                                    <div class="text-sm font-medium text-gray-700">
                                                        {{ permission.name }}
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        {{ permission.description }}
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="mt-6 flex justify-end space-x-3">
                            <Link
                                :href="route('roles.index')"
                                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                            >
                                Annuler
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Mise à jour...' : 'Mettre à jour' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    role: Object,
    permissions: Object,
    isSystemRole: Boolean,
});

const form = useForm({
    name: props.role.name,
    description: props.role.description || '',
    level: props.role.level,
    permissions: props.role.permissions || [],
});

function updateRole() {
    form.put(route('roles.update', props.role.id));
}

function getCategoryLabel(category) {
    const labels = {
        users: 'Utilisateurs',
        documents: 'Documents',
        tools: 'Outils PDF',
        settings: 'Paramètres',
        activity: 'Activité',
        storage: 'Stockage',
        invitations: 'Invitations',
        roles: 'Rôles',
        admin: 'Administration',
    };
    return labels[category] || category;
}

function toggleCategory(category) {
    const categoryPermissions = props.permissions[category];
    const categorySlugs = categoryPermissions.map(p => p.slug);
    
    if (isCategorySelected(category)) {
        form.permissions = form.permissions.filter(p => !categorySlugs.includes(p));
    } else {
        form.permissions = [...new Set([...form.permissions, ...categorySlugs])];
    }
}

function isCategorySelected(category) {
    const categoryPermissions = props.permissions[category];
    return categoryPermissions.every(p => form.permissions.includes(p.slug));
}
</script>