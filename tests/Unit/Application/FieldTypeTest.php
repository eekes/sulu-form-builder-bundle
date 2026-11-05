<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CheckboxFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\ChoiceFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CountryFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateTimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\FileFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HeadingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HiddenFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\NumberFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RatingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RepeaterFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextBlockFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RatingType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\FieldTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

#[CoversClass(AbstractFieldType::class)]
#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(TextFieldType::class)]
#[CoversClass(NumberFieldType::class)]
#[CoversClass(ChoiceFieldType::class)]
#[CoversClass(CheckboxFieldType::class)]
#[CoversClass(FileFieldType::class)]
#[CoversClass(DateFieldType::class)]
#[CoversClass(TimeFieldType::class)]
#[CoversClass(DateTimeFieldType::class)]
#[CoversClass(RatingFieldType::class)]
#[CoversClass(RepeaterFieldType::class)]
#[CoversClass(CountryFieldType::class)]
#[CoversClass(HiddenFieldType::class)]
#[CoversClass(HeadingFieldType::class)]
#[CoversClass(TextBlockFieldType::class)]
final class FieldTypeTest extends TestCase
{
    public function testTheCommonOptionsComeFromWhatTheEditorFilledIn(): void
    {
        $options = (new TextFieldType())->getFormOptions(new FieldDefinition('name', 'text', 'Your name', placeholder: 'Jane Doe', help: 'As on your ID', required: true));

        self::assertSame('Your name', $options['label']);
        self::assertTrue($options['required']);
        self::assertSame('As on your ID', $options['help']);
        self::assertSame(['placeholder' => 'Jane Doe'], $options['attr']);
        self::assertFalse($options['translation_domain'], 'Labels are already in the language of the visitor.');
        self::assertEquals([new NotBlank()], $options['constraints']);
    }

    public function testAnEmptyLabelIsNoLabelInsteadOfAnEmptyOne(): void
    {
        self::assertFalse((new TextFieldType())->getFormOptions(new FieldDefinition('name', 'text', ''))['label']);
    }

    public function testLengthLimitsBecomeAConstraintAndAnAttribute(): void
    {
        $options = (new TextFieldType())->getFormOptions(new FieldDefinition('name', 'text', 'Name', options: ['minLength' => 2, 'maxLength' => 50]));

        self::assertEquals([new Length(min: 2, max: 50)], $options['constraints']);
        self::assertSame(50, \is_array($options['attr']) ? $options['attr']['maxlength'] : null);
    }

    public function testANumberStoresAnIntegerOrAFloat(): void
    {
        $type = new NumberFieldType();
        $integer = new FieldDefinition('age', 'number', 'Age', options: ['min' => 18, 'max' => 120]);
        $decimal = new FieldDefinition('price', 'number', 'Price', options: ['decimals' => 2]);

        self::assertEquals([new Range(min: 18, max: 120)], $type->getFormOptions($integer)['constraints']);
        self::assertSame(42, $type->normalizeValue('42', $integer));
        self::assertSame(4.5, $type->normalizeValue('4.5', $decimal));
        self::assertNull($type->normalizeValue('abc', $integer));
    }

    public function testAChoiceIsADropdownRadioButtonsOrCheckboxes(): void
    {
        $type = new ChoiceFieldType();
        $choices = [['label' => 'Sales', 'value' => 'sales'], ['label' => 'Support', 'value' => ''], ['label' => '', 'value' => '']];

        $select = $type->getFormOptions(new FieldDefinition('department', 'choice', 'Department', placeholder: 'Pick one', options: ['choices' => $choices]));
        self::assertSame(ChoiceType::class, $type->getFormType(new FieldDefinition('department', 'choice', 'Department')));
        self::assertSame(['Sales' => 'sales', 'Support' => 'Support'], $select['choices'], 'A choice without a value uses its label; an empty one is dropped.');
        self::assertFalse($select['expanded']);
        self::assertFalse($select['multiple']);
        self::assertSame('Pick one', $select['placeholder']);

        $radio = $type->getFormOptions(new FieldDefinition('department', 'choice', 'Department', options: ['choices' => $choices, 'display' => 'radio']));
        self::assertTrue($radio['expanded']);
        self::assertFalse($radio['multiple']);
        self::assertFalse($radio['placeholder']);

        $checkboxes = $type->getFormOptions(new FieldDefinition('department', 'choice', 'Department', required: true, options: ['choices' => $choices, 'display' => 'checkboxes']));
        self::assertTrue($checkboxes['expanded']);
        self::assertTrue($checkboxes['multiple']);
        self::assertEquals([new Count(min: 1)], $checkboxes['constraints']);
    }

