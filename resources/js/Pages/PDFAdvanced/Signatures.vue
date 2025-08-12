<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import { ShieldCheckIcon, DocumentCheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documents: Object,
});

const selectedDocument = ref(null);
const showSignModal = ref(false);
const showVerifyModal = ref(false);
const verificationResult = ref(null);

const form = useForm({
    certificate: null,
    private_key: null,
    password: '',
    signer_name: '',
    reason: '',
    location: '',
    contact_info: ''
});

function openSignModal(document) {
    selectedDocument.value = document;
    showSignModal.value = true;
}

function openVerifyModal(document) {
    selectedDocument.value = document;
    showVerifyModal.value = true;
    verifySignature(document);
}

function closeModal() {
    showSignModal.value = false;
    showVerifyModal.value = false;
    selectedDocument.value = null;
    verificationResult.value = null;
    form.reset();
}

function signDocument() {
    if (!selectedDocument.value) return;
    
    form.post(route('pdf-advanced.sign', selectedDocument.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        }
    });
}

function verifySignature(document) {
    fetch(route('pdf-advanced.verify-signature', document.id))
        .then(response => response.json())
        .then(data => {
            verificationResult.value = data;
        })
        .catch(error => {
            console.error('Error verifying signature:', error);
            verificationResult.value = { success: false, message: 'Erreur lors de la vérification' };
        });
}

function handleCertificateChange(e) {
    form.certificate = e.target.files[0];
}

function handlePrivateKeyChange(e) {
    form.private_key = e.target.files[0];
}
</script>

<template>
    <Head title="Signatures Numériques" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Signatures Numériques
                </h2>
                <ShieldCheckIcon class="h-6 w-6 text-gray-400" />
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Introduction -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Signatures Numériques de Documents
                        </h3>
                        <div class="prose dark:prose-invert max-w-none">
                            <p class="text-gray-600 dark:text-gray-400">
                                Signez numériquement vos documents PDF avec des certificats X.509 pour garantir leur authenticité,
                                intégrité et non-répudiation. Vérifiez également les signatures existantes sur vos documents.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-green-900 dark:text-green-100 mb-2">
                                    ✅ Authenticité
                                </h4>
                                <p class="text-sm text-green-700 dark:text-green-300">
                                    Confirme l'identité du signataire
                                </p>
                            </div>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">
                                    🔒 Intégrité
                                </h4>
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    Détecte toute modification après signature
                                </p>
                            </div>
                            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-purple-900 dark:text-purple-100 mb-2">
                                    📋 Non-répudiation
                                </h4>
                                <p class="text-sm text-purple-700 dark:text-purple-300">
                                    Le signataire ne peut nier avoir signé
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents List -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Documents PDF disponibles
                        </h3>

                        <div v-if="documents.data && documents.data.length > 0" class="space-y-3">
                            <div
                                v-for="document in documents.data"
                                :key="document.id"
                                class="flex items-center justify-between p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"
                            >
                                <div class="flex items-center space-x-3">
                                    <DocumentCheckIcon class="h-8 w-8 text-gray-400" />
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ document.original_name }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ (document.size / 1024 / 1024).toFixed(2) }} MB
                                        </p>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button
                                        @click="openSignModal(document)"
                                        class="inline-flex items-center px-3 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                    >
                                        Signer
                                    </button>
                                    <button
                                        @click="openVerifyModal(document)"
                                        class="inline-flex items-center px-3 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 focus:bg-green-700 active:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                    >
                                        Vérifier
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <DocumentCheckIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-gray-500 dark:text-gray-400">
                                Aucun document PDF disponible
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sign Modal -->
        <Modal :show="showSignModal" @close="closeModal">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Signer le document
                </h3>

                <div v-if="selectedDocument" class="mb-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Document : <span class="font-medium">{{ selectedDocument.original_name }}</span>
                    </p>
                </div>

                <form @submit.prevent="signDocument" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Certificat (.crt, .pem, .p12) *
                        </label>
                        <input
                            type="file"
                            @change="handleCertificateChange"
                            accept=".crt,.pem,.p12"
                            required
                            class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Clé privée (si séparée)
                        </label>
                        <input
                            type="file"
                            @change="handlePrivateKeyChange"
                            class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Mot de passe du certificat *
                        </label>
                        <input
                            v-model="form.password"
                            type="password"
                            required
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Nom du signataire *
                        </label>
                        <input
                            v-model="form.signer_name"
                            type="text"
                            required
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Raison de la signature
                        </label>
                        <input
                            v-model="form.reason"
                            type="text"
                            placeholder="Ex: J'approuve ce document"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Lieu
                        </label>
                        <input
                            v-model="form.location"
                            type="text"
                            placeholder="Ex: Paris, France"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                        />
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="closeModal"
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300"
                        >
                            Annuler
                        </button>
                        <PrimaryButton
                            type="submit"
                            :disabled="form.processing"
                            :class="{ 'opacity-25': form.processing }"
                        >
                            {{ form.processing ? 'Signature...' : 'Signer' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Verify Modal -->
        <Modal :show="showVerifyModal" @close="closeModal">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Vérification de signature
                </h3>

                <div v-if="selectedDocument" class="mb-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Document : <span class="font-medium">{{ selectedDocument.original_name }}</span>
                    </p>
                </div>

                <div v-if="verificationResult">
                    <div v-if="verificationResult.success" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                        <div class="flex">
                            <ShieldCheckIcon class="h-5 w-5 text-green-400 mt-0.5" />
                            <div class="ml-3">
                                <h4 class="text-sm font-medium text-green-800 dark:text-green-200">
                                    Signature valide
                                </h4>
                                <div class="mt-2 text-sm text-green-700 dark:text-green-300">
                                    <p v-if="verificationResult.signer">
                                        Signé par : {{ verificationResult.signer }}
                                    </p>
                                    <p v-if="verificationResult.date">
                                        Date : {{ verificationResult.date }}
                                    </p>
                                    <p v-if="verificationResult.reason">
                                        Raison : {{ verificationResult.reason }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-red-400 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <h4 class="text-sm font-medium text-red-800 dark:text-red-200">
                                    {{ verificationResult.message || 'Signature invalide ou absente' }}
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-center py-4">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                    <p class="mt-2 text-sm text-gray-500">Vérification en cours...</p>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        @click="closeModal"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300"
                    >
                        Fermer
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>