<?php

namespace Tests\Integration;

use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use App\Adapters\Outbound\Persistence\DatabaseMemberRepository;
use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use Domain\Entities\Book;
use Domain\Entities\Member;
use Domain\Entities\User;
use Domain\ValueObjects\DocumentNumber;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PostgresLoanConcurrencyTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    public function test_competing_checkouts_of_the_last_copy_commit_exactly_one_loan(): void
    {
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        self::assertSame('pgsql', DB::connection('pgsql')->getDriverName());

        $book = new Book(
            Str::uuid()->toString(),
            'Último ejemplar concurrente',
            'Autor de integración',
            new Isbn($this->uniqueIsbn()),
            self::CATEGORY_NOVEL,
            new Quantity(1),
            new Quantity(1)
        );
        $member = new Member(
            Str::uuid()->toString(),
            'Socio concurrente',
            new DocumentNumber('concurrent-'.Str::lower(Str::random(16)))
        );
        $user = new User(
            Str::uuid()->toString(),
            'concurrent-'.Str::lower(Str::random(16)),
            'integration-hash',
            'librarian'
        );
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'library-concurrency-'.Str::uuid();
        self::assertTrue(mkdir($directory));
        $readyPaths = [$directory.DIRECTORY_SEPARATOR.'ready-a', $directory.DIRECTORY_SEPARATOR.'ready-b'];
        $releasePath = $directory.DIRECTORY_SEPARATOR.'release';
        $worker = __DIR__.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'concurrent_lend_worker.php';
        $processes = [];

        try {
            (new DatabaseBookRepository)->save($book);
            (new DatabaseMemberRepository)->save($member);
            (new DatabaseUserRepository)->save($user);

            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    $member->id,
                    $user->id,
                    $book->id,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 20;
            while (! file_exists($readyPaths[0]) || ! file_exists($readyPaths[1])) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        self::fail('A concurrent checkout worker exited early: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    self::fail('Both concurrent checkout workers did not reach the read barrier.');
                }
                usleep(10000);
            }
            file_put_contents($releasePath, 'go', LOCK_EX);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame(['conflict', 'success'], $statuses);
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'success'
            ));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'conflict'
                    && ($result['code'] ?? null) === 'BOOK_NOT_AVAILABLE'
            ));
            self::assertSame(0, (int) DB::table('library.book')->where('id', $book->id)->value('available_copies'));
            self::assertSame(1, (int) DB::table('library.book')->where('id', $book->id)->value('version'));
            self::assertSame(1, (int) DB::table('library.loan')->where('member_id', $member->id)->count());
            self::assertSame(
                1,
                (int) DB::table('library.loan_item')
                    ->join('library.loan', 'library.loan.id', '=', 'library.loan_item.loan_id')
                    ->where('library.loan.member_id', $member->id)
                    ->whereNull('library.loan_item.returned_at')
                    ->count()
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::table('library.loan')->where('member_id', $member->id)->delete();
            DB::table('library.book')->where('id', $book->id)->delete();
            DB::table('library.member')->where('id', $member->id)->delete();
            DB::table('library.user')->where('id', $user->id)->delete();
            foreach (array_merge($readyPaths, [$releasePath]) as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
            DB::purge('pgsql');
        }
    }

    private function uniqueIsbn(): string
    {
        $firstTwelve = '978'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        $weightedSum = 0;
        for ($index = 0; $index < 12; $index++) {
            $weightedSum += (int) $firstTwelve[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return $firstTwelve.(string) ((10 - $weightedSum % 10) % 10);
    }
}
