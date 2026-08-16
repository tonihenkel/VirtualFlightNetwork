<?php

/** Central language catalogue used by the website, ATC client and plugin API. */
function vfnLanguages(): array
{
    return [
        'ar' => ['name' => 'العربية', 'english_name' => 'Arabic', 'flag' => 'sa', 'dir' => 'rtl'],
        'bn' => ['name' => 'বাংলা', 'english_name' => 'Bengali', 'flag' => 'bd', 'dir' => 'ltr'],
        'zh' => ['name' => '中文（普通话）', 'english_name' => 'Chinese (Mandarin)', 'flag' => 'cn', 'dir' => 'ltr'],
        'nl' => ['name' => 'Nederlands', 'english_name' => 'Dutch', 'flag' => 'nl', 'dir' => 'ltr'],
        'en' => ['name' => 'English', 'english_name' => 'English', 'flag' => 'gb', 'dir' => 'ltr'],
        'fr' => ['name' => 'Français', 'english_name' => 'French', 'flag' => 'fr', 'dir' => 'ltr'],
        'de' => ['name' => 'Deutsch', 'english_name' => 'German', 'flag' => 'de', 'dir' => 'ltr'],
        'hi' => ['name' => 'हिन्दी', 'english_name' => 'Hindi', 'flag' => 'in', 'dir' => 'ltr'],
        'id' => ['name' => 'Bahasa Indonesia', 'english_name' => 'Indonesian', 'flag' => 'id', 'dir' => 'ltr'],
        'it' => ['name' => 'Italiano', 'english_name' => 'Italian', 'flag' => 'it', 'dir' => 'ltr'],
        'ja' => ['name' => '日本語', 'english_name' => 'Japanese', 'flag' => 'jp', 'dir' => 'ltr'],
        'ko' => ['name' => '한국어', 'english_name' => 'Korean', 'flag' => 'kr', 'dir' => 'ltr'],
        'pl' => ['name' => 'Polski', 'english_name' => 'Polish', 'flag' => 'pl', 'dir' => 'ltr'],
        'pt' => ['name' => 'Português', 'english_name' => 'Portuguese', 'flag' => 'pt', 'dir' => 'ltr'],
        'ru' => ['name' => 'Русский', 'english_name' => 'Russian', 'flag' => 'ru', 'dir' => 'ltr'],
        'es' => ['name' => 'Español', 'english_name' => 'Spanish', 'flag' => 'es', 'dir' => 'ltr'],
        'tr' => ['name' => 'Türkçe', 'english_name' => 'Turkish', 'flag' => 'tr', 'dir' => 'ltr'],
    ];
}

function vfnLanguageCodes(): array
{
    return array_keys(vfnLanguages());
}

function vfnLanguageMeta(string $code): array
{
    $languages = vfnLanguages();
    return $languages[strtolower($code)] ?? $languages['en'];
}
