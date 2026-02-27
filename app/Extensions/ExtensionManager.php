<?php

declare(strict_types=1);

namespace App\Extensions;

use App\Extensions\Contracts\HawkiExtensionInterface;
use Illuminate\Support\Collection;

class ExtensionManager
{
    private Collection $extensions;

    private bool $booted = false;

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
            $extension = new $className;
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
        if ($this->booted) {
            return;
        }

        $registry = $this->getCustomRegistry();

        foreach ($registry as $pkg => $data) {
            // Only register if enabled
            if (($data['enabled'] ?? false) === true) {
                $this->registerClass($data['class']);
            }
        }

        $this->booted = true;
    }

    /**
     * Get all registered extensions.
     *
     * @return Collection<string, HawkiExtensionInterface>
     */
    public function getExtensions(): Collection
    {
        $this->boot();

        return $this->extensions;
    }

    /**
     * Get all Orchid menu items from enabled extensions.
     */
    public function getOrchidMenuItems(): array
    {
        return $this->getExtensions()
            ->flatMap(fn ($ext) => $ext->orchidMenuItems())
            ->toArray();
    }

    /**
     * Get all permissions from enabled extensions.
     */
    public function getPermissions(): array
    {
        return $this->getExtensions()
            ->flatMap(fn ($ext) => $ext->permissions())
            ->toArray();
    }

    /**
     * Get all sidebar items for HAWKI UI from enabled extensions.
     */
    public function getSidebarItems(): array
    {
        return $this->getExtensions()
            ->filter(fn ($ext) => $ext->sidebarIcon() !== null)
            ->map(function ($ext) {
                $permissions = $ext->permissions();
                $firstGroup = $permissions[0] ?? null;
                $slug = "extension.{$ext->slug()}.access";

                if ($firstGroup instanceof \Orchid\Platform\ItemPermission) {
                    $slug = $firstGroup->items[0]['slug'] ?? $slug;
                } elseif (is_array($firstGroup)) {
                    $slug = $firstGroup['slug'] ?? $slug;
                }

                return [
                    'slug' => $ext->slug(),
                    'name' => $ext->name(),
                    'icon' => $ext->sidebarIcon(),
                    'permission' => $slug,
                ];
            })
            ->values()
            ->toArray();
    }
}
