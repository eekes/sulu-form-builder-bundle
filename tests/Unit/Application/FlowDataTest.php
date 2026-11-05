<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowData::class)]
final class FlowDataTest extends TestCase
{
    public function testTheAnswersOfAllStepsAreFlattenedWithoutTheBookkeeping(): void
    {
        $data = [
            FlowData::STEP_KEY => 'two',
            FlowData::FILES_KEY => ['one' => ['cv' => []]],
            'one' => ['name' => 'Jane', 'email' => 'jane@example.com'],
            'two' => ['message' => 'Hi'],
            'broken' => 'not an array',
        ];

        self::assertSame(['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'Hi'], FlowData::values($data));
        self::assertSame(['name' => 'Jane', 'email' => 'jane@example.com'], FlowData::values($data, 'two'));
    }
}
