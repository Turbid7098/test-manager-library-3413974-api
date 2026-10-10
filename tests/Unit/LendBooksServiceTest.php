<?php

namespace Tests\Unit;

use Application\DTO\CoverContents;
use Application\DTO\MostBorrowedRow;
use Application\DTO\OverdueLoanItem;
use Application\DTO\PageResult;
use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\Clock;
use Application\Ports\CoverStorage;
use Application\Ports\IdGenerator;
use Application\Ports\LoanRepository;
use Application\Ports\MemberRepository;
use Application\Ports\MostBorrowedReportReader;
use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\TransactionManager;
use Application\Ports\UserRepository;
use Application\Services\CreateBookService;
use Application\Services\CreateLibrarianService;
use Application\Services\CreateMemberService;
use Application\Services\DeleteBookService;
use Application\Services\GetBookCoverService;
use Application\Services\GetBookService;
use Application\Services\GetLoanService;
use Application\Services\LendBooksService;
use Application\Services\ListOverdueLoansService;
use Application\Services\LoginService;
use Application\Services\MostBorrowedReportService;
use Application\Services\RemoveBookCoverService;
use Application\Services\ReturnLoanItemService;
use Application\Services\SearchBooksService;
use Application\Services\SearchMembersService;
use Application\Services\SetBookCoverService;
use Application\Services\UpdateBookService;
use DateTimeImmutable;
use Domain\Entities\Book;
use Domain\Entities\Loan;
use Domain\Entities\LoanItem;
use Domain\Entities\Member;
use Domain\Entities\User;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\DomainException;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\DateRange;
use Domain\ValueObjects\DocumentNumber;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;

final class FakeClock implements Clock
{
    public function __construct(public DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

final class FakeIds implements IdGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return 'id-'.$this->next++;
    }
}

final class FakeTransactionManager implements TransactionManager
{
    public int $calls = 0;

    public function execute(callable $callback): mixed
    {
        $this->calls++;

        return $callback();
    }
}

final class FakeBooks implements BookRepository
{
    /** @var array<string, Book> */
    public array $records = [];

    public bool $openLoans = false;

    public int $saved = 0;

    public ?array $searchArguments = null;

    public PageResult $searchPage;

    public function __construct()
    {
        $this->searchPage = new PageResult([], 1, 20, 0);
    }

    public function findById(string $id): ?Book
    {
        return $this->records[$id] ?? null;
    }

    public function findByIsbn(Isbn $isbn): ?Book
    {
        foreach ($this->records as $book) {
            if ($book->deletedAt() === null && $book->isbn->equals($isbn)) {
                return $book;
            }
        }

        return null;
    }

    public function save(Book $book): void
    {
        $this->records[$book->id] = $book;
        $this->saved++;
    }

    public function findManyByIds(array $ids): array
    {
        $books = [];
        foreach ($ids as $id) {
            if (isset($this->records[$id])) {
                $books[] = $this->records[$id];
            }
        }

        return $books;
    }

    public function search(string $query, ?string $categoryId, int $page, int $size): PageResult
    {
        $this->searchArguments = [$query, $categoryId, $page, $size];

        return $this->searchPage;
    }

    public function hasOpenLoans(string $bookId): bool
    {
        return $this->openLoans;
    }
}

final class FakeMembers implements MemberRepository
{
    /** @var array<string, Member> */
    public array $records = [];

    public ?array $searchArguments = null;

    public PageResult $searchPage;

    public function __construct()
    {
        $this->searchPage = new PageResult([], 1, 20, 0);
    }

    public function findById(string $id): ?Member
    {
        return $this->records[$id] ?? null;
    }

    public function findByDocumentNumber(DocumentNumber $documentNumber): ?Member
    {
        foreach ($this->records as $member) {
            if ($member->documentNumber->value === $documentNumber->value) {
                return $member;
            }
        }

        return null;
    }

    public function save(Member $member): void
    {
        $this->records[$member->id] = $member;
    }

