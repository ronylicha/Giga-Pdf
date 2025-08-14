<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Éditeur WYSIWYG : {{ document.original_name }}
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
                    <p class="text-gray-600">Chargement de l'éditeur WYSIWYG avancé...</p>
                </div>
            </div>

            <!-- Error message -->
            <div v-else-if="error" class="max-w-7xl mx-auto p-6">
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <strong class="font-bold">Erreur!</strong>
                    <span class="block sm:inline">{{ error }}</span>
                </div>
            </div>

            <!-- New WYSIWYG Editor -->
            <PDFWysiwygEditor
                v-else
                :document="document"
                :initial-content="editorHtml"
                @save="handleSave"
                @export="handleExport"
            />
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
import { ref, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PDFWysiwygEditor from '@/Components/PDFWysiwygEditor.vue';
import axios from 'axios';

const props = defineProps({
    document: Object,
});

const loading = ref(true);
const error = ref(null);
const editorHtml = ref('');
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
            editorHtml.value = response.data.html;
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

// Handle save from WYSIWYG editor
const handleSave = async (html) => {
    try {
        // Validate HTML before sending
        if (!html || html.length === 0) {
            showToast('Le document est vide', 'error');
            return;
        }
        
        const response = await axios.post(
            route('documents.save-html', props.document.id),
            { html },
            {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            }
        );
        
        if (response.data.success) {
            showToast('Document sauvegardé avec succès!', 'success');
            // Store the saved document ID for reference
            if (response.data.document_id) {
                console.log('Document saved with ID:', response.data.document_id);
            }
        } else {
            showToast(response.data.error || 'Erreur lors de la sauvegarde', 'error');
        }
    } catch (err) {
        console.error('Save error:', err);
        const errorMessage = err.response?.data?.message || err.response?.data?.error || 'Erreur lors de la sauvegarde';
        showToast(errorMessage, 'error');
    }
};

// Handle export from WYSIWYG editor
const handleExport = async (html) => {
    try {
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
        htmlInput.value = html;
        form.appendChild(htmlInput);
        
        // Show loading toast
        showToast('Génération du PDF en cours...', 'success');
        
        // Submit form - will redirect to documents list
        document.body.appendChild(form);
        form.submit();
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

onMounted(() => {
    loadHtmlContent();
});
</script>

<style scoped>
/* Styles for the WYSIWYG editor container */
:deep(.pdf-wysiwyg-editor) {
    height: calc(100vh - 120px);
}
</style>