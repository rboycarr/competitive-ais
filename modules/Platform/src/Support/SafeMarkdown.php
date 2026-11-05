<?php

namespace Modules\Platform\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Markdown for display (docs/07 §4 Output, T9): rendered on the server through an allowlist, and handed to the page as
 * a tree of allowlisted nodes, never as HTML (the pages have no v-html, eslint `vue/no-v-html`). Steps:
 * - CommonMark (GitHub flavour) with raw HTML escaped, unsafe links refused and nesting limited;
 * - the result is walked: only the tags in TAGS survive, with only the attributes listed for them; any other element
 *   is replaced by its children (its text stays, as text);
 * - a link keeps its address only for http(s) and mailto, and is shown with rel="noopener noreferrer nofollow";
 *   any other link becomes its text;
 * - an image becomes its alt text: nothing on a page fetches an address an author typed (no tracking pixels).
 * The page renders the tree with a fixed tag list of its own (MarkdownView), so a node outside it is dropped there too.
 */
final class SafeMarkdown
{
    /** Element => attributes it may keep. */
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'em' => [], 'del' => [], 'code' => [], 'pre' => [],
        'blockquote' => [], 'ul' => [], 'ol' => ['start'], 'li' => [], 'a' => ['href'],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['align'], 'td' => ['align'],
    ];

    /** Elements that hold only blocks or rows. */
    private const CONTAINERS = ['body', 'ul', 'ol', 'blockquote', 'table', 'thead', 'tbody', 'tr'];

    /** Elements whose content is dropped with them (never shown as text). */
    private const DROPPED = ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math'];

    /**
     * @return list<array<string, mixed>> each {tag, attrs, children} or {text}
     */
    public static function tree(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        // CommonMark's own output (raw HTML already escaped), so libxml's HTML parser only has to read well-formed tags.
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        $body = $document->getElementsByTagName('body')->item(0);

        return $body === null ? [] : self::children($body);
    }

    /**
     * @return list<array<string, mixed>> each {tag, attrs, children} or {text}
     */
    private static function children(DOMNode $parent): array
    {
        $nodes = [];
        // Between blocks (the top level, lists, tables, quotes) whitespace is layout, not content.
        $container = in_array(strtolower($parent->nodeName), self::CONTAINERS, true);
        foreach ($parent->childNodes as $child) {
            if ($container && $child instanceof DOMText && trim($child->data) === '') {
                continue;
            }
            foreach (self::node($child) as $node) {
                // Adjacent text joins, so an unwrapped element leaves one text node.
                $last = array_key_last($nodes);
                if (isset($node['text'], $nodes[$last]['text'])) {
                    $nodes[$last]['text'] .= $node['text'];
                } else {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>> each {tag, attrs, children} or {text}
     */
    private static function node(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            return $node->data === '' ? [] : [['text' => $node->data]];
        }
        if (! $node instanceof DOMElement) {
            return []; // comments, processing instructions
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROPPED, true)) {
            return [];
        }
        if ($tag === 'img') {
            $alt = $node->getAttribute('alt');

            return $alt === '' ? [] : [['text' => $alt]];
        }
        if (! array_key_exists($tag, self::TAGS)) {
            return self::children($node);
        }

        $attrs = [];
        foreach (self::TAGS[$tag] as $name) {
            $value = trim($node->getAttribute($name));
            if ($value === '') {
                continue;
            }
            $keep = match ($name) {
                'href' => self::isSafeLink($value),
                'start' => preg_match('/^[0-9]{1,9}$/', $value) === 1,
                default => in_array($value, ['left', 'center', 'right'], true), // align
            };
            if ($keep) {
                $attrs[$name] = $value;
            }
        }
        if ($tag === 'a') {
            if (! isset($attrs['href'])) {
                return self::children($node);
            }
            $attrs['rel'] = 'noopener noreferrer nofollow';
        }

        return [['tag' => $tag, 'attrs' => $attrs, 'children' => self::children($node)]];
    }

    private static function isSafeLink(string $href): bool
    {
        if (preg_match('/^mailto:[^\s<>"]+@[^\s<>"]+$/i', $href) === 1) {
            return true;
        }

        return WebAddress::isValid($href);
    }
}
