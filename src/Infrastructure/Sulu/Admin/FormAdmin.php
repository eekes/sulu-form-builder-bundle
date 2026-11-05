<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Sulu\Bundle\ActivityBundle\Infrastructure\Sulu\Admin\View\ActivityViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ListItemAction;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * "Form builder" in the main navigation - not "Forms", so it is never mistaken for Sulu's own form
 * bundle - and one tab per concern on a form instead of one long page:
 *
 * - Builder: the steps and the 12-column grid of fields, each field edited in a modal
 * - Settings: title, key, what happens after sending, button labels
 * - Notifications: the e-mails, each edited in a modal
 * - Integrations: flows that send submissions to other services (when any integration is enabled)
 * - Submissions: what visitors sent, every answer readable in a modal
 * - Spam: what a spam check caught, with why and where from; a person caught by mistake can be
 *   released (only with the permissions of the spam security context)
 * - Activity: who changed what
 */
final class FormAdmin extends Admin
{
    public const string LIST_VIEW = 'eekes_form_builder.forms.list';
    public const string ADD_FORM_VIEW = 'eekes_form_builder.forms.add_form';
    public const string ADD_FORM_DETAILS_VIEW = 'eekes_form_builder.forms.add_form.settings';
    public const string EDIT_FORM_VIEW = 'eekes_form_builder.forms.edit_form';
    public const string EDIT_FORM_BUILDER_VIEW = 'eekes_form_builder.forms.edit_form.builder';
    public const string EDIT_FORM_SETTINGS_VIEW = 'eekes_form_builder.forms.edit_form.settings';
    public const string EDIT_FORM_NOTIFICATIONS_VIEW = 'eekes_form_builder.forms.edit_form.notifications';
    public const string EDIT_FORM_INTEGRATIONS_VIEW = 'eekes_form_builder.forms.edit_form.integrations';
    public const string EDIT_FORM_SUBMISSIONS_VIEW = 'eekes_form_builder.forms.edit_form.submissions';
    public const string EDIT_FORM_SPAM_VIEW = 'eekes_form_builder.forms.edit_form.spam';
    public const string EDIT_FORM_ACTIVITY_VIEW = 'eekes_form_builder.forms.edit_form.activity';

    public const string BUILDER_FORM_KEY = 'eekes_form_builder';
    public const string SETTINGS_FORM_KEY = 'eekes_form_settings';

    public const string SHOW_SUBMISSION_ITEM_ACTION = 'eekes_form_builder.show_submission';

    /**
     * Settings → Form builder integrations: the status of every integration, and connecting accounts.
     * Its own permission, because connecting an account acts in the name of the organisation.
     */
    public const string INTEGRATIONS_SECURITY_CONTEXT = 'sulu.form_builder.integrations';
    public const string INTEGRATIONS_VIEW = 'eekes_form_builder.integration_connections';
    public const string INTEGRATIONS_PATH = 'form-builder-integrations';

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly int $navigationPosition,
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly ?ActivityViewBuilderFactoryInterface $activityViewBuilderFactory = null,
    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if ($this->integrationRegistry->enabled() !== []
            && $this->securityChecker->hasPermission(self::INTEGRATIONS_SECURITY_CONTEXT, PermissionTypes::VIEW)
            && $navigationItemCollection->has(Admin::SETTINGS_NAVIGATION_ITEM)
        ) {
            $integrations = new NavigationItem('eekes_form_builder.form_integrations');
            $integrations->setPosition(60);
            $integrations->setView(self::INTEGRATIONS_VIEW);
            $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($integrations);
        }

