<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Convertir des Documents
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <!-- Conversion Wizard -->
                        <div class="mb-8">
                            <div class="flex items-center justify-center">
                                <div class="flex items-center">
                                    <div :class="[
                                        'flex items-center justify-center w-10 h-10 rounded-full',
                                        step >= 1 ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600'
                                    ]">
                                        1
                                    </div>
                                    <span class="ml-2 text-sm font-medium">Sélectionner</span>
                                </div>
                                <div class="w-32 h-1 mx-4 bg-gray-300">
                                    <div :class="['h-full bg-indigo-600 transition-all duration-300']" :style="`width: ${step >= 2 ? '100%' : '0'}`"></div>
                                </div>
                                <div class="flex items-center">
                                    <div :class="[
                                        'flex items-center justify-center w-10 h-10 rounded-full',
                                        step >= 2 ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600'
                                    ]">
                                        2
                                    </div>
                                    <span class="ml-2 text-sm font-medium">Configurer</span>
                                </div>
                                <div class="w-32 h-1 mx-4 bg-gray-300">
                                    <div :class="['h-full bg-indigo-600 transition-all duration-300']" :style="`width: ${step >= 3 ? '100%' : '0'}`"></div>
                                </div>
                                <div class="flex items-center">
                                    <div :class="[
                                        'flex items-center justify-center w-10 h-10 rounded-full',
                                        step >= 3 ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600'
                                    ]">
                                        3
                                    </div>
                                    <span class="ml-2 text-sm font-medium">Convertir</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1: Select Files -->
                        <div v-if="step === 1">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Sélectionner les fichiers à convertir</h3>
                            
                            <!-- File Selection -->
                            <div class="mb-6">
                                <div class="flex space-x-4 mb-4">
                                    <button
                                        @click="sourceType = 'upload'"
                                        :class="[
                                            'px-4 py-2 rounded-lg',
                                            sourceType === 'upload' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'
                                        ]"
                                    >
                                        Télécharger des fichiers
                                    </button>
                                    <button
                                        @click="sourceType = 'existing'"
                                        :class="[
                                            'px-4 py-2 rounded-lg',
                                            sourceType === 'existing' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'
                                        ]"
                                    >
                                        Mes documents
                                    </button>
                                </div>

                                <!-- Upload Area -->
                                <div v-if="sourceType === 'upload'" 
                                     @drop="handleDrop"
                                     @dragover.prevent
                                     @dragenter.prevent
                                     class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                    </svg>
                                    <p class="text-gray-600 mb-2">Glissez vos fichiers ici ou</p>
                                    <label class="cursor-pointer">
                                        <span class="mt-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                                            Parcourir
                                        </span>
                                        <input type="file" multiple @change="handleFileSelect" class="hidden" accept="*">
                                    </label>
                                </div>

                                <!-- Existing Documents -->
                                <div v-else class="border rounded-lg max-h-96 overflow-y-auto">
                                    <div v-for="doc in userDocuments" :key="doc.id" 
                                         class="flex items-center justify-between p-3 hover:bg-gray-50 border-b">
                                        <div class="flex items-center">
                                            <input
                                                type="checkbox"
                                                :value="doc"
                                                v-model="selectedFiles"
                                                class="mr-3 rounded border-gray-300 text-indigo-600"
                                            >
                                            <div>
                                                <p class="font-medium text-gray-900">{{ doc.original_name }}</p>
                                                <p class="text-sm text-gray-500">{{ formatFileSize(doc.size) }} • {{ doc.extension }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Selected Files -->
                            <div v-if="selectedFiles.length > 0" class="mb-6">
                                <h4 class="font-medium text-gray-700 mb-2">Fichiers sélectionnés ({{ selectedFiles.length }})</h4>
                                <div class="space-y-2">
                                    <div v-for="(file, index) in selectedFiles" :key="index"
                                         class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex items-center">
                                            <svg class="w-8 h-8 text-gray-400 mr-3" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z" />
                                            </svg>
                                            <div>
                                                <p class="font-medium">{{ file.name || file.original_name }}</p>
                                                <p class="text-sm text-gray-500">{{ getFileExtension(file) }}</p>
                                            </div>
                                        </div>
                                        <button @click="removeFile(index)" class="text-red-600 hover:text-red-800">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button
                                    @click="step = 2"
                                    :disabled="selectedFiles.length === 0"
                                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    Suivant
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Configure -->
                        <div v-if="step === 2">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Configuration de la conversion</h3>

                            <!-- Output Format -->
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Format de sortie</label>
                                <div class="grid grid-cols-4 gap-3">
                                    <button
                                        v-for="format in outputFormats"
                                        :key="format.value"
                                        @click="selectedFormat = format.value"
                                        :class="[
                                            'p-4 rounded-lg border-2 text-center transition',
                                            selectedFormat === format.value 
                                                ? 'border-indigo-600 bg-indigo-50' 
                                                : 'border-gray-200 hover:border-gray-300'
                                        ]"
                                    >
                                        <div class="text-2xl mb-1">{{ format.icon }}</div>
                                        <div class="font-medium">{{ format.name }}</div>
                                        <div class="text-xs text-gray-500">{{ format.extension }}</div>
                                    </button>
                                </div>
                            </div>

                            <!-- Format Options -->
                            <div v-if="selectedFormat === 'pdf'" class="mb-6 p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-medium text-gray-700 mb-3">Options PDF</h4>
                                <div class="space-y-3">
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="pdfOptions.compress" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Compresser le PDF</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="pdfOptions.ocr" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Appliquer l'OCR (reconnaissance de texte)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="pdfOptions.mergePdf" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Fusionner tous les PDF en un seul</span>
                                    </label>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Qualité</label>
                                        <select v-model="pdfOptions.quality" class="w-full px-3 py-2 border rounded-lg">
                                            <option value="high">Haute qualité</option>
                                            <option value="medium">Qualité moyenne</option>
                                            <option value="low">Basse qualité (fichier plus petit)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="selectedFormat === 'image'" class="mb-6 p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-medium text-gray-700 mb-3">Options Image</h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Format d'image</label>
                                        <select v-model="imageOptions.format" class="w-full px-3 py-2 border rounded-lg">
                                            <option value="jpg">JPG</option>
                                            <option value="png">PNG</option>
                                            <option value="webp">WebP</option>
                                            <option value="tiff">TIFF</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Résolution (DPI)</label>
                                        <input type="number" v-model="imageOptions.dpi" min="72" max="600" class="w-full px-3 py-2 border rounded-lg">
                                    </div>
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="imageOptions.grayscale" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Convertir en niveaux de gris</span>
                                    </label>
                                </div>
                            </div>

                            <div v-else-if="selectedFormat === 'word'" class="mb-6 p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-medium text-gray-700 mb-3">Options Word</h4>
                                <div class="space-y-3">
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="wordOptions.preserveFormatting" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Préserver la mise en forme</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" v-model="wordOptions.extractImages" class="rounded border-gray-300 text-indigo-600 mr-2">
                                        <span>Extraire les images</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex justify-between">
                                <button
                                    @click="step = 1"
                                    class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                                >
                                    Précédent
                                </button>
                                <button
                                    @click="startConversion"
                                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                                >
                                    Démarrer la conversion
                                </button>
                            </div>
                        </div>

                        <!-- Step 3: Convert -->
                        <div v-if="step === 3">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Conversion en cours...</h3>

                            <!-- Conversion Progress -->
                            <div class="space-y-4">
                                <div v-for="(file, index) in conversionQueue" :key="index"
                                     class="p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center">
                                            <div v-if="file.status === 'pending'" class="text-gray-400 mr-3">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <div v-else-if="file.status === 'processing'" class="text-blue-600 mr-3">
                                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </div>
                                            <div v-else-if="file.status === 'completed'" class="text-green-600 mr-3">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <div v-else-if="file.status === 'error'" class="text-red-600 mr-3">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="font-medium">{{ file.name }}</p>
                                                <p class="text-sm text-gray-500">
                                                    {{ file.status === 'pending' ? 'En attente' :
                                                       file.status === 'processing' ? 'Conversion en cours...' :
                                                       file.status === 'completed' ? 'Terminé' : 'Erreur' }}
                                                </p>
                                            </div>
                                        </div>
                                        <div v-if="file.status === 'completed' && file.downloadUrl">
                                            <a :href="file.downloadUrl" 
                                               class="text-indigo-600 hover:text-indigo-800 font-medium">
                                                Télécharger
                                            </a>
                                        </div>
                                    </div>
                                    <div v-if="file.status === 'processing'" class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                                             :style="`width: ${file.progress}%`"></div>
                                    </div>
                                    <div v-if="file.error" class="mt-2 text-sm text-red-600">
                                        {{ file.error }}
                                    </div>
                                </div>
                            </div>

                            <!-- Summary -->
                            <div v-if="conversionComplete" class="mt-6 p-4 bg-green-50 rounded-lg">
                                <div class="flex items-center">
                                    <svg class="w-6 h-6 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <p class="font-medium text-green-900">Conversion terminée !</p>
                                        <p class="text-sm text-green-700">{{ successCount }} fichier(s) converti(s) avec succès</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between mt-6">
                                <button
                                    @click="resetConversion"
                                    class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                                >
                                    Nouvelle conversion
                                </button>
                                <Link
                                    :href="route('documents.index')"
                                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                                >
                                    Voir mes documents
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    userDocuments: {
        type: Array,
        default: () => []
    }
});

