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

namespace mod_aidialogue\tests;

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\criterion_config;

/**
 * Shared test helper for constructing in-memory activity_config instances.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_test_helper {
    /**
     * Build an activity_config instance via reflection without touching the database.
     *
     * @param criterion_config[]|null $criteria Optional criteria list; defaults to two sample criteria.
     * @param int                     $maxtokens Optional max_tokens limit; defaults to 1024.
     * @return activity_config
     */
    public static function make_config(
        ?array $criteria = null,
        int $maxtokens = \mod_aidialogue\local\ai_client::DEFAULT_MAX_TOKENS,
    ): activity_config {
        if ($criteria === null) {
            $criteria = [
                new criterion_config(
                    id: 101,
                    sortorder: 1,
                    bloomslevel: activity_config::BLOOMS_ANALYSE,
                    description: 'Explain the role of chlorophyll.',
                    minturns: 1,
                    maxturns: 2,
                ),
                new criterion_config(
                    id: 102,
                    sortorder: 2,
                    bloomslevel: activity_config::BLOOMS_EVALUATE,
                    description: 'Evaluate the impact of light intensity.',
                    minturns: 1,
                    maxturns: 3,
                ),
            ];
        }

        $ref = new \ReflectionClass(activity_config::class);
        $config = $ref->newInstanceWithoutConstructor();
        $constructor = $ref->getConstructor();
        $constructor->invoke(
            $config,
            1,
            'Photosynthesis Dialogue',
            'Chlorophyll absorbs light energy to drive photosynthesis.',
            'https://api.openai.com/v1',
            'testkey',
            'gpt-4o',
            0,
            false,
            $criteria,
            $maxtokens,
        );

        return $config;
    }
}
