<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Notification\PlaceholderRenderer;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceholderRenderer::class)]
final class PlaceholderRendererTest extends TestCase
{
    public function testAnswersReplaceTheirPlaceholders(): void
    {
        self::assertSame('Thanks Jane, we got your message about sales.', (new PlaceholderRenderer())->render('Thanks {{ name }}, we got your message about {{department}}.', $this->submission(), false));
    }

    public function testTheFormAndTheSubmissionAreKnownToo(): void
    {
        $submission = $this->submission();
        $submission->url = 'https://example.com/contact';

        self::assertSame('Contact via https://example.com/contact', (new PlaceholderRenderer())->render('{{ form_title }} via {{ url }}', $submission, false));
    }

    public function testAnUnknownPlaceholderBecomesEmpty(): void
    {
        self::assertSame('Hello !', (new PlaceholderRenderer())->render('Hello {{ nobody }}!', $this->submission(), false));
    }

    /**
     * The body of an e-mail is HTML an editor wrote, the answers are text a visitor typed.
     */
    public function testWhatAVisitorTypedIsEscapedInHtml(): void
    {
        $rendered = (new PlaceholderRenderer())->render('<p>{{ message }}</p>', $this->submission(), true);

        self::assertSame("<p>&lt;script&gt;alert(1)&lt;/script&gt;<br />\nbye</p>", $rendered);
    }

    public function testATemplateIsNeverEvaluated(): void
    {
        self::assertSame('{% if true %}x{% endif %}', (new PlaceholderRenderer())->render('{% if true %}x{% endif %}', $this->submission(), false));
    }

    public function testThePlaceholdersOfATextCanBeListed(): void
    {
        self::assertSame(['name', 'email'], (new PlaceholderRenderer())->placeholders('{{ name }} <{{email}}> {{ name }}'));
    }

    public function testPlaceholdersFollowRenamedFieldsAndKeepTheirSpacing(): void
    {
        self::assertSame(
            'Hi {{ first }} <{{mail}}>, {{ form_title }}',
            (new PlaceholderRenderer())->renamePlaceholders('Hi {{ name }} <{{email}}>, {{ form_title }}', ['name' => 'first', 'email' => 'mail']),
        );
    }

    private function submission(): Submission
    {
        return new Submission(new Form('contact', 'Contact'), 'en', [
            new SubmittedValue('name', 'Name', 'text', 'Jane', 'Jane'),
            new SubmittedValue('department', 'Department', 'choice', 'sales', 'sales'),
            new SubmittedValue('message', 'Message', 'textarea', "<script>alert(1)</script>\nbye", "<script>alert(1)</script>\nbye"),
        ]);
    }
}
