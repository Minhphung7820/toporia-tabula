<?php

declare(strict_types=1);

namespace Toporia\Tabula;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Tabula\Exports\Exporter;
use Toporia\Tabula\Imports\Importer;

/**
 * Class TabulaServiceProvider
 *
 * Service provider for the Tabula package.
 * Registers importers, exporters, and configuration.
 */
final class TabulaServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     */
    protected bool $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            Importer::class,
            Exporter::class,
            Tabula::class,
            'tabula',
            'tabula.importer',
            'tabula.exporter',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register Importer as singleton
        $container->singleton(Importer::class, function () {
            return new Importer();
        });

        // Register Exporter as singleton
        $container->singleton(Exporter::class, function () {
            return new Exporter();
        });

        // Register Tabula facade
        $container->singleton(Tabula::class, function () {
            return new Tabula();
        });

        // Bind aliases
        $container->bind('tabula', fn($c) => $c->get(Tabula::class));
        $container->bind('tabula.importer', fn($c) => $c->get(Importer::class));
        $container->bind('tabula.exporter', fn($c) => $c->get(Exporter::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/tabula.php' => 'config/tabula.php',
        ], 'tabula-config');
    }
}
