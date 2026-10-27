<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use App\Shared\Security\TokenGenerator;
use DomainException;

final readonly class CreatePropertyService
{
    public function __construct(
        private PropertyRepository $properties,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private TokenGenerator $tokens,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): int {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể tạo cơ sở lưu trú.');
        }

        $property = PropertyData::fromArray($input);
        $suffix = strtoupper(substr($this->tokens->generate(), 0, 12));
        $code = 'CS' . $suffix;
        $slug = $this->slugify($property->name) . '-' . strtolower($suffix);
        $data = $property->persistenceData($code, $slug);

        return $this->transactions->run(function () use (
            $organization, $actorId, $requestId, $clientIp, $data
        ): int {
            $propertyId = $this->properties->createDraft($organization->id, $data);
            $created = $this->properties->findForOrganization($propertyId, $organization->id);
            $this->audit->record(
                $actorId, 'PROPERTY_CREATED', 'PROPERTY', (string) $propertyId,
                $requestId, null, $created, $clientIp,
            );
            return $propertyId;
        });
    }

    private function slugify(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii === false ? $name : $ascii) ?? '');
        return trim($slug, '-') ?: 'property';
    }
}