        if (!$this->securityChecker->hasPermission(Form::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $navigationItem = new NavigationItem('eekes_form_builder.forms');
        $navigationItem->setPosition($this->navigationPosition);
        $navigationItem->setIcon('su-list-check');
        $navigationItem->setView(self::LIST_VIEW);

        $navigationItemCollection->add($navigationItem);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if ($this->integrationRegistry->enabled() !== [] && $this->securityChecker->hasPermission(self::INTEGRATIONS_SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            $viewCollection->add(
                $this->viewBuilderFactory->createViewBuilder(self::INTEGRATIONS_VIEW, '/'.self::INTEGRATIONS_PATH, self::INTEGRATIONS_VIEW)
                    ->setOption('mayEdit', $this->securityChecker->hasPermission(self::INTEGRATIONS_SECURITY_CONTEXT, PermissionTypes::EDIT)),
            );
        }

        if (!$this->securityChecker->hasPermission(Form::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $locales = $this->webspaceManager->getAllLocales();
        $mayAdd = $this->securityChecker->hasPermission(Form::SECURITY_CONTEXT, PermissionTypes::ADD);
        $mayEdit = $this->securityChecker->hasPermission(Form::SECURITY_CONTEXT, PermissionTypes::EDIT);
        $mayDelete = $this->securityChecker->hasPermission(Form::SECURITY_CONTEXT, PermissionTypes::DELETE);

        $listToolbarActions = [];
        $formToolbarActions = [];

        if ($mayAdd) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.add');
            $formToolbarActions[] = new ToolbarAction('sulu_admin.copy');
        }

        if ($mayEdit) {
            array_unshift($formToolbarActions, new ToolbarAction('sulu_admin.save'));
        }

        if ($mayDelete) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.delete');
            $formToolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        $viewCollection->add(
            $this->viewBuilderFactory->createListViewBuilder(self::LIST_VIEW, '/forms/:locale')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->setListKey(Form::LIST_KEY)
                ->setTitle('eekes_form_builder.forms')
                ->addListAdapters(['table'])
                ->addLocales($locales)
                ->setDefaultLocale($locales[0] ?? 'en')
                ->setAddView(self::ADD_FORM_VIEW)
                ->setEditView(self::EDIT_FORM_VIEW)
                ->addToolbarActions($listToolbarActions),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createResourceTabViewBuilder(self::ADD_FORM_VIEW, '/forms/:locale/add')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->addLocales($locales)
                ->setBackView(self::LIST_VIEW),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormViewBuilder(self::ADD_FORM_DETAILS_VIEW, '/settings')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->setFormKey(self::SETTINGS_FORM_KEY)
                ->setTabTitle('eekes_form_builder.settings')
                ->setEditView(self::EDIT_FORM_VIEW)
                ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                ->setParent(self::ADD_FORM_VIEW),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createResourceTabViewBuilder(self::EDIT_FORM_VIEW, '/forms/:locale/:id')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->addLocales($locales)
                ->setBackView(self::LIST_VIEW)
                ->setTitleProperty('title'),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormViewBuilder(self::EDIT_FORM_BUILDER_VIEW, '/builder')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->setFormKey(self::BUILDER_FORM_KEY)
                ->setTabTitle('eekes_form_builder.builder')
                ->setTabPriority(1024)
                ->addToolbarActions($formToolbarActions)
                ->setParent(self::EDIT_FORM_VIEW),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormViewBuilder(self::EDIT_FORM_SETTINGS_VIEW, '/settings')
                ->setResourceKey(Form::RESOURCE_KEY)
                ->setFormKey(self::SETTINGS_FORM_KEY)
                ->setTabTitle('eekes_form_builder.settings')
                ->setTabPriority(1000)
                ->addToolbarActions($formToolbarActions)
                ->setParent(self::EDIT_FORM_VIEW),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormOverlayListViewBuilder(self::EDIT_FORM_NOTIFICATIONS_VIEW, '/notifications')
                ->setResourceKey(Notification::RESOURCE_KEY)
                ->setListKey(Notification::LIST_KEY)
                ->setFormKey(Notification::FORM_KEY)
                ->setTabTitle('eekes_form_builder.notifications')
                ->setTabPriority(900)
                ->addListAdapters(['table'])
                ->setOverlaySize('small')
                ->setAddOverlayTitle('eekes_form_builder.add_notification')
                ->setEditOverlayTitle('eekes_form_builder.edit_notification')
                ->addRouterAttributesToListRequest(['id' => 'formId'])
                ->addRouterAttributesToFormRequest(['id' => 'formId'])
                ->addToolbarActions(array_values(array_filter([
                    $mayEdit ? new ToolbarAction('sulu_admin.add') : null,
                    $mayEdit ? new ToolbarAction('sulu_admin.delete') : null,
                ])))
                ->setParent(self::EDIT_FORM_VIEW),
        );

        // Only when a developer enabled an integration; otherwise there is nothing to set up.
        if ($this->integrationRegistry->enabled() !== []) {
            $viewCollection->add(
                $this->viewBuilderFactory->createFormOverlayListViewBuilder(self::EDIT_FORM_INTEGRATIONS_VIEW, '/integrations')
                    ->setResourceKey(Flow::RESOURCE_KEY)
                    ->setListKey(Flow::LIST_KEY)
                    ->setFormKey(Flow::FORM_KEY)
                    ->setTabTitle('eekes_form_builder.integrations')
                    ->setTabPriority(850)
                    ->addListAdapters(['table'])
                    ->setOverlaySize('small')
                    ->setAddOverlayTitle('eekes_form_builder.add_flow')
                    ->setEditOverlayTitle('eekes_form_builder.edit_flow')
                    ->addRouterAttributesToListRequest(['id' => 'formId'])
                    ->addRouterAttributesToFormRequest(['id' => 'formId'])
                    ->addToolbarActions(array_values(array_filter([
                        $mayEdit ? new ToolbarAction('sulu_admin.add') : null,
                        $mayEdit ? new ToolbarAction('sulu_admin.delete') : null,
                    ])))
                    ->setParent(self::EDIT_FORM_VIEW),
            );
        }

        $submissionsView = $this->viewBuilderFactory->createListViewBuilder(self::EDIT_FORM_SUBMISSIONS_VIEW, '/submissions')
                ->setResourceKey(Submission::RESOURCE_KEY)
                ->setListKey(Submission::LIST_KEY)
                ->setTabTitle('eekes_form_builder.submissions')
                ->setTabPriority(800)
                ->addListAdapters(['table'])
                ->addRouterAttributesToListRequest(['id' => 'formId'])
                ->addRouterAttributesToListMetadata(['id' => 'formId', 'locale' => 'locale'])
                ->addItemActions([new ListItemAction(self::SHOW_SUBMISSION_ITEM_ACTION)])
            ->addToolbarActions($mayDelete ? [new ToolbarAction('sulu_admin.delete')] : []);

        if (!$mayDelete) {
            $submissionsView->disableSelection();
        }

        $viewCollection->add($submissionsView->setParent(self::EDIT_FORM_VIEW));

        if ($this->securityChecker->hasPermission(Submission::SPAM_SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            $mayDeleteSpam = $this->securityChecker->hasPermission(Submission::SPAM_SECURITY_CONTEXT, PermissionTypes::DELETE);
            $spamView = $this->viewBuilderFactory->createListViewBuilder(self::EDIT_FORM_SPAM_VIEW, '/spam')
                ->setResourceKey(Submission::SPAM_RESOURCE_KEY)
                ->setListKey(Submission::SPAM_LIST_KEY)
                ->setTabTitle('eekes_form_builder.spam')
                ->setTabPriority(790)
                ->addListAdapters(['table'])
                ->addRouterAttributesToListRequest(['id' => 'formId'])
                ->addRouterAttributesToListMetadata(['id' => 'formId', 'locale' => 'locale'])
                ->addItemActions([new ListItemAction(self::SHOW_SUBMISSION_ITEM_ACTION, [
                    'mayRelease' => $this->securityChecker->hasPermission(Submission::SPAM_SECURITY_CONTEXT, PermissionTypes::EDIT),
                ])])
                ->addToolbarActions($mayDeleteSpam ? [new ToolbarAction('sulu_admin.delete')] : []);

            if (!$mayDeleteSpam) {
                $spamView->disableSelection();
            }

            $viewCollection->add($spamView->setParent(self::EDIT_FORM_VIEW));
        }

        if ($this->activityViewBuilderFactory !== null && $this->activityViewBuilderFactory->hasActivityListPermission()) {
            $viewCollection->add(
                $this->activityViewBuilderFactory
                    ->createActivityListViewBuilder(self::EDIT_FORM_ACTIVITY_VIEW, '/activity', Form::RESOURCE_KEY)
                    ->setParent(self::EDIT_FORM_VIEW),
            );
        }
    }

    /**
     * @return array<string, array<string, array<string, list<string>>>>
     */
    public function getSecurityContexts(): array
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'eekes_form_builder.forms' => [
                    Form::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                    // Edit is connecting and disconnecting an account.
                    self::INTEGRATIONS_SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::EDIT,
                    ],
                    // Edit is "Not spam": releasing a blocked submission as a real one.
                    Submission::SPAM_SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }
}
