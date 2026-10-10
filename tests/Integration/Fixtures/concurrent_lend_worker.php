<?php

use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use Application\DTO\PageResult;
use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\Clock;
use Application\Ports\IdGenerator;
use Application\Ports\LoanRepository;
use Application\Ports\MemberRepository;
use Application\Ports\TransactionManager;
use Application\Services\LendBooksService;
use Domain\Entities\Book;
use Domain\Exceptions\ConflictException;
use Domain\ValueObjects\Isbn;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$application = require dirname(__DIR__, 3).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$memberId = $argv[1];
$userId = $argv[2];
$bookId = $argv[3];
$readyPath = $argv[4];
$releasePath = $argv[5];
$delegate = new DatabaseBookRepository;
$books = new class($delegate, $readyPath, $releasePath) implements BookRepository
{
    public function __construct(
        private DatabaseBookRepository $delegate,
        private string $readyPath,
        private string $releasePath
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
    }

    public function findManyByIds(array $ids): array
    {
        $books = $this->delegate->findManyByIds($ids);
        file_put_contents($this->readyPath, 'ready', LOCK_EX);
        $deadline = microtime(true) + 20;
        while (! file_exists($this->releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrency test barrier timed out.');
            }
            usleep(10000);
        }

        return $books;
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

try {
    $service = new LendBooksService(
        $books,
        $application->make(CategoryRepository::class),
        $application->make(MemberRepository::class),
        $application->make(LoanRepository::class),
        $application->make(TransactionManager::class),
        $application->make(Clock::class),
        $application->make(IdGenerator::class)
    );
    $loan = $service->execute($memberId, $userId, [$bookId]);
    echo json_encode(['status' => 'success', 'loan_id' => $loan->id], JSON_THROW_ON_ERROR);
} catch (ConflictException $exception) {
    echo json_encode(['status' => 'conflict', 'code' => $exception->errorCode], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
