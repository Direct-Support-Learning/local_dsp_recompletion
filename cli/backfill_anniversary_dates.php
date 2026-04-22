<?php
/**
 * One-time backfill: sets odds_anniversary_date to timecreated for existing users
 * who do not already have the field populated.
 *
 * Usage: sudo -u www-data php admin/cli/run.php --script=local/dsp_recompletion/cli/backfill_anniversary_dates.php
 *   or:  sudo -u www-data php local/dsp_recompletion/cli/backfill_anniversary_dates.php
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['dry-run' => false, 'help' => false],
    ['n' => 'dry-run',   'h' => 'help']
);

if ($options['help']) {
    cli_writeln("Backfills odds_anniversary_date from timecreated for users missing the field.

Options:
  -n, --dry-run    Report what would be written without making changes
  -h, --help       Show this help
");
    exit(0);
}

$dryrun = (bool) $options['dry-run'];

// Resolve field ID.
$fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'odds_anniversary_date']);
if (!$fieldid) {
    cli_error('Custom profile field odds_anniversary_date not found. Create it first.');
}

// Users who already have the field set.
$alreadyset = $DB->get_fieldset_select(
    'user_info_data',
    'userid',
    "fieldid = ? AND data != ''",
    [$fieldid]
);
$alreadyset = !empty($alreadyset) ? array_flip($alreadyset) : [];

// All active real users.
$users = $DB->get_records_select(
    'user',
    'deleted = 0 AND suspended = 0 AND id > 1',
    [],
    '',
    'id, timecreated'
);

$total   = 0;
$skipped = 0;

foreach ($users as $user) {
    if (isset($alreadyset[$user->id])) {
        $skipped++;
        continue;
    }

    if (!$dryrun) {
        $DB->insert_record('user_info_data', (object) [
            'userid'     => $user->id,
            'fieldid'    => $fieldid,
            'data'       => (string) $user->timecreated,
            'dataformat' => 0,
        ]);
    }

    $total++;
}

$label = $dryrun ? 'Would backfill' : 'Backfilled';
cli_writeln("{$label} {$total} user(s). Skipped {$skipped} who already had the field set.");

if ($dryrun) {
    cli_writeln('Dry run — no changes written. Re-run without --dry-run to apply.');
}
