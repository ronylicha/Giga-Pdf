<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Modifier l'utilisateur
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">
                                Nom complet *
                            </label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                required
                            />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">
                                Adresse email *
                            </label>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                required
                            />
                            <InputError :message="form.errors.email" class="mt-2" />
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">
                                Nouveau mot de passe (laisser vide pour ne pas changer)
                            </label>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                                Confirmer le nouveau mot de passe
                            </label>
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError :message="form.errors.password_confirmation" class="mt-2" />
                        </div>

                        <div>
                            <label for="tenant_id" class="block text-sm font-medium text-gray-700">
                                Tenant
                            </label>
                            <select
                                id="tenant_id"
                                v-model="form.tenant_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                @change="onTenantChange"
                            >
                                <option value="">Sans tenant (Super Admin uniquement)</option>
                                <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                    {{ tenant.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.tenant_id" class="mt-2" />
                        </div>

                        <div>
                            <label for="role" class="block text-sm font-medium text-gray-700">
                                Rôle *
                            </label>
                            <select
                                id="role"
                                v-model="form.role"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                required
                            >
                                <option value="">Sélectionner un rôle</option>
                                <option v-for="role in filteredRoles" :key="role.name" :value="role.name">
                                    {{ role.display_name || formatRoleName(role.name) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.role" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-end space-x-3">
                            <Link
                                :href="route('super-admin.users.show', user.id)"
                                class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 focus:bg-gray-300 active:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                            >
                                Annuler
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Enregistrement...' : 'Enregistrer les modifications' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    user: Object,
    tenants: Array,
    roles: Array,
});

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    tenant_id: props.user.tenant_id || '',
    role: props.user.roles?.[0] || '',
});

// Filter roles based on tenant selection
const filteredRoles = computed(() => {
    if (!form.tenant_id) {
        // No tenant selected - only super-admin role
        return props.roles.filter(r => r.name === 'super-admin');
    } else {
        // Tenant selected - all roles except super-admin
        return props.roles.filter(r => r.name !== 'super-admin');
    }
});

const onTenantChange = () => {
    // Reset role if it's not compatible with the new tenant selection
    if (!form.tenant_id && form.role !== 'super-admin') {
        form.role = '';
    } else if (form.tenant_id && form.role === 'super-admin') {
        form.role = '';
    }
};

const formatRoleName = (role) => {
    return role.split('-').map(word => 
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
};

const submit = () => {
    form.put(route('super-admin.users.update', props.user.id), {
        onSuccess: () => {
            // Redirect handled by controller
        },
    });
};
</script>