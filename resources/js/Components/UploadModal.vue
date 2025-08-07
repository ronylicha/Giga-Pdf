<template>
    <Modal :show="show" @close="$emit('update:show', false)">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">
                Upload Document
            </h2>

            <div
                @drop="handleDrop"
                @dragover.prevent
                @dragenter.prevent
                class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-gray-400 transition"
            >
                <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-gray-600">
                        <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                            <span>Upload a file</span>
                            <input
                                id="file-upload"
                                name="file-upload"
                                type="file"
                                class="sr-only"
                                @change="handleFileSelect"
                                multiple
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.odt,.ods,.odp,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.svg,.webp,.html,.xml,.csv,.json,.md,.epub,.mobi"
                            >
                        </label>
                        <p class="pl-1">or drag and drop</p>
                    </div>
                    <p class="text-xs text-gray-500">
                        PDF, DOC, XLS, PPT, Images up to {{ maxFileSize }}MB
                    </p>
                </div>
            </div>

            <!-- File List -->
            <div v-if="files.length > 0" class="mt-4">
                <h3 class="text-sm font-medium text-gray-900 mb-2">Selected Files</h3>
                <ul class="divide-y divide-gray-200">
                    <li v-for="(file, index) in files" :key="index" class="py-2 flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span class="text-sm text-gray-900">{{ file.name }}</span>
                            <span class="ml-2 text-xs text-gray-500">({{ formatBytes(file.size) }})</span>
                        </div>
                        <button
                            @click="removeFile(index)"
                            class="text-red-600 hover:text-red-800"
                        >
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Upload Progress -->
            <div v-if="uploading" class="mt-4">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-gray-700">Uploading...</span>
                    <span class="text-sm text-gray-500">{{ uploadProgress }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-indigo-600 h-2 rounded-full transition-all duration-300" :style="`width: ${uploadProgress}%`"></div>
                </div>
            </div>

            <!-- Actions -->
            <div class="mt-6 flex justify-end space-x-3">
                <button
                    @click="$emit('update:show', false)"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Cancel
                </button>
                <button
                    @click="uploadFiles"
                    :disabled="files.length === 0 || uploading"
                    class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Upload
                </button>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['update:show']);

const files = ref([]);
const uploading = ref(false);
const uploadProgress = ref(0);
const maxFileSize = ref(100); // MB

const handleDrop = (e) => {
    e.preventDefault();
    const droppedFiles = Array.from(e.dataTransfer.files);
    addFiles(droppedFiles);
};

const handleFileSelect = (e) => {
    const selectedFiles = Array.from(e.target.files);
    addFiles(selectedFiles);
};

const addFiles = (newFiles) => {
    const maxSize = maxFileSize.value * 1024 * 1024;
    
    newFiles.forEach(file => {
        if (file.size > maxSize) {
            window.notify({
                type: 'error',
                title: 'File too large',
                message: `${file.name} exceeds the maximum file size of ${maxFileSize.value}MB`,
            });
            return;
        }
        
        if (!files.value.find(f => f.name === file.name)) {
            files.value.push(file);
        }
    });
};

const removeFile = (index) => {
    files.value.splice(index, 1);
};

const uploadFiles = async () => {
    if (files.value.length === 0) return;
    
    uploading.value = true;
    uploadProgress.value = 0;
    
    const form = useForm({
        files: files.value,
    });
    
    form.post(route('documents.upload'), {
        onProgress: (progress) => {
            uploadProgress.value = Math.round(progress.percentage);
        },
        onSuccess: () => {
            window.notify({
                type: 'success',
                title: 'Success',
                message: 'Files uploaded successfully',
            });
            files.value = [];
            emit('update:show', false);
        },
        onError: () => {
            window.notify({
                type: 'error',
                title: 'Upload Failed',
                message: 'Failed to upload files. Please try again.',
            });
        },
        onFinish: () => {
            uploading.value = false;
            uploadProgress.value = 0;
        },
    });
};

const formatBytes = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};
</script>