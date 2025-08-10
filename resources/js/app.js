import '../css/app.css';
import './bootstrap';
// Laravel Echo + Reverb (initialisé dynamiquement après le montage)

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);

        // Initialiser Echo sans bloquer l'app si le client n'est pas disponible
        if (typeof window !== 'undefined' && import.meta.env.VITE_REVERB_APP_KEY) {
            import('laravel-echo')
                .then(({ default: Echo }) => {
                    try {
                        window.Echo = new Echo({
                            broadcaster: 'reverb',
                            key: import.meta.env.VITE_REVERB_APP_KEY,
                            wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
                            wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
                            forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
                            enabledTransports: ['ws', 'wss'],
                        })
                    } catch (e) {
                        console.warn('Echo initialization failed:', e)
                    }
                })
                .catch((e) => console.warn('Echo client not available:', e))
        }

        return vueApp;
    },
    progress: {
        color: '#4B5563',
    },
});
