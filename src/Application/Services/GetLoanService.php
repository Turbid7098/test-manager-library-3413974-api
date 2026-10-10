<?php

namespace Application\Services;

use Application\Ports\Clock;
use Application\Ports\LoanRepository;
use Domain\Entities\Loan;
use Domain\Exceptions\NotFoundException;

final class GetLoanService
{
    public function __construct(
        private readonly LoanRepository $loans,
        private readonly Clock $clock
    ) {}

    /** @return array{loan: Loan, status: string} */
    public function execute(string $id): array
    {
        $loan = $this->loans->findById($id);
        if ($loan === null) {
            throw new NotFoundException('El préstamo no existe.');
        }

        return ['loan' => $loan, 'status' => $loan->getStatus($this->clock->now())];
    }
}
