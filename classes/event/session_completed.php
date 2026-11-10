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

namespace mod_aidialogue\event;

/**
 * Event triggered when a dialogue session is completed.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_completed extends \core\event\base {
    /**
     * Init method.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'aidialogue_session';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Return localised general event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:session_completed', 'mod_aidialogue');
    }

    /**
     * Return description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        $earlyexit = !empty($this->other['earlyexit']) ? 'yes' : 'no';
        $aigrade = isset($this->other['aigrade']) ? $this->other['aigrade'] : 'none';

        return "The user with id '{$this->userid}' completed the dialogue session with id '{$this->objectid}' " .
            "(early exit: {$earlyexit}, AI grade: {$aigrade}) " .
            "for the aidialogue with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Return relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/aidialogue/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception("The 'relateduserid' must be set.");
        }
        if (!is_array($this->other) || !array_key_exists('aigrade', $this->other)) {
            throw new \coding_exception("The 'aigrade' value must be set in other.");
        }
        if (!is_array($this->other) || !array_key_exists('earlyexit', $this->other)) {
            throw new \coding_exception("The 'earlyexit' value must be set in other.");
        }
    }

    /**
     * Return mapping for the event's objectid during restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session'];
    }
}
