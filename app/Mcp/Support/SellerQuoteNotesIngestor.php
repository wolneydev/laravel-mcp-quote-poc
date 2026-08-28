<?php

namespace App\Mcp\Support;

use Illuminate\Validation\ValidationException;

class SellerQuoteNotesIngestor
{
    /**
     * @return array{
     *     customer_mentions: list<string>,
     *     line_candidates: list<array{product_mention: string, quantity: int|float|null}>,
     *     valid_until_hint: string|null,
     *     notes_remainder: string,
     *     prices_ignored: bool,
     *     pii_redacted: bool,
     *     warnings: list<string>
     * }
     */
    public function ingest(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;

        if (trim($normalized) === '') {
            throw ValidationException::withMessages([
                'text' => '[empty_body] Notes cannot be empty.',
            ]);
        }

        $pricesIgnored = $this->containsMoney($normalized);
        $piiRedacted = $this->containsPii($normalized);
        $sanitized = $this->redactPii($this->stripMoney($normalized));

        $customerMentions = $this->extractCustomerMentions($sanitized);
        $lineCandidates = $this->extractLineCandidates($sanitized);
        $validUntilHint = $this->extractValidUntilHint($sanitized);
        $notesRemainder = $this->remainder($sanitized, $customerMentions, $lineCandidates, $validUntilHint);

        $warnings = [];

        if ($customerMentions === []) {
            $warnings[] = 'No customer mentions were found.';
        }

        if ($lineCandidates === []) {
            $warnings[] = 'No product mentions were found.';
        }

        foreach ($lineCandidates as $candidate) {
            if ($candidate['quantity'] === null) {
                $warnings[] = 'A product mention is missing a quantity.';
                break;
            }
        }

        return [
            'customer_mentions' => $customerMentions,
            'line_candidates' => $lineCandidates,
            'valid_until_hint' => $validUntilHint,
            'notes_remainder' => $notesRemainder,
            'prices_ignored' => $pricesIgnored,
            'pii_redacted' => $piiRedacted,
            'warnings' => $warnings,
        ];
    }

    private function containsMoney(string $text): bool
    {
        return preg_match(
            '/R\$|US\$|\$\s*\d|\b(?:USD|BRL|EUR|GBP|CAD)\b|\bunit_price\b|\btotals?\b|\bpre[cç]o\b/iu',
            $text,
        ) === 1;
    }

    private function containsPii(string $text): bool
    {
        return $this->redactPii($text) !== $text;
    }

    private function stripMoney(string $text): string
    {
        $stripped = preg_replace(
            '/(?:R\$|US\$|\$|€|£)\s*[\d][\d.,]*/u',
            '',
            $text,
        ) ?? $text;
        $stripped = preg_replace(
            '/\b[\d][\d.,]*\s*(?:USD|BRL|EUR|GBP|CAD|reais?)\b/iu',
            '',
            $stripped,
        ) ?? $stripped;
        $stripped = preg_replace(
            '/\b(?:unit_price|totals?)\b/iu',
            '',
            $stripped,
        ) ?? $stripped;

        return $this->collapseSpaces($stripped);
    }

