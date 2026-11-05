<?php

namespace Modules\Platform\Tests\Feature;

use Modules\Platform\Support\SafeMarkdown;
use Tests\TestCase;

/**
 * docs/07 §4 Output and T9: Markdown is rendered on the server through an allowlist and handed to pages as a node tree.
 * Script, event-handler, style and unsafe-link payloads never become elements or attributes; images never load.
 */
class SafeMarkdownTest extends TestCase
{
    public function test_formatting_survives_as_allowlisted_nodes(): void
    {
        $tree = SafeMarkdown::tree("# Title\n\n- **one**\n- _two_ ~~three~~\n\n| a | b |\n|:--|--:|\n| 1 | 2 |\n\n```\ncode <b>\n```\n\n[link](https://example.test/x?y=1)\n\n3. three\n4. four");

        $this->assertSame(['h1', 'ul', 'table', 'pre', 'p', 'ol'], array_column($tree, 'tag'));
        $this->assertSame(['start' => '3'], $tree[5]['attrs']);
        $this->assertSame(['href' => 'https://example.test/x?y=1', 'rel' => 'noopener noreferrer nofollow'], $tree[4]['children'][0]['attrs']);
        $this->assertSame([['text' => "code <b>\n"]], $tree[3]['children'][0]['children'], 'code keeps its text, as text');
        $this->assertSame([], SafeMarkdown::tree("  \n "));
    }

    public function test_payloads_never_become_elements_attributes_or_requests(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<a href="javascript:alert(1)">x</a>',
            '[x](javascript:alert(1))',
            '[x](JaVaScRiPt:alert(1))',
            '[x](data:text/html;base64,PHNjcmlwdD4=)',
            '[x](vbscript:msgbox)',
            '[x](//evil.example/path)',
            '[x](/relative)',
            '![tracker](https://tracker.example/p.gif)',
            '<iframe src="https://evil.example"></iframe>',
            '<style>body{display:none}</style>',
            '<svg onload=alert(1)>',
            '[x](https://example.test "title" onclick="alert(1)")',
        ];
        foreach ($payloads as $payload) {
            $json = (string) json_encode(SafeMarkdown::tree($payload), JSON_UNESCAPED_SLASHES);
            preg_match_all('/"tag":"([a-z0-9]+)"/', $json, $tags);
            $this->assertSame([], array_diff($tags[1], ['p', 'a']), "$payload produced ".implode(', ', $tags[1]));
            preg_match_all('/"href":"([^"]*)"/', $json, $hrefs);
            foreach ($hrefs[1] as $href) {
                $this->assertMatchesRegularExpression('#^https?://#', $href, $payload);
            }
            foreach (['onerror', 'onload', 'onclick', '"src"', '"style"', 'tracker.example', '"title"'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden.($forbidden[0] === '"' ? '' : '":'), $json, $payload);
            }
            $this->assertStringNotContainsString('https://tracker.example', $json, $payload);
        }
    }

    /** TASK-0084: encoded protocols, autolinks, mailto, and nesting. */
    public function test_edge_cases_keep_only_safe_links_and_allowlisted_nesting(): void
    {
        $links = fn (string $markdown): array => self::hrefs(SafeMarkdown::tree($markdown));

        $this->assertSame([], $links('[x](&#106;avascript:alert(1))'), 'an entity-encoded protocol is still refused');
        $this->assertSame([], $links('[x](java&#x09;script:alert(1))'));
        $this->assertSame([], $links('[x](%6A%61vascript:alert(1))'));
        $this->assertSame([], $links('[x](HTTPS:/missing-slash.example)'));
        $this->assertSame(['HTTPS://Example.test/A'], $links('[x](HTTPS://Example.test/A)'), 'upper-case schemes are http(s) too');
        $this->assertSame(['https://example.test/auto'], $links('<https://example.test/auto>'));
        $this->assertSame(['http://www.example.test'], $links('see www.example.test today'), 'GFM autolinks become http links');
        $this->assertSame(['mailto:analyst@example.test'], $links('<analyst@example.test>'));
        $this->assertSame([], $links('<javascript:alert(1)>'));
        $this->assertSame([], $links('[x](file:///etc/passwd) [y](ftp://example.test/f)'));

        $nested = SafeMarkdown::tree("- one\n  - two\n    > quoted [link](https://example.test)\n");
        $this->assertSame('ul', $nested[0]['tag']);
        $json = (string) json_encode($nested, JSON_UNESCAPED_SLASHES);
        preg_match_all('/"tag":"([a-z0-9]+)"/', $json, $tags);
        $this->assertSame(['ul', 'li', 'ul', 'li', 'blockquote', 'p', 'a'], $tags[1]);
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private static function hrefs(array $nodes): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $attrs = $node['attrs'] ?? [];
            if (is_array($attrs) && is_string($attrs['href'] ?? null)) {
                $found[] = $attrs['href'];
            }
            if (is_array($node['children'] ?? null)) {
                $found = [...$found, ...self::hrefs($node['children'])];
            }
        }

        return $found;
    }

    public function test_raw_html_is_shown_as_text(): void
    {
        $this->assertSame([['tag' => 'p', 'attrs' => [], 'children' => [['text' => '<b onclick="x">bold</b>']]]], SafeMarkdown::tree('<b onclick="x">bold</b>'));
    }
}
