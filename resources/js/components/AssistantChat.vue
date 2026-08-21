<template>
    <div class="assistant-chat" :class="{ standalone }">
        <div class="assistant-messages-wrap">
        <AssistantMascot class="assistant-mascot-watermark" />
        <div ref="scroller" class="assistant-messages" role="log" aria-live="polite" aria-label="Conversation">
            <div v-if="!messages.length" class="assistant-empty">
                <p>I'm Scout. Hand me rough notes and I'll disappear into the woods and come back with a draft — pages, posts, whatever you're carrying. I can also revise unpublished drafts and answer questions about your existing content and components. I never publish anything; drafts always come back to you first.</p>
            </div>

            <TransitionGroup name="assistant-msg">
                <div v-for="(message, index) in messages" :key="message.key ?? index" :class="['assistant-message', message.role]">
                    <div class="assistant-message-body" v-html="renderBody(message.content)"></div>
                    <Button
                        v-if="message.draft"
                        :href="message.draft.edit_url"
                        variant="primary"
                        size="sm"
                        class="assistant-draft-link"
                        :text="`Review draft: ${message.draft.title} (${message.draft.sections.length} sections) →`"
                    />
                </div>
            </TransitionGroup>

            <div v-if="streamingText" class="assistant-message assistant">
                <div class="assistant-message-body">{{ streamingText }}</div>
            </div>

            <Transition name="assistant-msg">
                <div v-if="busy && !streamingText" class="assistant-message assistant-busy">
                    <span class="assistant-typing" aria-hidden="true">
                        <span class="assistant-typing-dot"></span>
                        <span class="assistant-typing-dot"></span>
                        <span class="assistant-typing-dot"></span>
                    </span>
                    <Transition name="assistant-phase" mode="out-in">
                        <span :key="busyLabel" class="assistant-busy-label">{{ busyLabel }}</span>
                    </Transition>
                </div>
            </Transition>
        </div>
        </div>

        <form class="assistant-input" @submit.prevent="send">
            <textarea
                v-model="input"
                aria-label="Message the assistant"
                :rows="standalone ? 5 : 4"
                :placeholder="configured ? 'Describe the page you want, or ask a question…' : 'Set an AI provider API key (ANTHROPIC_API_KEY, OPENAI_API_KEY, or XAI_API_KEY) to enable chat.'"
                :disabled="busy || !configured"
                @keydown.meta.enter.prevent="send"
            ></textarea>
            <Button type="submit" variant="primary" size="sm" class="assistant-send" :disabled="busy || !configured || !input.trim()">Send</Button>
        </form>
    </div>
</template>

<script>
import { Button } from '@statamic/cms/ui';
import AssistantMascot from './AssistantMascot.vue';

export default {
    components: { Button, AssistantMascot },

    props: {
        configured: { type: Boolean, default: false },
        standalone: { type: Boolean, default: false },
    },

    data() {
        return {
            busy: false,
            busyLabel: 'Thinking',
            streamingText: '',
            roundBreak: false,
            input: '',
            messages: [],
            nextKey: 0,
        };
    },

    methods: {
        renderBody(content) {
            // Escape everything, then allow exactly one construct back in:
            // markdown links to CP paths or same-origin URLs.
            const escaped = String(content ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            return escaped.replace(
                /\[([^\]]+)\]\((\/cp\/[^\s)]+|https?:\/\/[^\s)]+)\)/g,
                (match, label, url) => {
                    if (url.startsWith('http') && !url.startsWith(window.location.origin)) return match;

                    return `<a href="${url}" class="assistant-inline-link">${label}</a>`;
                },
            );
        },

        push(message) {
            this.messages.push({ ...message, key: this.nextKey++ });
        },

        seed(content) {
            if (!this.messages.length) {
                this.push({ role: 'assistant', content });
            }
        },

        entryContext() {
            const match = window.location.pathname.match(/\/collections\/[^/]+\/entries\/([^/]+)/);

            return match ? { entry_id: match[1] } : {};
        },

        send() {
            const text = this.input.trim();
            if (!text) return;

            this.push({ role: 'user', content: text });
            this.input = '';
            this.busy = true;
            this.scrollDown();

            const payload = this.messages.map(({ role, content }) => ({ role, content }));

            this.streamChat({ messages: payload, context: this.entryContext() })
                .catch((error) => {
                    this.push({ role: 'assistant', content: error.message || 'Something went wrong — try again.' });
                })
                .finally(() => {
                    this.busy = false;
                    this.busyLabel = 'Thinking';
                    this.streamingText = '';
                    this.scrollDown();
                });
        },

        async streamChat(body) {
            const response = await fetch('/cp/scout/chat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            if (!response.ok || !response.headers.get('Content-Type')?.includes('text/event-stream')) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || 'Something went wrong — try again.');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const chunk = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);

                    const line = chunk.split('\n').find((l) => l.startsWith('data: '));
                    if (!line) continue;

                    this.handleStreamEvent(JSON.parse(line.slice(6)));
                }
            }
        },

        handleStreamEvent(event) {
            if (event.type === 'progress') {
                this.busyLabel = event.label;
                this.roundBreak = !!this.streamingText;
                return;
            }

            if (event.type === 'text') {
                // A tool round follows narration; keep rounds apart visually.
                if (this.streamingText && this.roundBreak) {
                    this.streamingText += '\n\n';
                    this.roundBreak = false;
                }

                this.streamingText += event.delta;
                this.scrollDown();
                return;
            }

            if (event.type === 'done') {
                let content = this.streamingText.trim();

                // The gave-up reply is synthesized, never streamed — append it.
                if (content && !content.endsWith(event.reply.trim())) {
                    content += '\n\n' + event.reply.trim();
                }

                this.push({ role: 'assistant', content: content || event.reply, draft: event.draft });
                this.streamingText = '';
                this.scrollDown();
                return;
            }

            if (event.type === 'error') {
                if (this.streamingText) this.push({ role: 'assistant', content: this.streamingText });
                this.push({ role: 'assistant', content: event.message });
                this.streamingText = '';
                this.scrollDown();
            }
        },

        scrollDown() {
            this.$nextTick(() => {
                const scroller = this.$refs.scroller;
                if (scroller) scroller.scrollTop = scroller.scrollHeight;
            });
        },
    },
};
</script>
