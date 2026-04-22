<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'   => '\local_dsp_recompletion\task\anniversary_recompletion',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '1',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
    ],
];