    public function search(string $query, int $page, int $size): PageResult
    {
        $this->searchArguments = [$query, $page, $size];

        return $this->searchPage;
    }
}

final class FakeCategories implements CategoryRepository
{
    /** @param array<string, string> $records */
    public function __construct(public array $records = []) {}

    public function exists(string $id): bool
    {
        return isset($this->records[$id]);
    }

    public function findNamesByIds(array $ids): array
    {
        return array_intersect_key($this->records, array_flip($ids));
    }
}

final class FakeLoans implements LoanRepository
{
    /** @var array<string, Loan> */
    public array $records = [];

    public bool $overdueForMember = false;

    public int $activeItems = 0;

    /** @var list<OverdueLoanItem> */
    public array $overdueItems = [];

    public function findById(string $id): ?Loan
    {
        return $this->records[$id] ?? null;
    }

    public function save(Loan $loan): void
    {
        $this->records[$loan->id] = $loan;
    }

    public function countActiveItemsForMember(string $memberId): int
    {
        return $this->activeItems;
    }

    public function memberHasOverdueLoans(string $memberId, DateTimeImmutable $now): bool
    {
        return $this->overdueForMember;
    }

    public function findOverdueItems(DateTimeImmutable $now): array
    {
        return $this->overdueItems;
    }
}

final class FakeUsers implements UserRepository
{
    /** @var array<string, User> */
    public array $records = [];

    public function findByUsername(string $username): ?User
    {
        return $this->records[strtolower(trim($username))] ?? null;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->records as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $this->records[$user->username()] = $user;
    }
}

final class FakePasswords implements PasswordHasher
{
    public function hash(string $plainText): string
    {
        return password_hash($plainText, PASSWORD_BCRYPT);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return password_verify($plainText, $hash);
    }
}

final class FakeTokens implements TokenIssuer
{
    public function issueToken(User $user): array
    {
        return [
            'access_token' => 'token-for-test',
            'token_type' => 'Bearer',
            'expires_in' => 28800,
            'role' => $user->role,
        ];
    }
}

final class FakeReports implements MostBorrowedReportReader
{
    public ?DateRange $requestedRange = null;

    /** @param list<MostBorrowedRow> $rows */
    public function __construct(public array $rows = []) {}

    public function mostBorrowed(DateRange $range): array
    {
        $this->requestedRange = $range;

        return $this->rows;
    }
}

final class FakeCovers implements CoverStorage
{
    public array $deleted = [];

    /** @var array<string, CoverContents> */
    public array $records = [];

    public function store(string $bookId, string $contents, string $mediaType): string
    {
        $key = 'opaque/'.$bookId.'/cover';
        $this->records[$key] = new CoverContents($contents, $mediaType);

        return $key;
    }

    public function read(string $key): ?CoverContents
    {
        return $this->records[$key] ?? null;
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        unset($this->records[$key]);
    }
}

final class LendBooksServiceTest extends TestCase
{
    private FakeBooks $books;

    private FakeMembers $members;

    private FakeLoans $loans;

    private FakeCategories $categories;

    private FakeClock $clock;

    private FakeIds $ids;

    private FakeTransactionManager $transactions;

    private LendBooksService $lending;

    protected function setUp(): void
    {
        $this->books = new FakeBooks;
        $this->members = new FakeMembers;
        $this->loans = new FakeLoans;
        $this->categories = new FakeCategories(['category-1' => 'Novela']);
        $this->clock = new FakeClock(new DateTimeImmutable('2026-10-10T12:00:00Z'));
        $this->ids = new FakeIds;
        $this->transactions = new FakeTransactionManager;
        $this->members->records['member-1'] = new Member(
            'member-1',
            'Ana',
            new DocumentNumber('123')
        );
        $this->books->records['book-1'] = $this->book();
        $this->lending = new LendBooksService(
            $this->books,
            $this->categories,
            $this->members,
            $this->loans,
            $this->transactions,
            $this->clock,
            $this->ids
        );
    }

