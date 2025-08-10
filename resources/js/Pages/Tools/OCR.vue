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
const ocrLanguage = ref('fra'); // 'fra', 'eng', 'deu', 'spa', 'ita', 'auto'
const outputFormat = ref('text'); // 'text', 'pdf', 'docx'
const pageRange = ref('all'); // 'all', 'specific'
const specificPages = ref('1-5');
const enhanceImage = ref(true);
const preserveLayout = ref(false);
const confidence = ref(70); // 0-100
const isProcessing = ref(false);

const supportedDocuments = computed(() => {
    return props.documents.filter(doc => 
        doc.mime_type === 'application/pdf' || 
        doc.mime_type.startsWith('image/')
    );
});

const canProcessOCR = computed(() => {
    if (!selectedDocument.value) return false;
    if (pageRange.value === 'specific' && !specificPages.value.trim()) return false;
    return true;
});

const getLanguageDisplay = (code) => {
    const languages = {
        'auto': 'Détection automatique',
        'fra': 'Français',
        'eng': 'Anglais',
        'deu': 'Allemand',
        'spa': 'Espagnol',
        'ita': 'Italien',
        'por': 'Portugais',
        'nld': 'Néerlandais',
        'rus': 'Russe',
        'chi_sim': 'Chinois simplifié',
        'jpn': 'Japonais',
        'ara': 'Arabe'
    };
    return languages[code] || code;
};

const processOCR = () => {
    if (!canProcessOCR.value) return;
    
    isProcessing.value = true;
    
    const data = {
        document_id: selectedDocument.value,
        ocr_language: ocrLanguage.value,
        output_format: outputFormat.value,
        page_range: pageRange.value,
        enhance_image: enhanceImage.value,
        preserve_layout: preserveLayout.value,
        confidence_threshold: confidence.value
    };
    
    if (pageRange.value === 'specific') {
        data.specific_pages = specificPages.value;
    }
    
    router.post(route('documents.ocr'), data, {
        onFinish: () => {
            isProcessing.value = false;
        }
    });
};
</script>

