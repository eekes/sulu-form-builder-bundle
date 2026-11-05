<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Application\EventListener;

use Eekes\Sulu\FormBuilderBundle\Application\Event\FieldBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\FormBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\IntegrationRunFinishedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\NotificationSendingEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\StepCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCreatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Remembers the events of the bundle, and lets a test hook into one with a callback - the way an
 * application listener would.
 */
final class EventRecorder
{
    /**
     * @var list<object>
     */
    public array $events = [];

    /**
     * @var array<class-string, \Closure(object): void>
     */
    private array $callbacks = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $event
     * @param \Closure(T): void $callback
     */
    public function on(string $event, \Closure $callback): void
    {
        $this->callbacks[$event] = static function (object $recorded) use ($event, $callback): void {
            if ($recorded instanceof $event) {
                $callback($recorded);
            }
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function recorded(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }

    public function reset(): void
    {
        $this->events = [];
        $this->callbacks = [];
    }

    #[AsEventListener(event: FormBuildEvent::class)]
    #[AsEventListener(event: FieldBuildEvent::class)]
    #[AsEventListener(event: StepCompletedEvent::class)]
    #[AsEventListener(event: SubmissionValidatedEvent::class)]
    #[AsEventListener(event: SubmissionCreatedEvent::class)]
    #[AsEventListener(event: NotificationSendingEvent::class)]
    #[AsEventListener(event: SubmissionCompletedEvent::class)]
    #[AsEventListener(event: IntegrationRunFinishedEvent::class)]
    public function __invoke(object $event): void
    {
        $this->events[] = $event;

        if (isset($this->callbacks[$event::class])) {
            ($this->callbacks[$event::class])($event);
        }
    }
}
