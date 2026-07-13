import AssistantWidget from './components/AssistantWidget.vue';
import AssistantPage from './components/AssistantPage.vue';

Statamic.booting(() => {
    Statamic.$components.register('assistant-widget', AssistantWidget);
    Statamic.$inertia.register('AssistantPage', AssistantPage);
});

Statamic.booted(() => {
    Statamic.$components.append('assistant-widget', { props: {} });
});
