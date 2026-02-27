<?php

declare(strict_types=1);

namespace App\Extensions\Contracts;

use Orchid\Platform\ItemPermission;
use Orchid\Screen\Actions\Menu;
use Orchid\Screen\Field;

interface HawkiExtensionInterface
{
    /**
     * Unique identifier for the extension.
     */
    public function slug(): string;

    /**
     * Display name of the extension.
     */
    public function name(): string;

    /**
     * Description of the extension.
     */
    public function description(): string;

    /**
     * Optional icon name for the HAWKI sidebar.
     * Returns null if no sidebar integration is desired.
     */
    public function sidebarIcon(): ?string;

    /**
     * Orchid menu items to be added under the "Extensions" category.
     *
     * @return Menu[]
     */
    public function orchidMenuItems(): array;

    /**
     * Permissions required for the extension.
     *
     * @return ItemPermission[]
     */
    public function permissions(): array;

    /**
     * Optional routes to be registered.
     */
    public function registerRoutes(): void;

    /**
     * The Composer package name for this extension (e.g. 'hawki/demo-extension').
     * Used as the identifier when persisting settings to the extension_settings table.
     */
    public function package(): string;

    /**
     * Settings fields shown on the extension's settings page.
     * Return an array of Orchid Field objects with simple key names (e.g. 'api_key').
     * Values are stored in the extension_settings table under (package, key).
     *
     * @return Field[]
     */
    public function settings(): array;
}
