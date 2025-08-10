<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    title: String,
    documents: {
        type: Array,
        default: () => []
    }
});

const selectedDocument = ref(null);
const watermarkType = ref('text'); // 'text', 'image'
const watermarkText = ref('CONFIDENTIEL');
const watermarkImage = ref(null);
const position = ref('center'); // 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right'
const opacity = ref(50); // 0-100
const fontSize = ref(36); // For text watermarks
const color = ref('#808080'); // For text watermarks
const rotation = ref(0); // -45 to 45 degrees
const isProcessing = ref(false);

const pdfDocuments = computed(() => {
    return props.documents.filter(doc => doc.mime_type === 'application/pdf');
});

const canAddWatermark = computed(() => {
    return selectedDocument.value && 
           ((watermarkType.value === 'text' && watermarkText.value.trim()) ||
            (watermarkType.value === 'image' && watermarkImage.value));
});

const handleImageUpload = (event) => {
    const file = event.target.files[0];
    if (file && file.type.startsWith('image/')) {
        watermarkImage.value = file;
    }
};

const addWatermark = () => {
    if (!canAddWatermark.value) return;
    
    isProcessing.value = true;
    
    const formData = new FormData();
    formData.append('document_id', selectedDocument.value);
    formData.append('watermark_type', watermarkType.value);
    formData.append('position', position.value);
    formData.append('opacity', opacity.value);
    formData.append('rotation', rotation.value);
    
    if (watermarkType.value === 'text') {
        formData.append('watermark_text', watermarkText.value);
        formData.append('font_size', fontSize.value);
        formData.append('color', color.value);
    } else if (watermarkType.value === 'image' && watermarkImage.value) {
        formData.append('watermark_image', watermarkImage.value);
    }
    
    router.post(route('documents.watermark'), formData, {
        onFinish: () => {
            isProcessing.value = false;
        }
    });
};
</script>

