<?php

namespace Tests\Integration;

use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use App\Adapters\Outbound\Persistence\DatabaseTransactionManager;
use App\Adapters\Outbound\Storage\FilesystemCoverStorage;
use Application\Services\GetBookCoverService;
use Application\Services\RemoveBookCoverService;
use Application\Services\SetBookCoverService;
use Domain\Entities\Book;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresCoverStorageTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        self::assertSame('pgsql', DB::connection('pgsql')->getDriverName());
        DB::connection('pgsql')->beginTransaction();
        Storage::fake('covers');
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function test_cover_storage_validates_image_bytes_and_round_trips_a_two_megabyte_png(): void
    {
        $books = new DatabaseBookRepository;
        $covers = new FilesystemCoverStorage;
        $book = new Book(
            Str::uuid()->toString(),
            'Portada de integración',
            'Autor de integración',
            new Isbn('9780307474728'),
            self::CATEGORY_NOVEL,
            new Quantity(1),
            new Quantity(1)
        );
        $books->save($book);
        $contents = $this->pngOfSize(2 * 1024 * 1024);
        self::assertSame(2 * 1024 * 1024, strlen($contents));
        $key = null;

        try {
            $updated = (new SetBookCoverService($books, $covers))
                ->execute($book->id, $contents, 'image/png');
            $key = $updated->coverKey();
            self::assertNotNull($key);
            self::assertTrue(Str::isUuid($key));
            self::assertSame($key, $books->findById($book->id)?->coverKey());
            self::assertTrue(Storage::disk('covers')->exists($key));

            $previousKey = $key;
            $updated = (new SetBookCoverService($books, $covers))
                ->execute($book->id, $contents, 'image/png');
            $key = $updated->coverKey();
            self::assertNotNull($key);
            self::assertNotSame($previousKey, $key);
            self::assertFalse(Storage::disk('covers')->exists($previousKey));
            self::assertTrue(Storage::disk('covers')->exists($key));

            $stored = (new GetBookCoverService($books, $covers))->execute($book->id);
            self::assertSame($contents, $stored->contents);
            self::assertSame('image/png', $stored->mediaType);

            try {
                (new SetBookCoverService($books, $covers))
                    ->execute($book->id, $this->pngOfSize(3 * 1024 * 1024), 'image/png');
                self::fail('A 3 MB cover must be rejected.');
            } catch (ValidationException $exception) {
                self::assertSame('VALIDATION_ERROR', $exception->errorCode);
            }

            try {
                $covers->store($book->id, 'not an image', 'image/png');
                self::fail('The adapter must reject bytes that are not an image.');
            } catch (ValidationException $exception) {
                self::assertSame('VALIDATION_ERROR', $exception->errorCode);
            }

            try {
                $covers->store($book->id, $contents, 'image/jpeg');
                self::fail('The declared media type must match the PNG bytes.');
            } catch (ValidationException $exception) {
                self::assertSame('VALIDATION_ERROR', $exception->errorCode);
            }

            (new RemoveBookCoverService($books, $covers, new DatabaseTransactionManager))
                ->execute($book->id);
            self::assertNull($books->findById($book->id)?->coverKey());
            self::assertFalse(Storage::disk('covers')->exists($key));
            self::assertNull($covers->read($key));

            try {
                (new GetBookCoverService($books, $covers))->execute($book->id);
                self::fail('A book without a cover must report not found.');
            } catch (NotFoundException) {
                self::assertNull($books->findById($book->id)?->coverKey());
            }
        } finally {
            if ($key !== null) {
                $covers->delete($key);
            }
        }
    }

    private function pngOfSize(int $size): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));
        $signature = "\x89PNG\r\n\x1a\n";
        $header = $chunk('IHDR', pack('NNC5', 1, 1, 8, 6, 0, 0, 0));
        $imageData = $chunk('IDAT', gzcompress("\x00\xff\x00\x00\xff"));
        $end = $chunk('IEND', '');
        $beforePadding = $signature.$header.$imageData;
        $paddingLength = $size - strlen($beforePadding) - strlen($end) - 12;
        self::assertGreaterThanOrEqual(8, $paddingLength);
        $padding = $chunk('tEXt', "Comment\0".str_repeat('x', $paddingLength - 8));
        $contents = $beforePadding.$padding.$end;
        self::assertSame($size, strlen($contents));

        return $contents;
    }
}
