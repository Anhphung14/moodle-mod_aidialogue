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

use mod_aidialogue\local\activity_config;

/**
 * AI Dialogue module data generator class.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aidialogue_generator extends testing_module_generator {
    /**
     * Create a new AI Dialogue instance for testing.
     *
     * @param array|stdClass|null $record Data for the new instance.
     * @param array|null $options Additional options.
     * @return stdClass Activity record with cmid.
     */
    public function create_instance($record = null, ?array $options = null): stdClass {
        $record = (object) (array) $record;
        if (empty($record->course)) {
            throw new \coding_exception('module generator requires $record->course');
        }

        // Default activity attributes if not supplied.
        if (!isset($record->knowledgetext)) {
            $record->knowledgetext = 'Photosynthesis converts sunlight into chemical energy using chlorophyll.';
        }
        if (!isset($record->maxattempts)) {
            $record->maxattempts = 0; // Unlimited.
        }
        if (!isset($record->completionpassorexhaust)) {
            $record->completionpassorexhaust = 0;
        }

        // Map criteria array to form field arrays if provided.
        if (!empty($record->criteria)) {
            $descriptions = [];
            $bloomslevels = [];
            $minturns = [];
            $maxturns = [];
            $criterionids = [];
            foreach ($record->criteria as $criterion) {
                $c = (object) $criterion;
                $descriptions[] = $c->description ?? 'Test criterion';
                $bloomslevels[] = $c->bloomslevel ?? activity_config::BLOOMS_ANALYSE;
                $minturns[] = $c->minturns ?? 1;
                $maxturns[] = $c->maxturns ?? 2;
                $criterionids[] = $c->id ?? 0;
            }
            $record->description = $descriptions;
            $record->bloomslevel = $bloomslevels;
            $record->minturns    = $minturns;
            $record->maxturns    = $maxturns;
            $record->criterionid = $criterionids;
        } else if (empty($record->description)) {
            $record->description = ['Explain the role of chlorophyll in light absorption.'];
            $record->bloomslevel = [activity_config::BLOOMS_ANALYSE];
            $record->minturns    = [1];
            $record->maxturns    = [2];
            $record->criterionid = [0];
        }

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Helper to create an individual criterion record for testing.
     *
     * @param int $aidialogueid Activity instance ID.
     * @param array|stdClass $record Criterion data.
     * @return stdClass Created criterion record.
     */
    public function create_criterion(int $aidialogueid, $record = []): stdClass {
        global $DB;

        $now = time();
        $record = (object) (array) $record;
        $record->aidialogueid = $aidialogueid;
        $record->sortorder    = $record->sortorder ?? 1;
        $record->bloomslevel  = $record->bloomslevel ?? activity_config::BLOOMS_ANALYSE;
        $record->description  = $record->description ?? 'Test criterion description';
        $record->minturns     = $record->minturns ?? 1;
        $record->maxturns     = $record->maxturns ?? 2;
        $record->timecreated  = $record->timecreated ?? $now;
        $record->timemodified = $record->timemodified ?? $now;

        $record->id = $DB->insert_record('aidialogue_criterion', $record);

        return $record;
    }
}
