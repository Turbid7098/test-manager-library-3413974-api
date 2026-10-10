<?php

namespace Tests\Integration;

use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use App\Adapters\Outbound\Persistence\DatabaseCategoryRepository;
use App\Adapters\Outbound\Persistence\DatabaseMemberRepository;
use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use DateTimeImmutable;
use Domain\Entities\Book;
use Domain\Entities\Member;
use Domain\Entities\User;
use Domain\ValueObjects\DocumentNumber;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresPersistenceAdaptersTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        $connection = DB::connection('pgsql');

        if ($connection->getDriverName() !== 'pgsql') {
            self::fail('Persistence integration tests require a real PostgreSQL connection.');
        }

        $connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function test_book_repository_persists_searches_and_hides_withdrawn_books(): void
    {
        $books = new DatabaseBookRepository;
        $book = $this->book('Una biblioteca de invierno', '9780307474728');
        $books->save($book);

        $found = $books->findById($book->id);
        self::assertNotNull($found);
        self::assertSame($book->title(), $found->title());
        self::assertSame($book->author(), $found->author());
        self::assertSame($book->isbn->value, $found->isbn->value);
        self::assertSame(3, $found->availableCopies()->value);
        self::assertSame($book->id, $books->findByIsbn(new Isbn('9780307474728'))?->id);

        $other = $this->book('Invierno en la montaña', '9780307474735');
        $books->save($other);
        $literalPercent = $this->book('100% natural', '9780307474742');
        $books->save($literalPercent);
        $wildcardLookalike = $this->book('100 percent natural', '9780307474759');
        $books->save($wildcardLookalike);
        $literalUnderscore = $this->book('A_Book', '9780307474766');
        $books->save($literalUnderscore);
        $underscoreLookalike = $this->book('A Book', '9780307474773');
        $books->save($underscoreLookalike);

        $firstPage = $books->search('INVIERNO', self::CATEGORY_NOVEL, 1, 1);
        self::assertSame(2, $firstPage->total);
        self::assertSame(1, $firstPage->page);
        self::assertSame(1, $firstPage->size);
        self::assertCount(1, $firstPage->items);
        self::assertSame('Invierno en la montaña', $firstPage->items[0]->title());
        self::assertSame($literalPercent->id, $books->search('%', null, 1, 20)->items[0]->id);
        self::assertSame(1, $books->search('%', null, 1, 20)->total);
        self::assertSame($literalUnderscore->id, $books->search('_', null, 1, 20)->items[0]->id);
        self::assertSame(1, $books->search('_', null, 1, 20)->total);
        self::assertSame($book->id, $books->findManyByIds([$book->id, Str::uuid()->toString()])[0]->id);
        self::assertSame([], $books->findManyByIds([]));

        $book->setCoverKey('covers/integration-book.png');
        $book->withdraw();
        $books->save($book);
        $updated = $books->findById($book->id);
        self::assertSame('covers/integration-book.png', $updated?->coverKey());
        self::assertSame(2, $updated?->availableCopies()->value);

        self::assertFalse($books->hasOpenLoans($book->id));
        $member = $this->member('Lector de prueba', 'integration-book-member');
        (new DatabaseMemberRepository)->save($member);
        $user = $this->user('integration-book-librarian');
        (new DatabaseUserRepository)->save($user);
        $loanId = Str::uuid()->toString();
        $itemId = Str::uuid()->toString();
        DB::table('library.loan')->insert([
            'id' => $loanId,
            'member_id' => $member->id,
            'loaned_by_user_id' => $user->id,
            'loaned_at' => '2026-10-10T12:00:00Z',
        ]);
        DB::table('library.loan_item')->insert([
            'id' => $itemId,
            'loan_id' => $loanId,
            'book_id' => $book->id,
            'book_title' => $book->title(),
            'isbn' => $book->isbn->value,
            'category_name' => 'Novela',
            'due_at' => '2026-10-24T12:00:00Z',
            'returned_at' => null,
        ]);
        self::assertTrue($books->hasOpenLoans($book->id));

        DB::table('library.loan_item')->where('id', $itemId)->update(['returned_at' => '2026-10-12T12:00:00Z']);
        self::assertFalse($books->hasOpenLoans($book->id));

        $book->delete(new DateTimeImmutable('2026-10-10T12:00:00Z'));
        $books->save($book);
        self::assertNull($books->findById($book->id));
        self::assertNull($books->findByIsbn(new Isbn('9780307474728')));
        self::assertSame(0, $books->search('Una biblioteca de invierno', null, 1, 20)->total);
    }

    public function test_member_repository_finds_and_searches_by_name_or_document_with_pagination(): void
    {
        $members = new DatabaseMemberRepository;
        $first = $this->member('Ana Biblioteca', 'member-document-001');
        $second = $this->member('Ana Lectura', 'member-document-002');
        $third = $this->member('Beatriz Lectora', 'unique-document-search');
        foreach ([$first, $second, $third] as $member) {
            $members->save($member);
        }

        self::assertSame($first->id, $members->findById($first->id)?->id);
        self::assertSame($third->id, $members->findByDocumentNumber(new DocumentNumber(' unique-document-search '))?->id);

        $byName = $members->search('ANA', 1, 1);
        self::assertSame(2, $byName->total);
        self::assertSame('Ana Biblioteca', $byName->items[0]->fullName());
        self::assertSame('Ana Lectura', $members->search('Ana', 2, 1)->items[0]->fullName());

        $byDocument = $members->search('DOCUMENT-002', 1, 20);
        self::assertSame(1, $byDocument->total);
        self::assertSame($second->id, $byDocument->items[0]->id);
    }

    public function test_user_and_category_repositories_map_persisted_records(): void
    {
        $users = new DatabaseUserRepository;
        $user = $this->user('  Integration-Librarian  ');
        $users->save($user);

        self::assertSame($user->id, $users->findById($user->id)?->id);
        self::assertSame('integration-librarian', $users->findByUsername(' INTEGRATION-LIBRARIAN ')?->username());
        self::assertNull($users->findById(Str::uuid()->toString()));

        $categories = new DatabaseCategoryRepository;
        self::assertTrue($categories->exists(self::CATEGORY_NOVEL));
        self::assertFalse($categories->exists(Str::uuid()->toString()));
        self::assertSame(
            [self::CATEGORY_NOVEL => 'Novela'],
            $categories->findNamesByIds([self::CATEGORY_NOVEL, Str::uuid()->toString()])
        );
        self::assertSame(
            ['11111111-1111-4111-8111-111111111111' => 'Novela'],
            array_intersect_key($categories->findAllNames(), [self::CATEGORY_NOVEL => true])
        );
        self::assertSame([], $categories->findNamesByIds([]));
    }

    private function book(string $title, string $isbn): Book
    {
        return new Book(
            Str::uuid()->toString(),
            $title,
            'Autor de integración',
            new Isbn($isbn),
            self::CATEGORY_NOVEL,
            new Quantity(3),
            new Quantity(3)
        );
    }

    private function member(string $name, string $documentNumber): Member
    {
        return new Member(
            Str::uuid()->toString(),
            $name,
            new DocumentNumber($documentNumber)
        );
    }

    private function user(string $username): User
    {
        return new User(Str::uuid()->toString(), $username, 'integration-hash', 'librarian');
    }
}
