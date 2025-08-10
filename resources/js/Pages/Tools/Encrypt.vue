<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    title: String,
    documents: {
        type: Array,
        default: () => []
    }
});

const selectedDocument = ref(null);
const password = ref('');
const confirmPassword = ref('');
const encryptionLevel = ref('standard'); // 'standard', 'high'
const permissions = ref({
    print: true,
    copy: true,
    modify: false,
    annotate: true,
    fillForms: true,
    extract: false,
    assemble: false
});
const ownerPassword = ref('');
const isProcessing = ref(false);
const showAdvanced = ref(false);

const pdfDocuments = computed(() => {
    return props.documents.filter(doc => doc.mime_type === 'application/pdf');
});

const canEncrypt = computed(() => {
    return selectedDocument.value && 
           password.value.length >= 6 && 
           password.value === confirmPassword.value;
});

const passwordStrength = computed(() => {
    const pwd = password.value;
    if (pwd.length < 6) return { level: 'weak', text: 'Faible', color: 'text-red-600' };
    if (pwd.length < 8 || !/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(pwd)) {
        return { level: 'medium', text: 'Moyen', color: 'text-yellow-600' };
    }
    if (pwd.length >= 12 && /(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s])/.test(pwd)) {
        return { level: 'strong', text: 'Fort', color: 'text-green-600' };
    }
    return { level: 'good', text: 'Bon', color: 'text-blue-600' };
});

const encryptPDF = () => {
    if (!canEncrypt.value) return;
    
    isProcessing.value = true;
    
    const data = {
        document_id: selectedDocument.value,
        password: password.value,
        encryption_level: encryptionLevel.value,
        permissions: permissions.value
    };
    
    if (ownerPassword.value && showAdvanced.value) {
        data.owner_password = ownerPassword.value;
    }
    
    router.post(route('documents.encrypt'), data, {
        onFinish: () => {
            isProcessing.value = false;
        }
    });
};
</script>

