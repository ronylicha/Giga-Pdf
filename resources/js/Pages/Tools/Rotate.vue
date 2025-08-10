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
const rotationAngle = ref(90); // 90, 180, 270, -90
const pageRange = ref('all'); // 'all', 'specific'
const specificPages = ref('1-10');
const isProcessing = ref(false);

const pdfDocuments = computed(() => {
    return props.documents.filter(doc => doc.mime_type === 'application/pdf');
});

const canRotate = computed(() => {
    return selectedDocument.value && rotationAngle.value;
});

const rotatePDF = () => {
    if (!canRotate.value) return;
    
    isProcessing.value = true;
    
    const data = {
        document_id: selectedDocument.value,
        rotation_angle: rotationAngle.value,
        page_range: pageRange.value
    };
    
    if (pageRange.value === 'specific') {
        data.specific_pages = specificPages.value;
    }
    
    router.post(route('documents.rotate'), data, {
        onFinish: () => {
            isProcessing.value = false;
        }
    });
};
</script>

<template>
    <Head title="Pivoter PDF" />

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
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Document Selection -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Sélectionner le PDF à pivoter
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
                                        Téléchargez un fichier PDF pour pouvoir le pivoter.
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

                    <!-- Rotation Configuration -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Options de rotation
                                </h3>
                                
                                <!-- Rotation Angle -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Angle de rotation
                                    </label>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="flex items-center">
                                            <input 
                                                id="rotation-90" 
                                                v-model="rotationAngle" 
                                                :value="90" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="rotation-90" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">90° (sens horaire)</div>
                                                <div class="text-xs text-gray-500">Rotation vers la droite</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="rotation-minus-90" 
                                                v-model="rotationAngle" 
                                                :value="-90" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="rotation-minus-90" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">90° (sens antihoraire)</div>
                                                <div class="text-xs text-gray-500">Rotation vers la gauche</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="rotation-180" 
                                                v-model="rotationAngle" 
                                                :value="180" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="rotation-180" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">180°</div>
                                                <div class="text-xs text-gray-500">Retournement complet</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="rotation-270" 
                                                v-model="rotationAngle" 
                                                :value="270" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="rotation-270" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">270°</div>
                                                <div class="text-xs text-gray-500">Trois quarts de tour</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Page Range -->
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Pages à pivoter
                                    </label>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <input 
                                                id="range-all" 
                                                v-model="pageRange" 
                                                value="all" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="range-all" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Toutes les pages</div>
                                                <div class="text-xs text-gray-500">Appliquer la rotation à tout le document</div>
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input 
                                                id="range-specific" 
                                                v-model="pageRange" 
                                                value="specific" 
                                                type="radio" 
                                                class="rounded-full border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                            />
                                            <label for="range-specific" class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                                                <div class="font-medium">Pages spécifiques</div>
                                                <div class="text-xs text-gray-500">Définir une plage de pages</div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div v-if="pageRange === 'specific'" class="mt-3">
                                        <input 
                                            v-model="specificPages"
                                            type="text" 
                                            class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                            placeholder="1-5, 7, 10-15"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Utilisez le format: 1-5, 7, 10-15
                                        </p>
                                    </div>
                                </div>

                                <!-- Rotate Button -->
                                <PrimaryButton 
                                    @click="rotatePDF"
                                    :disabled="!canRotate || isProcessing"
                                    class="w-full justify-center"
                                >
                                    <svg v-if="isProcessing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                    </svg>
                                    {{ isProcessing ? 'Rotation en cours...' : 'Pivoter le PDF' }}
                                </PrimaryButton>

                                <div v-if="!canRotate" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Sélectionnez un PDF pour le pivoter
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
                                            <li>90° sens horaire = rotation vers la droite</li>
                                            <li>90° sens antihoraire = rotation vers la gauche</li>
                                            <li>180° = retournement complet de la page</li>
                                            <li>Le fichier pivoté sera disponible dans vos documents</li>
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