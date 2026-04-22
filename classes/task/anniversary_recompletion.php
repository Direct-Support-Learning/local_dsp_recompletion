<?php
namespace local_dsp_recompletion\task;

defined('MOODLE_INTERNAL') || die();

class anniversary_recompletion extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_anniversary_recompletion', 'local_dsp_recompletion');
    }

    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/recompletion/locallib.php');

        // ── 1. Find users whose anniversary (month/day) is today ────────────────
        // Leap-year edge case: Feb 29 anniversaries fire on Feb 28 in non-leap years.
        $sql = "SELECT u.id, uid.data AS anniversary_date
                  FROM {user} u
                  JOIN {user_info_data} uid ON uid.userid = u.id
                  JOIN {user_info_field} uif ON uif.id = uid.fieldid
                       AND uif.shortname = 'odds_anniversary_date'
                 WHERE u.deleted  = 0
                   AND u.suspended = 0
                   AND uid.data   != ''
                   AND (
                         (MONTH(FROM_UNIXTIME(CAST(uid.data AS UNSIGNED))) = MONTH(CURDATE())
                          AND DAY(FROM_UNIXTIME(CAST(uid.data AS UNSIGNED))) = DAY(CURDATE()))
                         OR
                         (MONTH(FROM_UNIXTIME(CAST(uid.data AS UNSIGNED))) = 2
                          AND DAY(FROM_UNIXTIME(CAST(uid.data AS UNSIGNED))) = 29
                          AND MONTH(CURDATE()) = 2
                          AND DAY(LAST_DAY(CURDATE())) = 28)
                       )";

        $anniversaryusers = $DB->get_records_sql($sql);

        if (empty($anniversaryusers)) {
            mtrace('local_dsp_recompletion: no anniversary users today.');
            return;
        }

        mtrace('local_dsp_recompletion: ' . count($anniversaryusers) . ' anniversary user(s) found.');

        // ── 2. Load all dsp_job_* user profile fields ───────────────────────────
        $jobfields = $DB->get_records_sql(
            "SELECT id, shortname FROM {user_info_field} WHERE shortname LIKE ?",
            ['dsp_job_%']
        );

        if (empty($jobfields)) {
            mtrace('local_dsp_recompletion: no dsp_job_* profile fields configured.');
            return;
        }

        $jobfieldids = array_keys($jobfields);

        // ── 3. Load course custom field definitions ──────────────────────────────
        $dspjobfield    = $DB->get_record('customfield_field', ['shortname' => 'dsp_job']);
        $recurrencefield = $DB->get_record('customfield_field', ['shortname' => 'recurrence']);

        if (!$dspjobfield || !$recurrencefield) {
            mtrace('local_dsp_recompletion: course custom fields dsp_job / recurrence not found.');
            return;
        }

        // ── 4. Load all courses with recurrence = 1 that have a dsp_job value ───
        $recurrencecourses = $DB->get_records_sql(
            "SELECT rec.instanceid AS courseid, dj.value AS dsp_jobs
               FROM {customfield_data} rec
               JOIN {customfield_data} dj
                    ON dj.instanceid = rec.instanceid
                   AND dj.fieldid    = :dspjobfieldid
                   AND dj.value     != ''
              WHERE rec.fieldid = :recurrencefieldid
                AND rec.value   = '1'",
            [
                'dspjobfieldid'    => $dspjobfield->id,
                'recurrencefieldid' => $recurrencefield->id,
            ]
        );

        if (empty($recurrencecourses)) {
            mtrace('local_dsp_recompletion: no courses with recurrence = 1 and dsp_job set.');
            return;
        }

        // ── 5. Process each anniversary user ────────────────────────────────────
        [$fieldsql, $fieldparams] = $DB->get_in_or_equal($jobfieldids, SQL_PARAMS_NAMED, 'fid');

        foreach ($anniversaryusers as $user) {

            // Get this user's active DSP job shortnames (strip 'dsp_job_' prefix).
            $fieldparams['dspauid'] = $user->id;
            $activejobrecords = $DB->get_records_sql(
                "SELECT uif.shortname
                   FROM {user_info_data} uid
                   JOIN {user_info_field} uif ON uif.id = uid.fieldid
                  WHERE uid.userid  = :dspauid
                    AND uid.fieldid {$fieldsql}
                    AND uid.data    = '1'",
                $fieldparams
            );

            if (empty($activejobrecords)) {
                continue;
            }

            $activejobs = array_map(
                fn($r) => $r->shortname,
                $activejobrecords
            );

            // Find matching courses and reset completions.
            foreach ($recurrencecourses as $coursedata) {
                if (!$this->course_matches_jobs($coursedata->dsp_jobs, $activejobs)) {
                    continue;
                }

                $course = $DB->get_record('course', ['id' => $coursedata->courseid]);
                if (!$course) {
                    continue;
                }

                try {
                    // reset_user() fetches its own config when null is passed.
                    $recompletiontask = new \local_recompletion\task\check_recompletion();
                    $errors = $recompletiontask->reset_user($user->id, $course);
                    if (!empty($errors)) {
                        mtrace("  skipped user {$user->id} in course {$course->id}: " . implode(', ', $errors));
                    } else {
                        mtrace("  reset user {$user->id} in course {$course->id}.");
                    }
                } catch (\Throwable $e) {
                    mtrace("  ERROR resetting user {$user->id} in course {$course->id}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Returns true if the course's dsp_job JSON array intersects the user's active job shortnames.
     *
     * @param string   $dsbjobsjson  JSON array stored in customfield_data.value
     * @param string[] $userjobs     Active job shortnames for the user
     */
    private function course_matches_jobs(string $dsbjobsjson, array $userjobs): bool {
        $coursejobs = json_decode($dsbjobsjson, true);
        if (!is_array($coursejobs)) {
            return false;
        }
        return (bool) array_intersect($userjobs, $coursejobs);
    }
}
