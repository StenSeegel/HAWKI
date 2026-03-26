---
trigger: always_on
---

## Architecture & Boundaries (Core vs. Extension)
1. **Avoid Core Modifications**: Features of the Translation Extension must not enforce changes in `app/Services/AI/Providers/` or other Core namespaces. Logic for debugging, logging, or special payload processing must remain within `app/Services/Translation/` or in the `TranslationApiController`.
2. **Settings Hierarchy**: Global settings reside in `app_settings`. Specific settings for the translation function (e.g., DeepL API keys, debug mode) must be managed in `translate_settings` via the `TranslateSetting` model.