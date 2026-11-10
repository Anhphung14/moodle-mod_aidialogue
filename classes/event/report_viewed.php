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
 * Event triggered when a teacher views the class report or an individual student review.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_viewed extends \core\event\base {
    /**
     * Init method.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'aidialogue';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Return localised general event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:report_viewed', 'mod_aidialogue');
    }

    /**
     * Return description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        if (($this->other['mode'] ?? '') === 'review') {
            return "The user with id '{$this->userid}' viewed the session review for user with id '{$this->relateduserid}' " .
                "(session id '{$this->other['sessionid']}') for the aidialogue with course module id '{$this->contextinstanceid}'.";
        }

        return "The user with id '{$this->userid}' viewed the class report " .
            "for the aidialogue with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Return relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        if (($this->other['mode'] ?? '') === 'review') {
            return new \moodle_url('/mod/aidialogue/review.php', [
                'id' => $this->contextinstanceid,
                'session' => $this->other['sessionid'],
            ]);
        }

        return new \moodle_url('/mod/aidialogue/report.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();

        $invalidmode = !is_array($this->other) || empty($this->other['mode'])
            || !in_array($this->other['mode'], ['class', 'review'], true);
        if ($invalidmode) {
            throw new \coding_exception("The 'mode' value must be set to 'class' or 'review' in other.");
        }
        if ($this->other['mode'] === 'review' && empty($this->other['sessionid'])) {
            throw new \coding_exception("The 'sessionid' value must be set in other when mode is review.");
        }
    }

    /**
     * Return mapping for the event's objectid during restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'aidialogue', 'restore' => 'aidialogue'];
    }

    /**
     * Return mapping for other fields during restore.
     *
     * @return array
     */
    public static function get_other_mapping(): array {
        return [
            'sessionid' => ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session'],
        ];
    }
}
