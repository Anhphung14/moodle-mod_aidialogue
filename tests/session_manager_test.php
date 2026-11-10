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
use mod_aidialogue\local\session_manager;

/**
 * Unit tests for session_manager database persistence.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(session_manager::class)]
final class session_manager_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        // Ensure required AI settings are configured.
        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
    }

    /**
     * Test creating a session creates session record and pending criterion results.
     */
    public function test_create_session(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        $this->assertGreaterThan(0, (int) $session->id);
        $this->assertSame('pending', $session->status);
        $this->assertSame(1, (int) $session->attemptnumber);

        // Verify criterion results were pre-created with status 'pending'.
        $results = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $session->id]);
        $this->assertCount(count($config->criteria), $results);
        foreach ($results as $result) {
            $this->assertSame('pending', $result->status);
        }
    }

    /**
     * Test creating session throws exception when max attempts limit is reached.
     */
    public function test_create_session_max_attempts(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance([
            'course' => $course->id,
            'maxattempts' => 1,
        ]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();

        // First attempt should succeed.
        $manager->create_session((int) $instance->id, (int) $student->id, $config);

        // Second attempt must throw max attempts reached exception.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:maxattemptsreached', 'mod_aidialogue'));

        $manager->create_session((int) $instance->id, (int) $student->id, $config);
    }

    /**
     * Test session activation and completion lifecycle.
     */
    public function test_session_lifecycle(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        // 1. Activate session.
        $manager->activate_session((int) $session->id);
        $activesession = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('active', $activesession->status);
        $this->assertGreaterThan(0, (int) $activesession->timestarted);

        // 2. Close session.
        $manager->close_session(
            sessionid: (int) $session->id,
            studentreport: 'Student feedback text',
            teacherreport: 'Teacher report text',
            aigrade: 90.0,
            earlyexit: false
        );

        $closedsession = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('complete', $closedsession->status);
        $this->assertSame(90.0, (float) $closedsession->aigrade);
        $this->assertSame('Student feedback text', $closedsession->studentreport);
        $this->assertSame('Teacher report text', $closedsession->teacherreport);
        $this->assertGreaterThan(0, (int) $closedsession->timefinished);
    }

    /**
     * Test adding turns and sequential turn numbers.
     */
    public function test_add_turns_sequence(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);
        $criterion = $config->criteria[0];

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        // Add 3 turns in sequence.
        $turn1 = $manager->add_turn((int) $session->id, null, 'ai', 'session_open', 'Hello');
        $turn2 = $manager->add_turn((int) $session->id, $criterion->id, 'student', null, 'Hi there');
        $turn3 = $manager->add_turn((int) $session->id, $criterion->id, 'ai', 'probe_deeper', 'Tell me more');

        $this->assertSame(1, $turn1->turnnumber);
        $this->assertSame(2, $turn2->turnnumber);
        $this->assertSame(3, $turn3->turnnumber);

        // Verify student turn count for this criterion.
        $studentturns = $manager->count_student_turns_for_criterion((int) $session->id, $criterion->id);
        $this->assertSame(1, $studentturns);

        // Verify retrieval of all turns.
        $allturns = $manager->get_turns((int) $session->id);
        $this->assertCount(3, $allturns);
    }

    /**
     * Test criteria resolution and all criteria met checks across multiple criteria.
     */
    public function test_criteria_met_checks(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance([
            'course' => $course->id,
            'criteria' => [
                ['sortorder' => 1, 'description' => 'Criterion 1'],
                ['sortorder' => 2, 'description' => 'Criterion 2'],
            ],
        ]);
        $config = activity_config::load_from_db((int) $instance->id);
        $criterion1 = $config->criteria[0];
        $criterion2 = $config->criteria[1];

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        // Initially both pending -> not resolved, not met.
        $this->assertFalse($manager->all_criteria_resolved((int) $session->id));
        $this->assertFalse($manager->all_criteria_met((int) $session->id));

        // Update only Criterion 1 to 'met' -> Criterion 2 still pending, so both must remain false.
        $manager->update_criterion_result(
            sessionid: (int) $session->id,
            criterionid: $criterion1->id,
            status: 'met',
            evidence: 'Good explanation'
        );
        $this->assertFalse($manager->all_criteria_resolved((int) $session->id));
        $this->assertFalse($manager->all_criteria_met((int) $session->id));

        // Update Criterion 2 to 'met' -> now all resolved and all met.
        $manager->update_criterion_result(
            sessionid: (int) $session->id,
            criterionid: $criterion2->id,
            status: 'met',
            evidence: 'Great analysis'
        );
        $this->assertTrue($manager->all_criteria_resolved((int) $session->id));
        $this->assertTrue($manager->all_criteria_met((int) $session->id));

        // If Criterion 2 is 'limit' -> all resolved is true, but all met is false.
        $manager->update_criterion_result(
            sessionid: (int) $session->id,
            criterionid: $criterion2->id,
            status: 'limit'
        );
        $this->assertTrue($manager->all_criteria_resolved((int) $session->id));
        $this->assertFalse($manager->all_criteria_met((int) $session->id));
    }

    /**
     * Test delete_session cascades across session, turn, and criterion_result tables
     * while leaving other sessions untouched.
     */
    public function test_delete_session(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();
        $session1 = $manager->create_session((int) $instance->id, (int) $student1->id, $config);
        $manager->add_turn((int) $session1->id, null, 'ai', 'session_open', 'Opening prompt 1');

        $session2 = $manager->create_session((int) $instance->id, (int) $student2->id, $config);
        $manager->add_turn((int) $session2->id, null, 'ai', 'session_open', 'Opening prompt 2');

        // Preconditions: both sessions have rows.
        $this->assertSame(1, $DB->count_records('aidialogue_turn', ['sessionid' => $session1->id]));
        $this->assertSame(1, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session1->id]));

        // Delete session 1 only.
        $manager->delete_session((int) $session1->id);

        // Session 1 rows are gone.
        $this->assertFalse($DB->get_record('aidialogue_session', ['id' => $session1->id]));
        $this->assertSame(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session1->id]));
        $this->assertSame(0, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session1->id]));

        // Session 2 rows survive intact.
        $this->assertNotFalse($DB->get_record('aidialogue_session', ['id' => $session2->id]));
        $this->assertSame(1, $DB->count_records('aidialogue_turn', ['sessionid' => $session2->id]));
        $this->assertSame(1, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session2->id]));
    }

    /**
     * Test delete_sessions batches deletion across multiple sessions.
     */
    public function test_delete_sessions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();
        $session1 = $manager->create_session((int) $instance->id, (int) $student1->id, $config);
        $manager->add_turn((int) $session1->id, null, 'ai', 'session_open', 'Prompt 1');

        $session2 = $manager->create_session((int) $instance->id, (int) $student2->id, $config);
        $manager->add_turn((int) $session2->id, null, 'ai', 'session_open', 'Prompt 2');

        $session3 = $manager->create_session((int) $instance->id, (int) $student3->id, $config);
        $manager->add_turn((int) $session3->id, null, 'ai', 'session_open', 'Prompt 3');

        // Delete empty array is a safe no-op.
        $manager->delete_sessions([]);
        $this->assertSame(3, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance->id]));

        // Batch delete sessions 1 and 2.
        $manager->delete_sessions([(int) $session1->id, (int) $session2->id]);

        $this->assertFalse($DB->record_exists('aidialogue_session', ['id' => $session1->id]));
        $this->assertFalse($DB->record_exists('aidialogue_session', ['id' => $session2->id]));
        $this->assertSame(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session1->id]));
        $this->assertSame(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session2->id]));

        // Session 3 remains untouched.
        $this->assertTrue($DB->record_exists('aidialogue_session', ['id' => $session3->id]));
        $this->assertSame(1, $DB->count_records('aidialogue_turn', ['sessionid' => $session3->id]));
    }

    /**
     * Data provider: stored statuses that activate_session() must leave alone.
     *
     * @return array
     */
    public static function not_pending_status_provider(): array {
        return [
            'already active' => ['active'],
            'already complete' => ['complete'],
        ];
    }

    /**
     * Test activate_session() does not change a session that is no longer pending.
     *
     * A late request can hold an older copy of the record, so the stored status decides.
     *
     * @param string $status The stored status of the session.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('not_pending_status_provider')]
    public function test_activate_session_ignores_session_that_is_not_pending(string $status): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue')->create_instance(['course' => $course->id]);
        $manager = new session_manager();
        $session = $manager->create_session(
            (int) $instance->id,
            (int) $student->id,
            activity_config::load_from_db((int) $instance->id),
        );
        $DB->update_record('aidialogue_session', (object) [
            'id' => $session->id,
            'status' => $status,
            'timestarted' => 1000,
            'timemodified' => 1000,
        ]);

        $manager->activate_session((int) $session->id);

        $stored = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame($status, $stored->status);
        $this->assertEquals(1000, $stored->timestarted);
        $this->assertEquals(1000, $stored->timemodified);
    }
}
