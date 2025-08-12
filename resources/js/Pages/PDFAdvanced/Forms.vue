<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, nextTick } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { DocumentTextIcon, PlusIcon, PencilIcon, TrashIcon, ArrowDownTrayIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
// Import PDF.js - use legacy build like in Show.vue
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf.min.mjs';

// Configure PDF.js worker
pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/pdf.worker.5.4.min.js';

// Store PDF instance outside of Vue's reactivity
let pdfInstance = null;

const props = defineProps({
    documents: {
        type: Array,
        default: () => []
    }
});

const form = useForm({
    document_id: null,
    fields: []
});

const selectedDocument = ref(null);
const pdfCanvas = ref(null);
const currentPage = ref(1);
const totalPages = ref(0);
const scale = ref(1.5);
const isLoading = ref(false);
const showFieldEditor = ref(false);
const selectedField = ref(null);
const isDragging = ref(false);
const draggedField = ref(null);

// Field types
const fieldTypes = [
    { value: 'text', label: 'Champ texte', icon: '📝' },
    { value: 'textarea', label: 'Zone de texte', icon: '📄' },
    { value: 'checkbox', label: 'Case à cocher', icon: '☑️' },
    { value: 'radio', label: 'Bouton radio', icon: '⭕' },
    { value: 'select', label: 'Liste déroulante', icon: '📋' },
    { value: 'date', label: 'Date', icon: '📅' },
    { value: 'signature', label: 'Signature', icon: '✍️' },
    { value: 'email', label: 'Email', icon: '✉️' },
    { value: 'number', label: 'Nombre', icon: '🔢' },
];

// Field editor form
const fieldForm = useForm({
    type: 'text',
    name: '',
    label: '',
    required: false,
    placeholder: '',
    defaultValue: '',
    options: [], // For select/radio
    x: 100,
    y: 100,
    width: 200,
    height: 30,
    page: 1,
    fontSize: 12,
    fontFamily: 'Helvetica',
});

onMounted(() => {
    console.log('Forms component mounted');
});

async function loadDocument() {
    if (!form.document_id) return;
    
    const doc = props.documents.find(d => d.id === parseInt(form.document_id));
    if (!doc) return;
    
    selectedDocument.value = doc;
    isLoading.value = true;
    
    try {
        // Load PDF for preview
        await loadPdfPreview();
        
        // Load existing form fields if any
        await loadExistingFields();
    } catch (error) {
        console.error('Error loading document:', error);
        alert('Erreur lors du chargement du document');
    } finally {
        isLoading.value = false;
    }
}

async function loadPdfPreview() {
    if (!form.document_id) return;
    
    try {
        const url = route('documents.preview', form.document_id);
        console.log('Loading PDF from:', url);
        
        const response = await axios.get(url, {
            responseType: 'arraybuffer',
            headers: {
                'Accept': 'application/pdf',
            }
        });
        
        const loadingTask = pdfjsLib.getDocument({
            data: response.data
        });
        
        pdfInstance = await loadingTask.promise;
        totalPages.value = pdfInstance.numPages;
        
        // Wait for DOM update
        await nextTick();
        
        // Render first page
        if (pdfCanvas.value) {
            await renderPage(1);
        }
    } catch (error) {
        console.error('Error loading PDF:', error);
        throw error;
    }
}

async function renderPage(pageNum) {
    if (!pdfInstance || !pdfCanvas.value) return;
    
    try {
        const page = await pdfInstance.getPage(pageNum);
        const viewport = page.getViewport({ scale: scale.value });
        
        const canvas = pdfCanvas.value;
        const context = canvas.getContext('2d');
        
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        await page.render(renderContext).promise;
        currentPage.value = pageNum;
        
        // Draw form fields on top
        drawFormFields();
    } catch (error) {
        console.error('Error rendering page:', error);
    }
}

function drawFormFields() {
    if (!pdfCanvas.value) return;
    
    const canvas = pdfCanvas.value;
    const context = canvas.getContext('2d');
    
    // Draw each field for the current page
    form.fields.forEach(field => {
        if (field.page !== currentPage.value) return;
        
        // Draw field rectangle
        context.save();
        context.strokeStyle = field === selectedField.value ? '#4F46E5' : '#6B7280';
        context.lineWidth = 2;
        context.setLineDash([5, 5]);
        context.strokeRect(
            field.x * scale.value,
            field.y * scale.value,
            field.width * scale.value,
            field.height * scale.value
        );
        
        // Draw field label
        context.fillStyle = '#4F46E5';
        context.font = `${12 * scale.value}px Arial`;
        context.fillText(
            field.label || field.name,
            field.x * scale.value,
            (field.y - 5) * scale.value
        );
        
        // Draw field icon
        const fieldType = fieldTypes.find(t => t.value === field.type);
        if (fieldType) {
            context.font = `${16 * scale.value}px Arial`;
            context.fillText(
                fieldType.icon,
                (field.x + field.width - 20) * scale.value,
                (field.y + field.height/2 + 5) * scale.value
            );
        }
        
        context.restore();
    });
}

async function loadExistingFields() {
    if (!form.document_id) return;
    
    try {
        const response = await axios.get(route('pdf-advanced.extract-form-data', form.document_id));
        
        if (response.data.success && response.data.fields) {
            form.fields = response.data.fields;
        }
    } catch (error) {
        // No existing fields, start fresh
        form.fields = [];
    }
}

function addField() {
    showFieldEditor.value = true;
    selectedField.value = null;
    fieldForm.reset();
    fieldForm.page = currentPage.value;
}

function editField(field) {
    selectedField.value = field;
    showFieldEditor.value = true;
    
    // Load field data into form
    Object.keys(fieldForm.data()).forEach(key => {
        if (field[key] !== undefined) {
            fieldForm[key] = field[key];
        }
    });
}

function saveField() {
    const fieldData = {
        id: selectedField.value?.id || Date.now(),
        ...fieldForm.data()
    };
    
    if (selectedField.value) {
        // Update existing field
        const index = form.fields.findIndex(f => f.id === selectedField.value.id);
        if (index !== -1) {
            form.fields[index] = fieldData;
        }
    } else {
        // Add new field
        form.fields.push(fieldData);
    }
    
    showFieldEditor.value = false;
    selectedField.value = null;
    renderPage(currentPage.value);
}

function deleteField(field) {
    if (confirm('Supprimer ce champ ?')) {
        form.fields = form.fields.filter(f => f.id !== field.id);
        renderPage(currentPage.value);
    }
}

function cancelFieldEdit() {
    showFieldEditor.value = false;
    selectedField.value = null;
    fieldForm.reset();
}

function addOption() {
    fieldForm.options.push({ value: '', label: '' });
}

function removeOption(index) {
    fieldForm.options.splice(index, 1);
}

// Canvas click handler to place fields
function handleCanvasClick(event) {
    if (!pdfCanvas.value || showFieldEditor.value) return;
    
    const rect = pdfCanvas.value.getBoundingClientRect();
    const x = (event.clientX - rect.left) / scale.value;
    const y = (event.clientY - rect.top) / scale.value;
    
    // Check if clicking on existing field
    const clickedField = form.fields.find(field => {
        return field.page === currentPage.value &&
               x >= field.x && x <= field.x + field.width &&
               y >= field.y && y <= field.y + field.height;
    });
    
    if (clickedField) {
        editField(clickedField);
    } else {
        // Add new field at click position
        fieldForm.x = Math.round(x);
        fieldForm.y = Math.round(y);
        addField();
    }
}

// Drag and drop handlers
function startDrag(field, event) {
    isDragging.value = true;
    draggedField.value = field;
    event.dataTransfer.effectAllowed = 'move';
}

function handleDrop(event) {
    if (!draggedField.value || !pdfCanvas.value) return;
    
    const rect = pdfCanvas.value.getBoundingClientRect();
    const x = (event.clientX - rect.left) / scale.value;
    const y = (event.clientY - rect.top) / scale.value;
    
    draggedField.value.x = Math.round(x);
    draggedField.value.y = Math.round(y);
    draggedField.value.page = currentPage.value;
    
    isDragging.value = false;
    draggedField.value = null;
    renderPage(currentPage.value);
}

async function saveForm() {
    if (!form.document_id || form.fields.length === 0) {
        alert('Veuillez sélectionner un document et ajouter des champs');
        return;
    }
    
    try {
        const response = await axios.post(
            route('pdf-advanced.create-form', form.document_id),
            {
                fields: form.fields,
                create_new_document: true
            }
        );
        
        if (response.data.success) {
            alert('Formulaire créé avec succès !');
            
            // Download the new PDF with form
            if (response.data.document) {
                window.location.href = route('documents.download', response.data.document.id);
            }
        }
    } catch (error) {
        console.error('Error saving form:', error);
        alert('Erreur lors de la sauvegarde du formulaire');
    }
}

async function exportFormData() {
    const data = {
        document: selectedDocument.value,
        fields: form.fields,
        totalPages: totalPages.value
    };
    
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `form_${selectedDocument.value.original_name.replace('.pdf', '')}.json`;
    a.click();
    URL.revokeObjectURL(url);
}

async function importFormData(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    try {
        const text = await file.text();
        const data = JSON.parse(text);
        
        if (data.fields && Array.isArray(data.fields)) {
            form.fields = data.fields;
            renderPage(currentPage.value);
            alert('Champs importés avec succès');
        }
    } catch (error) {
        console.error('Error importing form data:', error);
        alert('Erreur lors de l\'importation des champs');
    }
}

function previousPage() {
    if (currentPage.value > 1) {
        renderPage(currentPage.value - 1);
    }
}

function nextPage() {
    if (currentPage.value < totalPages.value) {
        renderPage(currentPage.value + 1);
    }
}

function zoomIn() {
    scale.value = Math.min(scale.value + 0.25, 3);
    renderPage(currentPage.value);
}

function zoomOut() {
    scale.value = Math.max(scale.value - 0.25, 0.5);
    renderPage(currentPage.value);
}
</script>

<template>
    <Head title="Créer Formulaires PDF" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Créer et Gérer des Formulaires PDF
                </h2>
                <DocumentTextIcon class="h-6 w-6 text-gray-400" />
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Document Selection -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Sélectionner un document
                        </h3>
                        
                        <div class="flex items-center space-x-4">
                            <select
                                v-model="form.document_id"
                                @change="loadDocument"
                                class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            >
                                <option value="">Sélectionner un document PDF</option>
                                <option
                                    v-for="document in (documents || [])"
                                    :key="document.id"
                                    :value="document.id"
                                >
                                    {{ document.original_name }}
                                </option>
                            </select>
                            
                            <div class="flex space-x-2">
                                <label class="cursor-pointer">
                                    <input
                                        type="file"
                                        accept=".json"
                                        @change="importFormData"
                                        class="hidden"
                                    />
                                    <SecondaryButton>
                                        <ArrowUpTrayIcon class="h-4 w-4 mr-2" />
                                        Importer
                                    </SecondaryButton>
                                </label>
                                
                                <SecondaryButton 
                                    @click="exportFormData"
                                    :disabled="!selectedDocument || form.fields.length === 0"
                                >
                                    <ArrowDownTrayIcon class="h-4 w-4 mr-2" />
                                    Exporter
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Editor -->
                <div v-if="selectedDocument" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- PDF Preview -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Aperçu du document
                                </h3>
                                
                                <!-- Controls -->
                                <div class="flex items-center space-x-4">
                                    <!-- Zoom -->
                                    <div class="flex items-center space-x-2">
                                        <button
                                            @click="zoomOut"
                                            class="p-1 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
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
                                            :disabled="currentPage <= 1"
                                            class="p-1 text-gray-600 hover:text-gray-900 disabled:text-gray-300 dark:text-gray-400 dark:hover:text-gray-100 dark:disabled:text-gray-600"
                                        >
                                            ◀
                                        </button>
                                        <span class="text-sm text-gray-600 dark:text-gray-400">
                                            Page {{ currentPage }} / {{ totalPages }}
                                        </span>
                                        <button
                                            @click="nextPage"
                                            :disabled="currentPage >= totalPages"
                                            class="p-1 text-gray-600 hover:text-gray-900 disabled:text-gray-300 dark:text-gray-400 dark:hover:text-gray-100 dark:disabled:text-gray-600"
                                        >
                                            ▶
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Canvas -->
                            <div class="relative overflow-auto max-h-[800px] bg-gray-50 dark:bg-gray-900 rounded border-2 border-dashed border-gray-300 dark:border-gray-700">
                                <canvas
                                    ref="pdfCanvas"
                                    @click="handleCanvasClick"
                                    @drop="handleDrop"
                                    @dragover.prevent
                                    class="mx-auto cursor-crosshair"
                                    style="display: block;"
                                ></canvas>
                                
                                <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-gray-900/80">
                                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
                                </div>
                            </div>
                            
                            <div class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                                💡 Cliquez sur le PDF pour ajouter un champ, ou cliquez sur un champ existant pour le modifier
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fields Panel -->
                    <div class="space-y-6">
                        <!-- Add Field Button -->
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <PrimaryButton @click="addField" class="w-full justify-center">
                                    <PlusIcon class="h-4 w-4 mr-2" />
                                    Ajouter un champ
                                </PrimaryButton>
                            </div>
                        </div>
                        
                        <!-- Fields List -->
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h4 class="text-md font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Champs du formulaire ({{ form.fields.length }})
                                </h4>
                                
                                <div class="space-y-2 max-h-96 overflow-y-auto">
                                    <div
                                        v-for="field in form.fields"
                                        :key="field.id"
                                        :class="[
                                            'p-3 rounded-lg border cursor-move',
                                            field === selectedField 
                                                ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20' 
                                                : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'
                                        ]"
                                        draggable="true"
                                        @dragstart="startDrag(field, $event)"
                                    >
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1">
                                                <div class="flex items-center">
                                                    <span class="mr-2">{{ fieldTypes.find(t => t.value === field.type)?.icon }}</span>
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{ field.label || field.name }}
                                                    </span>
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                    Type: {{ field.type }} | Page: {{ field.page }}
                                                    {{ field.required ? '| Requis' : '' }}
                                                </div>
                                            </div>
                                            <div class="flex space-x-1">
                                                <button
                                                    @click="editField(field)"
                                                    class="p-1 text-gray-600 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400"
                                                >
                                                    <PencilIcon class="h-4 w-4" />
                                                </button>
                                                <button
                                                    @click="deleteField(field)"
                                                    class="p-1 text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
                                                >
                                                    <TrashIcon class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div v-if="form.fields.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                        Aucun champ ajouté
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Save Button -->
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <PrimaryButton 
                                    @click="saveForm" 
                                    class="w-full justify-center"
                                    :disabled="form.fields.length === 0"
                                >
                                    💾 Créer le formulaire PDF
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Field Editor Modal -->
                <div v-if="showFieldEditor" class="fixed inset-0 z-50 overflow-y-auto">
                    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 transition-opacity" @click="cancelFieldEdit">
                            <div class="absolute inset-0 bg-gray-500 dark:bg-gray-900 opacity-75"></div>
                        </div>
                        
                        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    {{ selectedField ? 'Modifier le champ' : 'Ajouter un champ' }}
                                </h3>
                                
                                <div class="space-y-4">
                                    <!-- Field Type -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Type de champ
                                        </label>
                                        <select
                                            v-model="fieldForm.type"
                                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                        >
                                            <option v-for="type in fieldTypes" :key="type.value" :value="type.value">
                                                {{ type.icon }} {{ type.label }}
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <!-- Field Name -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Nom du champ (identifiant unique)
                                        </label>
                                        <input
                                            v-model="fieldForm.name"
                                            type="text"
                                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            placeholder="ex: nom_client"
                                        />
                                    </div>
                                    
                                    <!-- Field Label -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Libellé (affiché à l'utilisateur)
                                        </label>
                                        <input
                                            v-model="fieldForm.label"
                                            type="text"
                                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            placeholder="ex: Nom du client"
                                        />
                                    </div>
                                    
                                    <!-- Required -->
                                    <div>
                                        <label class="flex items-center">
                                            <input
                                                v-model="fieldForm.required"
                                                type="checkbox"
                                                class="rounded border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm"
                                            />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                Champ obligatoire
                                            </span>
                                        </label>
                                    </div>
                                    
                                    <!-- Placeholder (for text fields) -->
                                    <div v-if="['text', 'textarea', 'email', 'number'].includes(fieldForm.type)">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Placeholder
                                        </label>
                                        <input
                                            v-model="fieldForm.placeholder"
                                            type="text"
                                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                        />
                                    </div>
                                    
                                    <!-- Default Value -->
                                    <div v-if="fieldForm.type !== 'signature'">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Valeur par défaut
                                        </label>
                                        <input
                                            v-model="fieldForm.defaultValue"
                                            type="text"
                                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                        />
                                    </div>
                                    
                                    <!-- Options (for select/radio) -->
                                    <div v-if="['select', 'radio'].includes(fieldForm.type)">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Options
                                        </label>
                                        <div class="space-y-2">
                                            <div v-for="(option, index) in fieldForm.options" :key="index" class="flex space-x-2">
                                                <input
                                                    v-model="option.value"
                                                    type="text"
                                                    placeholder="Valeur"
                                                    class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                                />
                                                <input
                                                    v-model="option.label"
                                                    type="text"
                                                    placeholder="Libellé"
                                                    class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                                />
                                                <button
                                                    @click="removeOption(index)"
                                                    class="p-2 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                >
                                                    <TrashIcon class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                        <button
                                            @click="addOption"
                                            class="mt-2 text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                        >
                                            + Ajouter une option
                                        </button>
                                    </div>
                                    
                                    <!-- Position & Size -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Position X
                                            </label>
                                            <input
                                                v-model.number="fieldForm.x"
                                                type="number"
                                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Position Y
                                            </label>
                                            <input
                                                v-model.number="fieldForm.y"
                                                type="number"
                                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Largeur
                                            </label>
                                            <input
                                                v-model.number="fieldForm.width"
                                                type="number"
                                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Hauteur
                                            </label>
                                            <input
                                                v-model.number="fieldForm.height"
                                                type="number"
                                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                <PrimaryButton @click="saveField" class="w-full sm:ml-3 sm:w-auto">
                                    {{ selectedField ? 'Mettre à jour' : 'Ajouter' }}
                                </PrimaryButton>
                                <SecondaryButton @click="cancelFieldEdit" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Annuler
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>