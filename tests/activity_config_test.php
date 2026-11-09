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

namespace mod_aidialogue;

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\criterion_config;
use mod_aidialogue\tests\config_test_helper;

/**
 * Unit tests for activity_config and criterion_config.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(activity_config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(criterion_config::class)]
final class activity_config_test extends \advanced_testcase {
    /**
     * Test criterion_config DTO instantiation and properties.
     */
    public function test_criterion_config_properties(): void {
        $criterion = new criterion_config(
            id: 10,
            sortorder: 1,
            bloomslevel: activity_config::BLOOMS_ANALYSE,
            description: 'Explain chlorophyll absorption spectrum.',
            minturns: 2,
            maxturns: 4,
        );

        $this->assertSame(10, $criterion->id);
        $this->assertSame(1, $criterion->sortorder);
        $this->assertSame(activity_config::BLOOMS_ANALYSE, $criterion->bloomslevel);
        $this->assertSame('Explain chlorophyll absorption spectrum.', $criterion->description);
        $this->assertSame(2, $criterion->minturns);
        $this->assertSame(4, $criterion->maxturns);
    }

    /**
     * Test get_criterion returns criterion by 0-based index or null when out of bounds.
     */
    public function test_get_criterion_index(): void {
        $criterion1 = new criterion_config(
            id: 1,
            sortorder: 1,
            bloomslevel: activity_config::BLOOMS_ANALYSE,
            description: 'Criterion 1',
            minturns: 1,
            maxturns: 2,
        );
        $criterion2 = new criterion_config(
            id: 2,
            sortorder: 2,
            bloomslevel: activity_config::BLOOMS_EVALUATE,
            description: 'Criterion 2',
            minturns: 1,
            maxturns: 3,
        );

        $config = config_test_helper::make_config([$criterion1, $criterion2]);

        $this->assertSame($criterion1, $config->get_criterion(0));
        $this->assertSame($criterion2, $config->get_criterion(1));
        $this->assertNull($config->get_criterion(2));
        $this->assertNull($config->get_criterion(-1));
    }

    /**
     * Test load_from_db throws exception when AI credentials are not configured.
     */
    public function test_load_from_db_missing_credentials(): void {
        $this->resetAfterTest(true);

        // Clear plugin AI credentials.
        unset_config('aiurl', 'mod_aidialogue');
        unset_config('aiapikey', 'mod_aidialogue');
        unset_config('aimodel', 'mod_aidialogue');

        $course = $this->getDataGenerator()->create_course();
        $aidialogue = $this->getDataGenerator()->create_module('aidialogue', ['course' => $course->id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aicredentialsmissing', 'mod_aidialogue'));
        activity_config::load_from_db((int) $aidialogue->id);
    }

    /**
     * Test load_from_db successfully loads activity and criteria from database.
     */
    public function test_load_from_db_success(): void {
        $this->resetAfterTest(true);

        // Configure valid AI credentials (with trailing slash to test rtrim).
        set_config('aiurl', 'https://api.openai.com/v1/', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key-123', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_aidialogue_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');

        $record = [
            'course' => $course->id,
            'name' => 'Cell Division Assessment',
            'knowledgetext' => 'Mitosis produces two genetically identical daughter cells.',
            'maxattempts' => 3,
            'completionpassed' => 1,
            'completionexhausted' => 1,
        ];
        $instance = $generator->create_instance($record);

        // Add a second criterion with sortorder = 0 (lower than generator's default 1)
        // to prove load_from_db orders by sortorder ASC rather than insertion id.
        $generator->create_criterion((int) $instance->id, [
            'sortorder' => 0,
            'bloomslevel' => activity_config::BLOOMS_EVALUATE,
            'description' => 'Compare mitosis and meiosis.',
        ]);

        // Load through activity_config.
        $config = activity_config::load_from_db((int) $instance->id);

        $this->assertSame((int) $instance->id, $config->id);
        $this->assertSame('Cell Division Assessment', $config->name);
        $this->assertSame('Mitosis produces two genetically identical daughter cells.', $config->knowledgetext);
        $this->assertSame('https://api.openai.com/v1', $config->aiurl);
        $this->assertSame('dummy-key-123', $config->aiapikey);
        $this->assertSame('gpt-4o', $config->aimodel);
        $this->assertSame(3, $config->maxattempts);
        $this->assertTrue($config->completionpassed);
        $this->assertTrue($config->completionexhausted);

        // Verify criteria were loaded in sortorder ASC (sortorder 0 before sortorder 1).
        $this->assertCount(2, $config->criteria);
        $firstcriterion = $config->get_criterion(0);
        $secondcriterion = $config->get_criterion(1);
        $this->assertNotNull($firstcriterion);
        $this->assertNotNull($secondcriterion);
        $this->assertSame(activity_config::BLOOMS_EVALUATE, $firstcriterion->bloomslevel);
        $this->assertSame('Compare mitosis and meiosis.', $firstcriterion->description);
        $this->assertSame(activity_config::BLOOMS_ANALYSE, $secondcriterion->bloomslevel);
        $this->assertSame(ai_client::DEFAULT_MAX_TOKENS, $config->maxtokens);
    }

    /**
     * Test load_from_db loads configured maxtokens when valid.
     */
    public function test_load_from_db_maxtokens_configured(): void {
        $this->resetAfterTest(true);

        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
        set_config('maxtokens', 2048, 'mod_aidialogue');

        $course = $this->getDataGenerator()->create_course();
        $aidialogue = $this->getDataGenerator()->create_module('aidialogue', ['course' => $course->id]);

        $config = activity_config::load_from_db((int) $aidialogue->id);
        $this->assertSame(2048, $config->maxtokens);
    }

    /**
     * Test load_from_db falls back to default maxtokens when value is below minimum.
     */
    public function test_load_from_db_maxtokens_below_minimum_fallback(): void {
        $this->resetAfterTest(true);

        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
        set_config('maxtokens', 100, 'mod_aidialogue');

        $course = $this->getDataGenerator()->create_course();
        $aidialogue = $this->getDataGenerator()->create_module('aidialogue', ['course' => $course->id]);

        $config = activity_config::load_from_db((int) $aidialogue->id);
        $this->assertSame(ai_client::DEFAULT_MAX_TOKENS, $config->maxtokens);
    }
}
