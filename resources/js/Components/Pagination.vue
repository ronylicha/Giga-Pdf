<template>
    <div class="flex items-center justify-between">
        <div class="flex-1 flex justify-between sm:hidden">
            <Component
                :is="link.url ? 'Link' : 'span'"
                v-for="link in visibleOnMobile"
                :key="link.label"
                :href="link.url"
                :class="[
                    link.url ? 'hover:bg-gray-50' : 'cursor-not-allowed opacity-50',
                    'relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white'
                ]"
                v-html="link.label"
            />
        </div>
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Affichage de
                    <span class="font-medium">{{ from }}</span>
                    à
                    <span class="font-medium">{{ to }}</span>
                    sur
                    <span class="font-medium">{{ total }}</span>
                    résultats
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <Component
                        :is="link.url ? 'Link' : 'span'"
                        v-for="link in links"
                        :key="link.label"
                        :href="link.url"
                        :class="[
                            link.active ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
                            link.url ? '' : 'cursor-not-allowed opacity-50',
                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                            link === links[0] ? 'rounded-l-md' : '',
                            link === links[links.length - 1] ? 'rounded-r-md' : ''
                        ]"
                        v-html="formatLabel(link.label)"
                    />
                </nav>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    links: {
        type: Array,
        required: true,
    },
    from: {
        type: Number,
        default: 0,
    },
    to: {
        type: Number,
        default: 0,
    },
    total: {
        type: Number,
        default: 0,
    },
});

const visibleOnMobile = computed(() => {
    return props.links.filter(link => 
        link.label.includes('Previous') || link.label.includes('Next')
    );
});

const formatLabel = (label) => {
    return label
        .replace('&laquo; Previous', '← Précédent')
        .replace('Next &raquo;', 'Suivant →')
        .replace('&laquo;', '«')
        .replace('&raquo;', '»');
};
</script>