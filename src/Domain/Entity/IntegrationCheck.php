<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationCheckRepository;

/**
 * The last "Test connection" of an integration: when, by whom, and what came back. One row per
 * integration, overwritten by the next test.
 */
#[ORM\Entity(repositoryClass: IntegrationCheckRepository::class)]
#[ORM\Table(name: 'eekes_form_integration_check')]
#[ORM\UniqueConstraint(name: 'uniq_eekes_form_integration_check', columns: ['integration'])]
class IntegrationCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $testedAt;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $testedBy = null;

    #[ORM\Column]
    public bool $passed = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $message = null;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $details = [];

    public function __construct(
        #[ORM\Column(length: 64)]
        public string $integration,
    ) {
        $this->testedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, string> $details
     */
    public function record(bool $passed, ?string $message, array $details, ?string $testedBy): void
    {
        $this->passed = $passed;
        $this->message = $message;
        $this->details = $details;
        $this->testedBy = $testedBy;
        $this->testedAt = new \DateTimeImmutable();
    }
}
