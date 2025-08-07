<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Télécharger des Documents
                </h2>
                <Link
                    :href="route('documents.index')"
                    class="text-gray-600 hover:text-gray-900"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <!-- Storage Info -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-gray-700">Espace de stockage</span>
                        <span class="text-sm text-gray-500">
                            {{ formatFileSize(storage.used) }} / {{ formatFileSize(storage.limit) }}
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div
                            :style="`width: ${storagePercentage}%`"
                            :class="[
                                'h-2 rounded-full transition-all duration-300',
                                storagePercentage > 90 ? 'bg-red-600' : storagePercentage > 75 ? 'bg-yellow-500' : 'bg-green-500'
                            ]"
                        />
                    </div>
                </div>

                <!-- Upload Area -->
                <div class="bg-white rounded-lg shadow-sm">
                    <div class="p-6">
                        <div
                            @drop="handleDrop"
                            @dragover.prevent
                            @dragenter.prevent
                            @dragleave="isDragging = false"
                            :class="[
                                'border-2 border-dashed rounded-lg p-12 text-center transition-colors',
                                isDragging ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300 hover:border-gray-400'
                            ]"
                        >
                            <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            
                            <div class="mb-4">
                                <p class="text-lg font-medium text-gray-900">
                                    Glissez et déposez vos fichiers ici
                                </p>
                                <p class="text-sm text-gray-500 mt-1">
                                    ou
                                </p>
                            </div>
                            
                            <div>
                                <label for="file-upload" class="cursor-pointer">
                                    <span class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                                        Parcourir les fichiers
                                    </span>
                                    <input
                                        id="file-upload"
                                        type="file"
                                        multiple
                                        @change="handleFileSelect"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.odt,.ods,.odp,.jpg,.jpeg,.png,.gif,.bmp,.svg,.html,.xml"
                                        class="sr-only"
                                    >
                                </label>
                            </div>
                            
                            <p class="text-xs text-gray-500 mt-4">
                                Formats supportés: PDF, Word, Excel, PowerPoint, Images, HTML, et plus.<br>
                                Taille maximale: {{ maxFileSize }}MB par fichier
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Files Queue -->
                <div v-if="files.length > 0" class="mt-6 bg-white rounded-lg shadow-sm">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">
                                Fichiers sélectionnés ({{ files.length }})
                            </h3>
                            <button
                                @click="clearAll"
                                class="text-sm text-red-600 hover:text-red-900"
                            >
                                Tout supprimer
                            </button>
                        </div>
                        
                        <div class="space-y-3">
                            <div
                                v-for="(file, index) in files"
                                :key="index"
                                class="border border-gray-200 rounded-lg p-4"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center flex-1">
                                        <div class="flex-shrink-0">
                                            <svg
                                                v-if="getFileIcon(file.type) === 'pdf'"
                                                class="h-10 w-10 text-red-500"
                                                fill="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z" />
                                            </svg>
                                            <svg
                                                v-else-if="getFileIcon(file.type) === 'image'"
                                                class="h-10 w-10 text-purple-500"
                                                fill="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path d="M21,3H3C2,3 1,4 1,5V19A2,2 0 0,0 3,21H21C22,21 23,20 23,19V5C23,4 22,3 21,3M5,17L8.5,12.5L11,15.5L14.5,11L19,17H5Z" />
                                            </svg>
                                            <svg
                                                v-else
                                                class="h-10 w-10 text-gray-400"
                                                fill="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path d="M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M11,4H6V20H11L18,20V11H11V4Z" />
                                            </svg>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <p class="text-sm font-medium text-gray-900">{{ file.name }}</p>
                                            <p class="text-sm text-gray-500">{{ formatFileSize(file.size) }}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        <!-- Status -->
                                        <div v-if="file.status === 'pending'" class="text-gray-400">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div v-else-if="file.status === 'uploading'" class="text-blue-600">
                                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </div>
                                        <div v-else-if="file.status === 'success'" class="text-green-600">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div v-else-if="file.status === 'error'" class="text-red-600">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        
                                        <!-- Remove button -->
                                        <button
                                            @click="removeFile(index)"
                                            :disabled="file.status === 'uploading'"
                                            class="text-gray-400 hover:text-gray-600 disabled:opacity-50"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Progress bar -->
                                <div v-if="file.status === 'uploading'" class="mt-3">
                                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                                        <span>Téléchargement en cours...</span>
                                        <span>{{ file.progress }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                        <div
                                            :style="`width: ${file.progress}%`"
                                            class="bg-indigo-600 h-1.5 rounded-full transition-all duration-300"
                                        />
                                    </div>
                                </div>
                                
                                <!-- Error message -->
                                <div v-if="file.error" class="mt-2 text-sm text-red-600">
                                    {{ file.error }}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Upload Actions -->
                        <div class="mt-6 flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                Taille totale: {{ formatFileSize(totalSize) }}
                            </div>
                            <div class="space-x-3">
                                <button
                                    @click="clearAll"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Annuler
                                </button>
                                <button
                                    @click="uploadAll"
                                    :disabled="isUploading || !hasValidFiles"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span v-if="isUploading" class="flex items-center">
                                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Téléchargement...
                                    </span>
                                    <span v-else>Télécharger tout</span>
                                </button>
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
    storage: {
        type: Object,
        default: () => ({ used: 0, limit: 10737418240 }) // 10GB default
    },
    maxFileSize: {
        type: Number,
        default: 100 // 100MB default
    }
});