// Conversion state
const step = ref(1);
const sourceType = ref('upload');
const selectedFiles = ref([]);
const selectedFormat = ref('pdf');
const conversionQueue = ref([]);
const conversionComplete = ref(false);

// Format options
const outputFormats = [
    { value: 'pdf', name: 'PDF', extension: '.pdf', icon: '📄' },
    { value: 'word', name: 'Word', extension: '.docx', icon: '📝' },
    { value: 'excel', name: 'Excel', extension: '.xlsx', icon: '📊' },
    { value: 'powerpoint', name: 'PowerPoint', extension: '.pptx', icon: '📽️' },
    { value: 'image', name: 'Image', extension: '.jpg/.png', icon: '🖼️' },
    { value: 'html', name: 'HTML', extension: '.html', icon: '🌐' },
    { value: 'text', name: 'Texte', extension: '.txt', icon: '📃' },
    { value: 'markdown', name: 'Markdown', extension: '.md', icon: '📋' },
];

// Conversion options
const pdfOptions = ref({
    compress: false,
    ocr: false,
    mergePdf: false,
    quality: 'high'
});

const imageOptions = ref({
    format: 'jpg',
    dpi: 150,
    grayscale: false
});

const wordOptions = ref({
    preserveFormatting: true,
    extractImages: true
});

