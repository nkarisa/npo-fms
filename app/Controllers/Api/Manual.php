<?php

namespace App\Controllers\Api;

use App\Libraries\Markdown;

/**
 * Serves the user manual to the in-app help page: the rendered document and the
 * screenshots it references. The manual lives in docs/ (outside the web root),
 * so it is read and served here rather than linked to directly.
 */
class Manual extends BaseApiController
{
    private const DOC = 'user-manual.md';

    /** The manual rendered to HTML, with a table of contents built from its headings. */
    public function index()
    {
        $path = $this->docPath(self::DOC);
        if ($path === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'The user manual is not available.']);
        }

        $markdown = (string) file_get_contents($path);

        // Rewrite image references (docs/images/foo.png) to the API image route.
        $html = Markdown::render($markdown, static fn (string $src) => '/api/manual/image/' . rawurlencode(basename($src)));

        return $this->response->setJSON([
            'html'    => $html,
            'toc'     => $this->toc($markdown),
            'updated' => date('j M Y', filemtime($path)),
        ]);
    }

    /** Serves one of the manual's images by file name. */
    public function image(string $name)
    {
        // Only a bare file name is accepted, so the path cannot escape the images folder.
        $name = basename($name);
        $path = ROOTPATH . 'docs/images/' . $name;
        if (!is_file($path) || !str_ends_with(strtolower($name), '.png')) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }

        return $this->response
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody((string) file_get_contents($path));
    }

    /** A table of contents from the ## and ### headings, for the on-page sidebar. */
    private function toc(string $markdown): array
    {
        $toc = [];
        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
            if (preg_match('/^(#{2,3})\s+(.*)$/', $line, $m)) {
                $text  = trim($m[2]);
                $toc[] = ['level' => strlen($m[1]), 'text' => $text, 'id' => $this->slug($text)];
            }
        }

        return $toc;
    }

    private function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9 \-]/', '', $text);
        $text = preg_replace('/\s+/', '-', $text);

        return trim($text, '-');
    }

    private function docPath(string $file): ?string
    {
        $path = ROOTPATH . 'docs/' . basename($file);

        return is_file($path) ? $path : null;
    }
}
