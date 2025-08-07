<template>
    <transition-group
        tag="div"
        class="fixed bottom-0 right-0 p-4 z-50 space-y-2"
        enter-active-class="transition ease-out duration-300"
        enter-from-class="transform opacity-0 translate-x-full"
        enter-to-class="transform opacity-100 translate-x-0"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="transform opacity-100 translate-x-0"
        leave-to-class="transform opacity-0 translate-x-full"
    >
        <div
            v-for="notification in notifications"
            :key="notification.id"
            class="max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden"
        >
            <div class="p-4">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg v-if="notification.type === 'success'" class="h-6 w-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else-if="notification.type === 'error'" class="h-6 w-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else class="h-6 w-6 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3 w-0 flex-1 pt-0.5">
                        <p class="text-sm font-medium text-gray-900">
                            {{ notification.title }}
                        </p>
                        <p v-if="notification.message" class="mt-1 text-sm text-gray-500">
                            {{ notification.message }}
                        </p>
                    </div>
                    <div class="ml-4 flex-shrink-0 flex">
                        <button
                            @click="removeNotification(notification.id)"
                            class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <span class="sr-only">Close</span>
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </transition-group>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const notifications = ref([]);
let notificationId = 0;

const addNotification = (notification) => {
    const id = ++notificationId;
    notifications.value.push({
        id,
        ...notification,
    });

    setTimeout(() => {
        removeNotification(id);
    }, 5000);
};

const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index > -1) {
        notifications.value.splice(index, 1);
    }
};

onMounted(() => {
    // Listen for flash messages from Inertia
    if (page.props.flash?.success) {
        addNotification({
            type: 'success',
            title: 'Success',
            message: page.props.flash.success,
        });
    }

    if (page.props.flash?.error) {
        addNotification({
            type: 'error',
            title: 'Error',
            message: page.props.flash.error,
        });
    }

    // Listen for Laravel Echo events
    if (window.Echo) {
        window.Echo.private(`user.${page.props.auth.user.id}`)
            .listen('ConversionProgress', (e) => {
                addNotification({
                    type: 'info',
                    title: 'Conversion Progress',
                    message: `${e.message} - ${e.progress}%`,
                });
            })
            .listen('ConversionCompleted', (e) => {
                addNotification({
                    type: 'success',
                    title: 'Conversion Completed',
                    message: `Your document has been successfully converted.`,
                });
            })
            .listen('ConversionFailed', (e) => {
                addNotification({
                    type: 'error',
                    title: 'Conversion Failed',
                    message: e.error,
                });
            });
    }
});

// Expose for global usage
window.notify = addNotification;
</script>