<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Event\IntegrationRunFinishedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Message\ExecuteIntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\RunStatus;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Turns the flows of a form into runs for a submission, and executes runs.
 *
 * A run never throws: whatever goes wrong - a disabled integration, a value that is missing, a
 * remote service that is down - ends up in the run log, so the visitor's submission is never
 * affected and the editor can see what happened and try again. Every attempt is kept, and a flow
 * that did not run is logged too, with the reason.
 *
 * A failure that may pass later (see IntegrationResult::$retryable) is tried again automatically,
 * as RetryPolicy says, when runs go through Messenger.
 */
final readonly class IntegrationRunner
{
    public function __construct(
        private FlowRepository $flowRepository,
        private IntegrationRunRepository $integrationRunRepository,
        private IntegrationRegistry $integrationRegistry,
        private ConditionEvaluator $conditionEvaluator,
        private FlowValueMapper $flowValueMapper,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?MessageBusInterface $messageBus = null,
        private bool $async = true,
        private RetryPolicy $retryPolicy = new RetryPolicy(),
        private ?SendersLocatorInterface $sendersLocator = null,
    ) {
    }

    /**
     * Creates and starts a run for every enabled flow whose conditions the submission meets; the
     * other flows of the form get a skipped run that says why.
     *
     * A stored submission gets stored runs, executed through Messenger when it is available. A
     * submission that is not kept has nothing to attach a log to: its runs are executed right
     * away, and only the application log hears about a failure or a skipped flow.
     *
     * @param string $trigger IntegrationRunAttempt::TRIGGER_SUBMISSION, or TRIGGER_RELEASE for a blocked submission that was released
     *
     * @return list<IntegrationRun>
     */
    public function start(FormDefinition $definition, Submission $submission, string $trigger = IntegrationRunAttempt::TRIGGER_SUBMISSION): array
    {
        $values = $submission->toArray();
        $runs = [];

        foreach ($this->flowRepository->findByForm($definition->id) as $flow) {
            $reason = match (true) {
                !$flow->enabled => 'The flow is switched off.',
                !$this->integrationRegistry->isEnabled($flow->integration) => \sprintf('The integration "%s" is not enabled.', $flow->integration),
                !$this->conditionEvaluator->isSetMet($flow->conditions(), $values) => 'The conditions of the flow did not match this submission.',
                default => null,
            };

            if ($reason !== null) {
                $this->recordSkipped($submission, $flow, $reason);

                continue;
            }

            $run = new IntegrationRun($submission, $flow, $this->flowValueMapper->map($flow, $submission));
            $runs[] = $run;

            if (!$submission->isPersisted()) {
                $this->execute($run, $trigger);

                continue;
            }

            $this->integrationRunRepository->save($run);

            if ($this->async && $this->messageBus !== null) {
                $this->messageBus->dispatch(new ExecuteIntegrationRun($run->id, $trigger));
            } else {
                $this->execute($run, $trigger);
            }
        }

        return $runs;
    }

    /**
     * Logs every flow of the form as not run, for a submission that was blocked as spam or that a
     * listener kept from integrations.
     */
    public function skip(FormDefinition $definition, Submission $submission, string $reason): void
    {
        foreach ($this->flowRepository->findByForm($definition->id) as $flow) {
            $this->recordSkipped($submission, $flow, $reason);
        }
    }

    /**
     * Executes a run, or executes it again: a retry uses the values and settings the run was
     * created with.
     *
     * @param string $trigger one of the IntegrationRunAttempt::TRIGGER_* constants
     * @param null|string $triggeredBy who started it, for an editor's retry
     */
    public function execute(IntegrationRun $run, string $trigger = IntegrationRunAttempt::TRIGGER_SUBMISSION, ?string $triggeredBy = null): IntegrationRun
    {
        $run->start($trigger, $triggeredBy);
        $result = $this->result($run);
        $run->finish($result->succeeded, $result->request, $result->response, $result->message, $result->retryable);

        $retryIn = $this->retryIn($run, $result);

        if ($retryIn !== null) {
            $run->scheduleRetry(new \DateTimeImmutable(\sprintf('+%d seconds', $retryIn)));
        }

        if ($run->isPersisted()) {
            $this->integrationRunRepository->save($run);
        }

        if ($run->status() === RunStatus::Failed || $run->status() === RunStatus::Retrying) {
            $this->logger->log($retryIn !== null ? 'warning' : 'error', 'The integration flow "{flow}" ({integration}: {action}) failed for submission {submission} on attempt {attempt}: {message}', [
                'flow' => $run->flowName,
                'integration' => $run->integration,
                'action' => $run->action,
                'submission' => $run->submission->isPersisted() ? $run->submission->id : 'not stored',
                'attempt' => $run->attempts,
                'message' => $run->message,
                'retry_in' => $retryIn,
            ]);
        }

        $this->eventDispatcher->dispatch(new IntegrationRunFinishedEvent($run));

        if ($retryIn !== null && $this->messageBus !== null) {
            $this->messageBus->dispatch(new ExecuteIntegrationRun($run->id, IntegrationRunAttempt::TRIGGER_AUTOMATIC), [new DelayStamp($retryIn * 1000)]);
        }

        return $run;
    }

    /**
     * Seconds until the next automatic attempt, or null when there is none: it succeeded, it may
     * not pass later, it is out of attempts, or there is no worker to try again.
     */
    private function retryIn(IntegrationRun $run, IntegrationResult $result): ?int
    {
        if ($result->succeeded || !$result->retryable || !$run->isPersisted() || !$this->async || $this->messageBus === null || !$this->canDelay()) {
            return null;
        }

        return $this->retryPolicy->allowsAnotherAttempt($run->integration, $run->attempts)
            ? $this->retryPolicy->delay($run->integration, $run->attempts)
            : null;
    }

    /**
     * Only a transport can delay a message. Without routing, Messenger handles it right away,
     * which would retry a timeout at once - within the visitor's request.
     */
    private function canDelay(): bool
    {
        if ($this->sendersLocator === null) {
            return true;
        }

        foreach ($this->sendersLocator->getSenders(new Envelope(new ExecuteIntegrationRun(0))) as $sender) {
            return true;
        }

        return false;
    }

    private function recordSkipped(Submission $submission, Flow $flow, string $reason): void
    {
        if (!$submission->isPersisted()) {
            $this->logger->info('The integration flow "{flow}" did not run: {reason}', ['flow' => $flow->name, 'reason' => $reason]);

            return;
        }

        $this->integrationRunRepository->save(IntegrationRun::skipped($submission, $flow, $this->flowValueMapper->map($flow, $submission), $reason));
    }

    private function result(IntegrationRun $run): IntegrationResult
    {
        $action = $this->integrationRegistry->action($run->integration, $run->action);

        if ($action === null) {
            return IntegrationResult::failure(\sprintf('The action "%s" of the integration "%s" is not available: it was removed or the integration is not enabled.', $run->action, $run->integration));
        }

        $missing = [];

        foreach ($action->fields as $field) {
            $value = $run->values[$field->key] ?? null;

            if ($field->required && ($value === null || $value === '' || $value === [] || $value === false)) {
                $missing[] = $field->key;
            }
        }

        if ($missing !== []) {
            return IntegrationResult::failure(\sprintf('Required values are missing: "%s". Check the mapping of the flow, and whether those fields can be left empty.', implode('", "', $missing)), $run->values);
        }

        try {
            return $this->integrationRegistry->get($run->integration)->execute(
                new IntegrationRequest($run->action, $run->values, $run->settings, $run->submission, $run->flowName),
            );
        } catch (IntegrationException $exception) {
            return IntegrationResult::failure($exception->getMessage(), $exception->request, $exception->response, $exception->retryable);
        } catch (TransportExceptionInterface $exception) {
            // The service could not be reached: a timeout, a refused connection. Worth another try.
            return IntegrationResult::failure($exception::class.': '.$exception->getMessage(), retryable: true);
        } catch (\Throwable $exception) {
            return IntegrationResult::failure($exception::class.': '.$exception->getMessage());
        }
    }
}
