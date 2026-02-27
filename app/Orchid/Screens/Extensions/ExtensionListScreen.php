<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Extensions\ExtensionManager;
use App\Services\ExtensionInstallerService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Repository;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ExtensionListScreen extends Screen
{
    public function query(): iterable
    {
        $extensionManager = app(ExtensionManager::class);
        $discovered = $extensionManager->getExtensions();

        $registry = $extensionManager->getCustomRegistry();
        $tableData = [];

        foreach ($registry as $pkg => $data) {
            // Find extension in discovered list by class name
            $loadedExt = $discovered->first(fn ($e) => get_class($e) === $data['class']);

            // Status determination
            $status = $data['status'] ?? 'pending';
            $installed = class_exists($data['class']);

            if ($installed && $status === 'pending') {
                $status = 'installed';
            }

            $tableData[] = new Repository([
                'package' => $pkg,
                'repository' => $data['repository'],
                'class' => $data['class'],
                'status' => $status,
                'installed' => $installed,
                'last_error' => $data['last_error'] ?? null,
                'name' => $loadedExt ? $loadedExt->name() : $pkg,
                'slug' => $loadedExt ? $loadedExt->slug() : null,
                'enabled' => $data['enabled'] ?? false,
                'icon' => $loadedExt ? $loadedExt->sidebarIcon() : 'bs.puzzle',
            ]);
        }

        return [
            'extensions' => $tableData,
        ];
    }

    public function name(): ?string
    {
        return 'Extensions';
    }

    public function description(): ?string
    {
        return 'Install and manage HAWKI extensions from external repositories.';
    }

    public function commandBar(): iterable
    {
        return [
            ModalToggle::make('Register New Extension')
                ->modal('registerExtensionModal')
                ->method('registerExtension')
                ->icon('bs.plus-circle'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::table('extensions', [
                TD::make('icon', '')
                    ->width('48px')
                    ->render(function (Repository $ext) {
                        $icon = $ext->get('icon', 'bs.puzzle');
                        try {
                            return svg($icon, 'icon', ['style' => 'width:1.25rem;height:1.25rem;'])->toHtml();
                        } catch (\Throwable) {
                            return '';
                        }
                    }),
                TD::make('package', 'Package')
                    ->render(fn (Repository $ext) => Link::make($ext->get('package'))
                        ->route('platform.extension.settings', ['package' => str_replace('/', '--', $ext->get('package'))])
                    ),
                TD::make('name', 'Display Name'),
                TD::make('status', 'Status')
                    ->render(function (Repository $ext) {
                        $status = $ext->get('status');
                        $enabled = $ext->get('enabled');
                        $installed = $ext->get('installed');

                        if ($installed) {
                            $label = $enabled ? 'Active' : 'Inactive';
                            $color = $enabled ? 'text-success' : 'text-muted';
                        } else {
                            $label = match ($status) {
                                'failed' => 'Failed',
                                'pending' => 'Pending',
                                default => ucfirst($status),
                            };
                            $color = match ($status) {
                                'failed' => 'text-danger',
                                'pending' => 'text-warning',
                                default => 'text-muted',
                            };
                        }

                        $html = "<span class='{$color}'>{$label}</span>";

                        if ($status === 'failed' && $ext->get('last_error')) {
                            $error = e($ext->get('last_error'));
                            $html .= " <i class='bs-info-circle text-danger ms-1' title='{$error}'></i>";
                        }

                        return $html;
                    }),
                TD::make('actions', 'Actions')
                    ->align(TD::ALIGN_RIGHT)
                    ->render(function (Repository $ext) {
                        $package = $ext->get('package');
                        $installed = $ext->get('installed');
                        $enabled = $ext->get('enabled');

                        $items = [];

                        if (! $installed) {
                            $items[] = Button::make('Install')
                                ->icon('bs.download')
                                ->method('installExtension', ['package' => $package]);
                        }

                        if ($installed) {
                            $items[] = $enabled
                                ? Button::make('Deactivate')
                                    ->icon('bs.pause-circle')
                                    ->method('toggleExtension', ['package' => $package])
                                : Button::make('Activate')
                                    ->icon('bs.play-circle')
                                    ->method('toggleExtension', ['package' => $package]);
                        }

                        $items[] = Button::make('Unregister')
                            ->icon('bs.trash')
                            ->method('unregisterExtension', ['package' => $package])
                            ->confirm('This will remove the extension from the HAWKI registry. The code remains in vendor until manually removed.');

                        return DropDown::make()
                            ->icon('bs.three-dots-vertical')
                            ->list($items);
                    }),
            ]),

            Layout::modal('registerExtensionModal', [
                Layout::rows([
                    Input::make('package')
                        ->title('Package Name')
                        ->placeholder('hawki/demo-extension')
                        ->required(),
                    Input::make('repository')
                        ->title('Repository URL (Partial or Full)')
                        ->placeholder('../HAWKI-extension-demo')
                        ->required(),
                    Input::make('class')
                        ->title('Extension Class')
                        ->placeholder('Hawki\DemoExtension\DemoExtension')
                        ->required(),
                ]),
            ])->title('Register Extension'),
        ];
    }

    public function registerExtension(Request $request)
    {
        $request->validate([
            'package' => 'required',
            'repository' => 'required',
            'class' => 'required',
        ]);

        app(ExtensionManager::class)->addToCustomRegistry(
            $request->get('package'),
            $request->get('repository'),
            $request->get('class')
        );

        Toast::info('Extension registered. You can now install it.');
    }

    public function installExtension(string $package)
    {
        $extensionManager = app(ExtensionManager::class);
        $registry = $extensionManager->getCustomRegistry();
        $data = $registry[$package] ?? null;

        if (! $data) {
            Toast::error('Extension not found in registry.');

            return;
        }

        $extensionManager->updateRegistryStatus($package, 'pending');

        $installer = app(ExtensionInstallerService::class);

        // 1. Update composer.json
        if (! $installer->addPackage($package, $data['repository'])) {
            $extensionManager->updateRegistryStatus($package, 'failed', 'Failed to update composer.json or invalid path.');
            Toast::error('Failed to update composer.json');

            return;
        }

        Toast::info('Composer sequence started. This may take a moment...');

        // 2. Run composer update
        $result = $installer->runUpdate($package);

        if ($result['success']) {
            $extensionManager->updateRegistryStatus($package, 'installed');
            Toast::success('Extension installed successfully! Please restart containers if needed.');
        } else {
            Log::error('Extension Installation Failed', $result);
            $extensionManager->updateRegistryStatus($package, 'failed', $result['error']);
            Toast::error('Installation failed. Check logs or the info icon in the table.');
        }
    }

    public function toggleExtension(string $package)
    {
        $extensionManager = app(ExtensionManager::class);
        $registry = $extensionManager->getCustomRegistry();

        if (isset($registry[$package])) {
            $enabled = ! ($registry[$package]['enabled'] ?? false);
            $registry[$package]['enabled'] = $enabled;

            app(SettingsService::class)->set('extensions_registry', $registry, 'json');

            Toast::success($enabled ? 'Extension enabled.' : 'Extension disabled.');
        }
    }

    public function unregisterExtension(string $package)
    {
        $settingsService = app(SettingsService::class);
        $registry = app(ExtensionManager::class)->getCustomRegistry();

        unset($registry[$package]);
        $settingsService->set('extensions_registry', $registry, 'array');

        Toast::info('Extension removed from HAWKI registry.');
    }

    public function saveExtensionSettings(Request $request)
    {
        $settings = $request->get('settings', []);
        $settingsService = app(SettingsService::class);

        foreach ($settings as $key => $value) {
            $settingsService->set($key, (bool) $value, 'boolean');
        }

        Toast::success('Settings saved.');
    }
}