    public function test_value_objects_and_book_keep_their_invariants(): void
    {
        self::assertSame('0000000000001', (new Isbn('0000000000001'))->value);
        self::assertSame('123', (new DocumentNumber(' 123 '))->value);
        self::assertSame(0, (new Quantity(0))->value);
        self::assertEquals(
            new DateTimeImmutable('2026-10-10T12:00:00Z'),
            (new DateRange(
                new DateTimeImmutable('2026-10-10T12:00:00Z'),
                new DateTimeImmutable('2026-10-10T12:00:00Z')
            ))->start
        );

        try {
            new Isbn('123456789012X');
            self::fail('An ISBN with a non-digit must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('VALIDATION_ERROR', $exception->errorCode);
        }
        try {
            new Quantity(-1);
            self::fail('Negative quantities must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, (new Quantity(0))->value);
        }

        $book = $this->book(total: 5, available: 3);
        $book->updateDetails(' New title ', ' New author ', 'category-1', new Quantity(7));
        self::assertSame('New title', $book->title());
        self::assertSame(5, $book->availableCopies()->value);

        $this->expectException(ValidationException::class);
        $book->updateDetails('New title', 'New author', 'category-1', new Quantity(1));
    }

    public function test_loan_requires_one_to_three_unique_lines_and_calculates_state(): void
    {
        $now = new DateTimeImmutable('2026-10-10T12:00:00Z');
        $item = $this->item('loan-1', 'item-1', 'book-1', $now->modify('+14 days'));
        $loan = new Loan('loan-1', 'member-1', 'user-1', $now, [$item]);
        self::assertSame('open', $loan->getStatus($now));
        self::assertSame('overdue', $loan->getStatus($now->modify('+15 days')));

        $loan->returnItem('item-1', $now->modify('+1 day'));
        self::assertSame('closed', $loan->getStatus($now->modify('+15 days')));
        self::assertCount(1, $loan->getItems());
        $this->loans->records[$loan->id] = $loan;
        self::assertSame(
            'closed',
            (new GetLoanService($this->loans, $this->clock))->execute($loan->id)['status']
        );

        $duplicate = $this->item('loan-2', 'item-2', 'book-1', $now->modify('+14 days'));
        $secondDuplicate = $this->item('loan-2', 'item-3', 'book-1', $now->modify('+14 days'));
        try {
            new Loan('loan-2', 'member-1', 'user-1', $now, [$duplicate, $secondDuplicate]);
            self::fail('Duplicate books must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('DUPLICATE_BOOK_IN_LOAN', $exception->errorCode);
        }

        $threeItems = [
            $this->item('loan-4', 'item-4a', 'book-a', $now->modify('+14 days')),
            $this->item('loan-4', 'item-4b', 'book-b', $now->modify('+14 days')),
            $this->item('loan-4', 'item-4c', 'book-c', $now->modify('+14 days')),
        ];
        self::assertCount(3, (new Loan('loan-4', 'member-1', 'user-1', $now, $threeItems))->getItems());
        $fourItems = [...$threeItems, $this->item('loan-4', 'item-4d', 'book-d', $now->modify('+14 days'))];
        try {
            new Loan('loan-4', 'member-1', 'user-1', $now, $fourItems);
            self::fail('A loan with more than three lines must be rejected.');
        } catch (ValidationException) {
            self::assertCount(3, $threeItems);
        }

        $this->expectException(ValidationException::class);
        new Loan('loan-3', 'member-1', 'user-1', $now, []);
    }

    public function test_lending_freezes_catalog_values_and_sets_fourteen_day_due_date(): void
    {
        $loan = $this->lending->execute('member-1', 'user-1', ['book-1']);
        $item = $loan->getItems()[0];

        self::assertSame('open', $loan->getStatus($this->clock->now()));
        self::assertSame('Title', $item->bookTitle);
        self::assertSame('Novela', $item->categoryName);
        $reportRow = new MostBorrowedRow('book-1', $item->bookTitle, $item->categoryName, 1);
        $report = new MostBorrowedReportService(new FakeReports([$reportRow]));
        self::assertSame(
            [$reportRow],
            $report->execute($this->clock->now()->modify('-1 day'), $this->clock->now()->modify('+30 days'))
        );
        self::assertSame('2026-10-24T12:00:00+00:00', $item->dueAt->format(DATE_ATOM));
        self::assertSame(4, $this->books->records['book-1']->availableCopies()->value);
        self::assertSame(1, $this->transactions->calls);
        self::assertSame($loan, $this->loans->records[$loan->id]);

        $this->books->records['book-1']->updateDetails(
            'Renamed title',
            'New author',
            'category-1',
            new Quantity(5)
        );
        $this->categories->records['category-1'] = 'Nueva categoría';
        self::assertSame('Title', $item->bookTitle);
        self::assertSame('Novela', $item->categoryName);
    }

    public function test_lending_rejects_invalid_lines_overdue_members_and_member_limit(): void
    {
        foreach ([[], ['book-1', 'book-1', 'book-1', 'book-1']] as $invalidIds) {
            try {
                $this->lending->execute('member-1', 'user-1', $invalidIds);
                self::fail('Loans must contain from one to three books.');
            } catch (ValidationException) {
                self::assertCount(0, $this->loans->records);
            }
        }

        try {
            $this->lending->execute('member-1', 'user-1', ['book-1', 'book-1']);
            self::fail('Duplicate books must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('DUPLICATE_BOOK_IN_LOAN', $exception->errorCode);
        }

        $this->loans->overdueForMember = true;
        try {
            $this->lending->execute('member-1', 'user-1', ['book-1']);
            self::fail('Members with overdue loans must be rejected.');
        } catch (ConflictException $exception) {
            self::assertSame('MEMBER_HAS_OVERDUE', $exception->errorCode);
        }

        $this->loans->overdueForMember = false;
        $this->loans->activeItems = 2;
        try {
            $this->lending->execute('member-1', 'user-1', ['book-1', 'book-2']);
            self::fail('The three-item member limit must be enforced.');
        } catch (ValidationException $exception) {
            self::assertSame('MEMBER_LIMIT_EXCEEDED', $exception->errorCode);
        }

        self::assertSame(5, $this->books->records['book-1']->availableCopies()->value);
        self::assertCount(0, $this->loans->records);
    }

    public function test_lending_without_inventory_does_not_create_a_loan(): void
    {
        $this->books->records['book-1'] = $this->book(total: 1, available: 0);

        try {
            $this->lending->execute('member-1', 'user-1', ['book-1']);
            self::fail('An unavailable book must be rejected.');
        } catch (ConflictException $exception) {
            self::assertSame('BOOK_NOT_AVAILABLE', $exception->errorCode);
        }

        self::assertCount(0, $this->loans->records);
    }

    public function test_returning_a_line_replenishes_exactly_once(): void
    {
        $now = $this->clock->now();
        $item = $this->item('loan-1', 'item-1', 'book-1', $now->modify('+14 days'));
        $loan = new Loan('loan-1', 'member-1', 'user-1', $now, [$item]);
        $this->loans->records[$loan->id] = $loan;
        $this->books->records['book-1'] = $this->book(total: 5, available: 4);
        $returns = new ReturnLoanItemService($this->books, $this->loans, $this->transactions, $this->clock);

        $returned = $returns->execute('loan-1', 'item-1');
        self::assertSame($now, $returned->returnedAt());
        self::assertSame(5, $this->books->records['book-1']->availableCopies()->value);

        try {
            $returns->execute('loan-1', 'item-1');
            self::fail('A returned line must not be returned twice.');
        } catch (ConflictException $exception) {
            self::assertSame('ITEM_ALREADY_RETURNED', $exception->errorCode);
        }

        self::assertSame(5, $this->books->records['book-1']->availableCopies()->value);
    }

    public function test_book_services_create_update_and_soft_delete_without_deleting_open_loans(): void
    {
        $book = $this->book();
        $this->books->records = [];
        $creator = new CreateBookService($this->books, $this->categories, $this->ids);
        $created = $creator->execute(' New ', ' Author ', '1234567890123', 'category-1', 3);
        self::assertSame(3, $created->availableCopies()->value);
        self::assertSame('New', $created->title());

        $updater = new UpdateBookService($this->books, $this->categories);
        $created->withdraw();
        $updater->execute($created->id, 'Updated', 'Author', 'category-1', 5);
        self::assertSame(4, $created->availableCopies()->value);

        $deleter = new DeleteBookService($this->books, $this->clock);
        $this->books->openLoans = true;
        try {
            $deleter->execute($created->id);
            self::fail('Books with open loans must not be withdrawn.');
        } catch (ConflictException $exception) {
            self::assertSame('BOOK_HAS_OPEN_LOANS', $exception->errorCode);
        }

        $this->books->openLoans = false;
        $deleted = $deleter->execute($created->id);
        self::assertSame($this->clock->now(), $deleted->deletedAt());
        self::assertNull($this->books->findByIsbn($deleted->isbn));
        try {
            (new GetBookService($this->books))->execute($deleted->id);
            self::fail('Soft-deleted books must be hidden from catalog reads.');
        } catch (NotFoundException) {
            self::assertNull($this->books->findByIsbn($deleted->isbn));
        }
        unset($book);
    }

    public function test_user_and_member_use_cases_normalize_and_protect_credentials(): void
    {
        $users = new FakeUsers;
        $passwords = new FakePasswords;
        $create = new CreateLibrarianService($users, $passwords, $this->ids);
        $user = $create->execute(' Ana ', 'test-only-password');
        self::assertSame('ana', $user->username());
        self::assertNotSame('test-only-password', $user->passwordHash);

        try {
            $create->execute('ANA', 'another-test-password');
            self::fail('Usernames must be unique after normalization.');
        } catch (ConflictException $exception) {
            self::assertSame('USERNAME_TAKEN', $exception->errorCode);
        }

        try {
            $create->execute('other', 'test-only-password', 'admin');
            self::fail('The API use case must reject administrator creation.');
        } catch (ValidationException) {
            self::assertCount(1, $users->records);
        }

        $login = new LoginService($users, $passwords, new FakeTokens);
        self::assertSame('librarian', $login->execute(' ANA ', 'test-only-password')['role']);
        try {
            $login->execute('unknown', 'incorrect');
            self::fail('Invalid credentials must be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('INVALID_CREDENTIALS', $exception->errorCode);
            self::assertSame('Las credenciales son inválidas.', $exception->getMessage());
        }

        $members = new FakeMembers;
        $member = (new CreateMemberService($members, $this->ids))->execute(' Ana ', ' 00123 ');
        self::assertSame('Ana', $member->fullName());
        self::assertSame('00123', $member->documentNumber->value);
        try {
            (new CreateMemberService($members, $this->ids))->execute('Another name', '00123');
            self::fail('Document numbers must be unique.');
        } catch (ConflictException $exception) {
            self::assertSame('DOCUMENT_TAKEN', $exception->errorCode);
        }
    }

    public function test_cover_replacement_and_removal_keep_keys_opaque_and_clear_before_delete(): void
    {
        $book = $this->book();
        $this->books->records[$book->id] = $book;
        $covers = new FakeCovers;
        $setCover = new SetBookCoverService($this->books, $covers);
        $setCover->execute($book->id, 'png-content', 'image/png');
        self::assertSame('opaque/book-1/cover', $book->coverKey());
        self::assertSame(
            'png-content',
            (new GetBookCoverService($this->books, $covers))->execute($book->id)->contents
        );

        $removeCover = new RemoveBookCoverService($this->books, $covers, $this->transactions);
        $removeCover->execute($book->id);
        self::assertNull($book->coverKey());
        self::assertSame(['opaque/book-1/cover'], $covers->deleted);
    }

    public function test_search_use_cases_enforce_page_bounds_and_forward_normalized_filters(): void
    {
        $expectedPage = new PageResult([$this->book()], 2, 10, 21);
        $this->books->searchPage = $expectedPage;
        $page = (new SearchBooksService($this->books))->execute('  García ', 'category-1', 2, 10);
        self::assertSame($expectedPage, $page);
        self::assertSame(['García', 'category-1', 2, 10], $this->books->searchArguments);

        $members = new FakeMembers;
        $expectedMemberPage = new PageResult([], 1, 5, 0);
        $members->searchPage = $expectedMemberPage;
        self::assertSame($expectedMemberPage, (new SearchMembersService($members))->execute(' Ana ', 1, 5));
        self::assertSame(['Ana', 1, 5], $members->searchArguments);

        $this->expectException(ValidationException::class);
        (new SearchBooksService($this->books))->execute(size: 51);
    }

    public function test_book_and_cover_use_cases_reject_duplicates_and_invalid_uploads(): void
    {
        $this->books->records['book-1'] = $this->book();
        $createBook = new CreateBookService($this->books, $this->categories, $this->ids);
        try {
            $createBook->execute('Duplicate', 'Author', '1234567890123', 'category-1', 1);
            self::fail('An active ISBN must not be duplicated.');
        } catch (ConflictException $exception) {
            self::assertSame('ISBN_TAKEN', $exception->errorCode);
        }

        $coverService = new SetBookCoverService($this->books, new FakeCovers);
        try {
            $coverService->execute('book-1', str_repeat('x', 2 * 1024 * 1024 + 1), 'image/png');
            self::fail('Covers larger than 2 MB must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, $this->books->saved);
        }
        $this->expectException(ValidationException::class);
        $coverService->execute('book-1', 'not-an-image', 'image/svg+xml');
    }

    public function test_overdue_lines_are_sorted_by_days_late_and_report_uses_validated_range(): void
    {
        $this->loans->overdueItems = [
            new OverdueLoanItem('loan-1', 'item-1', 'Book A', 'Member A', $this->clock->now(), 2),
            new OverdueLoanItem('loan-2', 'item-2', 'Book B', 'Member B', $this->clock->now(), 7),
        ];
        $overdue = (new ListOverdueLoansService($this->loans, $this->clock))->execute();
        self::assertSame(['item-2', 'item-1'], array_map(static fn (OverdueLoanItem $item) => $item->itemId, $overdue));

        $rows = [new MostBorrowedRow('book-1', 'Old title', 'Novela', 3)];
        $reader = new FakeReports($rows);
        $from = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-10-31T23:59:59Z');
        self::assertSame($rows, (new MostBorrowedReportService($reader))->execute($from, $to));
        self::assertSame($from, $reader->requestedRange?->start);

        try {
            (new MostBorrowedReportService($reader))->execute($to, $from);
            self::fail('An inverted report range must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('INVALID_DATE_RANGE', $exception->errorCode);
        }
    }

    public function test_domain_and_application_do_not_import_laravel_or_eloquent(): void
    {
        $sourceRoot = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertNotFalse($contents);
            self::assertDoesNotMatchRegularExpression('/use\s+(?:Illuminate|App)\\\\|Illuminate\\\\/', $contents, $file->getPathname());
        }
    }

    private function book(int $total = 5, int $available = 5): Book
    {
        return new Book(
            'book-1',
            'Title',
            'Author',
            new Isbn('1234567890123'),
            'category-1',
            new Quantity($total),
            new Quantity($available)
        );
    }

    private function item(string $loanId, string $itemId, string $bookId, DateTimeImmutable $dueAt): LoanItem
    {
        return new LoanItem(
            $itemId,
            $loanId,
            $bookId,
            'Title',
            new Isbn('1234567890123'),
            'Novela',
            $dueAt
        );
    }
}