// Computed
const successCount = computed(() => {
    return conversionQueue.value.filter(f => f.status === 'completed').length;
});

// Methods
const handleDrop = (e) => {
    e.preventDefault();
    const files = Array.from(e.dataTransfer.files);
    selectedFiles.value.push(...files);
};

const handleFileSelect = (e) => {
    const files = Array.from(e.target.files);
    selectedFiles.value.push(...files);
};

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1);
};

const getFileExtension = (file) => {
    if (file.name) {
        return file.name.split('.').pop().toUpperCase();
    }
    return file.extension?.toUpperCase() || 'Unknown';
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const startConversion = async () => {
    step.value = 3;
    
    // Initialize conversion queue
    conversionQueue.value = selectedFiles.value.map(file => ({
        name: file.name || file.original_name,
        status: 'pending',
        progress: 0,
        error: null,
        downloadUrl: null
    }));
    
    // Process each file
    for (let i = 0; i < conversionQueue.value.length; i++) {
        await convertFile(i);
    }
    
    conversionComplete.value = true;
};

const convertFile = async (index) => {
    const queueItem = conversionQueue.value[index];
    const file = selectedFiles.value[index];
    
    queueItem.status = 'processing';
    
    try {
        const formData = new FormData();
        
        if (file instanceof File) {
            formData.append('file', file);
        } else {
            formData.append('document_id', file.id);
        }
        
        formData.append('output_format', selectedFormat.value);
        
        // Add format-specific options
        if (selectedFormat.value === 'pdf') {
            formData.append('options', JSON.stringify(pdfOptions.value));
        } else if (selectedFormat.value === 'image') {
            formData.append('options', JSON.stringify(imageOptions.value));
        } else if (selectedFormat.value === 'word') {
            formData.append('options', JSON.stringify(wordOptions.value));
        }
        
        const response = await axios.post(route('conversions.create'), formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
            onUploadProgress: (progressEvent) => {
                queueItem.progress = Math.round((progressEvent.loaded * 100) / progressEvent.total);
            }
        });
        
        queueItem.status = 'completed';
        queueItem.progress = 100;
        queueItem.downloadUrl = response.data.download_url;
        
    } catch (error) {
        queueItem.status = 'error';
        queueItem.error = error.response?.data?.message || 'Erreur lors de la conversion';
    }
};

const resetConversion = () => {
    step.value = 1;
    selectedFiles.value = [];
    conversionQueue.value = [];
    conversionComplete.value = false;
    selectedFormat.value = 'pdf';
};
</script>