<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Éditeur de document : {{ document.original_name }}
                </h2>
                <div class="flex gap-2">
                    <Link
                        :href="route('documents.show', document.id)"
                        class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded"
                    >
                        Retour au document
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-0">
            <!-- Loading indicator -->
            <div v-if="loading" class="flex justify-center items-center h-96">
                <div class="text-center">
                    <svg class="animate-spin h-12 w-12 mx-auto mb-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-600">Chargement de l'éditeur avancé...</p>
                </div>
            </div>

            <!-- Error message -->
            <div v-else-if="error" class="max-w-7xl mx-auto p-6">
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <strong class="font-bold">Erreur!</strong>
                    <span class="block sm:inline">{{ error }}</span>
                </div>
            </div>

            <!-- Editor iframe -->
            <iframe
                v-else
                id="editorFrame"
                ref="editorFrame"
                :srcdoc="editorHtml"
                class="w-full"
                style="height: calc(100vh - 120px); border: none;"
                @load="onEditorLoad"
            ></iframe>
        </div>

        <!-- Success/Error Toast -->
        <div v-if="toast.show" 
             :class="[
                 'fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 z-50',
                 toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
             ]">
            {{ toast.message }}
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    document: Object,
});

const loading = ref(true);
const error = ref(null);
const editorHtml = ref('');
const editorFrame = ref(null);
const toast = ref({
    show: false,
    type: 'success',
    message: ''
});

// Load the HTML content
const loadHtmlContent = async () => {
    try {
        loading.value = true;
        error.value = null;
        
        const response = await axios.post(route('documents.convert-to-html', props.document.id));
        
        if (response.data.success) {
            // Add CSRF token to the HTML
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            editorHtml.value = response.data.html.replace(
                '</head>',
                `<meta name="csrf-token" content="${csrfToken}"></head>`
            );
        } else {
            error.value = response.data.error || 'Erreur lors de la conversion du document';
        }
    } catch (err) {
        console.error('Error loading HTML:', err);
        error.value = err.response?.data?.error || 'Erreur lors du chargement de l\'éditeur';
    } finally {
        loading.value = false;
    }
};

// Handle editor iframe load
const onEditorLoad = () => {
    const iframe = editorFrame.value;
    if (!iframe) return;
    
    const iframeWindow = iframe.contentWindow;
    
    // Override save functions to use our Vue methods
    iframeWindow.saveDocument = saveDocument;
    iframeWindow.exportAsPdf = exportAsPdf;
    
    // Add message listener for status updates
    window.addEventListener('message', handleEditorMessage);
};

// Handle messages from the editor
const handleEditorMessage = (event) => {
    if (event.data.type === 'status') {
        showToast(event.data.message, 'success');
    } else if (event.data.type === 'error') {
        showToast(event.data.message, 'error');
    }
};

// Save document
const saveDocument = async () => {
    try {
        const iframe = editorFrame.value;
        const content = iframe.contentDocument.getElementById('pdfContent').innerHTML;
        
        const response = await axios.post(
            route('documents.save-html', props.document.id),
            { html: content }
        );
        
        if (response.data.success) {
            showToast('Document sauvegardé avec succès!', 'success');
        } else {
            showToast('Erreur lors de la sauvegarde', 'error');
        }
    } catch (err) {
        console.error('Save error:', err);
        showToast('Erreur lors de la sauvegarde', 'error');
    }
};

// Export as PDF
const exportAsPdf = async () => {
    try {
        const iframe = editorFrame.value;
        const content = iframe.contentDocument.getElementById('pdfContent').innerHTML;
        
        // Create a form to submit the HTML content
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = route('documents.save-html-as-pdf', props.document.id);
        form.style.display = 'none';
        
        // Add CSRF token
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = '_token';
        csrfToken.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
        form.appendChild(csrfToken);
        
        // Add HTML content
        const htmlInput = document.createElement('input');
        htmlInput.type = 'hidden';
        htmlInput.name = 'html';
        htmlInput.value = content;
        form.appendChild(htmlInput);
        
        // Submit form - will redirect to documents list
        document.body.appendChild(form);
        form.submit();
        // Note: form.removeChild not needed as page will redirect
    } catch (err) {
        console.error('Export error:', err);
        showToast('Erreur lors de l\'export PDF', 'error');
    }
};

// Show toast notification
const showToast = (message, type = 'success') => {
    toast.value = {
        show: true,
        type,
        message
    };
    
    setTimeout(() => {
        toast.value.show = false;
    }, 3000);
};

// Keyboard shortcuts
const handleKeyboard = (e) => {
    // Ctrl/Cmd + S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveDocument();
    }
    // Ctrl/Cmd + E to export as PDF
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportAsPdf();
    }
};

onMounted(() => {
    loadHtmlContent();
    document.addEventListener('keydown', handleKeyboard);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeyboard);
    window.removeEventListener('message', handleEditorMessage);
});
</script>

<style scoped>
/* Ensure iframe takes full height */
iframe {
    min-height: 600px;
}
</style>