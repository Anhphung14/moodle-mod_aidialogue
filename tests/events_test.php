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

use mod_aidialogue\event\grade_updated;
use mod_aidialogue\event\report_viewed;
use mod_aidialogue\event\session_completed;
use mod_aidialogue\event\session_started;
use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\dialogue_engine;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\session_manager;

/**
 * Unit tests for mod_aidialogue events.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(session_started::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(session_completed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(report_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grade_updated::class)]
final class events_test extends \advanced_testcase {
    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
    }

    /**
     * Helper to create an activity with course module context and users.
     *
     * @param array $criteria Optional custom criteria definition.
     * @return array Array containing [$course, $cm, $context, $instance, $student, $teacher, $config].
     */
    private function create_activity_fixture(array $criteria = []): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $params = ['course' => $course->id];
        if (!empty($criteria)) {
            $params['criteria'] = $criteria;
        }
        $instance = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('aidialogue', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $config = activity_config::load_from_db((int) $instance->id);

        return [$course, $cm, $context, $instance, $student, $teacher, $config];
    }

    /**
     * Helper to create a fake AI client with canned responses.
     *
     * @param array $replies Ordered list of replies to return on successive chat() calls.
     * @return ai_client
     */
    private function create_mock_ai_client(array $replies): ai_client {
        $mock = $this->createMock(ai_client::class);
        $mock->method('chat')->willReturnOnConsecutiveCalls(...$replies);
        return $mock;
    }

    /**
     * Test session_started event is triggered when a student starts a session.
     */
    public function test_session_started_event(): void {
        [, $cm, $context, , $student, , $config] = $this->create_activity_fixture();

        $aiopening = "Welcome to the dialogue!\n\n[MOVE:session_open]";
        $mockai = $this->create_mock_ai_client([$aiopening]);

        $engine = new dialogue_engine(
            new session_manager(),
            $mockai,
            new prompt_builder()
        );

        $sink = $this->redirectEvents();
        $this->setUser($student);
        $result = $engine->start_session($config, (int) $student->id);
        $events = $sink->get_events();
        $sink->close();

        $session = $result['session'];

        $startedevents = array_values(array_filter($events, function ($e) {
            return $e instanceof session_started;
        }));

        $this->assertCount(1, $startedevents);
        $event = $startedevents[0];

        $this->assertSame((int) $session->id, (int) $event->objectid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame((int) $student->id, (int) $event->userid);
        $this->assertSame((int) $cm->id, (int) $event->contextinstanceid);
        $this->assertEquals($context, $event->get_context());
        $this->assertSame(1, $event->other['attemptnumber']);
        $this->assertEventContextNotUsed($event);

        $this->assertSame(get_string('event:session_started', 'mod_aidialogue'), $event->get_name());
        $this->assertStringContainsString("started the dialogue session with id '{$session->id}'", $event->get_description());
        $this->assertTrue($event->get_url()->compare(new \moodle_url('/mod/aidialogue/view.php', ['id' => $cm->id])));
    }

    /**
     * Test session_started event is NOT triggered if the initial AI call fails.
     */
    public function test_session_started_not_triggered_on_ai_failure(): void {
        [, , , , $student, , $config] = $this->create_activity_fixture();

        $mockai = $this->createMock(ai_client::class);
        $mockai->method('chat')->willThrowException(new \moodle_exception('error:aihttperror', 'mod_aidialogue'));

        $engine = new dialogue_engine(
            new session_manager(),
            $mockai,
            new prompt_builder()
        );

        $sink = $this->redirectEvents();
        $this->setUser($student);

        try {
            $engine->start_session($config, (int) $student->id);
            $this->fail('Expected moodle_exception when AI call fails.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:aihttperror', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $startedevents = array_filter($events, function ($e) {
            return $e instanceof session_started;
        });
        $this->assertEmpty($startedevents);
    }

    /**
     * Test session_completed event is triggered when session completes normally.
     */
    public function test_session_completed_event_normal(): void {
        $singlecriterion = [
            [
                'sortorder'   => 1,
                'bloomslevel' => activity_config::BLOOMS_ANALYSE,
                'description' => 'Single criterion for testing',
                'minturns'    => 1,
                'maxturns'    => 1,
            ],
        ];
        [, $cm, , , $student, , $config] = $this->create_activity_fixture($singlecriterion);

        $mockai = $this->create_mock_ai_client([
            "Welcome!\n\n[MOVE:session_open]",
            "Time is up on this topic.\n\n[MOVE:criterion_close]",
            "Student mentioned chlorophyll.",
            "Thank you for chatting!\n\n[MOVE:session_close]",
            "Student Summary Feedback",
            "Teacher Narrative Report\nGRADE:88.5",
        ]);

        $engine = new dialogue_engine(
            new session_manager(),
            $mockai,
            new prompt_builder()
        );

        $this->setUser($student);
        $startresult = $engine->start_session($config, (int) $student->id);
        $session = $startresult['session'];

        $sink = $this->redirectEvents();
        $engine->process_student_turn($config, $session, 'My final answer');
        $events = $sink->get_events();
        $sink->close();

        $completedevents = array_values(array_filter($events, function ($e) {
            return $e instanceof session_completed;
        }));

        $this->assertCount(1, $completedevents);
        $event = $completedevents[0];

        $this->assertSame((int) $session->id, (int) $event->objectid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame((int) $cm->id, (int) $event->contextinstanceid);
        $this->assertEquals(88.5, (float) $event->other['aigrade']);
        $this->assertFalse($event->other['earlyexit']);
        $this->assertSame(1, $event->other['attemptnumber']);

        $this->assertSame(get_string('event:session_completed', 'mod_aidialogue'), $event->get_name());
        $this->assertStringContainsString("completed the dialogue session with id '{$session->id}'", $event->get_description());
        $this->assertStringContainsString("early exit: no", $event->get_description());
        $this->assertStringContainsString("AI grade: 88.5", $event->get_description());
        $this->assertTrue($event->get_url()->compare(new \moodle_url('/mod/aidialogue/view.php', ['id' => $cm->id])));
    }

    /**
     * Test session_completed event is triggered when session ends via early exit.
     */
    public function test_session_completed_event_early_exit(): void {
        [, $cm, , , $student, , $config] = $this->create_activity_fixture();

        $mockai = $this->create_mock_ai_client([
            "Welcome!\n\n[MOVE:session_open]",
            "Session ended early.\n\n[MOVE:session_close]",
            "Student feedback after early exit.",
            "GRADE: 40.0\nREPORT: Student exited early.",
        ]);

        $engine = new dialogue_engine(
            new session_manager(),
            $mockai,
            new prompt_builder()
        );

        $this->setUser($student);
        $startresult = $engine->start_session($config, (int) $student->id);
        $session = $startresult['session'];

        $sink = $this->redirectEvents();
        $engine->end_session_early($config, $session);
        $events = $sink->get_events();
        $sink->close();

        $completedevents = array_values(array_filter($events, function ($e) {
            return $e instanceof session_completed;
        }));

        $this->assertCount(1, $completedevents);
        $event = $completedevents[0];

        $this->assertSame((int) $session->id, (int) $event->objectid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertEquals(40.0, (float) $event->other['aigrade']);
        $this->assertTrue($event->other['earlyexit']);

        $this->assertStringContainsString("early exit: yes", $event->get_description());
        $this->assertStringContainsString("AI grade: 40", $event->get_description());
    }

    /**
     * Test report_viewed event in class report mode.
     */
    public function test_report_viewed_class_mode(): void {
        [, $cm, $context, $instance, , $teacher] = $this->create_activity_fixture();

        $this->setUser($teacher);
        $sink = $this->redirectEvents();

        $event = report_viewed::create([
            'objectid' => $instance->id,
            'context'  => $context,
            'other'    => [
                'mode' => 'class',
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_viewed::class, $events[0]);
        $event = $events[0];

        $this->assertSame((int) $instance->id, (int) $event->objectid);
        $this->assertSame((int) $teacher->id, (int) $event->userid);
        $this->assertNull($event->relateduserid);
        $this->assertSame('class', $event->other['mode']);
        $this->assertArrayNotHasKey('sessionid', $event->other);

        $this->assertSame(get_string('event:report_viewed', 'mod_aidialogue'), $event->get_name());
        $this->assertStringContainsString("viewed the class report", $event->get_description());
        $this->assertTrue($event->get_url()->compare(new \moodle_url('/mod/aidialogue/report.php', ['id' => $cm->id])));
    }

    /**
     * Test report_viewed event in session review mode has activity objectid and sessionid in other.
     */
    public function test_report_viewed_review_mode(): void {
        [, $cm, $context, $instance, $student, $teacher, $config] = $this->create_activity_fixture();

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        $this->setUser($teacher);
        $sink = $this->redirectEvents();

        $event = report_viewed::create([
            'objectid'      => $instance->id,
            'context'       => $context,
            'relateduserid' => $student->id,
            'other'         => [
                'mode'      => 'review',
                'sessionid' => $session->id,
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_viewed::class, $events[0]);
        $event = $events[0];

        $this->assertSame((int) $instance->id, (int) $event->objectid);
        $this->assertSame((int) $teacher->id, (int) $event->userid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame('review', $event->other['mode']);
        $this->assertSame((int) $session->id, (int) $event->other['sessionid']);

        $this->assertStringContainsString("viewed the session review for user with id '{$student->id}'", $event->get_description());
        $this->assertTrue($event->get_url()->compare(new \moodle_url('/mod/aidialogue/review.php', [
            'id' => $cm->id,
            'session' => $session->id,
        ])));
    }

    /**
     * Test grade_updated event when a previous grade exists.
     */
    public function test_grade_updated_event(): void {
        [, $cm, $context, $instance, $student, $teacher, $config] = $this->create_activity_fixture();

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        $this->setUser($teacher);
        $sink = $this->redirectEvents();

        $event = grade_updated::create([
            'objectid'      => $session->id,
            'context'       => $context,
            'relateduserid' => $student->id,
            'other'         => [
                'oldgrade' => 70.0,
                'newgrade' => 92.5,
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(grade_updated::class, $events[0]);
        $event = $events[0];

        $this->assertSame((int) $session->id, (int) $event->objectid);
        $this->assertSame((int) $teacher->id, (int) $event->userid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame(70.0, (float) $event->other['oldgrade']);
        $this->assertSame(92.5, (float) $event->other['newgrade']);

        $this->assertSame(get_string('event:grade_updated', 'mod_aidialogue'), $event->get_name());
        $this->assertStringContainsString("from '70' to '92.5'", $event->get_description());
        $this->assertTrue($event->get_url()->compare(new \moodle_url('/mod/aidialogue/review.php', [
            'id' => $cm->id,
            'session' => $session->id,
        ])));
    }

    /**
     * Test grade_updated event description when oldgrade is null.
     */
    public function test_grade_updated_event_first_time(): void {
        [, , $context, $instance, $student, $teacher, $config] = $this->create_activity_fixture();

        $manager = new session_manager();
        $session = $manager->create_session((int) $instance->id, (int) $student->id, $config);

        $this->setUser($teacher);
        $event = grade_updated::create([
            'objectid'      => $session->id,
            'context'       => $context,
            'relateduserid' => $student->id,
            'other'         => [
                'oldgrade' => null,
                'newgrade' => 85.0,
            ],
        ]);

        $this->assertNull($event->other['oldgrade']);
        $this->assertStringContainsString("from 'none' to '85'", $event->get_description());
    }

    /**
     * Test event validation rules enforce required fields and handle missing other safely.
     */
    public function test_event_validations(): void {
        [, , $context, $instance, $student] = $this->create_activity_fixture();

        // 1. session_started requires relateduserid.
        try {
            session_started::create([
                'objectid' => 1,
                'context'  => $context,
            ]);
            $this->fail('Expected coding_exception for missing relateduserid in session_started');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("relateduserid", $e->getMessage());
        }

        // 2. session_completed requires aigrade and earlyexit.
        try {
            session_completed::create([
                'objectid'      => 1,
                'context'       => $context,
                'relateduserid' => $student->id,
                'other'         => ['earlyexit' => false],
            ]);
            $this->fail('Expected coding_exception for missing aigrade in session_completed');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("aigrade", $e->getMessage());
        }

        try {
            session_completed::create([
                'objectid'      => 1,
                'context'       => $context,
                'relateduserid' => $student->id,
                'other'         => ['aigrade' => 80.0],
            ]);
            $this->fail('Expected coding_exception for missing earlyexit in session_completed');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("earlyexit", $e->getMessage());
        }

        // 2b. session_completed with missing other array.
        try {
            session_completed::create([
                'objectid'      => 1,
                'context'       => $context,
                'relateduserid' => $student->id,
            ]);
            $this->fail('Expected coding_exception when other is omitted in session_completed');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("aigrade", $e->getMessage());
        }

        // 3. report_viewed requires valid mode.
        try {
            report_viewed::create([
                'objectid' => $instance->id,
                'context'  => $context,
                'other'    => ['mode' => 'invalid_mode'],
            ]);
            $this->fail('Expected coding_exception for invalid mode in report_viewed');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("mode", $e->getMessage());
        }

        // 3b. report_viewed review mode requires sessionid.
        try {
            report_viewed::create([
                'objectid' => $instance->id,
                'context'  => $context,
                'other'    => ['mode' => 'review'],
            ]);
            $this->fail('Expected coding_exception for missing sessionid in review mode');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("sessionid", $e->getMessage());
        }

        // 3c. report_viewed with missing other array.
        try {
            report_viewed::create([
                'objectid' => $instance->id,
                'context'  => $context,
            ]);
            $this->fail('Expected coding_exception when other is omitted in report_viewed');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("mode", $e->getMessage());
        }

        // 4. grade_updated requires newgrade.
        try {
            grade_updated::create([
                'objectid'      => 1,
                'context'       => $context,
                'relateduserid' => $student->id,
                'other'         => ['oldgrade' => 50.0],
            ]);
            $this->fail('Expected coding_exception for missing newgrade in grade_updated');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("newgrade", $e->getMessage());
        }

        // 4b. grade_updated with missing other array.
        try {
            grade_updated::create([
                'objectid'      => 1,
                'context'       => $context,
                'relateduserid' => $student->id,
            ]);
            $this->fail('Expected coding_exception when other is omitted in grade_updated');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString("newgrade", $e->getMessage());
        }
    }

    /**
     * Test event restore objectid and other mappings.
     */
    public function test_event_mappings(): void {
        $this->assertEquals(
            ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session'],
            session_started::get_objectid_mapping()
        );
        $this->assertEquals(
            ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session'],
            session_completed::get_objectid_mapping()
        );
        $this->assertEquals(
            ['db' => 'aidialogue', 'restore' => 'aidialogue'],
            report_viewed::get_objectid_mapping()
        );
        $this->assertEquals(
            ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session'],
            grade_updated::get_objectid_mapping()
        );
        $this->assertEquals(
            ['sessionid' => ['db' => 'aidialogue_session', 'restore' => 'aidialogue_session']],
            report_viewed::get_other_mapping()
        );
    }
}
