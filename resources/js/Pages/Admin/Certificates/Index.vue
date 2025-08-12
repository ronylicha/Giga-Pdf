<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ShieldCheckIcon, PlusIcon, KeyIcon, TrashIcon, CheckBadgeIcon, XCircleIcon, ClockIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    certificates: Array
});

const showCreateModal = ref(false);
const showDeleteModal = ref(false);
const certificateToDelete = ref(null);
const activeTab = ref('list');

const form = useForm({
    name: '',
    description: '',
    type: 'self_signed',
    certificate_file: null,
    private_key_file: null,
    password: '',
    is_default: false,
    // Self-signed fields
    key_size: '2048',
    common_name: '',
    organization: '',
    organizational_unit: '',
    country: 'FR',
    state: '',
    locality: '',
    email: '',
    validity_years: 1
});

const activeCertificates = computed(() => {
    return props.certificates.filter(cert => cert.is_active && !isExpired(cert));
});

const expiredCertificates = computed(() => {
    return props.certificates.filter(cert => isExpired(cert));
});

const isExpired = (cert) => {
    return new Date(cert.valid_to) < new Date();
};

const isExpiringSoon = (cert) => {
    const daysUntil = Math.floor((new Date(cert.valid_to) - new Date()) / (1000 * 60 * 60 * 24));
    return daysUntil > 0 && daysUntil <= 30;
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const getDaysUntilExpiration = (cert) => {
    const days = Math.floor((new Date(cert.valid_to) - new Date()) / (1000 * 60 * 60 * 24));
    if (days < 0) return 'Expiré';
    if (days === 0) return "Expire aujourd'hui";
    if (days === 1) return '1 jour';
    return `${days} jours`;
};

const openCreateModal = () => {
    form.reset();
    showCreateModal.value = true;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    form.reset();
};

const handleFileUpload = (event, field) => {
    form[field] = event.target.files[0];
};

const createCertificate = () => {
    form.post(route('admin.certificates.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
            form.reset();
        }
    });
};

const setAsDefault = (certificate) => {
    router.post(route('admin.certificates.set-default', certificate.id), {}, {
        preserveScroll: true
    });
};

const toggleStatus = (certificate) => {
    router.post(route('admin.certificates.toggle', certificate.id), {}, {
        preserveScroll: true
    });
};

const confirmDelete = (certificate) => {
    certificateToDelete.value = certificate;
    showDeleteModal.value = true;
};

const deleteCertificate = () => {
    if (certificateToDelete.value) {
        router.delete(route('admin.certificates.destroy', certificateToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showDeleteModal.value = false;
                certificateToDelete.value = null;
            }
        });
    }
};

const getStatusBadge = (cert) => {
    if (isExpired(cert)) {
        return { color: 'red', text: 'Expiré' };
    }
    if (!cert.is_active) {
        return { color: 'gray', text: 'Inactif' };
    }
    if (isExpiringSoon(cert)) {
        return { color: 'yellow', text: 'Expire bientôt' };
    }
    return { color: 'green', text: 'Actif' };
};
</script>

