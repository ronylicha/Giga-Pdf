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
        
        // Add timestamp to force fresh request
        const response = await axios.post(
            route('documents.convert-to-html', props.document.id),
            {},
            {
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                },
                params: {
                    _t: Date.now() // Force bypass any caching
                }
            }
        );
        
        if (response.data.success) {
            editorHtml.value = response.data.html;
            console.log('HTML loaded successfully:', {
                length: response.data.html.length,
                timestamp: response.data.timestamp,
                hasContent: response.data.html.includes('contenteditable')
            });
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
        // Show loading toast
        showToast('Génération du PDF en cours...', 'success');
        
        // Use fetch to handle errors properly
        const response = await fetch(route('documents.save-html-as-pdf', props.document.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'text/html,application/json'
            },
            body: JSON.stringify({ html })
        });
        
        if (!response.ok) {
            // Si erreur, afficher la vue d'erreur dans une modal
            const errorHtml = await response.text();
            showErrorModal(errorHtml);
            showToast('Erreur lors de la conversion PDF', 'error');
        } else {
            // Success - check if we got a redirect response
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                // We got a redirect, navigate to documents list
                window.location.href = route('documents.index');
            } else {
                // Parse JSON response
                try {
                    const data = await response.json();
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        showToast('Document sauvegardé avec succès', 'success');
                        setTimeout(() => {
                            window.location.href = route('documents.index');
                        }, 2000);
                    }
                } catch (e) {
                    // If not JSON, assume success and redirect
                    window.location.href = route('documents.index');
                }
            }
        }
    } catch (err) {
        console.error('Export error:', err);
        showToast('Erreur lors de l\'export PDF: ' + err.message, 'error');
    }
};

// Function to show error modal
const showErrorModal = (htmlContent) => {
    // Create modal container
    const modal = document.createElement('div');
    modal.id = 'errorModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    
    // Create iframe for error content
    const iframe = document.createElement('iframe');
    iframe.style.cssText = `
        width: 90%;
        max-width: 700px;
        height: 80%;
        max-height: 600px;
        border: none;
        border-radius: 20px;
        background: white;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    `;
    
    // Add close button
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        position: absolute;
        top: 10%;
        right: 5%;
        width: 40px;
        height: 40px;
        background: white;
        border: none;
        border-radius: 50%;
        font-size: 30px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        z-index: 10000;
    `;
    
    closeBtn.onclick = () => {
        document.body.removeChild(modal);
    };
    
    modal.appendChild(closeBtn);
    modal.appendChild(iframe);
    document.body.appendChild(modal);
    
    // Write content to iframe
    iframe.onload = () => {
        iframe.contentDocument.open();
        iframe.contentDocument.write(htmlContent);
        iframe.contentDocument.close();
    };
    
    // Also allow clicking outside to close
    modal.onclick = (e) => {
        if (e.target === modal) {
            document.body.removeChild(modal);
        }
    };
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