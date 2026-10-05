<?php
/*
| Moved to includes/stored_files.php.
|
| The HR app needs the same store -- attendance photographs are written to
| a disk Render discards, exactly as contract PDFs were -- and includes/ is
| where this project keeps what both applications share: mail_settings.php,
| app_url.php, account_access.php, role_chrome.php.
|
| This file stays so that nothing which already requires it has to change,
| and because the platform is deployed in two layouts and only one of them
| has includes/ one level up.
*/

$sharedStore = is_file(__DIR__ . '/../../includes/stored_files.php')
    ? __DIR__ . '/../../includes/stored_files.php'
    : __DIR__ . '/../../../SariSmarts/includes/stored_files.php';

require_once $sharedStore;
