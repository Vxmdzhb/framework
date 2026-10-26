<?php

declare(strict_types = 1);

// Declares the 'Translator' section, which the Translator reads and the
// admin System-config page writes (AdminConfigSystemController::saveTranslator).
// Don't put other sections here: they would be merged over the real ones.
return [
    'Translator' => [
        // Extra lang-file directory to load on top of the built-in resources/lang
        // ('' = built-in catalogs only).
        'lang_dir' => '',

        // When a translation key is missing, fall back to rendering the key itself
        // (true) rather than an empty string — easier to spot untranslated strings.
        'fallback_to_key' => true,
    ],
];
