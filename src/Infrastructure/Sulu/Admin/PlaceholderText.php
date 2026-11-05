<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

/**
 * Admin texts that show a placeholder, like "{{ email }} sends to what the visitor filled in".
 *
 * Sulu's admin parses every text of the "admin" domain as an ICU message, where "{{" is invalid.
 * So such a text holds %placeholder:email% instead, and this turns it into {{ email }} where the
 * server translates it: the forms of the bundle (see PlaceholderTextFormMetadataVisitor) and the
 * help texts of integrations.
 */
final class PlaceholderText
{
    public static function expand(string $text): string
    {
        return (string) preg_replace("/%placeholder:([a-z_]+(?:\\('[\\p{L}_]+'\\))?)%/u", '{{ $1 }}', $text);
    }
}
