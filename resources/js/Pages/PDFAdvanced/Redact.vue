<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import { LockClosedIcon, DocumentTextIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documents: Object,
});

const selectedDocument = ref(null);
const showRedactModal = ref(false);
const redactionMode = ref('areas'); // 'areas', 'patterns' or 'keywords'

const form = useForm({
    mode: 'areas',
    areas: [
        { x: 0, y: 0, width: 100, height: 20, page: 1 }
    ],
    patterns: [],
    keywords: [],
    custom_keyword: '',
    case_sensitive: false,
    whole_word: false,
    detect_sensitive: false,
    redaction_color: '#000000',
    remove_metadata: true
});

const sensitivePatterns = [
    { id: 'ssn', name: 'Numéros de sécurité sociale', pattern: '\\d{3}-\\d{2}-\\d{4}' },
    { id: 'phone', name: 'Numéros de téléphone', pattern: '\\d{10}|\\d{3}[-.]\\d{3}[-.]\\d{4}' },
    { id: 'email', name: 'Adresses email', pattern: '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}' },
    { id: 'credit_card', name: 'Cartes de crédit', pattern: '\\d{4}[\\s-]?\\d{4}[\\s-]?\\d{4}[\\s-]?\\d{4}' },
    { id: 'iban', name: 'IBAN', pattern: '[A-Z]{2}\\d{2}[A-Z0-9]{4}\\d{7}([A-Z0-9]?){0,16}' },
    { id: 'custom', name: 'Motif personnalisé', pattern: '' }
];

function openRedactModal(document) {
    selectedDocument.value = document;
    showRedactModal.value = true;
}

function closeModal() {
    showRedactModal.value = false;
    selectedDocument.value = null;
    form.reset();
}

function addArea() {
    form.areas.push({ x: 0, y: 0, width: 100, height: 20, page: 1 });
}

function removeArea(index) {
    form.areas.splice(index, 1);
}

function togglePattern(pattern) {
    const index = form.patterns.findIndex(p => p.id === pattern.id);
    if (index >= 0) {
        form.patterns.splice(index, 1);
    } else {
        form.patterns.push(pattern);
    }
}

function addKeyword() {
    if (form.custom_keyword && !form.keywords.includes(form.custom_keyword)) {
        form.keywords.push(form.custom_keyword);
        form.custom_keyword = '';
    }
}

function removeKeyword(index) {
    form.keywords.splice(index, 1);
}

function redactDocument() {
    if (!selectedDocument.value) return;
    
    form.mode = redactionMode.value;
    
    let endpoint;
    if (redactionMode.value === 'keywords') {
        endpoint = route('pdf-advanced.redact-keywords', selectedDocument.value.id);
    } else if (form.detect_sensitive) {
        endpoint = route('pdf-advanced.redact-sensitive', selectedDocument.value.id);
    } else {
        endpoint = route('pdf-advanced.redact-action', selectedDocument.value.id);
    }
    
    form.post(endpoint, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        }
    });
}
</script>

