<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    tenant: Object,
});

const form = useForm({
    max_storage_gb: props.tenant.max_storage_gb,
    max_users: props.tenant.max_users,
    max_file_size_mb: props.tenant.max_file_size_mb,
});

const currentUsage = ref({
    users: props.tenant.users_count || 0,
    storage_gb: ((props.tenant.storage_used || 0) / (1024 * 1024 * 1024)).toFixed(2),
});

const submit = () => {
    form.patch(route('super-admin.tenants.update-limits', props.tenant.id), {
        preserveScroll: true,
        onSuccess: () => {
            // Redirect back to tenant details
        },
    });
};
</script>

<template>
    <Head :title="`Modifier les limites - ${tenant.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Modifier les limites du tenant : {{ tenant.name }}
                </h2>
                <Link :href="route('super-admin.tenants.show', tenant.id)" 
                      class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                    ← Retour aux détails
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        
                        <!-- Utilisation actuelle -->
                        <div class="mb-8 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <h3 class="text-lg font-semibold mb-3 text-blue-900 dark:text-blue-200">
                                Utilisation actuelle
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <p class="text-sm text-blue-700 dark:text-blue-300">Utilisateurs</p>
                                    <p class="text-2xl font-bold text-blue-900 dark:text-blue-100">
                                        {{ currentUsage.users }} / {{ tenant.max_users }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm text-blue-700 dark:text-blue-300">Stockage</p>
                                    <p class="text-2xl font-bold text-blue-900 dark:text-blue-100">
                                        {{ currentUsage.storage_gb }} GB / {{ tenant.max_storage_gb }} GB
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm text-blue-700 dark:text-blue-300">Taille max fichier</p>
                                    <p class="text-2xl font-bold text-blue-900 dark:text-blue-100">
                                        {{ tenant.max_file_size_mb }} MB
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Formulaire de modification -->
                        <form @submit.prevent="submit" class="space-y-6">
                            
                            <!-- Limite de stockage -->
                            <div>
                                <InputLabel for="max_storage_gb" value="Limite de stockage (GB)" />
                                <TextInput
                                    id="max_storage_gb"
                                    type="number"
                                    step="0.1"
                                    min="0.1"
                                    v-model="form.max_storage_gb"
                                    class="mt-1 block w-full"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.max_storage_gb" />
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Stockage maximum autorisé pour ce tenant en gigaoctets.
                                    <span v-if="currentUsage.storage_gb > form.max_storage_gb" class="text-red-600 dark:text-red-400">
                                        ⚠️ Attention : l'utilisation actuelle ({{ currentUsage.storage_gb }} GB) dépasse cette limite.
                                    </span>
                                </p>
                            </div>

                            <!-- Limite d'utilisateurs -->
                            <div>
                                <InputLabel for="max_users" value="Limite d'utilisateurs" />
                                <TextInput
                                    id="max_users"
                                    type="number"
                                    min="1"
                                    v-model="form.max_users"
                                    class="mt-1 block w-full"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.max_users" />
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Nombre maximum d'utilisateurs autorisés pour ce tenant.
                                    <span v-if="currentUsage.users > form.max_users" class="text-red-600 dark:text-red-400">
                                        ⚠️ Attention : le nombre actuel d'utilisateurs ({{ currentUsage.users }}) dépasse cette limite.
                                    </span>
                                </p>
                            </div>

                            <!-- Taille max par fichier -->
                            <div>
                                <InputLabel for="max_file_size_mb" value="Taille maximale par fichier (MB)" />
                                <TextInput
                                    id="max_file_size_mb"
                                    type="number"
                                    min="1"
                                    v-model="form.max_file_size_mb"
                                    class="mt-1 block w-full"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.max_file_size_mb" />
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Taille maximale autorisée pour un fichier unique en mégaoctets.
                                </p>
                            </div>

                            <!-- Valeurs par défaut suggérées -->
                            <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    Valeurs par défaut recommandées
                                </h4>
                                <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <p>• Stockage : 1 GB</p>
                                    <p>• Utilisateurs : 5</p>
                                    <p>• Taille max fichier : 25 MB</p>
                                </div>
                                <button
                                    type="button"
                                    @click="form.max_storage_gb = 1; form.max_users = 5; form.max_file_size_mb = 25;"
                                    class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300"
                                >
                                    Appliquer les valeurs par défaut
                                </button>
                            </div>

                            <!-- Boutons d'action -->
                            <div class="flex items-center justify-end gap-4">
                                <Link
                                    :href="route('super-admin.tenants.show', tenant.id)"
                                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150"
                                >
                                    Annuler
                                </Link>
                                <PrimaryButton :disabled="form.processing">
                                    {{ form.processing ? 'Enregistrement...' : 'Enregistrer les modifications' }}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Note d'information -->
                <div class="mt-6 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                Important
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                <ul class="list-disc pl-5 space-y-1">
                                    <li>Les modifications de limites sont appliquées immédiatement</li>
                                    <li>Réduire les limites en dessous de l'utilisation actuelle empêchera les nouvelles créations mais n'affectera pas les données existantes</li>
                                    <li>Les utilisateurs seront notifiés si les limites changent significativement</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>