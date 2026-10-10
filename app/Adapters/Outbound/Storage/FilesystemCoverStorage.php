<?php

namespace App\Adapters\Outbound\Storage;

use Application\DTO\CoverContents;
use Application\Ports\CoverStorage;
use Domain\Exceptions\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class FilesystemCoverStorage implements CoverStorage
{
    private const DISK = 'covers';

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function store(string $bookId, string $contents, string $mediaType): string
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new ValidationException('La portada debe tener un tamaño máximo de 2 MB.');
        }

        $actualMediaType = $this->detectMediaType($contents);
        if ($actualMediaType !== $mediaType) {
            throw new ValidationException('El tipo declarado no coincide con el contenido de la portada.');
        }

        $key = Str::uuid()->toString();
        if (! Storage::disk(self::DISK)->put($key, $contents)) {
            throw new RuntimeException('No se pudo guardar la portada.');
        }

        return $key;
    }

    public function read(string $key): ?CoverContents
    {
        $this->assertValidKey($key);
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($key)) {
            return null;
        }

        $contents = $disk->get($key);
        try {
            $mediaType = $this->detectMediaType($contents);
        } catch (ValidationException $exception) {
            throw new RuntimeException('La portada almacenada no contiene una imagen válida.', previous: $exception);
        }

        return new CoverContents($contents, $mediaType);
    }

    public function delete(string $key): void
    {
        $this->assertValidKey($key);
        $disk = Storage::disk(self::DISK);
        if ($disk->exists($key) && ! $disk->delete($key)) {
            throw new RuntimeException('No se pudo eliminar la portada almacenada.');
        }
    }

    private function detectMediaType(string $contents): string
    {
        $mediaType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $imageInfo = $this->readImageInfo($contents);

        if (
            ! in_array($mediaType, ['image/jpeg', 'image/png'], true)
            || $imageInfo === false
            || $imageInfo['mime'] !== $mediaType
        ) {
            throw new ValidationException('La portada debe contener una imagen JPG o PNG válida.');
        }

        return $mediaType;
    }

    private function readImageInfo(string $contents): array|false
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return getimagesizefromstring($contents);
        } finally {
            restore_error_handler();
        }
    }

    private function assertValidKey(string $key): void
    {
        if (! Str::isUuid($key)) {
            throw new RuntimeException('La clave de portada almacenada no es válida.');
        }
    }
}
