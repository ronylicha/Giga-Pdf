<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { MagnifyingGlassIcon, DocumentTextIcon, ArrowsRightLeftIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
// Import PDF.js - use legacy build like in Show.vue
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf.min.mjs';

// Configure PDF.js worker - use version 5.4 worker
pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/pdf.worker.5.4.min.js';

// IMPORTANT: Store PDF instances outside of Vue's reactivity system
// to avoid proxy issues with PDF.js objects
let pdf1Instance = null;
let pdf2Instance = null;

const props = defineProps({
    documents: Array,
    comparison: Object,
});

const form = useForm({
    document1_id: null,
    document2_id: null,
    comparison_type: 'visual', // 'visual' or 'text'
    highlight_color: '#FFFF00',
    show_additions: true,
    show_deletions: true,
    show_modifications: true
});

const comparisonResult = ref(props.comparison || null);
const isComparing = ref(false);
const showPdfViewer = ref(false);

// PDF viewer state (only UI-related state in refs)
const pdf1Canvas = ref(null);
const pdf2Canvas = ref(null);
const currentPage1 = ref(1);
const currentPage2 = ref(1);
const totalPages1 = ref(0);
const totalPages2 = ref(0);
const scale = ref(1.5);
const loadingPdfs = ref(false);

// Watch for comparison prop changes
watch(() => props.comparison, (newComparison) => {
    if (newComparison) {
        comparisonResult.value = newComparison;
    }
});

// Ensure PDF.js is properly loaded
onMounted(() => {
    console.log('Component mounted, PDF.js version:', pdfjsLib.version);
    console.log('PDF.js worker source:', pdfjsLib.GlobalWorkerOptions.workerSrc);
});

const canCompare = computed(() => {
    return form.document1_id && form.document2_id && form.document1_id !== form.document2_id;
});

async function compareDocuments() {
    if (!canCompare.value) return;
    
    isComparing.value = true;
    comparisonResult.value = null;
    showPdfViewer.value = false;
    
    try {
        const endpoint = form.comparison_type === 'visual' 
            ? route('pdf-advanced.compare-action')
            : route('pdf-advanced.compare-text');
        
        // Utiliser axios pour une requête AJAX simple
        const response = await axios.post(endpoint, {
            document1_id: form.document1_id,
            document2_id: form.document2_id,
            comparison_type: form.comparison_type,
            highlight_color: form.highlight_color,
            show_additions: form.show_additions,
            show_deletions: form.show_deletions,
            show_modifications: form.show_modifications,
            threshold: 95,
            detailed_analysis: true,
            generate_diff_pdf: false,
            create_diff_images: true
        });
        
        if (response.data.success && response.data.comparison) {
            comparisonResult.value = response.data.comparison;
            // Charger les PDF après la comparaison
            await nextTick();
            await loadPdfsForComparison();
        }
    } catch (error) {
        console.error('Comparison error:', error);
        if (error.response && error.response.data && error.response.data.message) {
            alert('Erreur: ' + error.response.data.message);
        }
    } finally {
        isComparing.value = false;
    }
}

async function loadPdfsForComparison() {
    if (!form.document1_id || !form.document2_id) return;
    
    console.log('Starting PDF load for documents:', form.document1_id, form.document2_id);
    loadingPdfs.value = true;
    showPdfViewer.value = true;
    
    try {
        // Charger le premier PDF avec axios pour gérer l'authentification
        const url1 = route('documents.preview', form.document1_id);
        console.log('Loading PDF 1 from:', url1);
        
        const response1 = await axios.get(url1, {
            responseType: 'arraybuffer',
            headers: {
                'Accept': 'application/pdf',
            }
        });
        
        console.log('PDF 1 loaded, size:', response1.data.byteLength);
        
        const loadingTask1 = pdfjsLib.getDocument({
            data: response1.data
        });
        pdf1Instance = await loadingTask1.promise;
        totalPages1.value = pdf1Instance.numPages;
        console.log('PDF 1 parsed, pages:', totalPages1.value);
        
        // Charger le deuxième PDF
        const url2 = route('documents.preview', form.document2_id);
        console.log('Loading PDF 2 from:', url2);
        
        const response2 = await axios.get(url2, {
            responseType: 'arraybuffer',
            headers: {
                'Accept': 'application/pdf',
            }
        });
        
        console.log('PDF 2 loaded, size:', response2.data.byteLength);
        
        const loadingTask2 = pdfjsLib.getDocument({
            data: response2.data
        });
        pdf2Instance = await loadingTask2.promise;
        totalPages2.value = pdf2Instance.numPages;
        console.log('PDF 2 parsed, pages:', totalPages2.value);
        
        // Attendre que le DOM soit mis à jour
        await nextTick();
        
        // Vérifier que les canvas existent
        console.log('Canvas 1:', pdf1Canvas.value);
        console.log('Canvas 2:', pdf2Canvas.value);
        
        // Afficher la première page de chaque PDF
        if (pdf1Canvas.value && pdf2Canvas.value) {
            await renderPage(1, 1);
            await renderPage(2, 1);
        } else {
            console.error('Canvas elements not found!');
            alert('Erreur: Les éléments canvas ne sont pas disponibles');
        }
    } catch (error) {
        console.error('Error loading PDFs:', error);
        if (error.response && error.response.status === 404) {
            alert('Un ou plusieurs documents n\'ont pas été trouvés');
        } else if (error.response && error.response.status === 403) {
            alert('Vous n\'avez pas l\'autorisation de voir ces documents');
        } else {
            alert('Erreur lors du chargement des PDF: ' + (error.message || 'Erreur inconnue'));
        }
    } finally {
        loadingPdfs.value = false;
    }
}

async function renderPage(pdfNum, pageNum) {
    const pdf = pdfNum === 1 ? pdf1Instance : pdf2Instance;
    const canvas = pdfNum === 1 ? pdf1Canvas.value : pdf2Canvas.value;
    
    console.log(`Rendering PDF ${pdfNum}, page ${pageNum}`, { pdf, canvas });
    
    if (!pdf || !canvas) {
        console.error(`Missing PDF or canvas for PDF ${pdfNum}`, { pdf, canvas });
        return;
    }
    
    try {
        const page = await pdf.getPage(pageNum);
        console.log(`Got page ${pageNum} for PDF ${pdfNum}`);
        
        const viewport = page.getViewport({ scale: scale.value });
        console.log(`Viewport for PDF ${pdfNum}:`, { width: viewport.width, height: viewport.height });
        
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const context = canvas.getContext('2d');
        if (!context) {
            console.error(`Failed to get 2D context for canvas ${pdfNum}`);
            return;
        }
        
        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        console.log(`Starting render for PDF ${pdfNum}, page ${pageNum}`);
        await page.render(renderContext).promise;
        console.log(`Render complete for PDF ${pdfNum}, page ${pageNum}`);
        
        // Appliquer les surlignages des différences
        if (comparisonResult.value && comparisonResult.value.differences) {
            highlightDifferences(pdfNum, pageNum, context, viewport);
        }
        
        if (pdfNum === 1) {
            currentPage1.value = pageNum;
        } else {
            currentPage2.value = pageNum;
        }
    } catch (error) {
        console.error(`Error rendering page ${pageNum} of PDF ${pdfNum}:`, error);
    }
}

function highlightDifferences(pdfNum, pageNum, context, viewport) {
    const differences = comparisonResult.value.differences;
    
    if (!differences || !Array.isArray(differences)) return;
    
    // Filtrer les différences pour cette page
    const pageDifferences = differences.filter(diff => diff.page === pageNum);
    
    if (pageDifferences.length === 0) return;
    
    // Configurer le style de surlignage
    context.save();
    context.globalAlpha = 0.3;
    context.fillStyle = form.highlight_color;
    
    pageDifferences.forEach(diff => {
        if (diff.differences_found && Array.isArray(diff.differences_found)) {
            diff.differences_found.forEach(area => {
                // Les coordonnées peuvent nécessiter une conversion selon le format retourné
                const x = area.x || 0;
                const y = area.y || 0;
                const width = area.width || 100;
                const height = area.height || 20;
                
                // Appliquer le surlignage
                context.fillRect(x * scale.value, y * scale.value, width * scale.value, height * scale.value);
            });
        } else if (diff.has_differences) {
            // Si pas de zones spécifiques, surligner toute la page avec une transparence plus faible
            context.globalAlpha = 0.1;
            context.fillRect(0, 0, viewport.width, viewport.height);
        }
    });
    
    context.restore();
}

function previousPage() {
    if (currentPage1.value > 1) {
        renderPage(1, currentPage1.value - 1);
    }
    if (currentPage2.value > 1) {
        renderPage(2, currentPage2.value - 1);
    }
}

function nextPage() {
    if (currentPage1.value < totalPages1.value) {
        renderPage(1, currentPage1.value + 1);
    }
    if (currentPage2.value < totalPages2.value) {
        renderPage(2, currentPage2.value + 1);
    }
}

function goToPage(pageNum) {
    if (pageNum >= 1 && pageNum <= Math.max(totalPages1.value, totalPages2.value)) {
        if (pageNum <= totalPages1.value) {
            renderPage(1, pageNum);
        }
        if (pageNum <= totalPages2.value) {
            renderPage(2, pageNum);
        }
    }
}

function zoomIn() {
    scale.value = Math.min(scale.value + 0.25, 3);
    if (pdf1Instance) renderPage(1, currentPage1.value);
    if (pdf2Instance) renderPage(2, currentPage2.value);
}

function zoomOut() {
    scale.value = Math.max(scale.value - 0.25, 0.5);
    if (pdf1Instance) renderPage(1, currentPage1.value);
    if (pdf2Instance) renderPage(2, currentPage2.value);
}

function resetComparison() {
    form.reset();
    comparisonResult.value = null;
    showPdfViewer.value = false;
    // Clear PDF instances
    pdf1Instance = null;
    pdf2Instance = null;
    currentPage1.value = 1;
    currentPage2.value = 1;
    totalPages1.value = 0;
    totalPages2.value = 0;
}

function downloadDiff() {
    if (comparisonResult.value && comparisonResult.value.diff_document) {
        window.location.href = route('documents.download', comparisonResult.value.diff_document.id);
    }
}
</script>

<template>
    <Head title="Comparer Documents" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Comparer Documents
                </h2>
                <MagnifyingGlassIcon class="h-6 w-6 text-gray-400" />
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Introduction -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Comparaison de Documents PDF
                        </h3>
                        <div class="prose dark:prose-invert max-w-none">
                            <p class="text-gray-600 dark:text-gray-400">
                                Comparez deux versions d'un document PDF pour identifier les différences.
                                Choisissez entre une comparaison visuelle pixel par pixel ou une comparaison
                                textuelle pour analyser les changements de contenu.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">
                                    👁️ Comparaison Visuelle
                                </h4>
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    Compare les documents pixel par pixel pour détecter tout changement visuel,
                                    y compris la mise en page, les images et les polices.
                                </p>
                            </div>
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-green-900 dark:text-green-100 mb-2">
                                    📝 Comparaison Textuelle
                                </h4>
                                <p class="text-sm text-green-700 dark:text-green-300">
                                    Compare uniquement le contenu textuel pour identifier les ajouts,
                                    suppressions et modifications de texte.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Comparison Form -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Sélectionner les documents à comparer
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Document 1 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document 1 (Original)
                                </label>
                                <select
                                    v-model="form.document1_id"
                                    class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                >
                                    <option value="">Sélectionner un document</option>
                                    <option
                                        v-for="document in documents"
                                        :key="document.id"
                                        :value="document.id"
                                    >
                                        {{ document.original_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Document 2 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document 2 (Modifié)
                                </label>
                                <select
                                    v-model="form.document2_id"
                                    class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                >
                                    <option value="">Sélectionner un document</option>
                                    <option
                                        v-for="document in documents"
                                        :key="document.id"
                                        :value="document.id"
                                        :disabled="document.id === form.document1_id"
                                    >
                                        {{ document.original_name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Comparison Options -->
                        <div class="mt-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Type de comparaison
                                </label>
                                <div class="flex space-x-4">
                                    <label class="flex items-center">
                                        <input
                                            type="radio"
                                            v-model="form.comparison_type"
                                            value="visual"
                                            class="mr-2"
                                        />
                                        <span>Visuelle (pixel par pixel)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="radio"
                                            v-model="form.comparison_type"
                                            value="text"
                                            class="mr-2"
                                        />
                                        <span>Textuelle (contenu uniquement)</span>
                                    </label>
                                </div>
                            </div>

                            <div v-if="form.comparison_type === 'text'" class="space-y-3">
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        v-model="form.show_additions"
                                        class="mr-2"
                                    />
                                    <span class="text-green-600">Afficher les ajouts</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        v-model="form.show_deletions"
                                        class="mr-2"
                                    />
                                    <span class="text-red-600">Afficher les suppressions</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        v-model="form.show_modifications"
                                        class="mr-2"
                                    />
                                    <span class="text-yellow-600">Afficher les modifications</span>
                                </label>
                            </div>

                            <div class="flex items-center">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mr-3">
                                    Couleur de surbrillance :
                                </label>
                                <input
                                    type="color"
                                    v-model="form.highlight_color"
                                    class="h-8 w-20"
                                />
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button
                                @click="resetComparison"
                                class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300"
                            >
                                Réinitialiser
                            </button>
                            <PrimaryButton
                                @click="compareDocuments"
                                :disabled="!canCompare || isComparing"
                                :class="{ 'opacity-25': !canCompare || isComparing }"
                            >
                                <ArrowsRightLeftIcon class="h-4 w-4 mr-2" />
                                {{ isComparing ? 'Comparaison...' : 'Comparer' }}
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <!-- Comparison Results -->
                <div v-if="comparisonResult" class="mt-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Résultats de la comparaison
                        </h3>

                        <div class="space-y-4">
                            <!-- Statistics -->
                            <div v-if="comparisonResult.statistics" class="grid grid-cols-3 gap-4">
                                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                    <p class="text-2xl font-bold text-green-600">
                                        {{ comparisonResult.statistics.additions || 0 }}
                                    </p>
                                    <p class="text-sm text-green-700 dark:text-green-300">Ajouts</p>
                                </div>
                                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                                    <p class="text-2xl font-bold text-red-600">
                                        {{ comparisonResult.statistics.deletions || 0 }}
                                    </p>
                                    <p class="text-sm text-red-700 dark:text-red-300">Suppressions</p>
                                </div>
                                <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4">
                                    <p class="text-2xl font-bold text-yellow-600">
                                        {{ comparisonResult.statistics.modifications || 0 }}
                                    </p>
                                    <p class="text-sm text-yellow-700 dark:text-yellow-300">Modifications</p>
                                </div>
                            </div>
                            
                            <!-- Text Differences Display -->
                            <div v-if="comparisonResult.comparison_type === 'text' && comparisonResult.differences" class="mt-6">
                                <h4 class="text-md font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                    Détails des différences textuelles
                                </h4>
                                
                                <!-- Additions -->
                                <div v-if="form.show_additions && comparisonResult.differences.additions?.length > 0" class="mb-4">
                                    <h5 class="text-sm font-medium text-green-700 dark:text-green-300 mb-2">
                                        ➕ Ajouts ({{ comparisonResult.differences.additions.length }})
                                    </h5>
                                    <div class="max-h-40 overflow-y-auto border border-green-200 dark:border-green-800 rounded bg-green-50 dark:bg-green-900/20 p-2">
                                        <div v-for="(addition, idx) in comparisonResult.differences.additions.slice(0, 10)" :key="'add-' + idx" class="text-xs text-green-800 dark:text-green-200 mb-1">
                                            <span class="font-mono">Ligne {{ addition.line }}:</span> {{ addition.content }}
                                        </div>
                                        <div v-if="comparisonResult.differences.additions.length > 10" class="text-xs text-green-600 dark:text-green-400 italic">
                                            ... et {{ comparisonResult.differences.additions.length - 10 }} autres ajouts
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Deletions -->
                                <div v-if="form.show_deletions && comparisonResult.differences.deletions?.length > 0" class="mb-4">
                                    <h5 class="text-sm font-medium text-red-700 dark:text-red-300 mb-2">
                                        ➖ Suppressions ({{ comparisonResult.differences.deletions.length }})
                                    </h5>
                                    <div class="max-h-40 overflow-y-auto border border-red-200 dark:border-red-800 rounded bg-red-50 dark:bg-red-900/20 p-2">
                                        <div v-for="(deletion, idx) in comparisonResult.differences.deletions.slice(0, 10)" :key="'del-' + idx" class="text-xs text-red-800 dark:text-red-200 mb-1">
                                            <span class="font-mono">Ligne {{ deletion.line }}:</span> <s>{{ deletion.content }}</s>
                                        </div>
                                        <div v-if="comparisonResult.differences.deletions.length > 10" class="text-xs text-red-600 dark:text-red-400 italic">
                                            ... et {{ comparisonResult.differences.deletions.length - 10 }} autres suppressions
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modifications -->
                                <div v-if="form.show_modifications && comparisonResult.differences.modifications?.length > 0" class="mb-4">
                                    <h5 class="text-sm font-medium text-yellow-700 dark:text-yellow-300 mb-2">
                                        ✏️ Modifications ({{ comparisonResult.differences.modifications.length }})
                                    </h5>
                                    <div class="max-h-40 overflow-y-auto border border-yellow-200 dark:border-yellow-800 rounded bg-yellow-50 dark:bg-yellow-900/20 p-2">
                                        <div v-for="(mod, idx) in comparisonResult.differences.modifications.slice(0, 10)" :key="'mod-' + idx" class="text-xs mb-2">
                                            <div class="font-mono text-yellow-800 dark:text-yellow-200">Ligne {{ mod.line }}:</div>
                                            <div class="text-red-700 dark:text-red-300 ml-4">- <s>{{ mod.original }}</s></div>
                                            <div class="text-green-700 dark:text-green-300 ml-4">+ {{ mod.modified }}</div>
                                        </div>
                                        <div v-if="comparisonResult.differences.modifications.length > 10" class="text-xs text-yellow-600 dark:text-yellow-400 italic">
                                            ... et {{ comparisonResult.differences.modifications.length - 10 }} autres modifications
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Similarity Score -->
                            <div v-if="comparisonResult.similarity_percentage !== undefined" class="bg-gray-50 dark:bg-gray-900/20 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Similarité
                                    </span>
                                    <span class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                        {{ comparisonResult.similarity_percentage }}%
                                    </span>
                                </div>
                                <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div
                                        class="bg-indigo-600 h-2 rounded-full"
                                        :style="{ width: comparisonResult.similarity_percentage + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Download Button -->
                            <div v-if="comparisonResult.diff_document" class="flex justify-center">
                                <button
                                    @click="downloadDiff"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    <DocumentTextIcon class="h-4 w-4 mr-2" />
                                    Télécharger le rapport de comparaison
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PDF Side-by-Side Viewer -->
                <div v-if="showPdfViewer" class="mt-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Visualisation côte à côte
                            </h3>
                            
                            <!-- Controls -->
                            <div class="flex items-center space-x-4">
                                <!-- Zoom controls -->
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="zoomOut"
                                        class="p-1 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                        title="Zoom arrière"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"></path>
                                        </svg>
                                    </button>
                                    <span class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ Math.round(scale * 100) }}%
                                    </span>
                                    <button
                                        @click="zoomIn"
                                        class="p-1 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                        title="Zoom avant"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"></path>
                                        </svg>
                                    </button>
                                </div>
                                
                                <!-- Page navigation -->
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="previousPage"
                                        :disabled="currentPage1 <= 1 && currentPage2 <= 1"
                                        class="p-1 text-gray-600 hover:text-gray-900 disabled:text-gray-300 dark:text-gray-400 dark:hover:text-gray-100 dark:disabled:text-gray-600"
                                    >
                                        <ChevronLeftIcon class="h-5 w-5" />
                                    </button>
                                    
                                    <span class="text-sm text-gray-600 dark:text-gray-400">
                                        Page {{ Math.max(currentPage1, currentPage2) }} / {{ Math.max(totalPages1, totalPages2) }}
                                    </span>
                                    
                                    <button
                                        @click="nextPage"
                                        :disabled="currentPage1 >= totalPages1 && currentPage2 >= totalPages2"
                                        class="p-1 text-gray-600 hover:text-gray-900 disabled:text-gray-300 dark:text-gray-400 dark:hover:text-gray-100 dark:disabled:text-gray-600"
                                    >
                                        <ChevronRightIcon class="h-5 w-5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Loading indicator -->
                        <div v-if="loadingPdfs" class="flex justify-center py-8">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
                        </div>
                        
                        <!-- PDF Canvases -->
                        <div v-show="!loadingPdfs" class="grid grid-cols-1 lg:grid-cols-2 gap-4 overflow-auto">
                            <!-- Document 1 -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document 1 (Original)
                                    <span v-if="comparisonResult && comparisonResult.document1" class="text-xs text-gray-500">
                                        - {{ comparisonResult.document1.name }}
                                    </span>
                                </h4>
                                <div class="overflow-auto max-h-[800px] bg-gray-50 dark:bg-gray-900 rounded">
                                    <canvas 
                                        ref="pdf1Canvas" 
                                        class="mx-auto"
                                        style="display: block;"
                                    ></canvas>
                                </div>
                                <div v-if="currentPage1 > totalPages1 && totalPages1 > 0" class="text-center py-4 text-gray-500 dark:text-gray-400">
                                    Document terminé ({{ totalPages1 }} pages)
                                </div>
                            </div>
                            
                            <!-- Document 2 -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document 2 (Modifié)
                                    <span v-if="comparisonResult && comparisonResult.document2" class="text-xs text-gray-500">
                                        - {{ comparisonResult.document2.name }}
                                    </span>
                                </h4>
                                <div class="overflow-auto max-h-[800px] bg-gray-50 dark:bg-gray-900 rounded">
                                    <canvas 
                                        ref="pdf2Canvas" 
                                        class="mx-auto"
                                        style="display: block;"
                                    ></canvas>
                                </div>
                                <div v-if="currentPage2 > totalPages2 && totalPages2 > 0" class="text-center py-4 text-gray-500 dark:text-gray-400">
                                    Document terminé ({{ totalPages2 }} pages)
                                </div>
                            </div>
                        </div>
                        
                        <!-- Legend -->
                        <div class="mt-4 flex items-center justify-center space-x-4 text-sm">
                            <div class="flex items-center">
                                <div 
                                    class="w-4 h-4 mr-2 opacity-30" 
                                    :style="{ backgroundColor: form.highlight_color }"
                                ></div>
                                <span class="text-gray-600 dark:text-gray-400">Zones modifiées</span>
                            </div>
                            <div class="text-gray-500 dark:text-gray-500">
                                Les différences sont surlignées dans la couleur sélectionnée
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>