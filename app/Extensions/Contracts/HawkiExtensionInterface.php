<?php

declare(strict_types=1);

namespace App\Extensions\Contracts;

use Orchid\Screen\Actions\Menu;
use Orchid\Platform\ItemPermission;

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
}
