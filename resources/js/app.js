import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { route as ziggyRoute } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Chatbot';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const ziggyConfig = props.initialPage.props.ziggy;

        const app = createApp({ render: () => h(App, props) });

        // Make route() available globally in templates
        app.config.globalProperties.route = (name, params, absolute) =>
            ziggyRoute(name, params, absolute, ziggyConfig);

        app.use(plugin);
        app.mount(el);

        return app;
    },
    progress: {
        color: '#4B5563',
    },
});
