<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Eekes\Sulu\FormBuilderBundle\Application\Notification\PlaceholderRenderer;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldMapping;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;

/**
 * Carries a renamed field key to everything outside the form's own structure that refers to it.
 *
 * Within the structure the builder already follows a rename (conditions on fields and steps).
 * Notifications, flows and stored submissions are saved on their own, so without this they would
 * keep pointing at a key that no longer exists: a notification condition that never matches, a
 * placeholder that renders empty, a flow that sends nothing, an old submission in an empty column.
 */
final readonly class FieldKeyRenamer
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private FlowRepository $flowRepository,
        private SubmissionRepository $submissionRepository,
        private PlaceholderRenderer $placeholderRenderer,
    ) {
    }

    /**
     * Applies all renames at once, so swapping two keys works. Nothing is flushed: that is up to
     * whoever saves the form, so the form and everything that refers to it change together.
     *
     * @param array<string, string> $renamed new key by old key
     */
    public function rename(Form $form, array $renamed): void
    {
        if ($renamed === [] || !isset($form->id)) {
            return;
        }

        $rewrite = fn (string $text): string => $this->placeholderRenderer->renamePlaceholders($text, $renamed);

        foreach ($this->notificationRepository->findByForm($form) as $notification) {
            $notification->setConditions($notification->conditions()?->withRenamedFields($renamed));
            $notification->rewriteTexts($rewrite);
            $this->notificationRepository->save($notification, false);
        }

        foreach ($this->flowRepository->findByForm($form) as $flow) {
            $flow->setConditions($flow->conditions()?->withRenamedFields($renamed));
            $flow->setMapping(array_map(
                static fn (FieldMapping $mapping): FieldMapping => $mapping->isField()
                    ? FieldMapping::field($renamed[$mapping->value] ?? $mapping->value)
                    : FieldMapping::value($rewrite($mapping->value)),
                $flow->mapping(),
            ));
            $this->flowRepository->save($flow, false);
        }

        foreach ($this->submissionRepository->iterateByForm($form) as $submission) {
            $submission->renameKeys($renamed);
            $this->submissionRepository->save($submission, false);
        }
    }
}
