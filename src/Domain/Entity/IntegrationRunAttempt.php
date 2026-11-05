<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One try of a run: when, how long, what was sent and received, and who or what started it.
 * A run keeps every attempt, so the log shows the timeout before the success, not just the
 * success.
 */
#[ORM\Entity]
#[ORM\Table(name: 'eekes_form_integration_run_attempt')]
class IntegrationRunAttempt
{
    /**
     * The submission came in.
     */
    final public const string TRIGGER_SUBMISSION = 'submission';

    /**
     * Scheduled after a failure that may pass later.
     */
    final public const string TRIGGER_AUTOMATIC = 'automatic';

    /**
     * "Try again" in the admin.
     */
    final public const string TRIGGER_EDITOR = 'editor';

    /**
     * A blocked submission was released as a real one.
     */
    final public const string TRIGGER_RELEASE = 'release';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: IntegrationRun::class, inversedBy: 'attemptLog')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public IntegrationRun $run;

    #[ORM\Column]
    public int $number;

    /**
     * "trigger" is a reserved word in MySQL, hence the column name.
     */
    #[ORM\Column(name: 'attempt_trigger', length: 16)]
    public string $trigger;

    /**
     * Who clicked "Try again", for an attempt an editor started.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $triggeredBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    public ?int $durationMs = null;

    #[ORM\Column(nullable: true)]
    public ?bool $succeeded = null;

    #[ORM\Column]
    public bool $retryable = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $message = null;

    #[ORM\Column(name: 'request_data', type: Types::JSON, nullable: true)]
    public mixed $request = null;

    #[ORM\Column(name: 'response_data', type: Types::JSON, nullable: true)]
    public mixed $response = null;

    private float $startedAtMicrotime;

    public function __construct(IntegrationRun $run, int $number, string $trigger, ?string $triggeredBy = null)
    {
        $this->run = $run;
        $this->number = $number;
        $this->trigger = $trigger;
        $this->triggeredBy = $triggeredBy;
        $this->startedAt = new \DateTimeImmutable();
        $this->startedAtMicrotime = microtime(true);
    }

    public function finish(bool $succeeded, mixed $request, mixed $response, ?string $message, bool $retryable): void
    {
        $this->succeeded = $succeeded;
        $this->request = $request;
        $this->response = $response;
        $this->message = $message;
        $this->retryable = !$succeeded && $retryable;
        $this->durationMs = (int) round((microtime(true) - $this->startedAtMicrotime) * 1000);
    }
}
