<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Double;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A bus that only remembers what was dispatched, with its stamps: a transport that is never
 * consumed, so a test decides when the "worker" runs.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /**
     * @var list<Envelope>
     */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
