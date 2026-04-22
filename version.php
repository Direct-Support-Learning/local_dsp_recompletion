<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'local_dsp_recompletion';
$plugin->version      = 2026042200;
$plugin->requires     = 2024100700; // Moodle 4.5
$plugin->maturity     = MATURITY_STABLE;
$plugin->release      = '1.0.0';
$plugin->dependencies = [
    'local_recompletion' => ANY_VERSION,
];
