<?php
namespace local_dsp_recompletion\event;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Sets odds_anniversary_date profile field to the current Unix timestamp when a user is created.
     */
    public static function user_created(\core\event\user_created $event): void {
        global $DB;

        $userid  = $event->objectid;
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'odds_anniversary_date']);

        if (!$fieldid) {
            return;
        }

        $timestamp = (string) time();
        $existing  = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);

        if ($existing) {
            $existing->data = $timestamp;
            $DB->update_record('user_info_data', $existing);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid'     => $userid,
                'fieldid'    => $fieldid,
                'data'       => $timestamp,
                'dataformat' => 0,
            ]);
        }
    }
}
