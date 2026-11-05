<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Application;

use Eekes\Sulu\FormBuilderBundle\EekesSuluFormBuilderBundle;
use Sulu\Bundle\TestBundle\Kernel\SuluTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\UX\TwigComponent\TwigComponentBundle;

/**
 * The bundle tests itself against this minimal application, never against the surrounding Sulu
 * skeleton: a suite that needs one cannot be run in CI or by an outside contributor.
 */
class Kernel extends SuluTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new TwigComponentBundle();
        yield new EekesSuluFormBuilderBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__.'/config/config.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/var/cache/'.$this->getContext().'/'.$this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return __DIR__.'/var/log';
    }
}
