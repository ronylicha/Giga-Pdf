<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ document.original_name }}
                </h2>
                <div class="flex space-x-2">
                    <Link
                        :href="route('documents.html-editor', document.id)"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                    >
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Éditer
                    </Link>
                    <button
                        @click="downloadDocument"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                    >
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                        </svg>
                        Télécharger
                    </button>
                    <button
                        @click="showShareModal = true"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    >
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m9.032 4.024a3 3 0 10-5.464 0m5.464 0a3 3 0 10-5.464 0M9 12a3 3 0 110-6 3 3 0 010 6z" />
                        </svg>
                        Partager
                    </button>
                    <Link
                        :href="route('documents.index')"
                        class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                    >
                        Retour
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <!-- Document Info -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Type</p>
                                <p class="font-semibold">{{ document.mime_type }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Taille</p>
                                <p class="font-semibold">{{ formatFileSize(document.size) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Créé le</p>
                                <p class="font-semibold">{{ formatDate(document.created_at) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Modifié le</p>
                                <p class="font-semibold">{{ formatDate(document.updated_at) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- PDF Viewer -->
                    <div v-if="isPDF" class="p-6">
                        <div class="bg-gray-100 rounded-lg p-4">
                            <div class="flex justify-between items-center mb-4">
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="previousPage"
                                        :disabled="currentPage <= 1"
                                        class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                        </svg>
                                    </button>
                                    <span class="px-3">
                                        Page {{ currentPage }} / {{ totalPages }}
                                    </span>
                                    <button
                                        @click="nextPage"
                                        :disabled="currentPage >= totalPages"
                                        class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </button>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="zoomOut"
                                        class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                        </svg>
                                    </button>
                                    <span class="px-3">{{ Math.round(scale * 100) }}%</span>
                                    <button
                                        @click="zoomIn"
                                        class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="flex justify-center">
                                <canvas ref="pdfCanvas" class="border border-gray-300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Image Viewer -->
                    <div v-else-if="isImage" class="p-6">
                        <div class="flex justify-center">
                            <img :src="documentUrl" :alt="document.original_name" class="max-w-full h-auto">
                        </div>
                    </div>

                    <!-- Other file types -->
                    <div v-else class="p-6">
                        <div class="text-center py-12">
                            <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p class="mt-4 text-gray-500">Aperçu non disponible pour ce type de fichier</p>
                            <button
                                @click="downloadDocument"
                                class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                            >
                                Télécharger le fichier
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Actions Panel -->
                <div v-if="isPDF" class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold mb-4">Actions PDF</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <Link
                            :href="route('tools.merge')"
                            class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 text-center"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z" />
                            </svg>
                            <p class="text-sm">Fusionner</p>
                        </Link>
                        <Link
                            :href="route('tools.split')"
                            class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 text-center"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                            <p class="text-sm">Diviser</p>
                        </Link>
                        <Link
                            :href="route('documents.html-editor', document.id)"
                            class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 text-center"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            <p class="text-sm">Éditer</p>
                        </Link>
                        <Link
                            :href="route('tools.compress')"
                            class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 text-center"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-3-3v6m-9 3h18a2 2 0 002-2V8a2 2 0 00-2-2H3a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <p class="text-sm">Compresser</p>
                        </Link>
                        <Link
                            :href="route('conversions.create', { document_id: document.id })"
                            class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 text-center"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                            <p class="text-sm">Convertir</p>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Share Modal -->
        <Modal :show="showShareModal" @close="showShareModal = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">Partager le document</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Type de partage</label>
                        <select v-model="shareType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="link">Lien public</option>
                            <option value="email">Par email</option>
                        </select>
                    </div>
                    <div v-if="shareType === 'email'">
                        <label class="block text-sm font-medium text-gray-700">Email du destinataire</label>
                        <input
                            v-model="shareEmail"
                            type="email"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                            placeholder="email@example.com"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Expiration (optionnel)</label>
                        <input
                            v-model="shareExpiration"
                            type="datetime-local"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                    </div>
                    <div class="flex items-center">
                        <input
                            v-model="shareWithPassword"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 shadow-sm"
                        >
                        <label class="ml-2 text-sm text-gray-700">Protéger par mot de passe</label>
                    </div>
                    <div v-if="shareWithPassword">
                        <label class="block text-sm font-medium text-gray-700">Mot de passe</label>
                        <input
                            v-model="sharePassword"
                            type="password"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-2">
                    <button
                        @click="showShareModal = false"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                    >
                        Annuler
                    </button>
                    <button
                        @click="createShare"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                    >
                        Créer le lien
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf.min.mjs';

// Configure PDF.js worker - use local file to avoid CORS issues
pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/pdf.worker.min.js';

const props = defineProps({
    document: Object,
});

// PDF viewer state
const pdfCanvas = ref(null);
const currentPage = ref(1);
const totalPages = ref(0);
const scale = ref(1.5);
let pdfDoc = null;
let pageRendering = false;
let pageNumPending = null;

// Share modal
const showShareModal = ref(false);
const shareType = ref('link');
const shareEmail = ref('');
const shareExpiration = ref('');
const shareWithPassword = ref(false);
const sharePassword = ref('');

// Computed
const isPDF = computed(() => props.document.mime_type === 'application/pdf');
const isImage = computed(() => props.document.mime_type?.startsWith('image/'));
const documentUrl = computed(() => route('documents.serve', props.document.id));

// Methods
const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const downloadDocument = () => {
    window.location.href = route('documents.download', props.document.id);
};

const createShare = async () => {
    try {
        await router.post(route('documents.share', props.document.id), {
            type: shareType.value,
            email: shareEmail.value,
            expires_at: shareExpiration.value,
            password: shareWithPassword.value ? sharePassword.value : null,
        }, {
            onSuccess: () => {
                showShareModal.value = false;
                // Show success message
            }
        });
    } catch (error) {
        console.error('Error creating share:', error);
    }
};

// PDF viewer methods
const renderPage = (num) => {
    pageRendering = true;
    
    pdfDoc.getPage(num).then((page) => {
        const viewport = page.getViewport({ scale: scale.value });
        const canvas = pdfCanvas.value;
        const context = canvas.getContext('2d');
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        const renderTask = page.render(renderContext);
        
        renderTask.promise.then(() => {
            pageRendering = false;
            if (pageNumPending !== null) {
                renderPage(pageNumPending);
                pageNumPending = null;
            }
        });
    });
};

const queueRenderPage = (num) => {
    if (pageRendering) {
        pageNumPending = num;
    } else {
        renderPage(num);
    }
};

const previousPage = () => {
    if (currentPage.value <= 1) return;
    currentPage.value--;
    queueRenderPage(currentPage.value);
};

const nextPage = () => {
    if (currentPage.value >= totalPages.value) return;
    currentPage.value++;
    queueRenderPage(currentPage.value);
};

const zoomIn = () => {
    scale.value = Math.min(scale.value * 1.2, 3);
    queueRenderPage(currentPage.value);
};

const zoomOut = () => {
    scale.value = Math.max(scale.value / 1.2, 0.5);
    queueRenderPage(currentPage.value);
};

// Load PDF
const loadPDF = async () => {
    if (!isPDF.value) return;
    
    try {
        const loadingTask = pdfjsLib.getDocument(documentUrl.value);
        pdfDoc = await loadingTask.promise;
        totalPages.value = pdfDoc.numPages;
        renderPage(1);
    } catch (error) {
        console.error('Error loading PDF:', error);
    }
};

onMounted(() => {
    if (isPDF.value) {
        loadPDF();
    }
});
</script>