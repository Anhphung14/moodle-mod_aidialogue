<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Defines the restore structure step for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   backup
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one AI Dialogue activity.
 */
class restore_aidialogue_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the XML paths to restore.
     *
     * @return array Paths wrapped in the standard activity structure.
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('aidialogue', '/activity/aidialogue');
        $paths[] = new restore_path_element('aidialogue_criterion', '/activity/aidialogue/criteria/criterion');
        if ($userinfo) {
            $paths[] = new restore_path_element('aidialogue_session', '/activity/aidialogue/sessions/session');
            $paths[] = new restore_path_element('aidialogue_turn', '/activity/aidialogue/sessions/session/turns/turn');
            $paths[] = new restore_path_element(
                'aidialogue_result',
                '/activity/aidialogue/sessions/session/results/result'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the activity record.
     *
     * @param array $data Data read from the XML.
     */
    protected function process_aidialogue($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        $newitemid = $DB->insert_record('aidialogue', $data);
        // Immediately after inserting the activity record, tell the restore about it.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores a criterion. Turns and results point at it, so the mapping is saved.
     *
     * @param array $data Data read from the XML.
     */
    protected function process_aidialogue_criterion($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->aidialogueid = $this->get_new_parentid('aidialogue');

        $newitemid = $DB->insert_record('aidialogue_criterion', $data);
        $this->set_mapping('aidialogue_criterion', $oldid, $newitemid);
    }

    /**
     * Restores a session. Turns and results point at it, so the mapping is saved.
     *
     * A session of a user who is not part of the restore is skipped: without a user, several sessions
     * would get user 0 and the same attempt number, which breaks the unique index and aborts the restore.
     *
     * @param array $data Data read from the XML.
     */
    protected function process_aidialogue_session($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->aidialogueid = $this->get_new_parentid('aidialogue');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (!$data->userid) {
            // The turns and results of this session check the parent id and skip too.
            // Mapping to 0 also stops them from using the previous session's id.
            $this->set_mapping('aidialogue_session', $oldid, 0);
            return;
        }

        $newitemid = $DB->insert_record('aidialogue_session', $data);
        $this->set_mapping('aidialogue_session', $oldid, $newitemid);
    }

    /**
     * Restores a turn. The criterion is empty for session open and close turns, and must stay empty.
     *
     * A turn of a criterion that no longer exists becomes a turn without a criterion.
     *
     * @param array $data Data read from the XML.
     */
    protected function process_aidialogue_turn($data) {
        global $DB;

        $data = (object) $data;
        $data->sessionid = $this->get_new_parentid('aidialogue_session');
        if (!$data->sessionid) {
            return;
        }

        if (!empty($data->criterionid)) {
            $data->criterionid = $this->get_mappingid('aidialogue_criterion', $data->criterionid) ?: null;
        } else {
            $data->criterionid = null;
        }

        $DB->insert_record('aidialogue_turn', $data);
    }

    /**
     * Restores a criterion result. A result of a criterion that no longer exists is skipped.
     *
     * @param array $data Data read from the XML.
     */
    protected function process_aidialogue_result($data) {
        global $DB;

        $data = (object) $data;
        $data->sessionid = $this->get_new_parentid('aidialogue_session');
        $data->criterionid = $this->get_mappingid('aidialogue_criterion', $data->criterionid);
        if (!$data->sessionid || !$data->criterionid) {
            return;
        }

        $DB->insert_record('aidialogue_criterion_result', $data);
    }

    /**
     * Adds the intro files after the activity is restored.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aidialogue', 'intro', null);
    }
}
