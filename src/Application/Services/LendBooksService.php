<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\Clock;
use Application\Ports\IdGenerator;
use Application\Ports\LoanRepository;
use Application\Ports\MemberRepository;
use Application\Ports\TransactionManager;
use Domain\Entities\Loan;
use Domain\Entities\LoanItem;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\Isbn;

final class LendBooksService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly MemberRepository $members,
        private readonly LoanRepository $loans,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
        private readonly IdGenerator $ids
    ) {}

    /** @param list<string> $bookIds */
    public function execute(string $memberId, string $userId, array $bookIds): Loan
    {
        if (count($bookIds) < 1 || count($bookIds) > 3) {
            throw new ValidationException('El préstamo debe tener entre 1 y 3 libros.');
        }
        if (count(array_unique($bookIds)) !== count($bookIds)) {
            throw new ValidationException(
                'No se puede repetir el mismo libro en un préstamo.',
                [['field' => 'items', 'issue' => 'libro duplicado']],
                'DUPLICATE_BOOK_IN_LOAN'
            );
        }

        return $this->transactions->execute(function () use ($memberId, $userId, $bookIds): Loan {
            if ($this->members->findById($memberId) === null) {
                throw new NotFoundException('Socio no encontrado.');
            }

            $now = $this->clock->now();
            if ($this->loans->memberHasOverdueLoans($memberId, $now)) {
                throw new ConflictException(
                    'El socio tiene préstamos vencidos.',
                    'MEMBER_HAS_OVERDUE'
                );
            }
            if ($this->loans->countActiveItemsForMember($memberId) + count($bookIds) > 3) {
                throw new ValidationException(
                    'El socio no puede tener más de 3 libros sin devolver.',
                    [['field' => 'member', 'issue' => 'límite excedido']],
                    'MEMBER_LIMIT_EXCEEDED'
                );
            }

            $books = $this->books->findManyByIds($bookIds);
            if (count($books) !== count($bookIds)) {
                throw new NotFoundException('Uno o más libros no existen.');
            }

            $booksById = [];
            foreach ($books as $book) {
                if ($book->deletedAt() !== null) {
                    throw new NotFoundException('Uno o más libros no existen.');
                }
                $booksById[$book->id] = $book;
            }
            foreach ($bookIds as $bookId) {
                if (! isset($booksById[$bookId])) {
                    throw new NotFoundException('Uno o más libros no existen.');
                }
            }

            $categoryNames = $this->categories->findNamesByIds(
                array_values(array_unique(array_map(
                    static fn ($book): string => $book->categoryId(),
                    $books
                )))
            );
            $loanId = $this->ids->generate();
            $dueAt = $now->modify('+14 days');
            $items = [];

            foreach ($bookIds as $bookId) {
                $book = $booksById[$bookId];
                $categoryName = $categoryNames[$book->categoryId()] ?? null;
                if ($categoryName === null) {
                    throw new NotFoundException('La categoría del libro no existe.');
                }

                $book->withdraw();
                $this->books->save($book);
                $items[] = new LoanItem(
                    $this->ids->generate(),
                    $loanId,
                    $book->id,
                    $book->title(),
                    new Isbn($book->isbn->value),
                    $categoryName,
                    $dueAt
                );
            }

            $loan = new Loan($loanId, $memberId, $userId, $now, $items);
            $this->loans->save($loan);

            return $loan;
        });
    }
}
