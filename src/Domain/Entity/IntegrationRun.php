<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\RunStatus;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;

/**
 * One execution of a flow for one submission, with everything needed to see what happened and
 * to try again: the values that were sent, a snapshot of the settings, the request, the response
 * and the error.
 *
 * Snapshots rather than a reference to the flow: a retry has to do exactly what the first
 * attempt tried, even when the flow has been changed or deleted since.
 */
#[ORM\Entity(repositoryClass: IntegrationRunRepository::class)]
#[ORM\Table(name: 'eekes_form_integration_run')]
#[ORM\Index(name: 'idx_eekes_form_integration_run_status', columns: ['status'])]
class IntegrationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Submission::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Submission $submission;

    /**
     * The flow it came from; not a relation, because a run outlives the flow it came from.
     */
    #[ORM\Column(nullable: true)]
    public ?int $flowId = null;

    #[ORM\Column(length: 255)]
    public string $flowName;

    #[ORM\Column(length: 64)]
    public string $integration;

    #[ORM\Column(name: 'integration_action', length: 64)]
    public string $action;

    #[ORM\Column(length: 16)]
    private string $status = RunStatus::Pending->value;

    #[ORM\Column]
    public int $attempts = 0;

    /**
     * The mapped values, by target field key.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'mapped_values', type: Types::JSON)]
    public array $values = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $settings = [];

    /**
     * What was sent to the remote service, as the integration reported it.
     */
    #[ORM\Column(name: 'request_data', type: Types::JSON, nullable: true)]
    public mixed $request = null;

    #[ORM\Column(name: 'response_data', type: Types::JSON, nullable: true)]
    public mixed $response = null;

    /**
     * The error, or what the integration had to say about a success ("contact created").
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    /**
     * When the next automatic attempt is due, while the run is retrying.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $nextAttemptAt = null;

    /**
     * Every attempt, oldest first. The fields above repeat the last one, for the list.
     *
     * @var Collection<int, IntegrationRunAttempt>
     */
    #[ORM\OneToMany(targetEntity: IntegrationRunAttempt::class, mappedBy: 'run', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $attemptLog;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(Submission $submission, Flow $flow, array $values)
    {
        $this->submission = $submission;
        $this->flowId = $flow->id ?? null;
        $this->flowName = $flow->name;
        $this->integration = $flow->integration;
        $this->action = $flow->action;
        $this->settings = $flow->settings;
        $this->values = $values;
        $this->createdAt = new \DateTimeImmutable();
        $this->attemptLog = new ArrayCollection();
    }

    /**
     * A run that was never executed, with why not.
     *
     * @param array<string, mixed> $values
     */
    public static function skipped(Submission $submission, Flow $flow, array $values, string $reason): self
    {
        $run = new self($submission, $flow, $values);
        $run->status = RunStatus::Skipped->value;
        $run->message = $reason;
        $run->finishedAt = $run->createdAt;

        return $run;
    }

    public function status(): RunStatus
    {
        return RunStatus::tryFrom($this->status) ?? RunStatus::Pending;
    }

    /**
     * @param string $trigger one of the IntegrationRunAttempt::TRIGGER_* constants
     */
    public function start(string $trigger = IntegrationRunAttempt::TRIGGER_SUBMISSION, ?string $triggeredBy = null): IntegrationRunAttempt
    {
        ++$this->attempts;
        $this->status = RunStatus::Pending->value;
        $this->nextAttemptAt = null;

        $attempt = new IntegrationRunAttempt($this, $this->attempts, $trigger, $triggeredBy);
        $this->attemptLog->add($attempt);

        return $attempt;
    }

    public function finish(bool $succeeded, mixed $request, mixed $response, ?string $message, bool $retryable = false): void
    {
        $this->status = $succeeded ? RunStatus::Succeeded->value : RunStatus::Failed->value;
        $this->request = $request;
        $this->response = $response;
        $this->message = $message;
        $this->finishedAt = new \DateTimeImmutable();
        $this->lastAttempt()?->finish($succeeded, $request, $response, $message, $retryable);
    }

    /**
     * Failed, but tried again automatically at a later moment.
     */
    public function scheduleRetry(\DateTimeImmutable $at): void
    {
        $this->status = RunStatus::Retrying->value;
        $this->nextAttemptAt = $at;
    }

    /**
     * @return list<IntegrationRunAttempt>
     */
    public function attemptLog(): array
    {
        return array_values($this->attemptLog->toArray());
    }

    public function lastAttempt(): ?IntegrationRunAttempt
    {
        $last = $this->attemptLog->last();

        return $last instanceof IntegrationRunAttempt ? $last : null;
    }

    public function isPersisted(): bool
    {
        return isset($this->id);
    }
}