const files = ref([]);
const isDragging = ref(false);
const isUploading = ref(false);

const storagePercentage = computed(() => {
    if (props.storage.limit === 0) return 0;
    return Math.round((props.storage.used / props.storage.limit) * 100);
});

const totalSize = computed(() => {
    return files.value.reduce((total, file) => total + file.size, 0);
});

const hasValidFiles = computed(() => {
    return files.value.some(file => file.status === 'pending' && !file.error);
});

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const getFileIcon = (type) => {
    if (type.includes('pdf')) return 'pdf';
    if (type.includes('image')) return 'image';
    return 'document';
};

const handleDrop = (e) => {
    e.preventDefault();
    isDragging.value = false;
    handleFiles(e.dataTransfer.files);
};

const handleFileSelect = (e) => {
    handleFiles(e.target.files);
};

const handleFiles = (fileList) => {
    Array.from(fileList).forEach(file => {
        // Check file size
        if (file.size > props.maxFileSize * 1024 * 1024) {
            files.value.push({
                name: file.name,
                size: file.size,
                type: file.type,
                status: 'error',
                error: `Le fichier dépasse la taille maximale de ${props.maxFileSize}MB`,
                progress: 0,
                file: file
            });
            return;
        }
        
        // Check storage quota
        if (props.storage.used + totalSize.value + file.size > props.storage.limit) {
            files.value.push({
                name: file.name,
                size: file.size,
                type: file.type,
                status: 'error',
                error: 'Espace de stockage insuffisant',
                progress: 0,
                file: file
            });
            return;
        }
        
        // Add valid file
        files.value.push({
            name: file.name,
            size: file.size,
            type: file.type,
            status: 'pending',
            error: null,
            progress: 0,
            file: file
        });
    });
};

const removeFile = (index) => {
    files.value.splice(index, 1);
};

const clearAll = () => {
    files.value = [];
};

const uploadAll = async () => {
    isUploading.value = true;
    
    for (let i = 0; i < files.value.length; i++) {
        const file = files.value[i];
        
        if (file.status !== 'pending') continue;
        
        await uploadFile(i);
    }
    
    isUploading.value = false;
    
    // Redirect to documents list after successful upload
    const successCount = files.value.filter(f => f.status === 'success').length;
    if (successCount > 0) {
        setTimeout(() => {
            router.get(route('documents.index'));
        }, 1500);
    }
};

const uploadFile = async (index) => {
    const file = files.value[index];
    file.status = 'uploading';
    
    const formData = new FormData();
    formData.append('file', file.file);
    
    try {
        const response = await axios.post(route('documents.upload'), formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
            onUploadProgress: (progressEvent) => {
                file.progress = Math.round((progressEvent.loaded * 100) / progressEvent.total);
            }
        });
        
        file.status = 'success';
        file.progress = 100;
    } catch (error) {
        file.status = 'error';
        file.error = error.response?.data?.message || 'Erreur lors du téléchargement';
    }
};
</script>