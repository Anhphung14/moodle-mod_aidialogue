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
 * Unit tests for course reset in mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_reset_course_form_definition')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_reset_course_form_defaults')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_reset_userdata')]
final class course_reset_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        // Required AI connection settings.
        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
    }

    /**
     * Helper to create an activity with a session, turns, and criterion results for a course.
     *
     * @param \stdClass $course The course to create the activity in.
     * @return array Array containing [$instance, $student, $session].
     */
    private function create_activity_with_session(\stdClass $course): array {
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        // Add conversation turns.
        $criterion = $config->criteria[0];
        $manager->add_turn((int) $session->id, null, 'ai', 'session_open', 'Hello student');
        $manager->add_turn((int) $session->id, $criterion->id, 'student', null, 'Hi teacher');
        $manager->add_turn((int) $session->id, $criterion->id, 'ai', 'probe_deeper', 'Tell me more');

        return [$instance, $student, $session];
    }

    /**
     * Test course reset form definition adds header and checkbox with compliant labels.
     */
    public function test_reset_course_form_definition(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $mform = new \MoodleQuickForm('test_reset_form', 'post', '');
        aidialogue_reset_course_form_definition($mform);

        $this->assertTrue($mform->elementExists('aidialogueheader'));
        $this->assertTrue($mform->elementExists('aidialoguedelete'));
        $this->assertTrue($mform->elementExists('reset_aidialogue_sessions'));

        // Verify label complies with MDL-81872 (no repeated "Delete" or "Remove").
        $label = get_string('reset_aidialogue_sessions', 'aidialogue');
        $this->assertStringNotContainsStringIgnoringCase('delete', $label);
        $this->assertStringNotContainsStringIgnoringCase('remove', $label);
    }

    /**
     * Test course reset form defaults.
     */
    public function test_reset_course_form_defaults(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $defaults = aidialogue_reset_course_form_defaults($course);

        $this->assertEquals(['reset_aidialogue_sessions' => 1], $defaults);
    }

    /**
     * Test resetting user data clears sessions across multiple activities in target course,
     * including complete sessions, while leaving other courses untouched.
     */
    public function test_reset_userdata_clears_target_course_sessions_only(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        // Course 1: Activity 1 with student 1 (active session).
        [$instance1a, $student1a, $session1a] = $this->create_activity_with_session($course1);

        // Course 1: Activity 1 with student 2 (completed session).
        $student1b = $this->getDataGenerator()->create_and_enrol($course1, 'student');
        $config1a = activity_config::load_from_db((int) $instance1a->id);
        $manager = new session_manager();
        $session1b = $manager->create_session((int) $instance1a->id, (int) $student1b->id, $config1a);
        $manager->add_turn((int) $session1b->id, null, 'ai', 'session_open', 'Hello student 2');
        $manager->add_turn((int) $session1b->id, null, 'ai', 'session_close', 'Goodbye student 2');
        $DB->set_field('aidialogue_session', 'status', 'complete', ['id' => $session1b->id]);
        $DB->set_field('aidialogue_session', 'timefinished', time(), ['id' => $session1b->id]);
        $DB->set_field('aidialogue_criterion_result', 'status', 'met', ['sessionid' => $session1b->id]);

        // Course 1: Activity 2 with student 3 (multi-activity test).
        [$instance1b, $student1c, $session1c] = $this->create_activity_with_session($course1);

        // Course 2: Activity with student 4 (isolation test).
        [$instance2, $student2, $session2] = $this->create_activity_with_session($course2);

        // Verify initial state: Course 1 has 3 sessions across 2 activities.
        $this->assertEquals(2, $DB->count_records('aidialogue', ['course' => $course1->id]));
        $this->assertEquals(2, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance1a->id]));
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance1b->id]));
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance2->id]));

        // Reset course 1.
        $resetdata = (object) [
            'courseid' => $course1->id,
            'reset_aidialogue_sessions' => 1,
        ];
        $status = aidialogue_reset_userdata($resetdata);

        // Verify status return array.
        $this->assertCount(1, $status);
        $this->assertSame(get_string('modulenameplural', 'aidialogue'), $status[0]['component']);
        $this->assertSame(get_string('reset_aidialogue_sessions', 'aidialogue'), $status[0]['item']);
        $this->assertFalse($status[0]['error']);

        // Verify Course 1: all user sessions, turns, and criterion results across both activities are deleted.
        $this->assertEquals(0, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance1a->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance1b->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session1a->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session1b->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_turn', ['sessionid' => $session1c->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session1a->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session1b->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session1c->id]));

        // Verify Course 1: activity instances and criteria records are NOT deleted.
        $this->assertTrue($DB->record_exists('aidialogue', ['id' => $instance1a->id]));
        $this->assertTrue($DB->record_exists('aidialogue', ['id' => $instance1b->id]));
        $this->assertGreaterThan(0, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $instance1a->id]));
        $this->assertGreaterThan(0, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $instance1b->id]));

        // Verify Course 2: data remains completely untouched.
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance2->id]));
        $this->assertEquals(3, $DB->count_records('aidialogue_turn', ['sessionid' => $session2->id]));
        $this->assertGreaterThan(0, $DB->count_records('aidialogue_criterion_result', ['sessionid' => $session2->id]));
    }

    /**
     * Test resetting user data does nothing when the checkbox is not selected.
     */
    public function test_reset_userdata_unchecked(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $course = $this->getDataGenerator()->create_course();
        [$instance, $student, $session] = $this->create_activity_with_session($course);

        // Call reset with reset_aidialogue_sessions = 0.
        $resetdata = (object) [
            'courseid' => $course->id,
            'reset_aidialogue_sessions' => 0,
        ];
        $status = aidialogue_reset_userdata($resetdata);

        // Verify nothing was removed and status is empty.
        $this->assertEmpty($status);
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance->id]));
        $this->assertEquals(3, $DB->count_records('aidialogue_turn', ['sessionid' => $session->id]));
    }

    /**
     * Test resetting a course with an activity that has no sessions runs cleanly without error.
     */
    public function test_reset_userdata_course_with_no_sessions(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);

        $resetdata = (object) [
            'courseid' => $course->id,
            'reset_aidialogue_sessions' => 1,
        ];
        $status = aidialogue_reset_userdata($resetdata);

        $this->assertCount(1, $status);
        $this->assertFalse($status[0]['error']);
        $this->assertTrue($DB->record_exists('aidialogue', ['id' => $instance->id]));
    }
}
