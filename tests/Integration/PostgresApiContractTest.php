<?php

namespace Tests\Integration;

use App\Adapters\Outbound\Persistence\DatabaseMemberRepository;
use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use Application\Ports\Clock;
use Application\Ports\PasswordHasher;
use DateTimeImmutable;
use Domain\Entities\Member;
use Domain\Entities\User;
use Domain\ValueObjects\DocumentNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresApiContractTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    private string $adminToken;

    private string $librarianToken;

    /** @var list<string> */
    private array $coverKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        self::assertSame('pgsql', DB::connection('pgsql')->getDriverName());
        DB::connection('pgsql')->beginTransaction();
        Storage::fake('covers');

        $users = new DatabaseUserRepository;
        $passwords = app(PasswordHasher::class);
        $this->adminToken = $this->loginToken(
            $users,
            $passwords,
            'api-admin-'.Str::lower(Str::random(10)),
            'admin-pass',
            'admin'
        );
        $this->librarianToken = $this->loginToken(
            $users,
            $passwords,
            'api-librarian-'.Str::lower(Str::random(10)),
            'librarian-pass',
            'librarian'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->coverKeys as $key) {
            Storage::disk('covers')->delete($key);
        }
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function test_authentication_roles_categories_and_librarian_creation_follow_the_contract(): void
    {
        $this->getJson('/api/v1/categories')
            ->assertUnauthorized()
            ->assertJsonStructure(['code', 'message', 'details']);

        $this->withToken($this->adminToken)
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(5, 'items')
            ->assertJsonPath('items.0.name', 'Ciencia');

        $created = $this->withToken($this->adminToken)
            ->postJson('/api/v1/users', [
                'username' => ' Ana Bibliotecaria ',
                'password' => 'new-librarian-password',
                'role' => 'librarian',
            ])
            ->assertCreated()
            ->assertJsonPath('username', 'ana bibliotecaria')
            ->assertJsonPath('role', 'librarian')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_hash');

        $this->withToken($this->librarianToken)
            ->postJson('/api/v1/users', [
                'username' => 'another-librarian',
                'password' => 'password',
                'role' => 'librarian',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/users', [
                'username' => 'invalid-role-user',
                'password' => 'password',
                'role' => 'admin',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        self::assertNotEmpty($created->json('id'));
    }

    public function test_books_members_and_binary_cover_routes_are_protected_and_serialized(): void
    {
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonStructure(['items', 'page', 'size', 'total']);

        $book = $this->withToken($this->adminToken)
            ->postJson('/api/v1/books', [
                'title' => 'Libro HTTP',
                'author' => 'Autora HTTP',
                'isbn' => '9780307474728',
                'category_id' => self::CATEGORY_NOVEL,
                'total_copies' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('category.name', 'Novela')
            ->assertJsonPath('available_copies', 2)
            ->json();
        $bookId = $book['id'];

        $this->withToken($this->librarianToken)
            ->getJson('/api/v1/books?q=HTTP&size=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $bookId);

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/books/'.$bookId, [
                'title' => 'Libro HTTP editado',
                'author' => 'Autora HTTP',
                'category_id' => self::CATEGORY_NOVEL,
                'total_copies' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('total_copies', 3);

        $contents = $this->png();
        $upload = UploadedFile::fake()->createWithContent('cover.png', $contents);
        $storedBook = $this->withToken($this->adminToken)
            ->put('/api/v1/books/'.$bookId.'/cover', ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('id', $bookId)
            ->json();
        $coverKey = $storedBook['cover_key'];
        $this->coverKeys[] = $coverKey;

        $this->withToken($this->librarianToken)
            ->get('/api/v1/books/'.$bookId.'/cover')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent($contents);

        $oversized = UploadedFile::fake()->create('oversized.png', 3072, 'image/png');
        $this->withToken($this->adminToken)
            ->put('/api/v1/books/'.$bookId.'/cover', ['file' => $oversized])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['code', 'message', 'details']);

        $this->withToken($this->librarianToken)
            ->postJson('/api/v1/books', [])
            ->assertForbidden();

        $this->withToken($this->adminToken)
            ->delete('/api/v1/books/'.$bookId.'/cover')
            ->assertNoContent();
        self::assertNull(DB::table('library.book')->where('id', $bookId)->value('cover_key'));

        $member = $this->withToken($this->librarianToken)
            ->postJson('/api/v1/members', [
                'full_name' => 'Socio HTTP',
                'document_number' => 'api-member-'.Str::lower(Str::random(16)),
            ])
            ->assertCreated()
            ->assertJsonPath('active_loans', 0)
            ->json();
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/members/'.$member['id'])
            ->assertOk()
            ->assertJsonPath('active_loans', 0);
        $this->withToken($this->librarianToken)
            ->getJson('/api/v1/members?q=Socio+HTTP')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissingPath('items.0.active_loans');

        $this->withToken($this->adminToken)
            ->delete('/api/v1/books/'.$bookId)
            ->assertNoContent();
        $this->withToken($this->librarianToken)
            ->getJson('/api/v1/books/'.$bookId)
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_loans_returns_overdue_lists_and_report_follow_the_contract(): void
    {
        $book = $this->withToken($this->adminToken)
            ->postJson('/api/v1/books', [
                'title' => 'Libro para préstamo',
                'author' => 'Autor',
                'isbn' => '9780307474735',
                'category_id' => self::CATEGORY_NOVEL,
                'total_copies' => 1,
            ])
            ->assertCreated()
            ->json();
        $member = new Member(
            Str::uuid()->toString(),
            'Socio de préstamo',
            new DocumentNumber('loan-member-'.Str::lower(Str::random(16)))
        );
        (new DatabaseMemberRepository)->save($member);
        $memberId = $member->id;

        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-10T12:00:00Z');
            }
        });
        $loan = $this->withToken($this->librarianToken)
            ->postJson('/api/v1/loans', [
                'member_id' => $memberId,
                'items' => [['book_id' => $book['id']]],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('items.0.due_at', '2026-10-24T12:00:00Z')
            ->json();

        $this->withToken($this->librarianToken)
            ->postJson('/api/v1/loans', [
                'member_id' => $memberId,
                'items' => [['book_id' => $book['id']]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOK_NOT_AVAILABLE');

        $this->withToken($this->adminToken)
            ->delete('/api/v1/books/'.$book['id'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOK_HAS_OPEN_LOANS');

        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-01T12:00:00Z');
            }
        });
        $this->withToken($this->librarianToken)
            ->getJson('/api/v1/loans?status=overdue&member_id='.$memberId.'&page=1&size=10')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.status', 'overdue');
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/loans/overdue')
            ->assertOk()
            ->assertJsonPath('items.0.member_name', 'Socio de préstamo')
            ->assertJsonPath('items.0.days_late', 8);
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/loans/'.$loan['id'])
            ->assertOk()
            ->assertJsonPath('status', 'overdue');
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/reports/most-borrowed?from=2026-10-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('rows.0.book_id', $book['id'])
            ->assertJsonPath('rows.0.times_borrowed', 1)
            ->assertJsonMissingPath('rows.0.member_name');
        $this->withToken($this->librarianToken)
            ->getJson('/api/v1/reports/most-borrowed?from=2026-10-01&to=2026-11-30')
            ->assertForbidden();

        $itemId = $loan['items'][0]['id'];
        $this->withToken($this->librarianToken)
            ->postJson('/api/v1/loans/'.$loan['id'].'/items/'.$itemId.'/return')
            ->assertOk()
            ->assertJsonPath('returned_at', '2026-11-01T12:00:00Z');
        $this->withToken($this->librarianToken)
            ->postJson('/api/v1/loans/'.$loan['id'].'/items/'.$itemId.'/return')
            ->assertStatus(409)
            ->assertJsonPath('code', 'ITEM_ALREADY_RETURNED');
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/members/'.$memberId)
            ->assertOk()
            ->assertJsonPath('active_loans', 0);
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/loans/'.$loan['id'])
            ->assertOk()
            ->assertJsonPath('status', 'closed');
        self::assertSame(1, (int) DB::table('library.book')->where('id', $book['id'])->value('available_copies'));
    }

    private function loginToken(
        DatabaseUserRepository $users,
        PasswordHasher $passwords,
        string $username,
        string $password,
        string $role
    ): string {
        $users->save(new User(
            Str::uuid()->toString(),
            $username,
            $passwords->hash($password),
            $role
        ));

        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ])->assertOk()->json('access_token');
    }

    private function png(): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNC5', 1, 1, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\x00\xff\x00\x00\xff"))
            .$chunk('IEND', '');
    }
}
