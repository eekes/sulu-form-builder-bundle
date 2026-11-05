<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * An integration that can check, on request, that it really works: the credentials are accepted,
 * the account is the right one, the service answers. Settings → Form builder integrations shows a "Test
 * connection" button for it and keeps the outcome of the last test.
 *
 * Strongly recommended for every integration that talks to a service. Implement it only when the
 * test means something: a test that always passes is worse than none.
 */
interface TestableIntegrationInterface extends IntegrationInterface
{
    /**
     * Asks the service, e.g. who the credentials belong to. Return IntegrationStatus::ready() with
     * what was found ("Connected as Acme") or IntegrationStatus::actionRequired() with why not;
     * an exception counts as a failed test with its message.
     */
    public function testConnection(): IntegrationStatus;

    /**
     * Whether the test sends something real to the other side - a webhook can only be tested by
     * posting to it. The admin then calls it "Send a test" and asks first.
     */
    public function testSendsData(): bool;
}