<template>
    <Head title="Ajouter un filigrane PDF" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ title }}
                </h2>
                <Link :href="route('documents.index')" 
                      class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    Retour aux documents
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Document Selection -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Sélectionner le PDF
                                </h3>
                                
                                <div class="space-y-2 max-h-96 overflow-y-auto">
                                    <div v-for="document in pdfDocuments" 
                                         :key="document.id"
                                         class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
                                         :class="{'ring-2 ring-indigo-500 bg-indigo-50 dark:bg-indigo-900/20': selectedDocument === document.id}"
                                         @click="selectedDocument = document.id">
                                        <input 
                                            type="radio" 
                                            :checked="selectedDocument === document.id"
                                            @change="selectedDocument = document.id"
                                            class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            name="document"
                                        />
                                        <div class="ml-3 flex-1">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ document.original_name }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ (document.size / 1024 / 1024).toFixed(2) }} MB
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M20,2H8A2,2 0 0,0 6,4V16A2,2 0 0,0 8,18H20A2,2 0 0,0 22,16V4A2,2 0 0,0 20,2M20,8H8V4H20V8Z" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="pdfDocuments.length === 0" 
                                     class="text-center py-8">
                                    <div class="text-gray-400 dark:text-gray-600">
                                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">Aucun PDF trouvé</h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Téléchargez un fichier PDF pour pouvoir ajouter un filigrane.
                                    </p>
                                    <div class="mt-6">
                                        <Link :href="route('documents.create')"
                                              class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Télécharger un PDF
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Watermark Configuration -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Configuration du filigrane
                                </h3>
                                
                                <!-- Watermark Type -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Type de filigrane
                                    </label>
                                    <div class="flex space-x-4">
                                        <div class="flex items-center">
                                            <input 
                                                id="type-text" 
                                                v-model="watermarkType" 
                                                value="text" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="type-text" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Texte</div>
                                                <div class="text-xs text-gray-500">Ajouter un texte en filigrane</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="type-image" 
                                                v-model="watermarkType" 
                                                value="image" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="type-image" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Image</div>
                                                <div class="text-xs text-gray-500">Ajouter une image en filigrane</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Text Watermark Options -->
                                <div v-if="watermarkType === 'text'" class="mb-6">
                                    <div class="space-y-4">
                                        <div>
                                            <label for="watermark-text" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Texte du filigrane
                                            </label>
                                            <input 
                                                id="watermark-text"
                                                v-model="watermarkText"
                                                type="text" 
                                                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                                placeholder="CONFIDENTIEL"
                                            />
                                        </div>
                                        
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label for="font-size" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                    Taille de police
                                                </label>
                                                <input 
                                                    id="font-size"
                                                    v-model.number="fontSize"
                                                    type="number" 
                                                    min="12" 
                                                    max="72" 
                                                    class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                                />
                                            </div>
                                            
                                            <div>
                                                <label for="text-color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                    Couleur
                                                </label>
                                                <input 
                                                    id="text-color"
                                                    v-model="color"
                                                    type="color" 
                                                    class="w-full h-10 rounded-md cursor-pointer"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Image Watermark Options -->
                                <div v-else-if="watermarkType === 'image'" class="mb-6">
                                    <div>
                                        <label for="watermark-image" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Image du filigrane
                                        </label>
                                        <input 
                                            id="watermark-image"
                                            @change="handleImageUpload"
                                            type="file" 
                                            accept="image/*"
                                            class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Formats supportés: JPG, PNG, GIF, SVG
                                        </p>
                                    </div>
                                </div>

                                <!-- Position -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Position
                                    </label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button
                                            @click="position = 'top-left'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'top-left' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Haut gauche</div>
                                        </button>
                                        <button
                                            @click="position = 'top-center'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'top-center' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Haut centre</div>
                                        </button>
                                        <button
                                            @click="position = 'top-right'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'top-right' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Haut droite</div>
                                        </button>
                                        <button
                                            @click="position = 'center-left'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'center-left' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Centre gauche</div>
                                        </button>
                                        <button
                                            @click="position = 'center'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'center' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Centre</div>
                                        </button>
                                        <button
                                            @click="position = 'center-right'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'center-right' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Centre droite</div>
                                        </button>
                                        <button
                                            @click="position = 'bottom-left'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'bottom-left' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Bas gauche</div>
                                        </button>
                                        <button
                                            @click="position = 'bottom-center'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'bottom-center' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Bas centre</div>
                                        </button>
                                        <button
                                            @click="position = 'bottom-right'"
                                            :class="[
                                                'p-3 rounded-lg border-2 text-center transition',
                                                position === 'bottom-right' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-gray-400'
                                            ]"
                                        >
                                            <div class="text-xs">Bas droite</div>
                                        </button>
                                    </div>
                                </div>

                                <!-- Opacity and Rotation -->
                                <div class="mb-6">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label for="opacity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Opacité ({{ opacity }}%)
                                            </label>
                                            <input 
                                                id="opacity"
                                                v-model.number="opacity"
                                                type="range" 
                                                min="10" 
                                                max="100" 
                                                class="w-full"
                                            />
                                        </div>
                                        
                                        <div>
                                            <label for="rotation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Rotation ({{ rotation }}°)
                                            </label>
                                            <input 
                                                id="rotation"
                                                v-model.number="rotation"
                                                type="range" 
                                                min="-45" 
                                                max="45" 
                                                class="w-full"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Add Watermark Button -->
                                <PrimaryButton 
                                    @click="addWatermark"
                                    :disabled="!canAddWatermark || isProcessing"
                                    class="w-full justify-center"
                                >
                                    <svg v-if="isProcessing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4V2a1 1 0 011-1h4a1 1 0 011 1v2m-6 0h6m-6 0a2 2 0 00-2 2v12a2 2 0 002 2h6a2 2 0 002-2V6a2 2 0 00-2-2m-6 0V4" />
                                    </svg>
                                    {{ isProcessing ? 'Ajout en cours...' : 'Ajouter le filigrane' }}
                                </PrimaryButton>

                                <div v-if="!canAddWatermark" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Sélectionnez un PDF et configurez le filigrane
                                </div>
                            </div>
                        </div>

                        <!-- Tips -->
                        <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">
                                        Conseils
                                    </h3>
                                    <div class="mt-2 text-sm text-blue-700 dark:text-blue-300">
                                        <ul class="list-disc pl-5 space-y-1">
                                            <li>Utilisez une opacité réduite pour ne pas gêner la lecture</li>
                                            <li>Les filigranes en diagonale sont souvent plus discrets</li>
                                            <li>Choisissez des couleurs qui contrastent avec le fond</li>
                                            <li>Le fichier avec filigrane sera disponible dans vos documents</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>