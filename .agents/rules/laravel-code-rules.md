---
trigger: always_on
---

1. The Project uses Laravel.
2. The Admin Dashboard uses laravel/orchid.
3. We manage all configuration via DB Service "AppSettings".
4. Only write .env keys for quick testing.
5. Use app_settings table for global settings, this gets populated via settings.php, you don't need a migration to add new settings
6. Hawki is available in local development via ``https://app.hawki.dev``