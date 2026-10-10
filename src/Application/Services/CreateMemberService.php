<?php

namespace Application\Services;

use Application\Ports\IdGenerator;
use Application\Ports\MemberRepository;
use Domain\Entities\Member;
use Domain\Exceptions\ConflictException;
use Domain\ValueObjects\DocumentNumber;

final class CreateMemberService
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly IdGenerator $ids
    ) {}

    public function execute(string $fullName, string $documentNumber): Member
    {
        $document = new DocumentNumber($documentNumber);
        if ($this->members->findByDocumentNumber($document) !== null) {
            throw new ConflictException('El número de documento ya está registrado.', 'DOCUMENT_TAKEN');
        }

        $member = new Member($this->ids->generate(), $fullName, $document);
        $this->members->save($member);

        return $member;
    }
}
