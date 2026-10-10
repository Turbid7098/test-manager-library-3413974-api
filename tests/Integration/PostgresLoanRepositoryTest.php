<?php

namespace Tests\Integration;

use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use App\Adapters\Outbound\Persistence\DatabaseLoanRepository;
use App\Adapters\Outbound\Persistence\DatabaseMemberRepository;
use App\Adapters\Outbound\Persistence\DatabaseTransactionManager;
use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use Application\DTO\PageResult;
use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\Clock;
use Application\Ports\IdGenerator;
use Application\Services\LendBooksService;
use Application\Services\ReturnLoanItemService;
use DateTimeImmutable;
use Domain\Entities\Book;
use Domain\Entities\Member;
use Domain\Entities\User;
use Domain\Exceptions\ConflictException;
use Domain\ValueObjects\DocumentNumber;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresLoanRepositoryTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        self::assertSame('pgsql', DB::connection('pgsql')->getDriverName());
        DB::connection('pgsql')->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function test_lending_overdue_queries_and_return_are_persisted_atomically(): void
    {
        $books = new DatabaseBookRepository;
        $loans = new DatabaseLoanRepository;
        $member = $this->createMember();
        $user = $this->createUser();
        $book = $this->book(1);
        (new DatabaseMemberRepository)->save($member);
        (new DatabaseUserRepository)->save($user);
        $books->save($book);

        $clock = new class(new DateTimeImmutable('2026-10-10T12:00:00Z')) implements Clock
        {
            public function __construct(public DateTimeImmutable $current) {}

            public function now(): DateTimeImmutable
            {
                return $this->current;
            }
        };
        $ids = new class implements IdGenerator
        {
            public function generate(): string
            {
                return Str::uuid()->toString();
            }
        };
        $lend = new LendBooksService(
            $books,
            app(CategoryRepository::class),
            new DatabaseMemberRepository,
            $loans,
            new DatabaseTransactionManager,
            $clock,
            $ids
        );

        $loan = $lend->execute($member->id, $user->id, [$book->id]);

        self::assertSame(1, $loans->countActiveItemsForMember($member->id));
        self::assertFalse($loans->memberHasOverdueLoans($member->id, $clock->now()));
        self::assertSame(0, $books->findById($book->id)?->availableCopies()->value);
        self::assertSame($loan->id, $loans->findById($loan->id)?->id);

        $clock->current = new DateTimeImmutable('2026-11-01T12:00:00Z');
        self::assertTrue($loans->memberHasOverdueLoans($member->id, $clock->now()));
        $overdue = $loans->findOverdueItems($clock->now());
        self::assertCount(1, $overdue);
        self::assertSame($loan->id, $overdue[0]->loanId);
        self::assertSame($loan->getItems()[0]->id, $overdue[0]->itemId);
        self::assertSame(8, $overdue[0]->daysLate);

        $returnedItem = (new ReturnLoanItemService(
            $books,
            $loans,
            new DatabaseTransactionManager,
            $clock
        ))->execute($loan->id, $loan->getItems()[0]->id);

        self::assertTrue($returnedItem->isReturned());
        self::assertSame(0, $loans->countActiveItemsForMember($member->id));
        self::assertFalse($loans->memberHasOverdueLoans($member->id, $clock->now()));
        self::assertSame(1, $books->findById($book->id)?->availableCopies()->value);
        self::assertTrue($loans->findById($loan->id)?->getItems()[0]->isReturned());

        try {
            (new ReturnLoanItemService($books, $loans, new DatabaseTransactionManager, $clock))
                ->execute($loan->id, $loan->getItems()[0]->id);
            self::fail('A returned line must not be returned or restocked twice.');
        } catch (ConflictException $exception) {
            self::assertSame('ITEM_ALREADY_RETURNED', $exception->errorCode);
        }
        self::assertSame(1, $books->findById($book->id)?->availableCopies()->value);
    }

    public function test_optimistic_version_rejects_a_stale_book_write(): void
    {
        $repository = new DatabaseBookRepository;
        $book = $this->book(1);
        $repository->save($book);

        $firstRead = $repository->findById($book->id);
        $staleRead = $repository->findById($book->id);
        self::assertNotNull($firstRead);
        self::assertNotNull($staleRead);
        self::assertSame(0, $firstRead->version());
        self::assertSame(0, $staleRead->version());

        $firstRead->withdraw();
        $repository->save($firstRead);
        self::assertSame(1, $firstRead->version());

        $staleRead->withdraw();
        try {
            $repository->save($staleRead);
            self::fail('A stale book version must not overwrite the committed checkout.');
        } catch (ConflictException $exception) {
            self::assertSame('BOOK_NOT_AVAILABLE', $exception->errorCode);
        }

        self::assertSame(0, $repository->findById($book->id)?->availableCopies()->value);
        self::assertSame(
            1,
            (int) DB::table('library.book')->where('id', $book->id)->value('version')
        );
    }

    public function test_failed_multi_book_checkout_rolls_back_all_writes(): void
    {
        $delegate = new DatabaseBookRepository;
        $first = $this->book(1);
        $second = $this->book(1, '9780307474735');
        $member = $this->createMember();
        $user = $this->createUser();
        (new DatabaseMemberRepository)->save($member);
        (new DatabaseUserRepository)->save($user);
        $delegate->save($first);
        $delegate->save($second);

        $books = new class($delegate, $first->id, $second->id) implements BookRepository
        {
            private bool $interfered = false;

            public function __construct(
                private DatabaseBookRepository $delegate,
                private string $firstId,
                private string $secondId
            ) {}

            public function findById(string $id): ?Book
            {
                return $this->delegate->findById($id);
            }

            public function findByIsbn(Isbn $isbn): ?Book
            {
                return $this->delegate->findByIsbn($isbn);
            }

            public function save(Book $book): void
            {
                $this->delegate->save($book);
                if ($book->id === $this->firstId && ! $this->interfered) {
                    $this->interfered = true;
                    DB::table('library.book')
                        ->where('id', $this->secondId)
                        ->update([
                            'available_copies' => 0,
                            'version' => DB::raw('version + 1'),
                        ]);
                }
            }

            public function findManyByIds(array $ids): array
            {
                return $this->delegate->findManyByIds($ids);
            }

            public function search(string $query, ?string $categoryId, int $page, int $size): PageResult
            {
                return $this->delegate->search($query, $categoryId, $page, $size);
            }

            public function hasOpenLoans(string $bookId): bool
            {
                return $this->delegate->hasOpenLoans($bookId);
            }
        };
        $clock = new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-10T12:00:00Z');
            }
        };
        $ids = new class implements IdGenerator
        {
            public function generate(): string
            {
                return Str::uuid()->toString();
            }
        };
        $lending = new LendBooksService(
            $books,
            app(CategoryRepository::class),
            new DatabaseMemberRepository,
            new DatabaseLoanRepository,
            new DatabaseTransactionManager,
            $clock,
            $ids
        );

        try {
            $lending->execute($member->id, $user->id, [$first->id, $second->id]);
            self::fail('A conflicting version must abort the complete loan.');
        } catch (ConflictException $exception) {
            self::assertSame('BOOK_NOT_AVAILABLE', $exception->errorCode);
        }

        self::assertSame(1, $delegate->findById($first->id)?->availableCopies()->value);
        self::assertSame(1, $delegate->findById($second->id)?->availableCopies()->value);
        self::assertSame(0, (int) DB::table('library.loan')->where('member_id', $member->id)->count());
        self::assertSame(
            [0, 0],
            DB::table('library.book')
                ->whereIn('id', [$first->id, $second->id])
                ->orderBy('id')
                ->pluck('version')
                ->map(static fn ($version): int => (int) $version)
                ->all()
        );
    }

    private function book(int $copies, string $isbn = '9780307474728'): Book
    {
        return new Book(
            Str::uuid()->toString(),
            'Libro de integración',
            'Autor de integración',
            new Isbn($isbn),
            self::CATEGORY_NOVEL,
            new Quantity($copies),
            new Quantity($copies)
        );
    }

    private function createMember(): Member
    {
        return new Member(
            Str::uuid()->toString(),
            'Socio de integración',
            new DocumentNumber('integration-'.Str::lower(Str::random(18)))
        );
    }

    private function createUser(): User
    {
        return new User(
            Str::uuid()->toString(),
            'integration-'.Str::lower(Str::random(8)),
            'integration-hash',
            'librarian'
        );
    }
}