    private function redactPii(string $text): string
    {
        $redacted = preg_replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            '',
            $text,
        ) ?? $text;
        $redacted = preg_replace(
            '/\+?\d{1,3}[\s.\-()]{0,3}\d{2,3}[\s.\-()]{0,3}\d{4,5}[\s.\-]?\d{4}/',
            '',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace(
            '/\b\d{2}\s\d{4,5}-\d{4}\b/',
            '',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace(
            '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/',
            '',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace(
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
            '',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace(
            '/\b\d{11,14}\b/',
            '',
            $redacted,
        ) ?? $redacted;

        return $this->collapseSpaces($redacted);
    }

    /**
     * @return list<string>
     */
    private function extractCustomerMentions(string $text): array
    {
        $mentions = [];

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^(?:customer|client|cliente|company|empresa)\s*[:\-]\s*(.+)$/iu', $line, $matches) === 1) {
                $mentions[] = $this->cleanPhrase($matches[1]);

                continue;
            }

            if (preg_match('/(?:Visit|Call|Reuni[aã]o)[^\n]*[—\-:]\s*(.+)$/iu', $line, $matches) === 1) {
                $candidate = $this->cleanPhrase($matches[1]);

                if ($candidate !== '') {
                    $mentions[] = $candidate;
                }
            }

            if (preg_match('/\b([A-Z][A-Za-z0-9&.\'\-]*(?:\s+[A-Z][A-Za-z0-9&.\'\-]*)*\s+(?:Ltda?|LLC|Inc|GmbH|S\.?A\.?)\.?)\b/u', $line, $matches) === 1) {
                $mentions[] = $this->cleanPhrase($matches[1]);
            }
        }

        return array_values(array_unique(array_filter($mentions)));
    }

    /**
     * @return list<array{product_mention: string, quantity: int|float|null}>
     */
    private function extractLineCandidates(string $text): array
    {
        $candidates = [];

        foreach ($this->lines($text) as $line) {
            if ($this->isMetadataLine($line)) {
                continue;
            }

            foreach (preg_split('/[,;]/', $line) ?: [] as $segment) {
                $segment = trim($segment);

                if ($segment === '') {
                    continue;
                }

                if (preg_match('/^(\d+(?:[.,]\d+)?)\s*[xX×]\s+(.+)$/u', $segment, $matches) === 1) {
                    $candidates[] = $this->lineCandidate($matches[2], $matches[1]);

                    continue;
                }

                if (preg_match('/^(?:qty|qtd|quantity)\s*[:\-]?\s*(\d+(?:[.,]\d+)?)\s+(.+)$/iu', $segment, $matches) === 1) {
                    $candidates[] = $this->lineCandidate($matches[2], $matches[1]);

                    continue;
                }

                if (preg_match('/^(\d+(?:[.,]\d+)?)\s+(?!\d)([A-Za-z].+)$/u', $segment, $matches) === 1) {
                    $candidates[] = $this->lineCandidate($matches[2], $matches[1]);
                }
            }
        }

        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate['product_mention']).'|'.(string) $candidate['quantity'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @return array{product_mention: string, quantity: int|float|null}
     */
    private function lineCandidate(string $mention, string $quantity): array
    {
        $numeric = (float) str_replace(',', '.', $quantity);

        return [
            'product_mention' => $this->cleanPhrase($mention),
            'quantity' => fmod($numeric, 1.0) === 0.0 ? (int) $numeric : $numeric,
        ];
    }

    private function extractValidUntilHint(string $text): ?string
    {
        if (preg_match('/valid(?:ity)?\s+until\s+(.+)$/im', $text, $matches) === 1) {
            $hint = $this->cleanPhrase($matches[1]);

            return $hint === '' ? null : $hint;
        }

        if (preg_match('/validade\s*[:\-]?\s*(.+)$/im', $text, $matches) === 1) {
            $hint = $this->cleanPhrase($matches[1]);

            return $hint === '' ? null : $hint;
        }

        return null;
    }

    /**
     * @param  list<string>  $customerMentions
     * @param  list<array{product_mention: string, quantity: int|float|null}>  $lineCandidates
     */
    private function remainder(string $text, array $customerMentions, array $lineCandidates, ?string $validUntilHint): string
    {
        $lines = [];

        foreach ($this->lines($text) as $line) {
            if ($this->isProductLine($line) || $this->isMetadataLine($line)) {
                continue;
            }

            $kept = $line;

            foreach ($customerMentions as $mention) {
                $kept = str_ireplace($mention, '', $kept);
            }

            $kept = preg_replace('/[—\-:]+\s*$/u', '', $kept) ?? $kept;
            $kept = $this->cleanPhrase($kept);

            if ($kept === '' || ($validUntilHint !== null && strcasecmp($kept, $validUntilHint) === 0)) {
                continue;
            }

            $lines[] = $kept;
        }

        $remainder = implode('. ', array_unique($lines));
        $remainder = trim($remainder, " \t\n\r\0\x0B.");

        return $remainder === '' ? '' : $remainder.'.';
    }

    private function isMetadataLine(string $line): bool
    {
        return preg_match('/^(?:customer|client|cliente|company|empresa|valid(?:ity)?\s+until|validade)\b/iu', $line) === 1;
    }

    private function isProductLine(string $line): bool
    {
        return preg_match('/\d+(?:[.,]\d+)?\s*[xX×]\s+\S/u', $line) === 1
            || preg_match('/^(?:qty|qtd|quantity)\s*[:\-]?\s*\d/iu', $line) === 1
            || preg_match('/^\d+(?:[.,]\d+)?\s+(?!\d)[A-Za-z]/u', $line) === 1
            || preg_match('/,\s*\d+(?:[.,]\d+)?\s+(?!\d)[A-Za-z]/u', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    private function cleanPhrase(string $value): string
    {
        $cleaned = preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B.,;—\-")) ?? $value;

        return trim($cleaned);
    }

    private function collapseSpaces(string $text): string
    {
        $collapsed = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $collapsed = preg_replace('/ *\n */', "\n", $collapsed) ?? $collapsed;

        return trim($collapsed);
    }
}