    public function testTheChoicesAreTranslatedButTheValuesAreNot(): void
    {
        self::assertSame(['choices'], (new ChoiceFieldType())->getLocalizedOptions());
    }

    public function testAChoiceIsDisplayedByItsLabel(): void
    {
        $field = new FieldDefinition('department', 'choice', 'Department', options: ['choices' => [['label' => 'Sales', 'value' => 'sales'], ['label' => 'Support', 'value' => 'support']]]);

        self::assertSame('Sales, Support', (new ChoiceFieldType())->displayValue(['sales', 'support'], $field, 'en'));
        self::assertSame('removed', (new ChoiceFieldType())->displayValue('removed', $field, 'en'), 'A value whose choice was deleted later is shown as is.');
    }

    public function testARequiredCheckboxHasToBeTicked(): void
    {
        $type = new CheckboxFieldType(new IdentityTranslator());
        $options = $type->getFormOptions(new FieldDefinition('consent', 'checkbox', '<p>I accept the <a href="/privacy">privacy policy</a><script>x</script></p>', required: true));

        self::assertEquals([new IsTrue()], $options['constraints']);
        self::assertSame('I accept the <a href="/privacy">privacy policy</a>x', $options['label'], 'Inline tags stay, everything else goes.');
        self::assertTrue($options['label_html']);
        self::assertTrue($type->normalizeValue('1', new FieldDefinition('consent', 'checkbox', '')));
        self::assertSame('eekes_form_builder.yes', $type->displayValue(true, new FieldDefinition('consent', 'checkbox', ''), 'en'));
    }

    public function testAFileFieldLimitsSizeAndExtensions(): void
    {
        $type = new FileFieldType();
        $single = $type->getFormOptions(new FieldDefinition('cv', 'file', 'CV', required: true, options: ['maxSize' => 5, 'extensions' => 'PDF, .docx']));

        self::assertEquals([new NotBlank(), new File(maxSize: '5M', extensions: ['pdf', 'docx'])], $single['constraints']);
        self::assertSame(['accept' => '.pdf,.docx'], $single['attr']);

        $multiple = $type->getFormOptions(new FieldDefinition('photos', 'file', 'Photos', required: true, options: ['multiple' => true]));
        self::assertTrue($multiple['multiple']);
        self::assertEquals([new All(constraints: [new File()]), new Count(min: 1)], $multiple['constraints']);
    }

    public function testAFileIsStoredAsTheNamesOfTheUploads(): void
    {
        $field = new FieldDefinition('cv', 'file', 'CV');

        self::assertSame(['cv.pdf'], (new FileFieldType())->normalizeValue(new StoredFile('a.pdf', 'cv.pdf'), $field));
        self::assertSame(['a.pdf', 'b.pdf'], (new FileFieldType())->normalizeValue([new StoredFile('1', 'a.pdf'), new StoredFile('2', 'b.pdf')], $field));
        self::assertSame([], (new FileFieldType())->normalizeValue(null, $field));
    }

    public function testADateIsStoredAsIsoAndShownInTheLanguageOfTheSubmission(): void
    {
        $field = new FieldDefinition('birthday', 'date', 'Birthday');
        $type = new DateFieldType();

        self::assertSame('string', $type->getFormOptions($field)['input']);
        self::assertSame('', $type->displayValue(null, $field, 'en'));

        if (class_exists(\IntlDateFormatter::class)) {
            self::assertStringContainsString('2026', $type->displayValue('2026-10-28', $field, 'nl'));
            self::assertNotSame('2026-10-28', $type->displayValue('2026-10-28', $field, 'nl'));
        }
    }

    public function testATimeIsStoredAsHoursAndMinutesAndShownInTheLanguageOfTheSubmission(): void
    {
        $field = new FieldDefinition('call_at', 'time', 'Call me at');
        $type = new TimeFieldType();
        $options = $type->getFormOptions($field);

        self::assertSame('single_text', $options['widget']);
        self::assertSame('string', $options['input']);
        self::assertSame('H:i', $options['input_format']);
        self::assertSame('', $type->displayValue(null, $field, 'en'));
        self::assertSame('not a time', $type->displayValue('not a time', $field, 'en'));

        if (class_exists(\IntlDateFormatter::class)) {
            self::assertSame('14:30', $type->displayValue('14:30', $field, 'nl'));
            self::assertStringContainsString('2:30', $type->displayValue('14:30', $field, 'en'));
        }
    }

