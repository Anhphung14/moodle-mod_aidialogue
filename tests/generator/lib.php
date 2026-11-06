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
 * Data generator for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Data generator for mod_aidialogue.
 */
class mod_aidialogue_generator extends testing_module_generator {
    /**
     * Creates an AI Dialogue instance. Two criteria are added unless the record has its own.
     *
     * @param array|stdClass|null $record Fields from the add module form.
     * @param array|null $options Course module options.
     * @return stdClass The aidialogue record with the extra field cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $record->knowledgetext = $record->knowledgetext ?? 'Test knowledge text.';
        if (!isset($record->description)) {
            $record->criterionid = [0, 0];
            $record->bloomslevel = [1, 8];
            $record->description = ['Explains the main idea.', 'Gives an own example.'];
            $record->minturns = [1, 1];
            $record->maxturns = [2, 3];
        }

        return parent::create_instance($record, (array) $options);
    }
}
