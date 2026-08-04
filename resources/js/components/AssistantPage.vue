<template>
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header>
            <template #title>
                <AssistantMascot class="assistant-mascot assistant-mascot-lg" />
                Scout <span class="assistant-header-sub">· Content Assistant</span>
            </template>
        </Header>

        <div class="assistant-page-grid">
            <div class="assistant-page-chat">
                <assistant-chat ref="chat" :configured="configured" :can-audit="canAudit" :can-sync="canSync" standalone />
            </div>

            <aside class="assistant-page-sidebar">
                <CardPanel heading="Status">
                    <Table>
                        <TableRow>
                            <TableCell width="45%">API key</TableCell>
                            <TableCell>
                                <Badge :color="configured ? 'green' : 'red'">
                                    {{ configured ? 'Configured' : 'Missing' }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                        <TableRow>
                            <TableCell>Provider</TableCell>
                            <TableCell>{{ provider ?? '—' }}</TableCell>
                        </TableRow>
                        <TableRow>
                            <TableCell>Model</TableCell>
                            <TableCell>{{ model ?? '—' }}</TableCell>
                        </TableRow>
                    </Table>
                    <Description class="mt-3">
                        <template v-if="!configured">Set <code>ANTHROPIC_API_KEY</code>, <code>OPENAI_API_KEY</code>, or <code>XAI_API_KEY</code> in <code>.env</code> to enable chat.</template>
                        <template v-else>Want a different LLM provider? Implement the <code>Cascadia\Scout\AssistantClient</code> contract and add it to <code>config('scout.providers')</code>.</template>
                    </Description>
                </CardPanel>

                <CardPanel heading="Preferences">
                    <div class="flex items-center justify-between gap-3">
                        <label :for="bubbleSwitchId" class="text-sm">Show the assistant bubble on every page</label>
                        <Switch :id="bubbleSwitchId" v-model="bubble" @update:model-value="saveBubble" />
                    </div>
                    <Description class="mt-3">
                        With the bubble hidden, Scout stays reachable from this page and the command palette (&#8984;K).
                    </Description>
                </CardPanel>
            </aside>
        </div>
    </div>
</template>

<script>
import { Header, CardPanel, Table, TableRow, TableCell, Badge, Switch, Description } from '@statamic/cms/ui';
import AssistantChat from './AssistantChat.vue';
import AssistantMascot from './AssistantMascot.vue';

export default {
    components: { Header, CardPanel, Table, TableRow, TableCell, Badge, Switch, Description, AssistantChat, AssistantMascot },

    props: {
        configured: Boolean,
        canAudit: Boolean,
        canSync: Boolean,
        model: String,
        provider: String,
        showBubble: Boolean,
    },

    data() {
        return {
            bubble: this.showBubble,
            bubbleSwitchId: 'assistant-bubble-switch',
        };
    },

    methods: {
        saveBubble() {
            this.$axios
                .post('/cp/assistant/preferences', { show_bubble: this.bubble })
                .then(() => {
                    this.$toast.success(this.bubble ? 'Bubble enabled' : 'Bubble hidden');
                    window.dispatchEvent(new CustomEvent('assistant', { detail: this.bubble ? 'show-bubble' : 'hide-bubble' }));
                });
        },
    },
};
</script>
