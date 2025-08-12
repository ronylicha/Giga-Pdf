<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Header -->
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-4">
                    <div class="flex items-center">
                        <ApplicationLogo class="h-10 w-auto" />
                        <span class="ml-3 text-xl font-semibold text-gray-900">Giga-PDF</span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-500">Shared by {{ sharedBy }}</span>
                        <a
                            v-if="canDownload"
                            :href="route('share.download', share.token)"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                            </svg>
                            Download
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Info -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ document.original_name }}</h1>
                    
                    <div class="flex items-center space-x-6 text-sm text-gray-500">
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            {{ formatFileSize(document.size) }}
                        </div>
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Shared {{ formatDate(share.created_at) }}
                        </div>
                        <div v-if="share.expires_at" class="flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Expires {{ formatDate(share.expires_at) }}
                        </div>
                    </div>

                    <div v-if="share.message" class="mt-4 p-4 bg-blue-50 rounded-lg">
                        <p class="text-sm text-blue-800">{{ share.message }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- PDF Viewer -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6 mb-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div v-if="document.extension === 'pdf'" class="pdf-viewer">
                        <iframe 
                            :src="documentUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH'"
                            class="w-full h-screen rounded-lg border border-gray-200"
                            title="PDF Document"
                        ></iframe>
                    </div>
                    
                    <div v-else-if="isImage(document.extension)" class="image-viewer">
                        <img 
                            :src="documentUrl" 
                            :alt="document.original_name"
                            class="max-w-full h-auto mx-auto rounded-lg"
                        />
                    </div>
                    
                    <div v-else class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">Document Preview</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Preview is not available for this file type ({{ document.extension }}).
                        </p>
                        <div v-if="canDownload" class="mt-6">
                            <a
                                :href="route('share.download', share.token)"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                            >
                                Download to View
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center text-sm text-gray-500">
                <p>This document has been shared via Giga-PDF</p>
                <p class="mt-1">Views: {{ share.views_count }} | Downloads: {{ share.downloads_count }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
    share: Object,
    document: Object,
    documentUrl: String,
    canDownload: Boolean,
    canComment: Boolean,
    sharedBy: String,
});

const isImage = (extension) => {
    return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'].includes(extension?.toLowerCase());
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
};
</script>