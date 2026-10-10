<?php

namespace Application\Ports;

use Application\DTO\PageResult;
use Domain\Entities\Member;
use Domain\ValueObjects\DocumentNumber;

interface MemberRepository
{
    public function findById(string $id): ?Member;

    public function findByDocumentNumber(DocumentNumber $documentNumber): ?Member;

    public function save(Member $member): void;

    /** Searches by member name or document number and returns a paged result. */
    public function search(string $query, int $page, int $size): PageResult;
}
