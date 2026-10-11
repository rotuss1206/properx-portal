<?php

namespace App\Services\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes and reads member documents on the documents disk (Spec §2, §3.2, §8).
 *
 * - File names are random UUIDs; the original name is kept only in the database.
 * - The extension comes from the MIME type detected from the file contents, not from the client.
 * - Sensitive documents are encrypted with AES-256 before they leave the application.
 */
class DocumentVault
{
    private const ENCRYPTED_SUFFIX = '.enc';

    /**
     * Validation rules for uploads: PDF/JPG/PNG up to 10 MB, checked by extension and by content.
     *
     * @return list<string>
     */
    public static function uploadRules(): array
    {
        $types = config('portal.uploads.mime_types');

        return [
            'file',
            'max:'.config('portal.uploads.max_kilobytes'),
            'mimes:'.implode(',', array_unique([...array_values($types), 'jpeg'])),
            'mimetypes:'.implode(',', array_keys($types)),
        ];
    }

    public static function acceptedExtensions(): string
    {
        return '.pdf,.jpg,.jpeg,.png';
    }

    public function store(UploadedFile $file, string $directory, bool $encrypt): StoredDocument
    {
        // Sniff the contents ourselves; never trust the client's name or Content-Type.
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = config('portal.uploads.mime_types')[$mimeType]
            ?? throw new InvalidArgumentException("Unsupported file type [{$mimeType}].");

        $path = $this->put($directory, $extension, (string) $file->get(), $encrypt);

        return new StoredDocument($path, $file->getClientOriginalName(), $mimeType, (int) $file->getSize());
    }

    /**
     * Store raw contents under a new UUID name and return the path.
     */
    public function put(string $directory, string $extension, string $contents, bool $encrypt): string
    {
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension.($encrypt ? self::ENCRYPTED_SUFFIX : '');

        $this->disk()->put($path, $encrypt ? $this->encrypter()->encryptString($contents) : $contents);

        return $path;
    }

    /**
     * Plain contents of a stored document (decrypted when it was stored encrypted).
     */
    public function read(string $path): string
    {
        $contents = (string) $this->disk()->get($path);

        return self::isEncrypted($path) ? $this->encrypter()->decryptString($contents) : $contents;
    }

    public static function isEncrypted(string $path): bool
    {
        return str_ends_with($path, self::ENCRYPTED_SUFFIX);
    }

    public static function mimeTypeForPath(string $path): string
    {
        $extension = pathinfo(Str::beforeLast($path, self::ENCRYPTED_SUFFIX), PATHINFO_EXTENSION);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return array_search($extension, config('portal.uploads.mime_types'), true) ?: 'application/octet-stream';
    }

    public static function diskName(): string
    {
        return config('portal.documents_disk');
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    private function encrypter(): Encrypter
    {
        $key = config('portal.documents_encryption_key') ?: config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, config('app.cipher'));
    }
}
