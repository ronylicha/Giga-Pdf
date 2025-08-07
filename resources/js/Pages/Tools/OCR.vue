<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: String
});
</script>

<template>
    <Head title="OCR (Reconnaissance de texte)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ title }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-medium mb-4">Reconnaissance de texte OCR</h3>
                        <p>Cette page permettra d'extraire le texte d'un fichier PDF ou d'une image.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>