    public function testADateAndTimeIsStoredWithoutTimeZoneAndShownInTheLanguageOfTheSubmission(): void
    {
        $field = new FieldDefinition('appointment', 'datetime', 'Appointment');
        $type = new DateTimeFieldType();
        $options = $type->getFormOptions($field);

        self::assertSame('single_text', $options['widget']);
        self::assertSame('Y-m-d H:i', $options['input_format']);
        self::assertSame('', $type->displayValue('', $field, 'en'));

        if (class_exists(\IntlDateFormatter::class)) {
            $display = $type->displayValue('2026-10-28 14:30', $field, 'nl');

            self::assertStringContainsString('2026', $display);
            self::assertStringContainsString('14:30', $display);
        }
    }

    public function testARatingIsStarsFromOneOrAScaleFromZeroOrOne(): void
    {
        $type = new RatingFieldType();

        $stars = $type->getFormOptions(new FieldDefinition('score', 'rating', 'Score', options: ['min' => '0', 'max' => '3']));
        self::assertSame(RatingType::DISPLAY_STARS, $stars['rating_display']);
        self::assertSame(['1' => 1, '2' => 2, '3' => 3], $stars['choices'], 'Stars start at one, whatever the "from" says.');
        self::assertNull($stars['low_label']);

        $scale = $type->getFormOptions(new FieldDefinition('nps', 'rating', 'Recommend us?', options: ['display' => 'scale', 'min' => '0', 'max' => 10, 'lowLabel' => 'Not likely', 'highLabel' => ' ']));
        self::assertSame(RatingType::DISPLAY_SCALE, $scale['rating_display']);
        self::assertSame(range(0, 10), self::scores($scale));
        self::assertSame('Not likely', $scale['low_label']);
        self::assertNull($scale['high_label']);

        self::assertSame(range(1, 5), self::scores($type->getFormOptions(new FieldDefinition('score', 'rating', ''))), 'Five stars when the editor did not say.');
        self::assertSame(range(1, 10), self::scores($type->getFormOptions(new FieldDefinition('score', 'rating', '', options: ['max' => 99]))));
        self::assertSame(range(1, 2), self::scores($type->getFormOptions(new FieldDefinition('score', 'rating', '', options: ['max' => 1]))));
        self::assertSame(['lowLabel', 'highLabel'], $type->getLocalizedOptions());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<mixed>
     */
    private static function scores(array $options): array
    {
        self::assertIsArray($options['choices']);

        return array_values($options['choices']);
    }

    public function testARatingIsStoredAsANumberAndShownOutOfTheMaximum(): void
    {
        $type = new RatingFieldType();
        $field = new FieldDefinition('score', 'rating', 'Score', options: ['max' => 10]);

        self::assertSame(7, $type->normalizeValue('7', $field));
        self::assertNull($type->normalizeValue(null, $field));
        self::assertSame('7 / 10', $type->displayValue(7, $field, 'en'));
        self::assertSame('', $type->displayValue(null, $field, 'en'));
    }

    public function testARepeaterBuildsItsSubFieldsAsFieldsOfTheirOwn(): void
    {
        $repeater = FieldTypes::repeater();
        $field = new FieldDefinition('people', 'repeater', 'Participants', stepKey: 'register', options: ['fields' => [
            ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true, 'width' => '6'],
            ['type' => 'number', 'label' => 'Age', 'key' => 'age', 'max' => 120, 'width' => 5],
            ['type' => 'text', 'label' => 'Duplicate', 'key' => 'name'],
            ['type' => 'text', 'label' => 'Bad key', 'key' => '1st'],
            ['type' => 'file', 'label' => 'Photo', 'key' => 'photo'],
            ['type' => 'repeater', 'label' => 'Children', 'key' => 'children'],
            ['type' => 'heading', 'label' => 'Title', 'key' => 'title'],
            ['type' => 'signature', 'label' => 'Unknown', 'key' => 'sign'],
            'not a field',
        ]]);

        $subFields = $repeater->subFields($field);

        self::assertSame(['name', 'age'], array_map(static fn (FieldDefinition $subField): string => $subField->key, $subFields), 'Only usable, nestable input fields with a unique, valid key.');
        self::assertTrue($subFields[0]->required);
        self::assertSame(6, $subFields[0]->width->desktop);
        self::assertSame(12, $subFields[0]->width->mobile);
        self::assertSame(12, $subFields[1]->width->desktop, 'A width that is not offered is the full width.');
        self::assertSame(['max' => 120], $subFields[1]->options, 'Everything else is an option of the sub-field.');
        self::assertSame('register', $subFields[1]->stepKey);
        self::assertSame(['fields', 'addLabel', 'rowLabel'], $repeater->getLocalizedOptions());
    }

