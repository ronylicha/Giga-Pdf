<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Éditer : {{ document.original_name }}
                </h2>
                <div class="flex space-x-2">
                    <button
                        @click="saveDocument"
                        :disabled="!hasChanges || isSaving"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                    >
                        <span v-if="isSaving" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Enregistrement...
                        </span>
                        <span v-else>Enregistrer</span>
                    </button>
                    <Link
                        :href="route('documents.show', document.id)"
                        class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                    >
                        Fermer
                    </Link>
                </div>
            </div>
        </template>

        <div class="flex h-[calc(100vh-120px)]">
            <!-- Toolbar -->
            <div class="w-20 bg-gray-800 p-2 space-y-2">
                <!-- Select/Move Tool -->
                <button
                    @click="setTool('select')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'select' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Sélectionner"
                >
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2z" />
                    </svg>
                </button>

                <!-- Text Tool -->
                <button
                    @click="setTool('text')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'text' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Texte"
                >
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                </button>

                <!-- Highlight Tool -->
                <button
                    @click="setTool('highlight')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'highlight' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Surligner"
                >
                    <svg class="w-6 h-6 mx-auto" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M15.5 5H11L16 10L13.5 12.5L11.5 14.5L10 13L7.5 15.5L10 18L9 19H15L19 15V8.5L15.5 5M3.7 20.7L7.6 16.8C6.8 16 6.8 14.7 7.6 13.9L9.5 12L12 14.5L10.1 16.4C9.3 17.2 8 17.2 7.2 16.4L3.3 20.3C3.1 20.5 3.1 20.9 3.3 21.1C3.5 21.3 3.9 21.3 4.1 21.1L8 17.2C8.8 18 10.1 18 10.9 17.2L12.8 15.3L13.5 16L11 18.5C10.2 19.3 10.2 20.6 11 21.4C11.8 22.2 13.1 22.2 13.9 21.4L16.4 18.9L18.5 21H21L15.5 15.5L18 13L13 8L15.5 5.5" />
                    </svg>
                </button>

                <!-- Draw Tool -->
                <button
                    @click="setTool('draw')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'draw' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Dessiner"
                >
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                </button>

                <!-- Shape Tool -->
                <button
                    @click="setTool('shape')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'shape' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Formes"
                >
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5" />
                    </svg>
                </button>

                <!-- Stamp Tool -->
                <button
                    @click="setTool('stamp')"
                    :class="[
                        'w-full p-3 rounded-lg transition',
                        currentTool === 'stamp' ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    ]"
                    title="Tampon"
                >
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>

                <div class="border-t border-gray-700 pt-2">
                    <!-- Undo -->
                    <button
                        @click="undo"
                        :disabled="!canUndo"
                        class="w-full p-3 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 disabled:opacity-50"
                        title="Annuler"
                    >
                        <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                        </svg>
                    </button>

                    <!-- Redo -->
                    <button
                        @click="redo"
                        :disabled="!canRedo"
                        class="w-full p-3 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 disabled:opacity-50 mt-2"
                        title="Refaire"
                    >
                        <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10h-10a8 8 0 00-8 8v2m18-10l-6 6m6-6l-6-6" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- PDF Viewer/Editor -->
            <div class="flex-1 bg-gray-100 overflow-auto relative">
                <div class="p-4">
                    <!-- Page Navigation -->
                    <div class="bg-white rounded-lg shadow-sm p-3 mb-4 flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <button
                                @click="previousPage"
                                :disabled="currentPage === 1"
                                class="p-2 rounded hover:bg-gray-100 disabled:opacity-50"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <span class="text-sm">
                                Page <input
                                    v-model.number="currentPage"
                                    type="number"
                                    min="1"
                                    :max="totalPages"
                                    class="w-16 px-2 py-1 border rounded text-center"
                                    @change="goToPage"
                                > sur {{ totalPages }}
                            </span>
                            <button
                                @click="nextPage"
                                :disabled="currentPage === totalPages"
                                class="p-2 rounded hover:bg-gray-100 disabled:opacity-50"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>

                        <!-- Zoom Controls -->
                        <div class="flex items-center space-x-2">
                            <button
                                @click="zoomOut"
                                class="p-2 rounded hover:bg-gray-100"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7" />
                                </svg>
                            </button>
                            <span class="text-sm">{{ Math.round(zoomLevel * 100) }}%</span>
                            <button
                                @click="zoomIn"
                                class="p-2 rounded hover:bg-gray-100"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7" />
                                </svg>
                            </button>
                            <button
                                @click="fitToPage"
                                class="p-2 rounded hover:bg-gray-100"
                                title="Ajuster à la page"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- PDF Canvas -->
                    <div class="flex justify-center">
                        <div class="relative bg-white shadow-lg" :style="`transform: scale(${zoomLevel}); transform-origin: top center;`">
                            <canvas
                                ref="pdfCanvas"
                                @mousedown="startAnnotation"
                                @mousemove="updateAnnotation"
                                @mouseup="endAnnotation"
                                class="cursor-crosshair"
                            />
                            
                            <!-- Annotations Layer -->
                            <div class="absolute inset-0 pointer-events-none">
                                <div
                                    v-for="annotation in currentPageAnnotations"
                                    :key="annotation.id"
                                    :style="getAnnotationStyle(annotation)"
                                    class="absolute pointer-events-auto"
                                    @click="selectAnnotation(annotation)"
                                >
                                    <!-- Text Annotation -->
                                    <div v-if="annotation.type === 'text'" class="p-2">
                                        <input
                                            v-if="annotation.id === selectedAnnotation?.id"
                                            v-model="annotation.content"
                                            @blur="updateAnnotationContent(annotation)"
                                            class="bg-transparent border-none outline-none"
                                            :style="`color: ${annotation.color}; font-size: ${annotation.fontSize}px;`"
                                        >
                                        <span v-else :style="`color: ${annotation.color}; font-size: ${annotation.fontSize}px;`">
                                            {{ annotation.content }}
                                        </span>
                                    </div>

                                    <!-- Highlight Annotation -->
                                    <div
                                        v-else-if="annotation.type === 'highlight'"
                                        :style="`background-color: ${annotation.color}; opacity: 0.3;`"
                                        class="w-full h-full"
                                    />

                                    <!-- Drawing Annotation -->
                                    <svg
                                        v-else-if="annotation.type === 'draw'"
                                        :width="annotation.width"
                                        :height="annotation.height"
                                        class="absolute"
                                    >
                                        <path
                                            :d="annotation.path"
                                            :stroke="annotation.color"
                                            :stroke-width="annotation.strokeWidth"
                                            fill="none"
                                        />
                                    </svg>

                                    <!-- Shape Annotation -->
                                    <div
                                        v-else-if="annotation.type === 'shape'"
                                        :style="`border: 2px solid ${annotation.color};`"
                                        :class="[
                                            'w-full h-full',
                                            annotation.shape === 'circle' ? 'rounded-full' : ''
                                        ]"
                                    />

                                    <!-- Delete Button -->
                                    <button
                                        v-if="annotation.id === selectedAnnotation?.id"
                                        @click.stop="deleteAnnotation(annotation)"
                                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Properties Panel -->
            <div class="w-80 bg-white border-l border-gray-200 p-4 overflow-y-auto">
                <h3 class="font-semibold text-lg mb-4">Propriétés</h3>

                <!-- Tool Properties -->
                <div v-if="currentTool === 'text'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Police</label>
                        <select v-model="textProperties.fontFamily" class="w-full px-3 py-2 border rounded-lg">
                            <option value="Arial">Arial</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Verdana">Verdana</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Taille</label>
                        <input
                            v-model.number="textProperties.fontSize"
                            type="number"
                            min="8"
                            max="72"
                            class="w-full px-3 py-2 border rounded-lg"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                        <input
                            v-model="textProperties.color"
                            type="color"
                            class="w-full h-10 rounded-lg cursor-pointer"
                        >
                    </div>
                </div>

                <div v-else-if="currentTool === 'highlight'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                        <div class="grid grid-cols-6 gap-2">
                            <button
                                v-for="color in highlightColors"
                                :key="color"
                                @click="highlightProperties.color = color"
                                :style="`background-color: ${color};`"
                                :class="[
                                    'w-10 h-10 rounded-lg border-2',
                                    highlightProperties.color === color ? 'border-gray-800' : 'border-transparent'
                                ]"
                            />
                        </div>
                    </div>
                </div>

                <div v-else-if="currentTool === 'draw'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Épaisseur</label>
                        <input
                            v-model.number="drawProperties.strokeWidth"
                            type="range"
                            min="1"
                            max="10"
                            class="w-full"
                        >
                        <span class="text-sm text-gray-500">{{ drawProperties.strokeWidth }}px</span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                        <input
                            v-model="drawProperties.color"
                            type="color"
                            class="w-full h-10 rounded-lg cursor-pointer"
                        >
                    </div>
                </div>

                <div v-else-if="currentTool === 'shape'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Forme</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                @click="shapeProperties.shape = 'rectangle'"
                                :class="[
                                    'p-3 rounded-lg border-2',
                                    shapeProperties.shape === 'rectangle' ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                                ]"
                            >
                                <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <rect x="4" y="6" width="16" height="12" stroke-width="2"/>
                                </svg>
                            </button>
                            <button
                                @click="shapeProperties.shape = 'circle'"
                                :class="[
                                    'p-3 rounded-lg border-2',
                                    shapeProperties.shape === 'circle' ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                                ]"
                            >
                                <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="8" stroke-width="2"/>
                                </svg>
                            </button>
                            <button
                                @click="shapeProperties.shape = 'arrow'"
                                :class="[
                                    'p-3 rounded-lg border-2',
                                    shapeProperties.shape === 'arrow' ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                                ]"
                            >
                                <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                        <input
                            v-model="shapeProperties.color"
                            type="color"
                            class="w-full h-10 rounded-lg cursor-pointer"
                        >
                    </div>
                </div>

                <div v-else-if="currentTool === 'stamp'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tampon</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="stamp in stamps"
                                :key="stamp.id"
                                @click="selectedStamp = stamp"
                                :class="[
                                    'p-3 rounded-lg border-2',
                                    selectedStamp?.id === stamp.id ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                                ]"
                            >
                                <span class="text-xs">{{ stamp.name }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Annotations List -->
                <div class="mt-8 border-t pt-4">
                    <h4 class="font-medium text-gray-700 mb-3">Annotations ({{ annotations.length }})</h4>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <div
                            v-for="annotation in annotations"
                            :key="annotation.id"
                            @click="selectAnnotation(annotation)"
                            :class="[
                                'p-2 rounded-lg cursor-pointer',
                                selectedAnnotation?.id === annotation.id ? 'bg-indigo-50 border-indigo-300' : 'bg-gray-50 hover:bg-gray-100'
                            ]"
                            class="border"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium">{{ annotation.type }}</span>
                                <span class="text-xs text-gray-500">Page {{ annotation.page }}</span>
                            </div>
                            <div class="text-xs text-gray-600 mt-1">
                                {{ annotation.content || 'Annotation' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import * as pdfjsLib from 'pdfjs-dist';

// Configure PDF.js worker
pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.js`;

const props = defineProps({
    document: Object,
});

// Editor state
const currentTool = ref('select');
const currentPage = ref(1);
const totalPages = ref(0);
const zoomLevel = ref(1);
const hasChanges = ref(false);
const isSaving = ref(false);

// Undo/Redo
const history = ref([]);
const historyIndex = ref(-1);
const canUndo = computed(() => historyIndex.value > 0);
const canRedo = computed(() => historyIndex.value < history.value.length - 1);

// PDF rendering
const pdfCanvas = ref(null);
const pdfDoc = ref(null);
const pageRendering = ref(false);
const pageNumPending = ref(null);

// Annotations
const annotations = ref([]);
const selectedAnnotation = ref(null);
const currentAnnotation = ref(null);
const isDrawing = ref(false);

// Tool properties
const textProperties = ref({
    fontFamily: 'Arial',
    fontSize: 14,
    color: '#000000',
});

const highlightProperties = ref({
    color: '#FFFF00',
});

const highlightColors = [
    '#FFFF00', '#00FF00', '#00FFFF', '#FF00FF', '#FFA500', '#FF0000'
];

const drawProperties = ref({
    strokeWidth: 2,
    color: '#000000',
});

const shapeProperties = ref({
    shape: 'rectangle',
    color: '#000000',
});

const stamps = ref([
    { id: 'approved', name: 'Approuvé' },
    { id: 'rejected', name: 'Rejeté' },
    { id: 'draft', name: 'Brouillon' },
    { id: 'confidential', name: 'Confidentiel' },
    { id: 'urgent', name: 'Urgent' },
    { id: 'signed', name: 'Signé' },
]);

const selectedStamp = ref(null);

// Computed
const currentPageAnnotations = computed(() => {
    return annotations.value.filter(a => a.page === currentPage.value);
});

// Methods
const setTool = (tool) => {
    currentTool.value = tool;
    selectedAnnotation.value = null;
};

const loadPDF = async () => {
    const url = `/storage/${props.document.stored_name}`;
    const loadingTask = pdfjsLib.getDocument(url);
    
    try {
        pdfDoc.value = await loadingTask.promise;
        totalPages.value = pdfDoc.value.numPages;
        renderPage(1);
    } catch (error) {
        console.error('Error loading PDF:', error);
    }
};

const renderPage = async (num) => {
    if (pageRendering.value) {
        pageNumPending.value = num;
        return;
    }
    
    pageRendering.value = true;
    
    try {
        const page = await pdfDoc.value.getPage(num);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = pdfCanvas.value;
        const context = canvas.getContext('2d');
        
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        await page.render(renderContext).promise;
        pageRendering.value = false;
        
        if (pageNumPending.value !== null) {
            renderPage(pageNumPending.value);
            pageNumPending.value = null;
        }
    } catch (error) {
        console.error('Error rendering page:', error);
        pageRendering.value = false;
    }
};

const previousPage = () => {
    if (currentPage.value <= 1) return;
    currentPage.value--;
    renderPage(currentPage.value);
};

const nextPage = () => {
    if (currentPage.value >= totalPages.value) return;
    currentPage.value++;
    renderPage(currentPage.value);
};

const goToPage = () => {
    if (currentPage.value < 1) currentPage.value = 1;
    if (currentPage.value > totalPages.value) currentPage.value = totalPages.value;
    renderPage(currentPage.value);
};

const zoomIn = () => {
    zoomLevel.value = Math.min(zoomLevel.value + 0.25, 3);
};

const zoomOut = () => {
    zoomLevel.value = Math.max(zoomLevel.value - 0.25, 0.5);
};

const fitToPage = () => {
    zoomLevel.value = 1;
};

const startAnnotation = (e) => {
    if (currentTool.value === 'select') return;
    
    const rect = pdfCanvas.value.getBoundingClientRect();
    const x = (e.clientX - rect.left) / zoomLevel.value;
    const y = (e.clientY - rect.top) / zoomLevel.value;
    
    const annotation = {
        id: Date.now(),
        type: currentTool.value,
        page: currentPage.value,
        x: x,
        y: y,
        width: 0,
        height: 0,
        content: '',
        color: getToolColor(),
        fontSize: textProperties.value.fontSize,
        fontFamily: textProperties.value.fontFamily,
        strokeWidth: drawProperties.value.strokeWidth,
        shape: shapeProperties.value.shape,
        path: currentTool.value === 'draw' ? `M ${x} ${y}` : '',
    };
    
    if (currentTool.value === 'text') {
        annotation.content = 'Nouveau texte';
        annotation.width = 150;
        annotation.height = 30;
    } else if (currentTool.value === 'stamp' && selectedStamp.value) {
        annotation.content = selectedStamp.value.name;
        annotation.stampType = selectedStamp.value.id;
        annotation.width = 100;
        annotation.height = 40;
    }
    
    currentAnnotation.value = annotation;
    isDrawing.value = true;
};

const updateAnnotation = (e) => {
    if (!isDrawing.value || !currentAnnotation.value) return;
    
    const rect = pdfCanvas.value.getBoundingClientRect();
    const x = (e.clientX - rect.left) / zoomLevel.value;
    const y = (e.clientY - rect.top) / zoomLevel.value;
    
    if (currentTool.value === 'draw') {
        currentAnnotation.value.path += ` L ${x} ${y}`;
    } else if (currentTool.value !== 'text' && currentTool.value !== 'stamp') {
        currentAnnotation.value.width = x - currentAnnotation.value.x;
        currentAnnotation.value.height = y - currentAnnotation.value.y;
    }
};

const endAnnotation = () => {
    if (!isDrawing.value || !currentAnnotation.value) return;
    
    if (currentAnnotation.value.width !== 0 || currentAnnotation.value.height !== 0 || 
        currentTool.value === 'text' || currentTool.value === 'stamp') {
        annotations.value.push(currentAnnotation.value);
        addToHistory();
        hasChanges.value = true;
    }
    
    currentAnnotation.value = null;
    isDrawing.value = false;
};

const selectAnnotation = (annotation) => {
    selectedAnnotation.value = annotation;
    currentTool.value = 'select';
};

const deleteAnnotation = (annotation) => {
    const index = annotations.value.indexOf(annotation);
    if (index > -1) {
        annotations.value.splice(index, 1);
        selectedAnnotation.value = null;
        addToHistory();
        hasChanges.value = true;
    }
};

const updateAnnotationContent = (annotation) => {
    addToHistory();
    hasChanges.value = true;
};

const getToolColor = () => {
    switch (currentTool.value) {
        case 'text': return textProperties.value.color;
        case 'highlight': return highlightProperties.value.color;
        case 'draw': return drawProperties.value.color;
        case 'shape': return shapeProperties.value.color;
        default: return '#000000';
    }
};

const getAnnotationStyle = (annotation) => {
    return {
        left: `${annotation.x}px`,
        top: `${annotation.y}px`,
        width: annotation.width ? `${Math.abs(annotation.width)}px` : 'auto',
        height: annotation.height ? `${Math.abs(annotation.height)}px` : 'auto',
    };
};

const addToHistory = () => {
    const state = JSON.stringify(annotations.value);
    if (historyIndex.value < history.value.length - 1) {
        history.value = history.value.slice(0, historyIndex.value + 1);
    }
    history.value.push(state);
    historyIndex.value++;
};

const undo = () => {
    if (!canUndo.value) return;
    historyIndex.value--;
    annotations.value = JSON.parse(history.value[historyIndex.value]);
};

const redo = () => {
    if (!canRedo.value) return;
    historyIndex.value++;
    annotations.value = JSON.parse(history.value[historyIndex.value]);
};

const saveDocument = async () => {
    isSaving.value = true;
    
    try {
        await router.post(route('documents.update', props.document.id), {
            annotations: annotations.value,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                hasChanges.value = false;
            },
        });
    } finally {
        isSaving.value = false;
    }
};

// Lifecycle
onMounted(() => {
    loadPDF();
    
    // Initialize history
    history.value = [JSON.stringify(annotations.value)];
    historyIndex.value = 0;
    
    // Keyboard shortcuts
    const handleKeydown = (e) => {
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if ((e.key === 'z' && e.shiftKey) || e.key === 'y') {
                e.preventDefault();
                redo();
            } else if (e.key === 's') {
                e.preventDefault();
                saveDocument();
            }
        }
    };
    
    window.addEventListener('keydown', handleKeydown);
    
    onUnmounted(() => {
        window.removeEventListener('keydown', handleKeydown);
    });
});
</script>