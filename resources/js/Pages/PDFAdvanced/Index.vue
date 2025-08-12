<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { 
    ShieldCheckIcon, 
    LockClosedIcon,
    DocumentCheckIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon
} from '@heroicons/vue/24/outline';

const tools = [
    {
        name: 'Signatures Numériques',
        description: 'Signez et vérifiez numériquement vos documents PDF avec des certificats X.509',
        icon: ShieldCheckIcon,
        route: 'pdf-advanced.signatures',
        color: 'blue'
    },
    {
        name: 'Rédaction Sécurisée', 
        description: 'Masquez de manière permanente les informations sensibles dans vos PDF',
        icon: LockClosedIcon,
        route: 'pdf-advanced.redact',
        color: 'red'
    },
    {
        name: 'PDF/A & PDF/X',
        description: 'Convertissez vos PDF aux standards d\'archivage et d\'impression',
        icon: DocumentCheckIcon,
        route: 'pdf-advanced.standards',
        color: 'green'
    },
    {
        name: 'Comparer Documents',
        description: 'Comparez visuellement et textuellement deux versions d\'un PDF',
        icon: MagnifyingGlassIcon,
        route: 'pdf-advanced.compare',
        color: 'purple'
    },
    {
        name: 'Formulaires PDF',
        description: 'Créez, remplissez et extrayez des données de formulaires PDF',
        icon: PencilSquareIcon,
        route: 'pdf-advanced.forms',
        color: 'indigo'
    }
];
</script>

<template>
    <Head title="Outils PDF Avancés" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Outils PDF Avancés
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Fonctionnalités Professionnelles
                            </h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Accédez aux outils avancés pour la gestion professionnelle de vos documents PDF
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <Link
                                v-for="tool in tools"
                                :key="tool.route"
                                :href="route(tool.route)"
                                class="group relative rounded-lg p-6 bg-white dark:bg-gray-700 hover:shadow-lg transition-all duration-200 border border-gray-200 dark:border-gray-600"
                            >
                                <div>
                                    <span
                                        :class="[
                                            'rounded-lg inline-flex p-3',
                                            `bg-${tool.color}-50 dark:bg-${tool.color}-900/20`
                                        ]"
                                    >
                                        <component
                                            :is="tool.icon"
                                            :class="[
                                                'h-6 w-6',
                                                `text-${tool.color}-600 dark:text-${tool.color}-400`
                                            ]"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </div>
                                <div class="mt-4">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                        {{ tool.name }}
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ tool.description }}
                                    </p>
                                </div>
                                <span
                                    class="absolute top-6 right-6 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 dark:group-hover:text-gray-500"
                                    aria-hidden="true"
                                >
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" />
                                    </svg>
                                </span>
                            </Link>
                        </div>

                        <div class="mt-8 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">
                                        Information
                                    </h3>
                                    <div class="mt-2 text-sm text-blue-700 dark:text-blue-300">
                                        <p>
                                            Ces outils avancés nécessitent des documents PDF valides. 
                                            Certaines fonctionnalités peuvent nécessiter des certificats ou des configurations spécifiques.
                                        </p>
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