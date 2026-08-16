<?php
declare(strict_types=1);

require_once __DIR__ . '/languages.php';

function vfnPluginContactMessage(string $language, string $action, string $frequency, string $atcCallsign): string
{
    $language = in_array(strtolower($language), vfnLanguageCodes(), true) ? strtolower($language) : 'en';
    $release = [
        'ar'=>'⚠ RELEASE: أنت تغادر مجالي الجوي. انتقل إلى UNICOM على 122.800 MHz.',
        'bn'=>'⚠ RELEASE: আপনি আমার আকাশসীমা ত্যাগ করছেন। 122.800 MHz-এ UNICOM-এ যান।',
        'zh'=>'⚠ RELEASE：你正在离开我的空域。请切换至 122.800 MHz 的 UNICOM。',
        'nl'=>'⚠ RELEASE: Je verlaat mijn luchtruim. Schakel over naar UNICOM op 122.800 MHz.',
        'en'=>'⚠ RELEASE: You are leaving my airspace. Switch to UNICOM on 122.800 MHz.',
        'fr'=>'⚠ RELEASE : Vous quittez mon espace aérien. Passez sur UNICOM 122.800 MHz.',
        'de'=>'⚠ RELEASE: Du verlässt meinen Luftraum. Wechsle auf UNICOM 122.800 MHz.',
        'hi'=>'⚠ RELEASE: आप मेरा हवाई क्षेत्र छोड़ रहे हैं। 122.800 MHz पर UNICOM पर जाएँ।',
        'id'=>'⚠ RELEASE: Anda meninggalkan wilayah udara saya. Pindah ke UNICOM 122.800 MHz.',
        'it'=>'⚠ RELEASE: Stai lasciando il mio spazio aereo. Passa su UNICOM 122.800 MHz.',
        'ja'=>'⚠ RELEASE：管制空域を離れます。UNICOM 122.800 MHz に切り替えてください。',
        'ko'=>'⚠ RELEASE: 관제 공역을 벗어납니다. UNICOM 122.800 MHz로 전환하십시오.',
        'pl'=>'⚠ RELEASE: Opuszczasz moją przestrzeń powietrzną. Przełącz na UNICOM 122.800 MHz.',
        'pt'=>'⚠ RELEASE: Está a sair do meu espaço aéreo. Mude para UNICOM em 122.800 MHz.',
        'ru'=>'⚠ RELEASE: Вы покидаете моё воздушное пространство. Перейдите на UNICOM 122.800 MHz.',
        'es'=>'⚠ RELEASE: Estás saliendo de mi espacio aéreo. Cambia a UNICOM en 122.800 MHz.',
        'tr'=>'⚠ RELEASE: Hava sahamdan ayrılıyorsunuz. 122.800 MHz UNICOM frekansına geçin.',
    ];
    if (in_array($action, ['release', 'leave-airspace'], true)) return $release[$language];
    $force = [
        'ar'=>'⚠ FORCE ACT: انتقل إلى %s MHz واتصل بـ %s.', 'bn'=>'⚠ FORCE ACT: %s MHz-এ যান এবং %s-এর সাথে যোগাযোগ করুন।',
        'zh'=>'⚠ FORCE ACT：请切换至 %s MHz 并联系 %s。', 'nl'=>'⚠ FORCE ACT: Schakel over naar %s MHz en neem contact op met %s.',
        'en'=>'⚠ FORCE ACT: Switch to %s MHz and contact %s.', 'fr'=>'⚠ FORCE ACT : Passez sur %s MHz et contactez %s.',
        'de'=>'⚠ FORCE ACT: Bitte wechsle auf %s MHz und melde dich bei %s.', 'hi'=>'⚠ FORCE ACT: %s MHz पर जाएँ और %s से संपर्क करें।',
        'id'=>'⚠ FORCE ACT: Pindah ke %s MHz dan hubungi %s.', 'it'=>'⚠ FORCE ACT: Passa su %s MHz e contatta %s.',
        'ja'=>'⚠ FORCE ACT：%s MHz に切り替えて %s にコンタクトしてください。', 'ko'=>'⚠ FORCE ACT: %s MHz로 전환하여 %s에 교신하십시오.',
        'pl'=>'⚠ FORCE ACT: Przełącz na %s MHz i skontaktuj się z %s.', 'pt'=>'⚠ FORCE ACT: Mude para %s MHz e contacte %s.',
        'ru'=>'⚠ FORCE ACT: Перейдите на %s MHz и свяжитесь с %s.', 'es'=>'⚠ FORCE ACT: Cambia a %s MHz y contacta con %s.',
        'tr'=>'⚠ FORCE ACT: %s MHz frekansına geçin ve %s ile iletişime geçin.',
    ];
    return sprintf($force[$language], $frequency, $atcCallsign);
}