<template>
    <Head title="Gestion des Certificats" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Gestion des Certificats
                </h2>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                    <PlusIcon class="h-4 w-4 mr-2" />
                    Nouveau Certificat
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Info Box -->
                <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <div class="flex">
                        <ShieldCheckIcon class="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5" />
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">
                                Gestion des certificats numériques
                            </h3>
                            <div class="mt-2 text-sm text-blue-700 dark:text-blue-300">
                                <p>Les certificats sont utilisés pour signer numériquement les documents PDF.</p>
                                <p class="mt-1">Vous pouvez créer des certificats auto-signés ou importer vos propres certificats.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Certificates List -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <!-- Empty State -->
                        <div v-if="!certificates || certificates.length === 0" class="text-center py-12">
                            <ShieldCheckIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Aucun certificat
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Commencez par créer ou importer un certificat.
                            </p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateModal"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                                >
                                    <PlusIcon class="h-4 w-4 mr-2" />
                                    Créer un certificat
                                </button>
                            </div>
                        </div>

                        <!-- Certificates Grid -->
                        <div v-else class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="cert in certificates"
                                :key="cert.id"
                                class="relative bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-6 hover:shadow-lg transition-shadow"
                            >
                                <!-- Default Badge -->
                                <div v-if="cert.is_default" class="absolute top-2 right-2">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        Par défaut
                                    </span>
                                </div>

                                <!-- Certificate Icon -->
                                <div class="flex items-center mb-4">
                                    <div class="flex-shrink-0">
                                        <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
                                            <KeyIcon class="h-6 w-6 text-gray-600 dark:text-gray-400" />
                                        </div>
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                            {{ cert.name }}
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ cert.type === 'self_signed' ? 'Auto-signé' : 'Importé' }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Certificate Details -->
                                <div class="space-y-2 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">CN:</span>
                                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ cert.common_name }}</span>
                                    </div>
                                    <div v-if="cert.organization">
                                        <span class="text-gray-500 dark:text-gray-400">Organisation:</span>
                                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ cert.organization }}</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Validité:</span>
                                        <span class="ml-2 text-gray-900 dark:text-gray-100">
                                            {{ formatDate(cert.valid_from) }} - {{ formatDate(cert.valid_to) }}
                                        </span>
                                    </div>
                                    <div v-if="cert.key_size">
                                        <span class="text-gray-500 dark:text-gray-400">Taille de clé:</span>
                                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ cert.key_size }} bits</span>
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="flex items-center">
                                        <span
                                            :class="[
                                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                                getStatusBadge(cert).color === 'green' ? 'bg-green-100 text-green-800' :
                                                getStatusBadge(cert).color === 'yellow' ? 'bg-yellow-100 text-yellow-800' :
                                                getStatusBadge(cert).color === 'red' ? 'bg-red-100 text-red-800' :
                                                'bg-gray-100 text-gray-800'
                                            ]"
                                        >
                                            <span class="mr-1">
                                                <CheckBadgeIcon v-if="getStatusBadge(cert).color === 'green'" class="h-3 w-3" />
                                                <ClockIcon v-else-if="getStatusBadge(cert).color === 'yellow'" class="h-3 w-3" />
                                                <XCircleIcon v-else class="h-3 w-3" />
                                            </span>
                                            {{ getStatusBadge(cert).text }}
                                        </span>
                                        <span v-if="!isExpired(cert)" class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                            {{ getDaysUntilExpiration(cert) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="mt-4 flex items-center space-x-2">
                                    <button
                                        v-if="!cert.is_default && cert.is_active && !isExpired(cert)"
                                        @click="setAsDefault(cert)"
                                        class="text-xs text-blue-600 hover:text-blue-700 font-medium"
                                    >
                                        Définir par défaut
                                    </button>
                                    <button
                                        v-if="!isExpired(cert)"
                                        @click="toggleStatus(cert)"
                                        class="text-xs text-gray-600 hover:text-gray-700 font-medium"
                                    >
                                        {{ cert.is_active ? 'Désactiver' : 'Activer' }}
                                    </button>
                                    <button
                                        @click="confirmDelete(cert)"
                                        class="text-xs text-red-600 hover:text-red-700 font-medium"
                                    >
                                        Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Certificate Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-50">
            <div class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                        <div class="bg-white dark:bg-gray-800 px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <ShieldCheckIcon class="h-6 w-6 text-blue-600" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                                    <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-gray-100">
                                        Nouveau Certificat
                                    </h3>
                                    
                                    <!-- Tabs -->
                                    <div class="mt-4 border-b border-gray-200 dark:border-gray-700">
                                        <nav class="-mb-px flex space-x-8">
                                            <button
                                                @click="form.type = 'self_signed'"
                                                :class="[
                                                    'py-2 px-1 border-b-2 font-medium text-sm',
                                                    form.type === 'self_signed'
                                                        ? 'border-blue-500 text-blue-600'
                                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                ]"
                                            >
                                                Auto-signé
                                            </button>
                                            <button
                                                @click="form.type = 'imported'"
                                                :class="[
                                                    'py-2 px-1 border-b-2 font-medium text-sm',
                                                    form.type === 'imported'
                                                        ? 'border-blue-500 text-blue-600'
                                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                ]"
                                            >
                                                Importer
                                            </button>
                                        </nav>
                                    </div>

                                    <div class="mt-4 space-y-4">
                                        <!-- Common Fields -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Nom du certificat *
                                            </label>
                                            <input
                                                v-model="form.name"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                placeholder="Mon Certificat"
                                            />
                                            <div v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                                {{ form.errors.name }}
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Description
                                            </label>
                                            <textarea
                                                v-model="form.description"
                                                rows="2"
                                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                placeholder="Description optionnelle"
                                            />
                                        </div>

                                        <!-- Self-signed Fields -->
                                        <div v-if="form.type === 'self_signed'" class="space-y-4">
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Taille de clé *
                                                    </label>
                                                    <select
                                                        v-model="form.key_size"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                    >
                                                        <option value="1024">1024 bits</option>
                                                        <option value="2048">2048 bits (Recommandé)</option>
                                                        <option value="4096">4096 bits</option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Validité (années) *
                                                    </label>
                                                    <input
                                                        v-model.number="form.validity_years"
                                                        type="number"
                                                        min="1"
                                                        max="10"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                    />
                                                </div>
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Nom commun (CN) *
                                                </label>
                                                <input
                                                    v-model="form.common_name"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                    placeholder="exemple.com ou Nom de la personne"
                                                />
                                            </div>

                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Organisation
                                                    </label>
                                                    <input
                                                        v-model="form.organization"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                        placeholder="Mon Entreprise"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Unité organisationnelle
                                                    </label>
                                                    <input
                                                        v-model="form.organizational_unit"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                        placeholder="IT"
                                                    />
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-3 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Pays (2 lettres)
                                                    </label>
                                                    <input
                                                        v-model="form.country"
                                                        type="text"
                                                        maxlength="2"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                        placeholder="FR"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        État/Province
                                                    </label>
                                                    <input
                                                        v-model="form.state"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                        placeholder="Île-de-France"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Ville
                                                    </label>
                                                    <input
                                                        v-model="form.locality"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                        placeholder="Paris"
                                                    />
                                                </div>
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Email
                                                </label>
                                                <input
                                                    v-model="form.email"
                                                    type="email"
                                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                    placeholder="admin@exemple.com"
                                                />
                                            </div>
                                        </div>

                                        <!-- Import Fields -->
                                        <div v-else class="space-y-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Fichier certificat (.crt, .pem, .p12) *
                                                </label>
                                                <input
                                                    @change="handleFileUpload($event, 'certificate_file')"
                                                    type="file"
                                                    accept=".crt,.pem,.p12"
                                                    class="mt-1 block w-full text-sm text-gray-900 dark:text-gray-100 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                                />
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Fichier clé privée (.key) *
                                                </label>
                                                <input
                                                    @change="handleFileUpload($event, 'private_key_file')"
                                                    type="file"
                                                    accept=".key"
                                                    class="mt-1 block w-full text-sm text-gray-900 dark:text-gray-100 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                                />
                                            </div>
                                        </div>

                                        <!-- Password Field -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Mot de passe de la clé privée *
                                            </label>
                                            <input
                                                v-model="form.password"
                                                type="password"
                                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                                placeholder="Mot de passe sécurisé"
                                            />
                                            <p class="mt-1 text-xs text-gray-500">
                                                Ce mot de passe protégera la clé privée
                                            </p>
                                        </div>

                                        <!-- Default Checkbox -->
                                        <div class="flex items-center">
                                            <input
                                                v-model="form.is_default"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            />
                                            <label class="ml-2 block text-sm text-gray-900 dark:text-gray-100">
                                                Définir comme certificat par défaut
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-gray-900 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                @click="createCertificate"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {{ form.processing ? 'Création...' : 'Créer' }}
                            </button>
                            <button
                                @click="closeCreateModal"
                                :disabled="form.processing"
                                class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-gray-800 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 sm:mt-0 sm:w-auto"
                            >
                                Annuler
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-50">
            <div class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                        <div class="bg-white dark:bg-gray-800 px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <TrashIcon class="h-6 w-6 text-red-600" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-gray-100">
                                        Supprimer le certificat
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Êtes-vous sûr de vouloir supprimer le certificat "{{ certificateToDelete?.name }}" ?
                                            Cette action est irréversible.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-900 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                @click="deleteCertificate"
                                class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:ml-3 sm:w-auto"
                            >
                                Supprimer
                            </button>
                            <button
                                @click="showDeleteModal = false"
                                class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-gray-800 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 sm:mt-0 sm:w-auto"
                            >
                                Annuler
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>