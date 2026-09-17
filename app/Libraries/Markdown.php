<?php

namespace App\Libraries;

/**
 * A small, dependency-free Markdown-to-HTML renderer covering the subset the
 * user manual uses: ATX headings, paragraphs, unordered and ordered lists,
 * GitHub-style tables, blockquotes, horizontal rules, fenced code blocks, and
 * inline bold, code, links and images.
 *
 * It is deliberately not a full CommonMark implementation — it renders our own
 * docs, which are written in a predictable style, and every value is escaped so
 * the output is safe to inject into the page.
 */
class Markdown
{
    /** Called for each image; return the URL to use in the <img src>. */
    private $imageResolver;

    /** @param callable|null $imageResolver maps a markdown image path to a URL */
    public function __construct(?callable $imageResolver = null)
    {
        $this->imageResolver = $imageResolver;
    }

    public static function render(string $markdown, ?callable $imageResolver = null): string
    {
        return (new self($imageResolver))->toHtml($markdown);
    }

    public function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $html  = [];
        $i     = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Blank line — skip.
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block ```
            if (preg_match('/^```/', $line)) {
                $code = [];
                $i++;
                while ($i < $count && !preg_match('/^```/', $lines[$i])) {
                    $code[] = esc($lines[$i]);
                    $i++;
                }
                $i++; // closing fence
                $html[] = '<pre class="doc-pre"><code>' . implode("\n", $code) . '</code></pre>';
                continue;
            }

            // Horizontal rule
            if (preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $html[] = '<hr class="doc-hr">';
                $i++;
                continue;
            }

            // Heading  # .. ######
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = $this->inline(trim($m[2]));
                $id     = $this->slug($m[2]);
                $html[] = "<h{$level} id=\"{$id}\" class=\"doc-h doc-h{$level}\">{$text}</h{$level}>";
                $i++;
                continue;
            }

            // Blockquote
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $quote = [];
                while ($i < $count && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                    $quote[] = $qm[1];
                    $i++;
                }
                $html[] = '<blockquote class="doc-quote">' . $this->inline(trim(implode(' ', $quote))) . '</blockquote>';
                continue;
            }

            // Table (a header row followed by a |---|---| separator)
            if (str_contains($line, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $lines[$i + 1]) && str_contains($lines[$i + 1], '-')) {
                [$tableHtml, $i] = $this->table($lines, $i);
                $html[] = $tableHtml;
                continue;
            }

            // Unordered list
            if (preg_match('/^\s*[-*+]\s+/', $line)) {
                [$listHtml, $i] = $this->list($lines, $i, false);
                $html[] = $listHtml;
                continue;
            }

            // Ordered list
            if (preg_match('/^\s*\d+\.\s+/', $line)) {
                [$listHtml, $i] = $this->list($lines, $i, true);
                $html[] = $listHtml;
                continue;
            }

            // Paragraph — gather consecutive non-blank, non-structural lines.
            $para = [];
            while ($i < $count && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6}\s|>\s?|```|\s*[-*+]\s+|\s*\d+\.\s+)/', $lines[$i])
                && !preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            $html[] = '<p class="doc-p">' . $this->inline(trim(implode(' ', $para))) . '</p>';
        }

        return implode("\n", $html);
    }

    /** @return array{0:string,1:int} */
    private function list(array $lines, int $i, bool $ordered): array
    {
        $count   = count($lines);
        $items   = [];
        $pattern = $ordered ? '/^\s*\d+\.\s+(.*)$/' : '/^\s*[-*+]\s+(.*)$/';

        while ($i < $count && preg_match($pattern, $lines[$i], $m)) {
            $items[] = '<li class="doc-li">' . $this->inline(trim($m[1])) . '</li>';
            $i++;
        }

        $tag  = $ordered ? 'ol' : 'ul';
        $html = "<{$tag} class=\"doc-list\">" . implode('', $items) . "</{$tag}>";

        return [$html, $i];
    }

    /** @return array{0:string,1:int} */
    private function table(array $lines, int $i): array
    {
        $count = count($lines);
        $split = fn (string $row) => array_map('trim', explode('|', trim($row, " \t|")));

        $headers = $split($lines[$i]);
        $i      += 2; // header + separator
        $rows    = [];
        while ($i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
            $rows[] = $split($lines[$i]);
            $i++;
        }

        $head = '<tr>' . implode('', array_map(fn ($h) => '<th>' . $this->inline($h) . '</th>', $headers)) . '</tr>';
        $body = implode('', array_map(function ($cells) use ($headers) {
            $tds = [];
            foreach ($headers as $k => $_) {
                $tds[] = '<td>' . $this->inline($cells[$k] ?? '') . '</td>';
            }

            return '<tr>' . implode('', $tds) . '</tr>';
        }, $rows));

        return ['<table class="doc-table"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>', $i];
    }

    /** Inline formatting on already-structural text. Escapes first, then applies markup. */
    private function inline(string $text): string
    {
        // Images: ![alt](src)  — resolved and escaped.
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
            $alt = esc($m[1]);
            $src = $this->imageResolver ? ($this->imageResolver)($m[2]) : $m[2];

            return '<img class="doc-img" src="' . esc($src, 'attr') . '" alt="' . $alt . '" loading="lazy">';
        }, $text);

        // Protect image tags from further processing by splitting on them.
        $parts = preg_split('/(<img\b[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $k => $part) {
            if (str_starts_with($part, '<img')) {
                continue;
            }
            $parts[$k] = $this->inlineText($part);
        }

        return implode('', $parts);
    }

    private function inlineText(string $text): string
    {
        // Extract inline code spans so their contents are not further formatted.
        $codes = [];
        $text  = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $token         = "\x00" . count($codes) . "\x00";
            $codes[$token] = '<code class="doc-code">' . esc($m[1]) . '</code>';

            return $token;
        }, $text);

        // Escape the rest.
        $text = esc($text);

        // Links: [text](href)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $href = esc($m[2], 'attr');
            $ext  = str_starts_with($m[2], 'http');

            return '<a class="doc-link" href="' . $href . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $text);

        // Bold **text**
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        // Italic *text* (single asterisks not part of a bold pair).
        $text = preg_replace('/(?<!\*)\*(?!\s)([^*]+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $text);

        // Restore code spans.
        return strtr($text, $codes);
    }

    /** A heading id that matches the GitHub-style anchors used in the manual's table of contents. */
    private function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9 \-]/', '', $text);
        $text = preg_replace('/\s+/', '-', $text);

        return trim($text, '-');
    }
}
