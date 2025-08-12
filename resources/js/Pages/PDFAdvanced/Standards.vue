<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import { DocumentCheckIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documents: Object,
});

const selectedDocument = ref(null);
const showConversionModal = ref(false);
const selectedStandard = ref('pdfa-1b');
const isConverting = ref(false);

const standards = [
    {
        value: 'pdfa-1b',
        name: 'PDF/A-1b',
        description: 'Archivage à long terme - Niveau B (basique)',
        icon: '📄'
    },
    {
        value: 'pdfa-2b',
        name: 'PDF/A-2b',
        description: 'Archivage avec support des transparences',
        icon: '📋'
    },
    {
        value: 'pdfa-3b',
        name: 'PDF/A-3b',
        description: 'Archivage avec pièces jointes',
        icon: '📎'
    },
    {
        value: 'pdfx-1a',
        name: 'PDF/X-1a',
        description: 'Impression professionnelle CMYK',
        icon: '🖨️'
    },
    {
        value: 'pdfx-3',
        name: 'PDF/X-3',
        description: 'Impression avec gestion des couleurs',
        icon: '🎨'
    },
    {
        value: 'pdfx-4',
        name: 'PDF/X-4',
        description: 'Impression moderne avec transparences',
        icon: '✨'
    }
];

const form = useForm({
    standard: 'pdfa-1b',
    options: {
        embed_fonts: true,
        optimize: true,
        validate: true
    }
});

function openConversionModal(document) {
    selectedDocument.value = document;
    showConversionModal.value = true;
}

function closeModal() {
    showConversionModal.value = false;
    selectedDocument.value = null;
    form.reset();
}

function convertDocument() {
    if (!selectedDocument.value) return;
    
    isConverting.value = true;
    const endpoint = selectedStandard.value.startsWith('pdfa') 
        ? route('pdf-advanced.convert-pdfa', selectedDocument.value.id)
        : route('pdf-advanced.convert-pdfx', selectedDocument.value.id);
    
    form.standard = selectedStandard.value;
    form.post(endpoint, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            isConverting.value = false;
        },
        onError: () => {
            isConverting.value = false;
        }
    });
}

const selectedStandardInfo = computed(() => {
    return standards.find(s => s.value === selectedStandard.value);
});
</script>

<template>
    <Head title="Standards PDF/A & PDF/X" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Standards PDF/A & PDF/X
                </h2>
                <DocumentCheckIcon class="h-6 w-6 text-gray-400" />
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Introduction -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Conversion aux Standards PDF
                        </h3>
                        <div class="prose dark:prose-invert max-w-none">
                            <p class="text-gray-600 dark:text-gray-400">
                                Convertissez vos documents PDF aux standards internationaux pour l'archivage à long terme (PDF/A) 
                                ou l'impression professionnelle (PDF/X). Ces formats garantissent la préservation et la compatibilité 
                                de vos documents.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">
                                    📚 PDF/A - Archivage
                                </h4>
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    Format standardisé pour l'archivage électronique à long terme. 
                                    Garantit que le document sera lisible dans le futur.
                                </p>
                            </div>
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                <h4 class="font-semibold text-green-900 dark:text-green-100 mb-2">
                                    🖨️ PDF/X - Impression
                                </h4>
                                <p class="text-sm text-green-700 dark:text-green-300">
                                    Format optimisé pour l'impression professionnelle. 
                                    Assure une reproduction fidèle des couleurs et de la mise en page.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents List -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Documents PDF disponibles
                        </h3>

                        <div v-if="documents.data && documents.data.length > 0" class="space-y-3">
                            <div
                                v-for="document in documents.data"
                                :key="document.id"
                                class="flex items-center justify-between p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"
                            >
                                <div class="flex items-center space-x-3">
                                    <DocumentCheckIcon class="h-8 w-8 text-gray-400" />
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ document.original_name }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ (document.size / 1024 / 1024).toFixed(2) }} MB
                                        </p>
                                    </div>
                                </div>
                                <button
                                    @click="openConversionModal(document)"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    Convertir
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <DocumentCheckIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-gray-500 dark:text-gray-400">
                                Aucun document PDF disponible
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conversion Modal -->
        <Modal :show="showConversionModal" @close="closeModal">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Convertir aux Standards PDF
                </h3>

                <div v-if="selectedDocument" class="mb-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Document : <span class="font-medium">{{ selectedDocument.original_name }}</span>
                    </p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Sélectionner un standard
                        </label>
                        <div class="grid grid-cols-1 gap-2">
                            <label
                                v-for="standard in standards"
                                :key="standard.value"
                                class="relative flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                :class="[
                                    selectedStandard === standard.value
                                        ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                                        : 'border-gray-200 dark:border-gray-700'
                                ]"
                            >
                                <input
                                    type="radio"
                                    v-model="selectedStandard"
                                    :value="standard.value"
                                    class="mt-1"
                                />
                                <div class="ml-3">
                                    <span class="text-lg mr-2">{{ standard.icon }}</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ standard.name }}
                                    </span>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                        {{ standard.description }}
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            <strong>Note :</strong> La conversion peut prendre quelques instants selon la taille du document.
                            Le document converti sera disponible dans vos documents.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <button
                        @click="closeModal"
                        :disabled="isConverting"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 disabled:opacity-50"
                    >
                        Annuler
                    </button>
                    <PrimaryButton
                        @click="convertDocument"
                        :disabled="isConverting"
                        :class="{ 'opacity-25': isConverting }"
                    >
                        {{ isConverting ? 'Conversion...' : 'Convertir' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>