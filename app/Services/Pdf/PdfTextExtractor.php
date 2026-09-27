<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfExtractionException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a PDF page-by-page and returns normalized text with page provenance.
 *
 * @phpstan-type ExtractionResult array{pages: array<int, string>, page_count: int}
 */
class PdfTextExtractor
{
    public function __construct(private readonly PageNormalizer $normalizer) {}

    /**
     * @return ExtractionResult
     *
     * @throws PdfExtractionException
     */
    public function extract(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw PdfExtractionException::unreadable('The file is missing from storage.');
        }

        $raw = $this->readPages($absolutePath);

        if ($raw === []) {
            throw PdfExtractionException::noSelectableText();
        }

        $pages = $this->normalizer->normalize($raw);

        if (trim(implode("\n\n", $pages)) === '') {
            throw PdfExtractionException::noSelectableText();
        }

        return [
            'pages' => $pages,
            'page_count' => count($pages),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function readPages(string $absolutePath): array
    {
        try {
            $parsed = (new Parser)->parseFile($absolutePath);
        } catch (Throwable $exception) {
            throw PdfExtractionException::unreadable($exception->getMessage());
        }

        $pages = [];
        $position = 0;

        foreach ($parsed->getPages() as $page) {
            $pages[++$position] = (string) $page->getText();
        }

        ksort($pages);

        return $pages;
    }
}
