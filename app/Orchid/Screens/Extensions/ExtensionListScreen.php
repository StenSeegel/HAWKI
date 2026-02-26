<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Extensions\ExtensionManager;
use App\Services\SettingsService;
use App\Services\ExtensionInstallerService;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Switcher;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Screen\Repository;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

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
            $loadedExt = $discovered->first(fn($e) => get_class($e) === $data['class']);
            
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
                TD::make('package', 'Package'),
                TD::make('name', 'Display Name'),
                TD::make('status', 'Status')
                    ->render(function (Repository $ext) {
                        $status = $ext->get('status');
                        $color = match($status) {
                            'installed' => 'text-success',
                            'failed' => 'text-danger',
                            'pending' => 'text-warning',
                            default => 'text-muted',
                        };
                        
                        $html = "<span class='{$color}'>" . ucfirst($status) . "</span>";
                        
                        if ($status === 'failed' && $ext->get('last_error')) {
                            $html .= " <i class='bs.info-circle' title='{$ext->get('last_error')}'></i>";
                        }
                        
                        return $html;
                    }),
                TD::make('enabled', 'Active')
                    ->render(function (Repository $ext) {
                        return $ext->get('installed') 
                            ? Switcher::make("settings.extension_enabled.{$ext->get('package')}")
                                ->value($ext->get('enabled'))
                                ->placeholder('Enable')
                                ->sendTrueOrFalse()
                                ->method('toggleExtension', ['package' => $ext->get('package')])
                            : '-';
                    }),
                TD::make('actions', 'Actions')
                    ->render(fn (Repository $ext) => 
                        !$ext->get('installed') 
                        ? Button::make('Install')
                            ->method('installExtension', ['package' => $ext->get('package')])
                            ->type(\Orchid\Support\Color::BASIC)
                            ->icon('bs.download')
                        : Button::make('Unregister')
                            ->method('unregisterExtension', ['package' => $ext->get('package')])
                            ->type(\Orchid\Support\Color::DANGER)
                            ->icon('bs.trash')
                            ->confirm('This will remove it from HAWKI registry. Code remains in vendor until manually removed.')
                    ),
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

        if (!$data) {
            Toast::error('Extension not found in registry.');
            return;
        }

        $extensionManager->updateRegistryStatus($package, 'pending');
        
        $installer = app(ExtensionInstallerService::class);
        
        // 1. Update composer.json
        if (!$installer->addPackage($package, $data['repository'])) {
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

    public function toggleExtension(string $package, Request $request)
    {
        $enabled = (bool) $request->input("settings.extension_enabled.{$package}");
        
        $extensionManager = app(ExtensionManager::class);
        $registry = $extensionManager->getCustomRegistry();
        
        if (isset($registry[$package])) {
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
