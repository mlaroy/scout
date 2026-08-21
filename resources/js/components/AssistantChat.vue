<template>
    <div
        class="assistant-chat"
        :class="{ standalone, 'assistant-dragging': dragging }"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div class="assistant-messages-wrap">
        <AssistantMascot class="assistant-mascot-watermark" />
        <div ref="scroller" class="assistant-messages" role="log" aria-live="polite" aria-label="Conversation">
            <div v-if="!messages.length" class="assistant-empty">
                <p>I'm Scout. Hand me rough notes and I'll disappear into the woods and come back with a draft — pages, posts, whatever you're carrying. I can also revise unpublished drafts and answer questions about your existing content and components. I never publish anything; drafts always come back to you first.</p>
                <p v-if="canAttach">Drop in a PDF and I'll draft from it directly.</p>
            </div>

            <TransitionGroup name="assistant-msg">
                <div v-for="(message, index) in messages" :key="message.key ?? index" :class="['assistant-message', message.role]">
                    <div v-if="messageAttachment(message.content)" class="assistant-attachment-chip">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="assistant-attachment-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6" />
                        </svg>
                        {{ messageAttachment(message.content) }}
                    </div>
                    <div class="assistant-message-body" v-html="renderBody(messageText(message.content))"></div>
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
                <div v-if="busy" class="assistant-message assistant-busy">
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

        <div v-if="pendingFile" class="assistant-pending-attachment">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="assistant-attachment-icon">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6" />
            </svg>
            <span class="assistant-pending-attachment-name">{{ pendingFile.name }}</span>
            <button type="button" class="assistant-pending-attachment-remove" aria-label="Remove attachment" @click="removeAttachment">&times;</button>
        </div>

        <form class="assistant-input" @submit.prevent="send">
            <button
                v-if="canAttach"
                type="button"
                class="assistant-attach-button"
                aria-label="Attach a PDF"
                :disabled="busy || !configured"
                @click="$refs.fileInput.click()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01" />
                </svg>
            </button>
            <input v-if="canAttach" ref="fileInput" type="file" accept="application/pdf" class="assistant-file-input" @change="onFilePicked">
            <textarea
                v-model="input"
                aria-label="Message the assistant"
                :rows="standalone ? 5 : 4"
                :placeholder="configured ? 'Describe the page you want, or ask a question…' : 'Set an AI provider API key (ANTHROPIC_API_KEY, OPENAI_API_KEY, or XAI_API_KEY) to enable chat.'"
                :disabled="busy || !configured"
                @keydown.meta.enter.prevent="send"
            ></textarea>
            <Button type="submit" variant="primary" size="sm" class="assistant-send" :disabled="busy || !configured || (!input.trim() && !pendingFile)">Send</Button>
        </form>
    </div>
</template>

<script>
import { Button } from '@statamic/cms/ui';
import AssistantMascot from './AssistantMascot.vue';

const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

export default {
    components: { Button, AssistantMascot },

    props: {
        configured: { type: Boolean, default: false },
        standalone: { type: Boolean, default: false },
        supportsAttachments: { type: Boolean, default: false },
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
            pendingFile: null,
            dragging: false,
        };
    },

    computed: {
        canAttach() {
            return this.supportsAttachments && this.configured;
        },
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

        messageText(content) {
            if (typeof content === 'string') return content;

            return content.find((block) => block.type === 'text')?.text ?? '';
        },

        messageAttachment(content) {
            if (typeof content === 'string') return null;

            return content.find((block) => block.type === 'document')?.title ?? null;
        },

        onDragOver() {
            if (this.canAttach) this.dragging = true;
        },

        onDragLeave() {
            this.dragging = false;
        },

        onDrop(event) {
            this.dragging = false;
            if (!this.canAttach) return;

            const file = event.dataTransfer?.files?.[0];
            if (file) this.attachFile(file);
        },

        onFilePicked(event) {
            const file = event.target.files?.[0];
            if (file) this.attachFile(file);
            event.target.value = '';
        },

        attachFile(file) {
            if (file.type !== 'application/pdf') {
                this.$toast.error('Scout can only read PDF attachments right now.');
                return;
            }

            if (file.size > MAX_ATTACHMENT_BYTES) {
                this.$toast.error('PDF attachments are limited to 10MB.');
                return;
            }

            const reader = new FileReader();

            reader.onload = () => {
                this.pendingFile = { name: file.name, data: reader.result.split(',')[1] ?? '' };
            };

            reader.onerror = () => this.$toast.error('Could not read that file — try again.');

            reader.readAsDataURL(file);
        },

        removeAttachment() {
            this.pendingFile = null;
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
            if (!text && !this.pendingFile) return;

            const content = this.pendingFile
                ? [
                    {
                        type: 'document',
                        source: { type: 'base64', media_type: 'application/pdf', data: this.pendingFile.data },
                        title: this.pendingFile.name,
                    },
                    { type: 'text', text: text || `Draft from the attached PDF (${this.pendingFile.name}).` },
                ]
                : text;

            this.push({ role: 'user', content });
            this.input = '';
            this.pendingFile = null;
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