    public function testARepeaterAsksForAsManyRowsAsTheEditorWants(): void
    {
        $repeater = FieldTypes::repeater();

        self::assertSame([], $repeater->getFormOptions(new FieldDefinition('people', 'repeater', ''))['constraints']);

        $required = $repeater->getFormOptions(new FieldDefinition('people', 'repeater', '', required: true, options: ['max' => '3']));
        self::assertEquals([new Count(min: 1, max: 3, minMessage: 'eekes_form_builder.repeater.too_few', maxMessage: 'eekes_form_builder.repeater.too_many')], $required['constraints']);
        self::assertSame(1, $required['min']);
        self::assertTrue($required['required'], 'The form stays required, so its required sub-fields are.');
        self::assertTrue($required['marked_required']);

        self::assertSame(2, $repeater->min(new FieldDefinition('people', 'repeater', '', options: ['min' => 5, 'max' => 2])), 'Never more than the maximum.');
        self::assertFalse($repeater->getFormOptions(new FieldDefinition('people', 'repeater', ''))['marked_required']);
    }

    public function testARepeaterStoresItsFilledRowsAndShowsOneLinePerRow(): void
    {
        $repeater = FieldTypes::repeater();
        $field = new FieldDefinition('people', 'repeater', 'Participants', options: ['fields' => [
            ['type' => 'text', 'label' => 'Name', 'key' => 'name'],
            ['type' => 'number', 'label' => 'Age', 'key' => 'age'],
            ['type' => 'checkbox', 'label' => 'Vegetarian', 'key' => 'vegetarian'],
        ]]);

        $value = $repeater->normalizeValue([
            ['name' => 'Jane', 'age' => '34', 'vegetarian' => true],
            ['name' => '', 'age' => null, 'vegetarian' => false],
            ['name' => 'Joe', 'unknown' => 'x'],
            'not a row',
        ], $field);

        self::assertSame([
            ['name' => 'Jane', 'age' => 34, 'vegetarian' => true],
            ['name' => 'Joe', 'age' => null, 'vegetarian' => false],
        ], $value);
        self::assertSame("1. Name: Jane, Age: 34, Vegetarian: eekes_form_builder.yes\n2. Name: Joe", $repeater->displayValue($value, $field, 'en'));
        self::assertSame('', $repeater->displayValue(null, $field, 'en'));
    }

    public function testACountryIsShownByItsNameAndPreferredCountriesComeFirst(): void
    {
        $type = new CountryFieldType();
        $options = $type->getFormOptions(new FieldDefinition('country', 'country', 'Country', options: ['preferredCountries' => 'be, nl, xx']));

        self::assertSame(['BE', 'NL'], $options['preferred_choices']);
        self::assertSame('België', $type->displayValue('BE', new FieldDefinition('country', 'country', ''), 'nl'));
    }

    public function testAHiddenFieldCarriesItsDefaultValue(): void
    {
        $options = (new HiddenFieldType())->getFormOptions(new FieldDefinition('campaign', 'hidden', 'Campaign', options: ['defaultValue' => 'spring']));

        self::assertSame('spring', $options['data']);
    }

    public function testContentFieldsCollectNothing(): void
    {
        $heading = new HeadingFieldType();
        $text = new TextBlockFieldType();

        self::assertFalse($heading->isInput());
        self::assertFalse($text->isInput());
        self::assertSame(['html' => '<h4 class="eekes-form-heading">Your &lt;details&gt;</h4>'], $heading->getFormOptions(new FieldDefinition('h', 'heading', 'Your <details>', options: ['level' => 'h4'])));
        self::assertSame(['html' => '<h3 class="eekes-form-heading">X</h3>'], $heading->getFormOptions(new FieldDefinition('h', 'heading', 'X', options: ['level' => 'script'])));
        self::assertSame(['html' => '<div class="eekes-form-text"><p>Read this</p></div>'], $text->getFormOptions(new FieldDefinition('t', 'text_block', '', options: ['content' => '<p>Read this</p>'])));
        self::assertNull($text->normalizeValue('anything', new FieldDefinition('t', 'text_block', '')));
    }

    public function testThePaletteIsGroupedInputsFirst(): void
    {
        $groups = array_values(array_unique(array_map(static fn ($metadata): string => $metadata->group, FieldTypes::registry()->all())));

        self::assertSame(['input', 'choice', 'content'], $groups);
    }

    public function testAnUnknownTypeIsReportedWithTheKnownOnes(): void
    {
        $this->expectExceptionMessageMatches('/"signature" is not registered\. Known types are "text", "email"/');

        FieldTypes::registry()->get('signature');
    }
}