<template>
    <Head title="OCR (Reconnaissance de texte)" />

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
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Document Selection -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Sélectionner le document
                                </h3>
                                
                                <div class="space-y-2 max-h-96 overflow-y-auto">
                                    <div v-for="document in supportedDocuments" 
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
                                                {{ (document.size / 1024 / 1024).toFixed(2) }} MB • {{ document.mime_type }}
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <svg v-if="document.mime_type === 'application/pdf'" class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M20,2H8A2,2 0 0,0 6,4V16A2,2 0 0,0 8,18H20A2,2 0 0,0 22,16V4A2,2 0 0,0 20,2M20,8H8V4H20V8Z" />
                                            </svg>
                                            <svg v-else class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M6,20H15L18,20V12L15,12V15L18,15V18H6V20M15,9H18V11H15V9Z" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="supportedDocuments.length === 0" 
                                     class="text-center py-8">
                                    <div class="text-gray-400 dark:text-gray-600">
                                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">Aucun document compatible trouvé</h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Téléchargez un PDF ou une image pour utiliser l'OCR.
                                    </p>
                                    <div class="mt-6">
                                        <Link :href="route('documents.create')"
                                              class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Télécharger un document
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- OCR Configuration -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Configuration OCR
                                </h3>
                                
                                <!-- Language Selection -->
                                <div class="mb-6">
                                    <label for="ocr-language" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Langue du document
                                    </label>
                                    <select 
                                        id="ocr-language"
                                        v-model="ocrLanguage"
                                        class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                    >
                                        <option value="auto">{{ getLanguageDisplay('auto') }}</option>
                                        <option value="fra">{{ getLanguageDisplay('fra') }}</option>
                                        <option value="eng">{{ getLanguageDisplay('eng') }}</option>
                                        <option value="deu">{{ getLanguageDisplay('deu') }}</option>
                                        <option value="spa">{{ getLanguageDisplay('spa') }}</option>
                                        <option value="ita">{{ getLanguageDisplay('ita') }}</option>
                                        <option value="por">{{ getLanguageDisplay('por') }}</option>
                                        <option value="nld">{{ getLanguageDisplay('nld') }}</option>
                                        <option value="rus">{{ getLanguageDisplay('rus') }}</option>
                                        <option value="chi_sim">{{ getLanguageDisplay('chi_sim') }}</option>
                                        <option value="jpn">{{ getLanguageDisplay('jpn') }}</option>
                                        <option value="ara">{{ getLanguageDisplay('ara') }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Choisissez la langue principale du document pour une meilleure précision
                                    </p>
                                </div>

                                <!-- Page Range -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Pages à traiter
                                    </label>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <input 
                                                id="range-all" 
                                                v-model="pageRange" 
                                                value="all" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="range-all" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Toutes les pages</div>
                                                <div class="text-xs text-gray-500">Traiter l'intégralité du document</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="range-specific" 
                                                v-model="pageRange" 
                                                value="specific" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="range-specific" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Pages spécifiques</div>
                                                <div class="text-xs text-gray-500">Sélectionner certaines pages uniquement</div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div v-if="pageRange === 'specific'" class="mt-3">
                                        <input 
                                            v-model="specificPages"
                                            type="text" 
                                            class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                            placeholder="1-5, 7, 10-15"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Format : 1-5, 7, 10-15 (plages et pages individuelles)
                                        </p>
                                    </div>
                                </div>

                                <!-- Output Format -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Format de sortie
                                    </label>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <input 
                                                id="output-text" 
                                                v-model="outputFormat" 
                                                value="text" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="output-text" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Texte brut (.txt)</div>
                                                <div class="text-xs text-gray-500">Fichier texte simple sans mise en forme</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="output-pdf" 
                                                v-model="outputFormat" 
                                                value="pdf" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="output-pdf" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">PDF interrogeable</div>
                                                <div class="text-xs text-gray-500">PDF avec couche de texte recherchable</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="output-docx" 
                                                v-model="outputFormat" 
                                                value="docx" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="output-docx" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Document Word (.docx)</div>
                                                <div class="text-xs text-gray-500">Document éditable avec mise en forme</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Processing Options -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Options de traitement
                                    </label>
                                    <div class="space-y-3">
                                        <label class="flex items-center">
                                            <input 
                                                v-model="enhanceImage" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Améliorer l'image</div>
                                                <div class="text-xs text-gray-500">Optimiser le contraste et la netteté</div>
                                            </span>
                                        </label>
                                        
                                        <label class="flex items-center">
                                            <input 
                                                v-model="preserveLayout" 
                                                type="checkbox" 
                                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Préserver la mise en page</div>
                                                <div class="text-xs text-gray-500">Conserver l'organisation spatiale du texte</div>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Confidence Threshold -->
                                <div class="mb-6">
                                    <label for="confidence" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Seuil de confiance ({{ confidence }}%)
                                    </label>
                                    <input 
                                        id="confidence"
                                        v-model.number="confidence"
                                        type="range" 
                                        min="0" 
                                        max="100" 
                                        class="w-full"
                                    />
                                    <div class="flex justify-between text-xs text-gray-500 mt-1">
                                        <span>Plus de texte</span>
                                        <span>Plus précis</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Définit la précision minimale requise pour conserver le texte reconnu
                                    </p>
                                </div>

                                <!-- Process Button -->
                                <PrimaryButton 
                                    @click="processOCR"
                                    :disabled="!canProcessOCR || isProcessing"
                                    class="w-full justify-center"
                                >
                                    <svg v-if="isProcessing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    {{ isProcessing ? 'Reconnaissance en cours...' : 'Lancer la reconnaissance OCR' }}
                                </PrimaryButton>

                                <div v-if="!canProcessOCR" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Sélectionnez un document pour commencer l'OCR
                                </div>
                            </div>
                        </div>

                        <!-- Tips -->
                        <div class="mt-6 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-purple-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-purple-800 dark:text-purple-200">
                                        Conseils pour un meilleur OCR
                                    </h3>
                                    <div class="mt-2 text-sm text-purple-700 dark:text-purple-300">
                                        <ul class="list-disc pl-5 space-y-1">
                                            <li>Utilisez des images de haute qualité (300 DPI minimum)</li>
                                            <li>Assurez-vous que le texte est bien contrasté avec l'arrière-plan</li>
                                            <li>Évitez les images floues ou inclinées</li>
                                            <li>La détection automatique de langue fonctionne mieux sur du texte long</li>
                                            <li>L'amélioration d'image aide avec des scans de mauvaise qualité</li>
                                            <li>Le résultat sera disponible dans vos documents</li>
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