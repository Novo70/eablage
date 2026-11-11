<?php
// Generated metadata is copied from /release.json by scripts/release_metadata.py.
$eablageRelease = json_decode(file_get_contents(__DIR__ . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
define('EABLAGE_WEB_VERSION', $eablageRelease['version']);
define('EABLAGE_RELEASE_DATE', $eablageRelease['date']);
unset($eablageRelease);
