<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LockOpenIcon, DocumentArrowDownIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    title: String,
    documents: Array
});

const selectedDocument = ref(null);
const isProcessing = ref(false);
const showPasswordModal = ref(false);
const error = ref('');
const success = ref('');

const form = useForm({
    document_id: null,
    password: '',
    force_remove: false
});

const selectedDocumentDetails = computed(() => {
    if (!selectedDocument.value) return null;
    return props.documents.find(doc => doc.id === selectedDocument.value);
});

const checkDocument = () => {
    if (!selectedDocument.value) {
        error.value = 'Veuillez sélectionner un document';
        return;
    }
    
    const doc = selectedDocumentDetails.value;
    
    // Check if document is already unlocked
    if (doc.metadata && doc.metadata.password_removed) {
        error.value = 'Ce document est déjà déverrouillé';
        return;
    }
    
    // Show password modal
    showPasswordModal.value = true;
    form.document_id = selectedDocument.value;
    error.value = '';
};

const removePassword = () => {
    if (!form.password && !form.force_remove) {
        error.value = 'Veuillez entrer le mot de passe du document ou activer la suppression forcée';
        return;
    }
    
    isProcessing.value = true;
    error.value = '';
    
    form.post(route('documents.decrypt'), {
        preserveScroll: true,
        onSuccess: () => {
            success.value = 'Le mot de passe a été supprimé avec succès. Le document déverrouillé a été créé.';
            showPasswordModal.value = false;
            form.reset();
            selectedDocument.value = null;
            isProcessing.value = false;
        },
        onError: (errors) => {
            error.value = errors.password || errors.error || 'Une erreur est survenue';
            isProcessing.value = false;
        }
    });
};

const closeModal = () => {
    showPasswordModal.value = false;
    form.reset('password');
    form.reset('force_remove');
    error.value = '';
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ title }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Success Message -->
                <div v-if="success" class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm">{{ success }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <!-- Tool Description -->
                        <div class="mb-8">
                            <div class="flex items-center mb-4">
                                <LockOpenIcon class="h-8 w-8 text-blue-500 mr-3" />
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Supprimer le mot de passe d'un PDF
                                    </h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        Déverrouillez vos documents PDF protégés par mot de passe
                                    </p>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex">
                                    <ExclamationTriangleIcon class="h-5 w-5 text-yellow-600 mt-0.5" />
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800">
                                            Information importante
                                        </h3>
                                        <div class="mt-2 text-sm text-yellow-700">
                                            <ul class="list-disc list-inside space-y-1">
                                                <li>Un nouveau document sans mot de passe sera créé</li>
                                                <li>Le document original restera inchangé</li>
                                                <li>La suppression forcée peut fonctionner sans mot de passe pour certains PDFs</li>
                                                <li>Cette action est irréversible pour le nouveau document</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Document Selection -->
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Sélectionner un document PDF protégé
                                </label>
                                
                                <div v-if="documents.length === 0" class="text-center py-8 bg-gray-50 dark:bg-gray-900 rounded-lg">
                                    <DocumentArrowDownIcon class="mx-auto h-12 w-12 text-gray-400" />
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                        Aucun document PDF disponible
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                        Téléchargez d'abord des documents PDF
                                    </p>
                                </div>
                                
                                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div
                                        v-for="doc in documents"
                                        :key="doc.id"
                                        @click="selectedDocument = doc.id"
                                        :class="[
                                            'relative p-4 border-2 rounded-lg cursor-pointer transition-all',
                                            selectedDocument === doc.id
                                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                                                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'
                                        ]"
                                    >
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0">
                                                <svg class="h-8 w-8 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M4 4a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-5L9 2H4z" />
                                                </svg>
                                            </div>
                                            <div class="ml-3 flex-1">
                                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                                    {{ doc.original_name }}
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ formatFileSize(doc.size) }}
                                                </p>
                                                <div v-if="doc.metadata && doc.metadata.password_removed" class="mt-1">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                        Déjà déverrouillé
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div v-if="selectedDocument === doc.id" class="absolute top-2 right-2">
                                            <svg class="h-5 w-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Error Message -->
                            <div v-if="error && !showPasswordModal" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                                <p class="text-sm">{{ error }}</p>
                            </div>

                            <!-- Action Button -->
                            <div class="flex justify-end">
                                <button
                                    @click="checkDocument"
                                    :disabled="!selectedDocument || isProcessing"
                                    :class="[
                                        'inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500',
                                        !selectedDocument || isProcessing
                                            ? 'bg-gray-400 cursor-not-allowed'
                                            : 'bg-blue-600 hover:bg-blue-700'
                                    ]"
                                >
                                    <LockOpenIcon class="h-5 w-5 mr-2" />
                                    Déverrouiller le PDF
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Password Modal -->
                <div v-if="showPasswordModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-50">
                    <div class="fixed inset-0 z-50 overflow-y-auto">
                        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:p-6">
                                <div>
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-100">
                                        <LockOpenIcon class="h-6 w-6 text-blue-600" />
                                    </div>
                                    <div class="mt-3 text-center sm:mt-5">
                                        <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-gray-100">
                                            Entrer le mot de passe
                                        </h3>
                                        <div class="mt-2">
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                Entrez le mot de passe actuel du document pour le déverrouiller, ou activez la suppression forcée si vous ne connaissez pas le mot de passe.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-5 space-y-4">
                                    <div>
                                        <input
                                            v-model="form.password"
                                            type="password"
                                            placeholder="Mot de passe du document (optionnel si suppression forcée)"
                                            class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                            @keyup.enter="removePassword"
                                        />
                                    </div>
                                    
                                    <!-- Force Remove Option -->
                                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg p-3">
                                        <div class="flex items-start">
                                            <input
                                                id="force_remove"
                                                v-model="form.force_remove"
                                                type="checkbox"
                                                class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            />
                                            <label for="force_remove" class="ml-2">
                                                <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Suppression forcée</span>
                                                <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">
                                                    Tenter de supprimer la protection sans mot de passe. Cela ne fonctionne que pour certains types de protection PDF.
                                                </p>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <!-- Error in modal -->
                                    <div v-if="error" class="text-sm text-red-600 dark:text-red-400">
                                        {{ error }}
                                    </div>
                                </div>
                                
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        @click="removePassword"
                                        :disabled="isProcessing || (!form.password && !form.force_remove)"
                                        :class="[
                                            'inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 sm:col-start-2',
                                            isProcessing || (!form.password && !form.force_remove)
                                                ? 'bg-gray-400 cursor-not-allowed'
                                                : 'bg-blue-600 hover:bg-blue-500 focus-visible:outline-blue-600'
                                        ]"
                                    >
                                        {{ isProcessing ? 'Traitement...' : 'Déverrouiller' }}
                                    </button>
                                    <button
                                        @click="closeModal"
                                        :disabled="isProcessing"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-gray-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 sm:col-start-1 sm:mt-0"
                                    >
                                        Annuler
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>