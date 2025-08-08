<template>
    <div v-if="$page.props.impersonation && $page.props.impersonation.active" class="bg-yellow-400 text-black border-b-2 border-yellow-600 relative z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center space-x-3">
                    <div class="flex items-center animate-pulse">
                        <ExclamationTriangleIcon class="w-5 h-5" />
                    </div>
                    <span class="font-semibold text-sm">
                        ⚠️ MODE IMPERSONATION - Vous êtes connecté en tant que: 
                        <span class="font-bold">{{ $page.props.auth.user?.name }}</span>
                    </span>
                </div>
                <button
                    @click="stopImpersonation"
                    class="inline-flex items-center px-4 py-1.5 bg-black text-yellow-400 rounded-md text-sm font-bold hover:bg-gray-800 transition shadow-md"
                >
                    <ArrowLeftOnRectangleIcon class="w-4 h-4 mr-2" />
                    Retour à mon compte
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { ExclamationTriangleIcon, ArrowLeftOnRectangleIcon } from '@heroicons/vue/24/outline';

const stopImpersonation = () => {
    router.post(route('stop-impersonation'));
};
</script>