<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_created',
        'callback'  => '\local_dsp_recompletion\event\observer::user_created',
        'priority'  => 0,
        'internal'  => false,
    ],
];
