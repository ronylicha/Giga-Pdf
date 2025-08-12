<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <ApplicationLogo class="mx-auto h-16 w-auto" />
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Password Protected Document
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    This document is protected. Please enter the password to continue.
                </p>
                <p class="mt-1 text-center text-sm font-medium text-gray-900">
                    {{ documentName }}
                </p>
            </div>
            
            <form class="mt-8 space-y-6" @submit.prevent="submit">
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="password" class="sr-only">Password</label>
                        <input 
                            id="password" 
                            v-model="form.password"
                            name="password" 
                            type="password" 
                            autocomplete="off" 
                            required 
                            class="appearance-none rounded-md relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm"
                            :class="{ 'border-red-500': form.errors.password }"
                            placeholder="Enter password"
                            @keyup.enter="submit"
                        />
                    </div>
                </div>

                <div v-if="form.errors.password" class="rounded-md bg-red-50 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-red-800">
                                {{ form.errors.password }}
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="tooManyAttempts" class="rounded-md bg-yellow-50 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-yellow-800">
                                Too many attempts. Please try again later.
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <button 
                        type="submit" 
                        :disabled="form.processing || tooManyAttempts"
                        class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <svg class="h-5 w-5 text-blue-500 group-hover:text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        {{ form.processing ? 'Verifying...' : 'Access Document' }}
                    </button>
                </div>
            </form>

            <div class="text-center">
                <p class="text-sm text-gray-600">
                    Don't have the password? 
                    <span class="font-medium text-blue-600">
                        Contact the person who shared this document.
                    </span>
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
    token: String,
    documentName: String,
});

const form = useForm({
    password: '',
});

const tooManyAttempts = ref(false);

const submit = () => {
    form.post(route('share.verify', props.token), {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.password?.includes('Too many attempts')) {
                tooManyAttempts.value = true;
                setTimeout(() => {
                    tooManyAttempts.value = false;
                }, 60000); // Reset after 60 seconds
            }
        },
    });
};
</script>