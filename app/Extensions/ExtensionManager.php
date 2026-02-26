<?php

declare(strict_types=1);

namespace App\Extensions;

use App\Extensions\Contracts\HawkiExtensionInterface;
use Illuminate\Support\Collection;

class ExtensionManager
{
    /** @var Collection<string, HawkiExtensionInterface> */
    private Collection $extensions;

    public function __construct()
    {
        $this->extensions = collect();
    }

    /**
     * Register a new extension.
     */
    public function register(HawkiExtensionInterface $extension): void
    {
        $this->extensions->put($extension->slug(), $extension);
        
        // Boot the extension (e.g. register routes)
        $extension->registerRoutes();
    }

    /**
     * Register an extension by class name.
     */
    public function registerClass(string $className): void
    {
        if (class_exists($className)) {
            $extension = new $className();
            if ($extension instanceof HawkiExtensionInterface) {
                $this->register($extension);
            }
        }
    }

    /**
     * Get the list of custom extensions from settings.
     */
    public function getCustomRegistry(): array
    {
        $settingsService = app(\App\Services\SettingsService::class);
        $registry = $settingsService->get('extensions_registry', []);

        if (is_string($registry)) {
            $registry = json_decode($registry, true) ?? [];
        }

        return is_array($registry) ? $registry : [];
    }

    /**
     * Add a package to the custom registry.
     */
    public function addToCustomRegistry(string $package, string $repository, string $class): void
    {
        $registry = $this->getCustomRegistry();
        $registry[$package] = [
            'package' => $package,
            'repository' => $repository,
            'class' => $class,
            'status' => 'pending',
            'last_error' => null,
            'enabled' => false,
        ];

        $settingsService = app(\App\Services\SettingsService::class);
        $settingsService->set('extensions_registry', $registry, 'json');
    }

    /**
     * Update the status of an extension in the registry.
     */
    public function updateRegistryStatus(string $package, string $status, ?string $error = null): void
    {
        $registry = $this->getCustomRegistry();
        if (isset($registry[$package])) {
            $registry[$package]['status'] = $status;
            $registry[$package]['last_error'] = $error;
            
            $settingsService = app(\App\Services\SettingsService::class);
            $settingsService->set('extensions_registry', $registry, 'json');
        }
    }

    /**
     * Remove a package from the custom registry.
     */
    public function removeFromCustomRegistry(string $package): void
    {
        $registry = $this->getCustomRegistry();
        unset($registry[$package]);

        $settingsService = app(\App\Services\SettingsService::class);
        $settingsService->set('extensions_registry', $registry, 'json');
    }

    /**
     * Boot all extensions in the registry.
     */
    public function boot(): void
    {
        $registry = $this->getCustomRegistry();
        
        foreach ($registry as $extension) {
            // Only register if enabled
            if (($extension['enabled'] ?? false) === true) {
                $this->registerClass($extension['class']);
            }
        }
    }

    /**
     * Get all registered extensions.
     *
     * @return Collection<string, HawkiExtensionInterface>
     */
    public function getExtensions(): Collection
    {
        return $this->extensions;
    }

    /**
     * Get all Orchid menu items from enabled extensions.
     */
    public function getOrchidMenuItems(): array
    {
        return $this->extensions
            ->flatMap(fn ($ext) => $ext->orchidMenuItems())
            ->toArray();
    }

    /**
     * Get all permissions from enabled extensions.
     */
    public function getPermissions(): array
    {
        return $this->extensions
            ->flatMap(fn ($ext) => $ext->permissions())
            ->toArray();
    }

    /**
     * Get all sidebar items for HAWKI UI from enabled extensions.
     */
    public function getSidebarItems(): array
    {
        return $this->extensions
            ->filter(fn ($ext) => $ext->sidebarIcon() !== null)
            ->map(fn ($ext) => [
                'slug' => $ext->slug(),
                'name' => $ext->name(),
                'icon' => $ext->sidebarIcon(),
                'permission' => $ext->permissions()[0]['slug'] ?? "platform.extension.{$ext->slug()}",
            ])
            ->values()
            ->toArray();
    }
}