<template>
    <Head title="Chiffrer PDF" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ title }}
                </h2>
                <Link :href="route('documents.index')" 
                      class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    Retour aux documents
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Document Selection -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Sélectionner le PDF à chiffrer
                                </h3>
                                
                                <div class="space-y-2 max-h-96 overflow-y-auto">
                                    <div v-for="document in pdfDocuments" 
                                         :key="document.id"
                                         class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
                                         :class="{'ring-2 ring-indigo-500 bg-indigo-50 dark:bg-indigo-900/20': selectedDocument === document.id}"
                                         @click="selectedDocument = document.id">
                                        <input 
                                            type="radio" 
                                            :checked="selectedDocument === document.id"
                                            @change="selectedDocument = document.id"
                                            class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            name="document"
                                        />
                                        <div class="ml-3 flex-1">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ document.original_name }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ (document.size / 1024 / 1024).toFixed(2) }} MB
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M20,2H8A2,2 0 0,0 6,4V16A2,2 0 0,0 8,18H20A2,2 0 0,0 22,16V4A2,2 0 0,0 20,2M20,8H8V4H20V8Z" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="pdfDocuments.length === 0" 
                                     class="text-center py-8">
                                    <div class="text-gray-400 dark:text-gray-600">
                                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">Aucun PDF trouvé</h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Téléchargez un fichier PDF pour pouvoir le chiffrer.
                                    </p>
                                    <div class="mt-6">
                                        <Link :href="route('documents.create')"
                                              class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Télécharger un PDF
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Encryption Configuration -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Configuration du chiffrement
                                </h3>
                                
                                <!-- Password -->
                                <div class="mb-6">
                                    <div class="space-y-4">
                                        <div>
                                            <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Mot de passe
                                            </label>
                                            <input 
                                                id="password"
                                                v-model="password"
                                                type="password" 
                                                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                                placeholder="Entrez un mot de passe sécurisé"
                                                minlength="6"
                                            />
                                            <div v-if="password" class="mt-1 flex items-center justify-between">
                                                <span :class="passwordStrength.color" class="text-xs">
                                                    Force: {{ passwordStrength.text }}
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    {{ password.length }} caractères
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label for="confirm-password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Confirmer le mot de passe
                                            </label>
                                            <input 
                                                id="confirm-password"
                                                v-model="confirmPassword"
                                                type="password" 
                                                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                                placeholder="Confirmez le mot de passe"
                                            />
                                            <div v-if="confirmPassword && password !== confirmPassword" class="mt-1 text-xs text-red-600">
                                                Les mots de passe ne correspondent pas
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Encryption Level -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Niveau de chiffrement
                                    </label>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <input 
                                                id="encryption-standard" 
                                                v-model="encryptionLevel" 
                                                value="standard" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="encryption-standard" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Standard (128-bit)</div>
                                                <div class="text-xs text-gray-500">Compatible avec la plupart des lecteurs PDF</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="encryption-high" 
                                                v-model="encryptionLevel" 
                                                value="high" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="encryption-high" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Élevé (256-bit)</div>
                                                <div class="text-xs text-gray-500">Sécurité maximale, nécessite des lecteurs récents</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Permissions -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Permissions
                                    </label>
                                    <div class="space-y-2">
                                        <label class="flex items-center">
                                            <input 
                                                v-model="permissions.print" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser l'impression</span>
                                        </label>
                                        
                                        <label class="flex items-center">
                                            <input 
                                                v-model="permissions.copy" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser la copie de texte</span>
                                        </label>
                                        
                                        <label class="flex items-center">
                                            <input 
                                                v-model="permissions.modify" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser la modification</span>
                                        </label>
                                        
                                        <label class="flex items-center">
                                            <input 
                                                v-model="permissions.annotate" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser les annotations</span>
                                        </label>
                                        
                                        <label class="flex items-center">
                                            <input 
                                                v-model="permissions.fillForms" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser le remplissage des formulaires</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Advanced Options -->
                                <div class="mb-6">
                                    <button 
                                        @click="showAdvanced = !showAdvanced"
                                        class="flex items-center text-sm text-indigo-600 hover:text-indigo-800"
                                    >
                                        <svg 
                                            :class="{'rotate-90': showAdvanced}"
                                            class="w-4 h-4 mr-1 transform transition-transform" 
                                            fill="none" 
                                            stroke="currentColor" 
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                        Options avancées
                                    </button>
                                    
                                    <div v-if="showAdvanced" class="mt-4 space-y-4">
                                        <div>
                                            <label for="owner-password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Mot de passe propriétaire (optionnel)
                                            </label>
                                            <input 
                                                id="owner-password"
                                                v-model="ownerPassword"
                                                type="password" 
                                                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                                placeholder="Mot de passe pour les permissions"
                                            />
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                Permet de contourner les restrictions de permissions
                                            </p>
                                        </div>
                                        
                                        <div class="space-y-2">
                                            <label class="flex items-center">
                                                <input 
                                                    v-model="permissions.extract" 
                                                    type="checkbox" 
                                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                                />
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser l'extraction de contenu</span>
                                            </label>
                                            
                                            <label class="flex items-center">
                                                <input 
                                                    v-model="permissions.assemble" 
                                                    type="checkbox" 
                                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                                />
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Autoriser l'assemblage de documents</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Encrypt Button -->
                                <PrimaryButton 
                                    @click="encryptPDF"
                                    :disabled="!canEncrypt || isProcessing"
                                    class="w-full justify-center"
                                >
                                    <svg v-if="isProcessing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                    {{ isProcessing ? 'Chiffrement en cours...' : 'Chiffrer le PDF' }}
                                </PrimaryButton>

                                <div v-if="!canEncrypt" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Sélectionnez un PDF et entrez un mot de passe valide
                                </div>
                            </div>
                        </div>

                        <!-- Tips -->
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
                                            <li>Utilisez un mot de passe fort (au moins 8 caractères)</li>
                                            <li>Conservez votre mot de passe en sécurité</li>
                                            <li>Sans le mot de passe, le PDF ne pourra plus être ouvert</li>
                                            <li>Le fichier chiffré sera disponible dans vos documents</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>