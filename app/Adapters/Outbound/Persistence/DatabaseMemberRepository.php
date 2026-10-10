<?php

namespace App\Adapters\Outbound\Persistence;

use Application\DTO\PageResult;
use Application\Ports\MemberRepository;
use Domain\Entities\Member;
use Domain\ValueObjects\DocumentNumber;
use Illuminate\Support\Facades\DB;

final class DatabaseMemberRepository implements MemberRepository
{
    public function findById(string $id): ?Member
    {
        $record = DB::table('library.member')->where('id', $id)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByDocumentNumber(DocumentNumber $documentNumber): ?Member
    {
        $record = DB::table('library.member')
            ->where('document_number', $documentNumber->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function save(Member $member): void
    {
        DB::table('library.member')->updateOrInsert(
            ['id' => $member->id],
            [
                'full_name' => $member->fullName(),
                'document_number' => $member->documentNumber->value,
            ]
        );
    }

    public function search(string $query, int $page, int $size): PageResult
    {
        $members = DB::table('library.member')
            ->when($query !== '', function ($builder) use ($query): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%';
                $builder->where(function ($search) use ($pattern): void {
                    $search->whereRaw("full_name ILIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("document_number ILIKE ? ESCAPE '!'", [$pattern]);
                });
            });

        $total = (clone $members)->count();
        $records = $members
            ->orderByRaw('LOWER(full_name) ASC')
            ->orderBy('id')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();

        return new PageResult(
            $records->map(fn (object $record): Member => $this->toDomain($record))->all(),
            $page,
            $size,
            $total
        );
    }

    private function toDomain(object $record): Member
    {
        return new Member(
            $record->id,
            $record->full_name,
            new DocumentNumber($record->document_number)
        );
    }
}
