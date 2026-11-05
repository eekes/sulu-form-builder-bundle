<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * A service that does something with a submission: subscribe a newsletter, create a lead,
 * open a ticket.
 *
 * An integration describes its actions - the fields each needs and the settings an editor picks -
 * and executes them. Everything else is done by the bundle: the admin tab, mapping the answers,
 * conditions, running in the background, logging every run and retrying it. Throw an
 * IntegrationException (or return IntegrationResult::failure()) when the remote service refuses;
 * any other exception is caught and logged as a failure too.
 */
interface IntegrationInterface
{
    /**
     * @return list<IntegrationAction>
     */
    public function getActions(): array;

    public function execute(IntegrationRequest $request): IntegrationResult;

    /**
     * The choices of a setting declared with dynamicOptions, e.g. the lists of a Mailchimp
     * account. Called when an editor opens the flow modal.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getOptions(string $action, string $setting): array;
}
