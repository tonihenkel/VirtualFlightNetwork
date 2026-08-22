<?php
function vfnCompendiumTranslations(string $language): array
{
    $en=[
        'nav_compendium'=>'Compendium','compendium_title'=>'VFN Compendium','compendium_intro'=>'Knowledge base for pilots, controllers, radio procedures, divisions and airports.','compendium_search_placeholder'=>'Search articles, terms or airports …','compendium_all_categories'=>'All categories','compendium_all_divisions'=>'All divisions','compendium_categories'=>'Categories','compendium_all_articles'=>'All articles','compendium_manage'=>'Manage compendium','compendium_not_found'=>'The requested article was not found.','compendium_no_results'=>'No matching articles found.','compendium_admin_title'=>'Compendium administration','compendium_admin_intro'=>'Create global or division-specific knowledge articles, redirects and revisions. HTML and scoped CSS are supported; JavaScript is blocked.','compendium_new_article'=>'New article','compendium_open'=>'Open compendium','compendium_new_section'=>'New section','compendium_invalid'=>'Please check the required fields and your permissions.','compendium_slug_exists'=>'This article address is already in use.','compendium_alias_exists'=>'An alias is already assigned to another article.','compendium_saved'=>'Article saved.','compendium_deleted'=>'Article deleted.','compendium_restored'=>'Revision restored.','compendium_field_title'=>'Title','compendium_field_slug'=>'Address / slug','compendium_field_summary'=>'Summary','compendium_field_content'=>'HTML content','compendium_field_category'=>'Category','compendium_field_language'=>'Language','compendium_field_scope'=>'Scope','compendium_field_division'=>'Division','compendium_field_airport'=>'Airport / location code','compendium_field_status'=>'Status','compendium_field_sort'=>'Sort order','compendium_field_aliases'=>'Redirects / aliases','compendium_aliases_hint'=>'One term per line, e.g. TA, Transition Altitude','compendium_scope_global'=>'Global','compendium_scope_division'=>'Division','compendium_status_draft'=>'Draft','compendium_status_published'=>'Published','compendium_status_archived'=>'Archived','compendium_preview'=>'Preview','compendium_revisions'=>'Revision history','compendium_restore'=>'Restore','compendium_delete_confirm'=>'Really delete this article and its revision history?','compendium_category_general'=>'General','compendium_category_radio'=>'Radio communication','compendium_category_plugin'=>'Pilot plugin','compendium_category_atc'=>'ATC client','compendium_category_training'=>'Training','compendium_category_exam'=>'Exam preparation','compendium_category_airport'=>'Airports','compendium_category_rules'=>'Rules','compendium_category_procedures'=>'Procedures'
    ];
    $de=[
        'nav_compendium'=>'Kompendium','compendium_title'=>'VFN-Kompendium','compendium_intro'=>'Wissensbasis für Piloten, Lotsen, Sprechfunk, Divisionen und Flughäfen.','compendium_search_placeholder'=>'Artikel, Begriffe oder Flughäfen suchen …','compendium_all_categories'=>'Alle Kategorien','compendium_all_divisions'=>'Alle Divisionen','compendium_categories'=>'Kategorien','compendium_all_articles'=>'Alle Artikel','compendium_manage'=>'Kompendium verwalten','compendium_not_found'=>'Der angeforderte Artikel wurde nicht gefunden.','compendium_no_results'=>'Keine passenden Artikel gefunden.','compendium_admin_title'=>'Kompendium-Verwaltung','compendium_admin_intro'=>'Globale oder divisionsbezogene Wissensartikel, Weiterleitungen und Revisionen verwalten. HTML und begrenztes CSS sind erlaubt; JavaScript wird blockiert.','compendium_new_article'=>'Neuer Artikel','compendium_open'=>'Kompendium öffnen','compendium_new_section'=>'Neuer Abschnitt','compendium_invalid'=>'Bitte Pflichtfelder und Berechtigungen prüfen.','compendium_slug_exists'=>'Diese Artikeladresse wird bereits verwendet.','compendium_alias_exists'=>'Eine Weiterleitung ist bereits einem anderen Artikel zugeordnet.','compendium_saved'=>'Artikel gespeichert.','compendium_deleted'=>'Artikel gelöscht.','compendium_restored'=>'Version wiederhergestellt.','compendium_field_title'=>'Titel','compendium_field_slug'=>'Adresse / Slug','compendium_field_summary'=>'Zusammenfassung','compendium_field_content'=>'HTML-Inhalt','compendium_field_category'=>'Kategorie','compendium_field_language'=>'Sprache','compendium_field_scope'=>'Gültigkeitsbereich','compendium_field_division'=>'Division','compendium_field_airport'=>'Flughafen- / Ortskennung','compendium_field_status'=>'Status','compendium_field_sort'=>'Sortierung','compendium_field_aliases'=>'Weiterleitungen / Aliasse','compendium_aliases_hint'=>'Ein Begriff pro Zeile, z. B. TA, Transition Altitude','compendium_scope_global'=>'Global','compendium_scope_division'=>'Division','compendium_status_draft'=>'Entwurf','compendium_status_published'=>'Veröffentlicht','compendium_status_archived'=>'Archiviert','compendium_preview'=>'Vorschau','compendium_revisions'=>'Versionsverlauf','compendium_restore'=>'Wiederherstellen','compendium_delete_confirm'=>'Artikel und Versionsverlauf wirklich löschen?','compendium_category_general'=>'Allgemein','compendium_category_radio'=>'Sprechfunk','compendium_category_plugin'=>'Piloten-Plugin','compendium_category_atc'=>'ATC-Client','compendium_category_training'=>'Ausbildung','compendium_category_exam'=>'Prüfungsvorbereitung','compendium_category_airport'=>'Flughäfen','compendium_category_rules'=>'Regelwerk','compendium_category_procedures'=>'Verfahren'
    ];
    $public=[
      'ar'=>['دليل VFN','قاعدة معرفة للطيارين والمراقبين والإجراءات والأقسام والمطارات.','ابحث في المقالات أو المصطلحات أو المطارات …'],
      'bn'=>['VFN সংকলন','পাইলট, কন্ট্রোলার, পদ্ধতি, বিভাগ ও বিমানবন্দরের জ্ঞানভান্ডার।','নিবন্ধ, শব্দ বা বিমানবন্দর খুঁজুন …'],
      'es'=>['Compendio VFN','Base de conocimientos para pilotos, controladores, procedimientos, divisiones y aeropuertos.','Buscar artículos, términos o aeropuertos …'],
      'fr'=>['Compendium VFN','Base de connaissances pour pilotes, contrôleurs, procédures, divisions et aéroports.','Rechercher des articles, termes ou aéroports …'],
      'hi'=>['VFN संकलन','पायलट, नियंत्रक, प्रक्रियाओं, डिवीजनों और हवाई अड्डों का ज्ञानकोष।','लेख, शब्द या हवाई अड्डे खोजें …'],
      'id'=>['Kompendium VFN','Basis pengetahuan untuk pilot, pengatur lalu lintas, prosedur, divisi, dan bandara.','Cari artikel, istilah, atau bandara …'],
      'it'=>['Compendio VFN','Base di conoscenza per piloti, controllori, procedure, divisioni e aeroporti.','Cerca articoli, termini o aeroporti …'],
      'ja'=>['VFN コンペンディウム','パイロット、管制官、手順、ディビジョン、空港の知識ベースです。','記事、用語、空港を検索 …'],
      'ko'=>['VFN 편람','조종사, 관제사, 절차, 디비전 및 공항을 위한 지식 자료입니다.','문서, 용어 또는 공항 검색 …'],
      'nl'=>['VFN-compendium','Kennisbank voor piloten, verkeersleiders, procedures, divisies en luchthavens.','Zoek artikelen, begrippen of luchthavens …'],
      'pl'=>['Kompendium VFN','Baza wiedzy dla pilotów, kontrolerów, procedur, dywizji i lotnisk.','Szukaj artykułów, pojęć lub lotnisk …'],
      'pt'=>['Compêndio VFN','Base de conhecimento para pilotos, controladores, procedimentos, divisões e aeroportos.','Pesquisar artigos, termos ou aeroportos …'],
      'ru'=>['Справочник VFN','База знаний для пилотов, диспетчеров, процедур, дивизионов и аэропортов.','Поиск статей, терминов или аэропортов …'],
      'tr'=>['VFN Kılavuzu','Pilotlar, kontrolörler, prosedürler, bölümler ve havalimanları için bilgi tabanı.','Makale, terim veya havalimanı ara …'],
      'zh'=>['VFN 百科','面向飞行员、管制员、程序、分部和机场的知识库。','搜索文章、术语或机场 …']
    ];
    $accessTranslations = [
      'en'=>['Compendium for users without OP','Access denied','The compendium is currently disabled for guests and users without OP permissions.'],
      'de'=>['Kompendium für Benutzer ohne OP','Zugriff verweigert','Das Kompendium ist derzeit für Gäste und Benutzer ohne OP-Berechtigung deaktiviert.'],
      'ar'=>['الدليل للمستخدمين دون صلاحية OP','تم رفض الوصول','الدليل معطل حاليًا للضيوف والمستخدمين دون صلاحية OP.'],
      'bn'=>['OP ছাড়া ব্যবহারকারীদের জন্য সংকলন','প্রবেশাধিকার প্রত্যাখ্যাত','অতিথি এবং OP অনুমতি ছাড়া ব্যবহারকারীদের জন্য সংকলনটি বর্তমানে নিষ্ক্রিয়।'],
      'zh'=>['面向无 OP 权限用户的手册','拒绝访问','该手册目前对访客和无 OP 权限的用户关闭。'],
      'nl'=>['Compendium voor gebruikers zonder OP','Toegang geweigerd','Het compendium is momenteel uitgeschakeld voor gasten en gebruikers zonder OP-rechten.'],
      'fr'=>['Compendium pour les utilisateurs sans OP','Accès refusé','Le compendium est actuellement désactivé pour les visiteurs et les utilisateurs sans droits OP.'],
      'hi'=>['OP के बिना उपयोगकर्ताओं के लिए संकलन','पहुँच अस्वीकृत','संकलन अभी अतिथियों और OP अनुमति के बिना उपयोगकर्ताओं के लिए बंद है।'],
      'id'=>['Kompendium untuk pengguna tanpa OP','Akses ditolak','Kompendium saat ini dinonaktifkan untuk tamu dan pengguna tanpa izin OP.'],
      'it'=>['Compendio per utenti senza OP','Accesso negato','Il compendio è attualmente disattivato per ospiti e utenti senza permessi OP.'],
      'ja'=>['OP権限のないユーザー向けコンペンディウム','アクセスが拒否されました','コンペンディウムは現在、ゲストおよびOP権限のないユーザーには無効です。'],
      'ko'=>['OP 권한이 없는 사용자를 위한 편람','접근 거부','편람은 현재 게스트와 OP 권한이 없는 사용자에게 비활성화되어 있습니다.'],
      'pl'=>['Kompendium dla użytkowników bez OP','Odmowa dostępu','Kompendium jest obecnie wyłączone dla gości i użytkowników bez uprawnień OP.'],
      'pt'=>['Compêndio para utilizadores sem OP','Acesso negado','O compêndio está atualmente desativado para visitantes e utilizadores sem permissões OP.'],
      'ru'=>['Справочник для пользователей без OP','Доступ запрещён','Справочник сейчас отключён для гостей и пользователей без прав OP.'],
      'es'=>['Compendio para usuarios sin OP','Acceso denegado','El compendio está actualmente desactivado para visitantes y usuarios sin permisos OP.'],
      'tr'=>['OP yetkisi olmayan kullanıcılar için kılavuz','Erişim reddedildi','Kılavuz şu anda misafirler ve OP yetkisi olmayan kullanıcılar için devre dışıdır.']
    ];
    $result=$language==='de'?array_replace($en,$de):$en;
    $access = $accessTranslations[$language] ?? $accessTranslations['en'];
    $result['admin_configuration_compendium_public_enabled'] = $access[0];
    $result['compendium_access_denied_title'] = $access[1];
    $result['compendium_access_disabled'] = $access[2];
    if(isset($public[$language])){$result['nav_compendium']=$public[$language][0];$result['compendium_title']=$public[$language][0];$result['compendium_intro']=$public[$language][1];$result['compendium_search_placeholder']=$public[$language][2];}
    return $result;
}
