<?php

declare(strict_types=1);

namespace App\Orchid;

use App\Models\OrchidAttachment;
use Orchid\Attachment\Models\Attachment;
use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;
use Orchid\Support\Color;

class PlatformProvider extends OrchidServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(Dashboard $dashboard): void
    {
        parent::boot($dashboard);

        // Override Orchid's default Attachment model to use our custom table
        Dashboard::useModel(Attachment::class, OrchidAttachment::class);

        // ...
    }

    /**
     * Register the application menu.
     *
     * @return Menu[]
     */
    public function menu(): array
    {
        return [
            Menu::make('Get Started')
                ->icon('bs.book')
                ->title('Overview')
                ->permission('platform.systems.*')
                ->route(config('platform.index')),

            Menu::make('Dashboard')
                ->icon('bs.rocket-takeoff')
                ->permission('platform.dashboard')
                ->list([
                    Menu::make('Global')
                        ->route('platform.dashboard.global')
                        ->icon('bs.globe2'),
                    Menu::make('Users')
                        ->route('platform.dashboard.users')
                        ->icon('bs.people'),
                    Menu::make('Requests')
                        ->route('platform.dashboard.requests')
                        ->icon('bs.bar-chart'),
                    Menu::make('Usage Debug')
                        ->route('platform.dashboard.usage.debug')
                        ->permission('platform.systems.usage.debug')
                        ->icon('bs.table'),
                ]),

            Menu::make('')
                ->title(__('Content'))
                ->permission('platform.systems.settings'),

            Menu::make('Announcements')
                ->icon('bs.megaphone')
                ->route('platform.announcements')
                ->permission('platform.systems.settings'),

            Menu::make('')
                ->title(__('Configuration'))
                ->permission('platform.systems.*'),

            Menu::make('System')
                ->icon('bs.house-gear')
                ->permission('platform.systems.settings')
                ->badge(fn () => $this->getHawkiCommitId(), Color::DARK)
                ->active(['platform.settings.*', 'platform.testing.*', 'platform.customization.*'])
                ->list([
                    Menu::make('Settings')
                        ->route('platform.settings.system')
                        ->icon('bs.gear')
                        ->active(['platform.settings.system', 'platform.settings.authentication', 'platform.settings.api', 'platform.settings.mail-configuration', 'platform.systems.settings.backup']),
                    Menu::make('Customization')
                        ->route('platform.customization.localizedtexts')
                        ->icon('bs.paint-bucket')
                        ->active(['platform.customization.*']),
                    Menu::make('Log Management')
                        ->route('platform.settings.log.system')
                        ->icon('bs.journal-code')
                        ->active(['platform.settings.log.system', 'platform.settings.log.configuration']),
                    Menu::make('Testing')
                        ->route('platform.testing.settings')
                        ->icon('bs.tools')
                        ->active(['platform.testing.settings', 'platform.testing.mail']),
                ]),

            Menu::make('AI')
                ->icon('bs.cpu')
                ->permission('platform.modelsettings.models')
                ->active('platform.models.*')
                ->list([
                    // Menu::make('Sync Dashboard')
                    //    ->route('platform.models.sync.dashboard')
                    //    ->permission('platform.modelsettings.providers')
                    //    ->icon('bs.arrow-clockwise')
                    //    ->badge(function () {
                    //        $activeProviders = \App\Models\ProviderSetting::where('is_active', true)->count();
                    //
                    //        return $activeProviders > 0 ? $activeProviders : null;
                    //    })
                    //    ->active('platform.models.sync.*'),
                    Menu::make('API Management')
                        ->route('platform.models.api.providers')
                        ->permission('platform.modelsettings.providers')
                        ->icon('bs.key')
                        ->active(['platform.models.api.providers', 'platform.models.api.formats']),
                    Menu::make('Language Models')
                        ->route('platform.models.language')
                        ->permission('platform.modelsettings.models')
                        ->icon('bs.chat-left'),
                    Menu::make('Assistants')
                        ->route('platform.models.assistants')
                        ->permission('platform.modelsettings.assistants')
                        ->icon('bs.stars')
                        ->active(['platform.models.assistants', 'platform.models.prompts', 'platform.models.tools']),
                ]),

            Menu::make('')
                ->title(__('Extensions'))
                ->permission('platform.extensions'),

            Menu::make(__('Extensions'))
                ->icon('bs.puzzle')
                ->route('platform.extensions')
                ->permission('platform.extensions'),

            Menu::make('')
                ->title(__('Access Controls'))
                ->permission('platform.access.*'),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.access.users'),

            Menu::make(__('Roles'))
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission('platform.access.roles')
                ->active(['platform.systems.roles', 'platform.role-assignments']),

            Menu::make('')
                ->divider(),

        ];
    }

    /**
     * Short commit id of the running code, shown as badge on the System menu entry.
     *
     * Prebuilt images carry build_info.json, written by the Dockerfile from the
     * GIT_COMMIT build arg CI passes. A live-mounted dev checkout has no such
     * file, so fall back to resolving HEAD from the .git directory.
     */
    private function getHawkiCommitId(): string
    {
        try {
            $buildInfoPath = base_path('build_info.json');

            if (file_exists($buildInfoPath)) {
                $commit = json_decode(file_get_contents($buildInfoPath), true)['commit'] ?? '';
                if ($commit !== '' && $commit !== 'unknown') {
                    return substr($commit, 0, 8);
                }
            }

            $commit = $this->resolveGitHead(base_path('.git'));

            return $commit !== null ? substr($commit, 0, 8) : 'N/A';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Resolve HEAD to a commit hash without the git binary: follows a symbolic
     * ref into refs/heads/* or packed-refs, accepts a detached HEAD as is.
     */
    private function resolveGitHead(string $gitDir): ?string
    {
        if (! is_file("$gitDir/HEAD")) {
            return null;
        }

        $head = trim((string) file_get_contents("$gitDir/HEAD"));

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
        }

        $ref = substr($head, 5);

        if (is_file("$gitDir/$ref")) {
            return trim((string) file_get_contents("$gitDir/$ref")) ?: null;
        }

        if (is_file("$gitDir/packed-refs")) {
            foreach (file("$gitDir/packed-refs", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_ends_with($line, " $ref")) {
                    return substr($line, 0, 40);
                }
            }
        }

        return null;
    }

    /**
     * Register permissions for the application.
     *
     * @return ItemPermission[]
     */
    public function permissions(): array
    {
        return [
            ItemPermission::group(__('Main')),

            ItemPermission::group(__('Dashboard & Reporting'))
                ->addPermission('platform.dashboard', __('Dashboard Access')),

            ItemPermission::group(__('System Settings'))
                ->addPermission('platform.systems.settings', __('System Settings'))
                ->addPermission('platform.systems.log', __('Log Management'))
                ->addPermission('platform.systems.usage.debug', __('Usage Debug')),

            ItemPermission::group(__('Model Configuration'))
                ->addPermission('platform.modelsettings.settings', __('Model Settings Management'))
                ->addPermission('platform.modelsettings.providers', __('API Providers'))
                ->addPermission('platform.modelsettings.models', __('Language Models'))
                ->addPermission('platform.modelsettings.assistants', __('Assistants')),

            ItemPermission::group(__('Extensions'))
                ->addPermission('platform.extensions', __('Extensions Management')),

            ItemPermission::group(__('Access Controls'))
                ->addPermission('platform.access.users', __('User Management'))
                ->addPermission('platform.access.roles', __('Role Management'))
                ->addPermission('platform.access.role-assignments', __('Role Assignments')),

            ItemPermission::group('HAWKI Features ')
                ->addPermission('chat.access', 'AI Chat Access')
                ->addPermission('groupchat.access', 'Group Chat Access')
                ->addPermission('text.access', 'AI Translation Access')
                ->addPermission('transcription.access', 'Transcription Access')
                ->addPermission('image_generation.access', 'Image Generation Access'),
        ];
    }
}
