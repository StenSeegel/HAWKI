<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Extensions\ExtensionManager;
use App\Services\ExtensionSettingService;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ExtensionSettingsScreen extends Screen
{
    public string $package = '';

    /**
     * Composer package names contain '/' which breaks Orchid's URL-based method dispatch.
     * We encode '/' as '--' in the URL and decode it here.
     */
    private function decodePackage(string $encoded): string
    {
        return str_replace('--', '/', $encoded);
    }

    private function resolveExtension(string $package): ?\App\Extensions\Contracts\HawkiExtensionInterface
    {
        $extensionManager = app(ExtensionManager::class);
        $registry = $extensionManager->getCustomRegistry();
        $data = $registry[$package] ?? null;

        if (! $data || ! class_exists($data['class'])) {
            return null;
        }

        $loaded = $extensionManager->getExtensions()
            ->first(fn ($e) => get_class($e) === $data['class']);

        if ($loaded) {
            return $loaded;
        }

        $instance = new $data['class'];

        return $instance instanceof \App\Extensions\Contracts\HawkiExtensionInterface
            ? $instance
            : null;
    }

    public function query(string $package): iterable
    {
        $this->package = $this->decodePackage($package);
        $extension = $this->resolveExtension($this->package);
        abort_if(! $extension, 404, "Extension not found: {$this->package}");

        return [];
    }

    public function name(): ?string
    {
        $extension = $this->resolveExtension($this->package);

        return $extension ? $extension->name().' — Settings' : 'Extension Settings';
    }

    public function description(): ?string
    {
        return 'Configure extension settings. Changes are saved immediately.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('← Back')
                ->route('platform.extensions'),
            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save')
                ->parameters(['package' => $this->package]),
        ];
    }

    public function layout(): iterable
    {
        $extension = $this->resolveExtension($this->package);

        if (! $extension) {
            return [];
        }

        $fields = $extension->settings();

        if (empty($fields)) {
            return [
                Layout::rows([
                    Label::make('no_settings')
                        ->title('No settings available')
                        ->value('This extension does not provide any configurable settings.'),
                ]),
            ];
        }

        return [
            Layout::rows($fields),
        ];
    }

    public function save(Request $request): void
    {
        $package = $request->input('package', $this->package);
        $extension = $this->resolveExtension($package);
        abort_if(! $extension, 404);

        $service = app(ExtensionSettingService::class);

        // Collect all field keys from the extension's settings definition
        $fieldKeys = array_map(
            fn ($field) => $field->get('name'),
            $extension->settings()
        );

        // Internal fields that should not be saved
        $skip = ['_token', '_method', 'package'];

        foreach ($fieldKeys as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            if ($request->has($key)) {
                $service->set($package, $key, $request->input($key));
            } else {
                // Unchecked checkboxes / switchera are not submitted — treat as false
                $service->set($package, $key, false);
            }
        }

        Toast::success('Settings saved.');
    }
}
