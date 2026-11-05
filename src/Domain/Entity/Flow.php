<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldMapping;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;

/**
 * One action of an integration, set up for one form: "subscribe to the newsletter list in
 * Mailchimp when the newsletter box is ticked", "create a lead in the CRM".
 *
 * The settings are whatever the action declares (a list, a pipeline, ...); the mapping says which
 * answer, or which fixed value, fills each field the action needs.
 */
#[ORM\Entity(repositoryClass: FlowRepository::class)]
#[ORM\Table(name: 'eekes_form_flow')]
class Flow
{
    final public const string RESOURCE_KEY = 'eekes_form_flows';
    final public const string LIST_KEY = 'eekes_form_flows';
    final public const string FORM_KEY = 'eekes_form_flow_details';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Form $form;

    /**
     * Internal name, only shown in the admin.
     */
    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column]
    public bool $enabled = true;

    #[ORM\Column(length: 64)]
    public string $integration;

    #[ORM\Column(name: 'integration_action', length: 64)]
    public string $action;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $settings = [];

    /**
     * @var array<string, array<string, mixed>> see FieldMapping
     */
    #[ORM\Column(type: Types::JSON)]
    private array $mapping = [];

    /**
     * @var array<mixed> see ConditionSet
     */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    #[ORM\Column]
    public int $position = 0;

    public function __construct(Form $form, string $name, string $integration, string $action)
    {
        $this->form = $form;
        $this->name = $name;
        $this->integration = $integration;
        $this->action = $action;
    }

    /**
     * @return array<string, FieldMapping> by target field key
     */
    public function mapping(): array
    {
        $mapping = [];

        foreach ($this->mapping as $target => $data) {
            $fieldMapping = FieldMapping::fromArray($data);

            if ($fieldMapping !== null) {
                $mapping[$target] = $fieldMapping;
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, FieldMapping> $mapping
     */
    public function setMapping(array $mapping): void
    {
        $this->mapping = array_map(static fn (FieldMapping $fieldMapping): array => $fieldMapping->toArray(), $mapping);
    }

    public function conditions(): ?ConditionSet
    {
        return ConditionSet::fromArray($this->conditions);
    }

    public function setConditions(?ConditionSet $conditions): void
    {
        $this->conditions = $conditions?->toArray() ?? [];
    }

    public function __clone()
    {
        unset($this->id);
    }
}
