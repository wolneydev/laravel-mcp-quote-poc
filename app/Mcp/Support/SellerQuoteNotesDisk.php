<?php

namespace App\Mcp\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerQuoteNotesDisk
{
    public const DISK = 'local';

    public const PREFIX = 'quotes/notes';

    public const MAX_BYTES = 65536;

    /**
     * @return array{storage_path: string, filename: string}
     */
    public function storeUtf8File(UploadedFile $file): array
    {
        $originalName = $this->safeOriginalFilename($file->getClientOriginalName());
        $contents = $this->readUploadedUtf8($file);
        $storagePath = self::PREFIX.'/'.Str::ulid().'.txt';

        Storage::disk(self::DISK)->put($storagePath, $contents);

        return [
            'storage_path' => $storagePath,
            'filename' => $originalName,
        ];
    }

    public function get(string $storagePath): string
    {
        $path = $this->assertSafeObjectKey($storagePath);

        if (! Storage::disk(self::DISK)->exists($path)) {
            throw ValidationException::withMessages([
                'storage_path' => '[unreadable] The notes file was not found on the private disk.',
            ]);
        }

        $contents = Storage::disk(self::DISK)->get($path);

        if (! is_string($contents)) {
            throw ValidationException::withMessages([
                'storage_path' => '[unreadable] The notes file could not be read.',
            ]);
        }

        return $this->assertDecodableUtf8($contents, 'storage_path');
    }

    public function assertSafeObjectKey(string $storagePath): string
    {
        $normalized = str_replace('\\', '/', trim($storagePath));

        if ($normalized === '' || str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            throw ValidationException::withMessages([
                'storage_path' => '[path_traversal] The notes path must stay under quotes/notes/ on the local disk.',
            ]);
        }

        if (! str_starts_with($normalized, self::PREFIX.'/')) {
            throw ValidationException::withMessages([
                'storage_path' => '[path_traversal] The notes path must stay under quotes/notes/ on the local disk.',
            ]);
        }

        if (substr_count($normalized, '/') !== 2) {
            throw ValidationException::withMessages([
                'storage_path' => '[path_traversal] The notes path must stay under quotes/notes/ on the local disk.',
            ]);
        }

        return $normalized;
    }

    public function assertDecodableUtf8(string $contents, string $field): string
    {
        $size = strlen($contents);

        if ($size > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                $field => '[oversize] Notes cannot exceed 64 KiB of decoded text.',
            ]);
        }

        if ($this->looksBinary($contents) || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages([
                $field => '[binary] Notes must be UTF-8 text, not a binary file.',
            ]);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;

        if (trim($normalized) === '') {
            throw ValidationException::withMessages([
                $field => '[empty_body] Notes cannot be empty.',
            ]);
        }

        return $normalized;
    }

    public function safeOriginalFilename(?string $name): string
    {
        $basename = basename(str_replace('\\', '/', (string) $name));
        $basename = preg_replace('/[^A-Za-z0-9._-]/', '-', $basename) ?? '';
        $basename = trim($basename, '.-');

        return $basename === '' ? 'notes.txt' : $basename;
    }

    private function readUploadedUtf8(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, ['txt', 'md'], true)) {
            throw ValidationException::withMessages([
                'notes' => '[type] Notes must be a UTF-8 .txt or .md file.',
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'notes' => '[oversize] Notes cannot exceed 64 KiB of decoded text.',
            ]);
        }

        $contents = file_get_contents($file->getRealPath() ?: $file->getPathname());

        if (! is_string($contents)) {
            throw ValidationException::withMessages([
                'notes' => '[unreadable] The notes file could not be read.',
            ]);
        }

        return $this->assertDecodableUtf8($contents, 'notes');
    }

    private function looksBinary(string $contents): bool
    {
        return str_contains($contents, "\0")
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $contents) === 1;
    }
}
