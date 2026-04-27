<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentationIntegrityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function markdownFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [
            $root.'/AGENTS.md',
            $root.'/README.md',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/docs', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        $files = array_unique($files);
        sort($files);

        $datasets = [];

        foreach ($files as $path) {
            $datasets[str_replace($root.'/', '', $path)] = [$path];
        }

        return $datasets;
    }

    #[DataProvider('markdownFiles')]
    public function test_markdown_links_point_to_existing_files_and_anchors(string $path): void
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        foreach ($this->markdownLinks($contents) as $link) {
            $this->assertResolvableMarkdownLink($path, $link);
        }
    }

    #[DataProvider('markdownFiles')]
    public function test_wiki_links_resolve_to_documentation_pages(string $path): void
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        foreach ($this->wikiLinks($contents) as $target) {
            $this->assertArrayHasKey(
                $target,
                $this->wikiLinkIndex(),
                sprintf('Wiki link [[%s]] in %s does not resolve to a docs markdown file.', $target, $this->relativePath($path)),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function markdownLinks(string $contents): array
    {
        preg_match_all('/(?<!!)\[[^\]]+\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $contents, $matches);

        return array_values(array_filter(
            $matches[1],
            fn (string $link): bool => ! str_starts_with($link, 'http')
                && ! str_starts_with($link, 'mailto:')
                && ! str_starts_with($link, '#'),
        ));
    }

    /**
     * @return list<string>
     */
    private function wikiLinks(string $contents): array
    {
        preg_match_all('/\[\[([^]#|]+)(?:#[^]|]+)?(?:\|[^]]+)?\]\]/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function assertResolvableMarkdownLink(string $sourcePath, string $link): void
    {
        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $targetPath = realpath(dirname($sourcePath).'/'.urldecode($target));

        $this->assertNotFalse(
            $targetPath,
            sprintf('Markdown link [%s] in %s points to a missing file.', $link, $this->relativePath($sourcePath)),
        );

        if ($anchor === null || $anchor === '') {
            return;
        }

        $this->assertContains(
            $anchor,
            $this->headingAnchors($targetPath),
            sprintf('Markdown link [%s] in %s points to a missing heading.', $link, $this->relativePath($sourcePath)),
        );
    }

    /**
     * @return array<string, string>
     */
    private function wikiLinkIndex(): array
    {
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];

        foreach (self::markdownFiles() as [$path]) {
            if (! str_starts_with($path, dirname(__DIR__, 2).'/docs/')) {
                continue;
            }

            $slug = pathinfo($path, PATHINFO_FILENAME);
            $index[$slug] = $path;

            if ($slug === 'index') {
                $index[basename(dirname($path))] = $path;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function headingAnchors(string $path): array
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        preg_match_all('/^#{1,6}\s+(.+)$/m', $contents, $matches);

        return array_map(
            fn (string $heading): string => $this->slugHeading($heading),
            $matches[1],
        );
    }

    private function slugHeading(string $heading): string
    {
        $heading = strtolower($heading);
        $heading = preg_replace('/[`*_~\[\]().:]/', '', $heading);
        $heading = preg_replace('/[^a-z0-9\s-]/', '', $heading ?? '');
        $heading = preg_replace('/\s/', '-', $heading ?? '');

        return trim($heading ?? '', '-');
    }

    private function relativePath(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }
}
