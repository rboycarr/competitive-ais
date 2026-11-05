// Shows Markdown sanitized on the server (Modules\Platform\Support\SafeMarkdown, docs/07 Output) as a tree of nodes.
// Elements are created from this fixed tag list, never from HTML (no v-html): a node with any other tag shows only its
// text, a link keeps only an http(s) or mailto address, and every attribute not listed here is dropped.
import { defineComponent, h, type PropType, type VNode } from 'vue';

export type MarkdownNode = { text: string } | { tag: string; attrs: Record<string, string>; children: MarkdownNode[] };

const ATTRIBUTES: Record<string, string[]> = {
    p: [], br: [], hr: [], strong: [], em: [], del: [], code: [], pre: [], blockquote: [], ul: [], ol: ['start'], li: [],
    a: ['href'], h1: [], h2: [], h3: [], h4: [], h5: [], h6: [],
    table: [], thead: [], tbody: [], tr: [], th: ['align'], td: ['align'],
};
const SAFE_LINK = /^(https?:\/\/|mailto:)/i;
// Headings sit below the page's own h1/h2.
const HEADING_SHIFT: Record<string, string> = { h1: 'h3', h2: 'h4', h3: 'h5', h4: 'h6', h5: 'h6', h6: 'h6' };

function render(node: MarkdownNode): VNode | string {
    if ('text' in node) {
        return node.text;
    }
    const children = node.children.map(render);
    const allowed = ATTRIBUTES[node.tag];
    if (allowed === undefined) {
        return h('span', children);
    }
    const attrs: Record<string, string> = {};
    for (const name of allowed) {
        const value = node.attrs[name];
        if (value !== undefined && (name !== 'href' || SAFE_LINK.test(value))) {
            attrs[name] = value;
        }
    }
    if (node.tag === 'a') {
        if (attrs.href === undefined) {
            return h('span', children);
        }
        attrs.rel = 'noopener noreferrer nofollow';
        attrs.class = 'underline';
    }

    return h(HEADING_SHIFT[node.tag] ?? node.tag, attrs, children);
}

export default defineComponent({
    name: 'MarkdownView',
    props: { nodes: { type: Array as PropType<MarkdownNode[]>, required: true } },
    setup(props) {
        return () => h('div', { class: 'markdown space-y-2' }, props.nodes.map(render));
    },
});