<template>
    <Head title="Rédaction Sécurisée" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Rédaction Sécurisée
                </h2>
                <LockClosedIcon class="h-6 w-6 text-gray-400" />
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Introduction -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Rédaction de Contenu Sensible
                        </h3>
                        <div class="prose dark:prose-invert max-w-none">
                            <p class="text-gray-600 dark:text-gray-400">
                                Masquez de manière permanente et irréversible les informations sensibles dans vos documents PDF.
                                La rédaction supprime complètement le contenu, empêchant toute récupération ultérieure.
                            </p>
                        </div>

                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mt-4">
                            <div class="flex">
                                <ExclamationTriangleIcon class="h-5 w-5 text-red-400 mt-0.5" />
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-red-800 dark:text-red-200">
                                        Attention - Action irréversible
                                    </h4>
                                    <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                                        La rédaction est permanente et ne peut pas être annulée. Assurez-vous de conserver
                                        une copie non rédactée si nécessaire.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                            <div class="bg-gray-50 dark:bg-gray-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                                    📍 Rédaction par zones
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Sélectionnez des zones spécifiques à masquer dans le document
                                </p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                                    🔍 Détection automatique
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Détectez et masquez automatiquement les données sensibles
                                </p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                                    🔤 Rédaction par mots-clés
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Masquez automatiquement des mots ou phrases spécifiques
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
                                    <DocumentTextIcon class="h-8 w-8 text-gray-400" />
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ document.original_name }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ (document.size / 1024 / 1024).toFixed(2) }} MB
                                        </p>
                                    </div>
                                </div>
                                <button
                                    @click="openRedactModal(document)"
                                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-900 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    Rédiger
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <DocumentTextIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-gray-500 dark:text-gray-400">
                                Aucun document PDF disponible
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Redact Modal -->
        <Modal :show="showRedactModal" @close="closeModal" max-width="3xl">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Rédaction du document
                </h3>

                <div v-if="selectedDocument" class="mb-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Document : <span class="font-medium">{{ selectedDocument.original_name }}</span>
                    </p>
                </div>

                <!-- Mode Selection -->
                <div class="mb-6">
                    <div class="flex space-x-4">
                        <label class="flex items-center">
                            <input
                                type="radio"
                                v-model="redactionMode"
                                value="areas"
                                class="mr-2"
                            />
                            <span>Rédaction par zones</span>
                        </label>
                        <label class="flex items-center">
                            <input
                                type="radio"
                                v-model="redactionMode"
                                value="patterns"
                                class="mr-2"
                            />
                            <span>Détection automatique</span>
                        </label>
                        <label class="flex items-center">
                            <input
                                type="radio"
                                v-model="redactionMode"
                                value="keywords"
                                class="mr-2"
                            />
                            <span>Rédaction par mots-clés</span>
                        </label>
                    </div>
                </div>

                <!-- Areas Mode -->
                <div v-if="redactionMode === 'areas'" class="space-y-4">
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100">Zones à rédiger</h4>
                            <button
                                @click="addArea"
                                class="text-sm text-indigo-600 hover:text-indigo-500"
                            >
                                + Ajouter une zone
                            </button>
                        </div>
                        <div class="space-y-2">
                            <div
                                v-for="(area, index) in form.areas"
                                :key="index"
                                class="flex items-center space-x-2"
                            >
                                <input
                                    v-model.number="area.x"
                                    type="number"
                                    placeholder="X"
                                    class="w-20 text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                                />
                                <input
                                    v-model.number="area.y"
                                    type="number"
                                    placeholder="Y"
                                    class="w-20 text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                                />
                                <input
                                    v-model.number="area.width"
                                    type="number"
                                    placeholder="Largeur"
                                    class="w-24 text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                                />
                                <input
                                    v-model.number="area.height"
                                    type="number"
                                    placeholder="Hauteur"
                                    class="w-24 text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                                />
                                <input
                                    v-model.number="area.page"
                                    type="number"
                                    placeholder="Page"
                                    min="1"
                                    class="w-20 text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                                />
                                <button
                                    @click="removeArea(index)"
                                    class="text-red-600 hover:text-red-500"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patterns Mode -->
                <div v-else-if="redactionMode === 'patterns'" class="space-y-4">
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                        <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">
                            Sélectionner les types de données à détecter
                        </h4>
                        <div class="space-y-2">
                            <label
                                v-for="pattern in sensitivePatterns"
                                :key="pattern.id"
                                class="flex items-center"
                            >
                                <input
                                    type="checkbox"
                                    @change="togglePattern(pattern)"
                                    :checked="form.patterns.some(p => p.id === pattern.id)"
                                    class="mr-3"
                                />
                                <span>{{ pattern.name }}</span>
                            </label>
                        </div>
                    </div>
                    
                    <label class="flex items-center">
                        <input
                            type="checkbox"
                            v-model="form.detect_sensitive"
                            class="mr-2"
                        />
                        <span>Détection automatique avancée (recommandé)</span>
                    </label>
                </div>

                <!-- Keywords Mode -->
                <div v-else-if="redactionMode === 'keywords'" class="space-y-4">
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                        <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">
                            Mots-clés à masquer
                        </h4>
                        
                        <!-- Add keyword input -->
                        <div class="flex items-center space-x-2 mb-4">
                            <input
                                v-model="form.custom_keyword"
                                @keyup.enter="addKeyword"
                                type="text"
                                placeholder="Entrez un mot ou une phrase..."
                                class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md"
                            />
                            <button
                                @click="addKeyword"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
                            >
                                Ajouter
                            </button>
                        </div>
                        
                        <!-- Keywords list -->
                        <div v-if="form.keywords.length > 0" class="space-y-2">
                            <div
                                v-for="(keyword, index) in form.keywords"
                                :key="index"
                                class="flex items-center justify-between bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded"
                            >
                                <span class="text-sm">{{ keyword }}</span>
                                <button
                                    @click="removeKeyword(index)"
                                    class="text-red-600 hover:text-red-500"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-gray-500 dark:text-gray-400 text-sm">
                            Aucun mot-clé ajouté
                        </div>
                    </div>
                    
                    <!-- Search options -->
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                        <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">
                            Options de recherche
                        </h4>
                        <div class="space-y-2">
                            <label class="flex items-center">
                                <input
                                    type="checkbox"
                                    v-model="form.case_sensitive"
                                    class="mr-2"
                                />
                                <span>Sensible à la casse</span>
                            </label>
                            <label class="flex items-center">
                                <input
                                    type="checkbox"
                                    v-model="form.whole_word"
                                    class="mr-2"
                                />
                                <span>Mot entier uniquement</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Additional Options -->
                <div class="mt-4 space-y-3">
                    <label class="flex items-center">
                        <input
                            type="checkbox"
                            v-model="form.remove_metadata"
                            class="mr-2"
                        />
                        <span>Supprimer les métadonnées du document</span>
                    </label>
                    
                    <div class="flex items-center">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mr-3">
                            Couleur de rédaction :
                        </label>
                        <input
                            type="color"
                            v-model="form.redaction_color"
                            class="h-8 w-20"
                        />
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <button
                        @click="closeModal"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300"
                    >
                        Annuler
                    </button>
                    <PrimaryButton
                        @click="redactDocument"
                        :disabled="form.processing"
                        :class="{ 'opacity-25': form.processing }"
                        class="bg-red-600 hover:bg-red-700"
                    >
                        {{ form.processing ? 'Rédaction...' : 'Appliquer la rédaction' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>