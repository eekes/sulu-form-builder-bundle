<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Double;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeInterface;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeMetadata;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CheckboxFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\ChoiceFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CountryFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateTimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\EmailFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\FileFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HeadingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HiddenFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\NumberFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\PhoneFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RatingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RepeaterFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextareaFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextBlockFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\UrlFieldType;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The field types of the bundle, built the way the compiler pass registers them, for unit tests
 * that need a registry without a container.
 */
final class FieldTypes
{
    public static function registry(): FieldTypeRegistry
    {
        // The repeater builds its sub-fields with the other types; it can never contain itself.
        $repeater = self::repeater();

        return self::registryOf([...self::all(), $repeater]);
    }

    public static function repeater(): RepeaterFieldType
    {
        return new RepeaterFieldType(self::registryOf(self::all()));
    }

    /**
     * @param list<FieldTypeInterface> $fieldTypes
     */
    private static function registryOf(array $fieldTypes): FieldTypeRegistry
    {
        $factories = [];
        $metadata = [];

        foreach ($fieldTypes as $fieldType) {
            $attribute = (new \ReflectionClass($fieldType))->getAttributes(AsFormFieldType::class)[0]->newInstance();
            $factories[$attribute->key] = static fn (): FieldTypeInterface => $fieldType;
            $metadata[$attribute->key] = new FieldTypeMetadata($attribute->key, $attribute->title, $attribute->icon, $attribute->group, $attribute->position, FieldTypeMetadata::defaultAdminFormKey($attribute->key));
        }

        return new FieldTypeRegistry(new ServiceLocator($factories), $metadata);
    }

    /**
     * @return list<FieldTypeInterface>
     */
    public static function all(): array
    {
        return [
            new TextFieldType(),
            new EmailFieldType(),
            new TextareaFieldType(),
            new NumberFieldType(),
            new PhoneFieldType(),
            new UrlFieldType(),
            new DateFieldType(),
            new TimeFieldType(),
            new DateTimeFieldType(),
            new FileFieldType(),
            new HiddenFieldType(),
            new ChoiceFieldType(),
            new CheckboxFieldType(new IdentityTranslator()),
            new CountryFieldType(),
            new RatingFieldType(),
            new HeadingFieldType(),
            new TextBlockFieldType(),
        ];
    }
}
