<template>
    <div class="assistant-widget">
        <!-- Bubble -->
        <button
            v-if="showBubble && canChat"
            class="assistant-bubble"
            :aria-expanded="open ? 'true' : 'false'"
            ref="bubble"
            aria-label="Open Scout, the content assistant"
            @click="open ? close() : openPanel()"
        >
            <svg v-if="!open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="assistant-bubble-icon">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="assistant-bubble-icon">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>

        <!-- Panel -->
        <div v-show="open" ref="panel" tabindex="-1" class="assistant-panel" role="dialog" aria-modal="true" aria-label="Scout, the content assistant" @keydown.esc="close" @keydown.tab="trapFocus">
            <header class="assistant-panel-header">
                <strong class="assistant-header-title">
                    <AssistantMascot class="assistant-mascot" />
                    Scout <span class="assistant-header-sub">· Content Assistant</span>
                </strong>
                <button class="assistant-panel-close" aria-label="Close" @click="close">&times;</button>
            </header>

            <assistant-chat ref="chat" :configured="configured" :can-audit="canAudit" :can-sync="canSync" @activity="open = true" />
        </div>
    </div>
</template>

<script>
import AssistantChat from './AssistantChat.vue';
import AssistantMascot from './AssistantMascot.vue';

export default {
    components: { AssistantChat, AssistantMascot },

    data() {
        return {
            open: false,
            configured: false,
            canChat: false,
            canAudit: false,
            canSync: false,
            showBubble: true,
        };
    },

    created() {
        this.$axios.get('/cp/assistant/boot').then((response) => {
            this.configured = response.data.configured;
            this.canChat = response.data.can_chat;
            this.canAudit = response.data.can_audit;
            this.canSync = response.data.can_sync;
            this.showBubble = response.data.show_bubble;

            this.registerCommandPalette();
        });

        window.addEventListener('assistant', this.handleCommand);
    },

    unmounted() {
        window.removeEventListener('assistant', this.handleCommand);
    },

    methods: {
        // Registered after boot so entries match what the user can
        // actually do here (permissions, and whether the host site has
        // the audit command at all).
        registerCommandPalette() {
            if (!this.canChat) return;

            const assistant = (detail) => () => window.dispatchEvent(new CustomEvent('assistant', { detail }));

            Statamic.$commandPalette.add({
                category: Statamic.$commandPalette.category.Actions,
                text: ['Scout', 'Ask the assistant'],
                icon: 'ai-chat-spark',
                action: assistant('open'),
            });

            Statamic.$commandPalette.add({
                category: Statamic.$commandPalette.category.Actions,
                text: ['Scout', 'Draft a page from content'],
                icon: 'entry',
                action: assistant('draft'),
            });

            if (this.canAudit) {
                Statamic.$commandPalette.add({
                    category: Statamic.$commandPalette.category.Actions,
                    text: ['Cascadia', 'Run component audit'],
                    icon: 'checkmark',
                    action: assistant('audit'),
                });
            }

            if (this.canSync) {
                Statamic.$commandPalette.add({
                    category: Statamic.$commandPalette.category.Actions,
                    text: ['Cascadia', 'Sync component catalog'],
                    icon: 'sync',
                    action: assistant('sync'),
                });
            }
        },

        openPanel() {
            this.open = true;

            this.$nextTick(() => {
                const textarea = this.$refs.panel?.querySelector('textarea:not(:disabled)');
                (textarea ?? this.focusables()[0] ?? this.$refs.panel)?.focus();
            });
        },

        close() {
            this.open = false;
            this.$nextTick(() => this.$refs.bubble?.focus());
        },

        focusables() {
            return Array.from(
                this.$refs.panel?.querySelectorAll(
                    'button:not(:disabled), textarea:not(:disabled), input:not(:disabled), a[href]',
                ) ?? [],
            ).filter((el) => el.offsetParent !== null);
        },

        trapFocus(event) {
            const focusables = this.focusables();
            if (!focusables.length) return event.preventDefault();

            const first = focusables[0];
            const last = focusables[focusables.length - 1];

            if (event.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.panel)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        handleCommand(event) {
            if (event.detail === 'show-bubble') {
                this.showBubble = true;
                return;
            }

            if (event.detail === 'hide-bubble') {
                this.showBubble = false;
                return;
            }

            if (!this.canChat) return;

            if (event.detail === 'audit') {
                this.openPanel();
                this.$nextTick(() => this.$refs.chat?.runAudit());
                return;
            }

            if (event.detail === 'sync') {
                this.openPanel();
                this.$nextTick(() => this.$refs.chat?.runSync());
                return;
            }

            this.openPanel();

            if (event.detail === 'draft') {
                this.$nextTick(() => this.$refs.chat?.seed(
                    "Paste the content for your page below — rough notes are fine. I'll head into the woods and come back with the right components in the right order.",
                ));
            }
        },
    },
};
</script>
