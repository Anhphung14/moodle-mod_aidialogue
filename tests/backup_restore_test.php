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
 * Backup and restore tests for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue;

use core_courseformat\formatactions;
use restore_date_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Backup and restore tests for mod_aidialogue.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_aidialogue_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_aidialogue_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_aidialogue_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_aidialogue_activity_structure_step::class)]
final class backup_restore_test extends restore_date_testcase {
    /**
     * Creates an activity with a known knowledge text and two criteria (Analyse and Custom).
     *
     * @param int $courseid The course id.
     * @param array $extra Extra activity fields.
     * @return \stdClass The activity record with cmid.
     */
    private function create_dialogue(int $courseid, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_module('aidialogue', $extra + [
            'course' => $courseid,
            'knowledgetext' => 'Test knowledge text.',
            'criteria' => [
                ['description' => 'Explains the main idea.', 'bloomslevel' => 1, 'minturns' => 1, 'maxturns' => 2],
                ['description' => 'Gives an own example.', 'bloomslevel' => 8, 'minturns' => 1, 'maxturns' => 3],
            ],
        ]);
    }

    /**
     * Adds one student session with an opening turn, a criterion turn, a closing turn and one result per criterion.
     *
     * @param \stdClass $aidialogue The activity record.
     * @param int $userid The student id.
     * @return \stdClass The session record.
     */
    private function add_session(\stdClass $aidialogue, int $userid): \stdClass {
        global $DB;

        $criteria = array_values($DB->get_records('aidialogue_criterion', ['aidialogueid' => $aidialogue->id], 'sortorder'));
        $now = time();

        $session = (object) [
            'aidialogueid' => $aidialogue->id,
            'userid' => $userid,
            'attemptnumber' => 1,
            'status' => 'complete',
            'studentreport' => 'Student report text',
            'teacherreport' => 'Teacher report text',
            'aigrade' => 80,
            'teachergrade' => 90,
            'timecreated' => $now,
            'timestarted' => $now,
            'timefinished' => $now,
            'earlyexit' => 0,
            'timemodified' => $now,
        ];
        $session->id = $DB->insert_record('aidialogue_session', $session);

        // The opening and closing turns have no criterion.
        $turns = [
            [null, 'ai', 'session_open', 'Hello'],
            [$criteria[0]->id, 'student', null, 'My answer'],
            [null, 'ai', 'session_close', 'Bye'],
        ];
        foreach ($turns as $number => [$criterionid, $role, $move, $content]) {
            $DB->insert_record('aidialogue_turn', (object) [
                'sessionid' => $session->id,
                'criterionid' => $criterionid,
                'role' => $role,
                'move' => $move,
                'content' => $content,
                'turnnumber' => $number + 1,
                'timecreated' => $now,
            ]);
        }

        foreach ($criteria as $criterion) {
            $DB->insert_record('aidialogue_criterion_result', (object) [
                'sessionid' => $session->id,
                'criterionid' => $criterion->id,
                'status' => 'met',
                'evidence' => 'Evidence for ' . $criterion->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return $session;
    }

    /**
     * A course backup with user data restores the activity, criteria, sessions, turns and results.
     */
    public function test_course_backup_restore_with_user_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $aidialogue = $this->create_dialogue($course->id, [
            'maxattempts' => 3,
            'completionpassorexhaust' => 1,
        ]);
        $session = $this->add_session($aidialogue, $student->id);

        $newcourseid = $this->backup_and_restore($course);

        $new = $DB->get_record('aidialogue', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals($aidialogue->id, $new->id);
        $this->assertEquals($aidialogue->name, $new->name);
        $this->assertEquals('Test knowledge text.', $new->knowledgetext);
        $this->assertEquals(3, $new->maxattempts);
        $this->assertEquals(1, $new->completionpassorexhaust);

        // Criteria are copied with new ids and the same content.
        $newcriteria = array_values($DB->get_records('aidialogue_criterion', ['aidialogueid' => $new->id], 'sortorder'));
        $this->assertCount(2, $newcriteria);
        $this->assertEquals('Explains the main idea.', $newcriteria[0]->description);
        $this->assertEquals(8, $newcriteria[1]->bloomslevel);
        $oldcriterionids = $DB->get_fieldset_select('aidialogue_criterion', 'id', 'aidialogueid = ?', [$aidialogue->id]);
        $this->assertEmpty(array_intersect($oldcriterionids, array_column($newcriteria, 'id')));

        // The session keeps the user and all its fields.
        $newsession = $DB->get_record('aidialogue_session', ['aidialogueid' => $new->id], '*', MUST_EXIST);
        $this->assertNotEquals($session->id, $newsession->id);
        $this->assertEquals($student->id, $newsession->userid);
        $this->assertEquals('Student report text', $newsession->studentreport);
        $this->assertEquals('Teacher report text', $newsession->teacherreport);
        $this->assertEquals(80, $newsession->aigrade);
        $this->assertEquals(90, $newsession->teachergrade);

        // Turns point at the new session. Criterion turns use the new criterion, other turns keep NULL.
        $turns = array_values($DB->get_records('aidialogue_turn', ['sessionid' => $newsession->id], 'turnnumber'));
        $this->assertCount(3, $turns);
        $this->assertNull($turns[0]->criterionid);
        $this->assertEquals($newcriteria[0]->id, $turns[1]->criterionid);
        $this->assertNull($turns[2]->criterionid);
        $this->assertEquals('session_close', $turns[2]->move);

        // One result per new criterion, none left pointing at the old ones.
        $results = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $newsession->id], '', 'criterionid, status');
        $this->assertEqualsCanonicalizing(array_column($newcriteria, 'id'), array_keys($results));

        // The original data is untouched.
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $aidialogue->id]));
    }

    /**
     * Build the restore step with the id mapping stubbed, so the skip logic can be tested without a second site.
     *
     * A same site restore always maps or creates every user, so a user that cannot be mapped only happens
     * when restoring on another site.
     *
     * @param array $mappings Map of item name => old id => new id. A missing entry means not mapped.
     * @param array $parents Map of element name => id of the last restored parent (null, 0 or an id).
     * @param array $mapped Filled with [item, old id, new id] for every set_mapping() call.
     * @return \restore_aidialogue_activity_structure_step A partial mock of the step.
     */
    private function make_restore_step(
        array $mappings,
        array $parents,
        array &$mapped = [],
    ): \restore_aidialogue_activity_structure_step {
        global $CFG;
        require_once($CFG->dirroot . '/mod/aidialogue/backup/moodle2/restore_aidialogue_stepslib.php');

        $step = $this->getMockBuilder(\restore_aidialogue_activity_structure_step::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_mappingid', 'get_new_parentid', 'set_mapping'])
            ->getMock();
        $step->method('get_mappingid')->willReturnCallback(
            fn($item, $oldid) => $mappings[$item][$oldid] ?? false
        );
        $step->method('get_new_parentid')->willReturnCallback(
            fn($item) => $parents[$item] ?? null
        );
        $step->method('set_mapping')->willReturnCallback(function ($item, $oldid, $newid) use (&$mapped) {
            $mapped[] = [$item, $oldid, $newid];
        });
        return $step;
    }

    /**
     * Call one of the protected process_* methods of the restore step.
     *
     * @param \restore_aidialogue_activity_structure_step $step The step.
     * @param string $method Method name.
     * @param array $data Data as read from the XML.
     */
    private function call_process(\restore_aidialogue_activity_structure_step $step, string $method, array $data): void {
        (new \ReflectionMethod($step, $method))->invoke($step, $data);
    }

    /**
     * A session of a user who cannot be mapped is skipped and its children are told there is no parent.
     */
    public function test_restore_skips_session_of_unmapped_user(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $aidialogue = $this->create_dialogue($course->id);
        $student = $this->getDataGenerator()->create_user();

        // Session 11 belongs to a user without a mapping, session 12 to a mapped student.
        $mapped = [];
        $step = $this->make_restore_step(
            ['user' => [500 => (int) $student->id]],
            ['aidialogue' => (int) $aidialogue->id],
            $mapped,
        );
        $base = [
            'attemptnumber' => 1,
            'status' => 'complete',
            'earlyexit' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $this->call_process($step, 'process_aidialogue_session', ['id' => 11, 'userid' => 999] + $base);
        $this->call_process($step, 'process_aidialogue_session', ['id' => 12, 'userid' => 500] + $base);

        // Only the mapped student has a session, and nothing was saved with user 0.
        $sessions = $DB->get_records('aidialogue_session', ['aidialogueid' => $aidialogue->id]);
        $this->assertCount(1, $sessions);
        $this->assertEquals($student->id, reset($sessions)->userid);

        // The skipped session is mapped to 0, so the children that follow it see no parent.
        $this->assertSame(['aidialogue_session', 11, 0], $mapped[0]);
        $this->assertSame('aidialogue_session', $mapped[1][0]);
        $this->assertEquals(12, $mapped[1][1]);
    }

    /**
     * Data provider: the children of a session that was skipped.
     *
     * @return array
     */
    public static function skipped_child_provider(): array {
        return [
            'turn' => ['process_aidialogue_turn', 'aidialogue_turn', [
                'role' => 'ai', 'move' => 'session_open', 'content' => 'Hello', 'turnnumber' => 1, 'timecreated' => 1,
            ]],
            'criterion result' => ['process_aidialogue_result', 'aidialogue_criterion_result', [
                'criterionid' => 21, 'status' => 'met', 'evidence' => 'x', 'timecreated' => 1, 'timemodified' => 1,
            ]],
        ];
    }

    /**
     * The turns and results of a skipped session are not restored, even when an earlier session was restored.
     *
     * @param string $method The process method.
     * @param string $table The table the child would be written to.
     * @param array $data Data of the child, as read from the XML.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipped_child_provider')]
    public function test_restore_skips_children_of_skipped_session(string $method, string $table, array $data): void {
        global $DB;

        // The parent id is 0, as set when the session was skipped. A criterion mapping exists for 21.
        $step = $this->make_restore_step(['aidialogue_criterion' => [21 => 77]], ['aidialogue_session' => 0]);
        $this->call_process($step, $method, ['id' => 1, 'criterionid' => 21] + $data);

        $this->assertEquals(0, $DB->count_records($table));
    }

    /**
     * A turn of a deleted criterion is restored without a criterion, and a result of a deleted criterion is skipped.
     */
    public function test_restore_handles_deleted_criterion(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $aidialogue = $this->create_dialogue($course->id);
        $this->add_session($aidialogue, $student->id);

        // Delete the first criterion. The student turn and one result still point at it.
        $deleted = $DB->get_record(
            'aidialogue_criterion',
            ['aidialogueid' => $aidialogue->id, 'sortorder' => 1],
            '*',
            IGNORE_MULTIPLE,
        );
        $DB->delete_records('aidialogue_criterion', ['id' => $deleted->id]);

        $newcourseid = $this->backup_and_restore($course);

        $new = $DB->get_record('aidialogue', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcriteria = $DB->get_records('aidialogue_criterion', ['aidialogueid' => $new->id]);
        $this->assertCount(1, $newcriteria);
        $newsession = $DB->get_record('aidialogue_session', ['aidialogueid' => $new->id], '*', MUST_EXIST);

        // All three turns are kept, and the turn of the deleted criterion has no criterion.
        $turns = array_values($DB->get_records('aidialogue_turn', ['sessionid' => $newsession->id], 'turnnumber'));
        $this->assertCount(3, $turns);
        $this->assertNull($turns[1]->criterionid);
        $this->assertSame('My answer', $turns[1]->content);

        // Only the result of the remaining criterion is kept.
        $results = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $newsession->id]);
        $this->assertCount(1, $results);
        $this->assertEquals(array_key_first($newcriteria), reset($results)->criterionid);
    }

    /**
     * Duplicating an activity copies the setup but not the student data.
     */
    public function test_duplicate_copies_setup_without_user_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $aidialogue = $this->create_dialogue($course->id);
        $this->add_session($aidialogue, $student->id);

        $newcm = formatactions::cm($course->id)->duplicate(cmid: $aidialogue->cmid);

        $this->assertNotEquals($aidialogue->cmid, $newcm->id);
        $new = $DB->get_record('aidialogue', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertNotEquals($aidialogue->id, $new->id);
        $this->assertEquals('Test knowledge text.', $new->knowledgetext);
        $this->assertEquals(2, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $new->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_session', ['aidialogueid' => $new->id]));

        // The original keeps its own data.
        $this->assertEquals(2, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $aidialogue->id]));
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $aidialogue->id]));
        $this->assertEquals(3, $DB->count_records('aidialogue_turn'));
    }

    /**
     * The activity declares that it supports backup.
     */
    public function test_supports_backup(): void {
        $this->assertTrue(plugin_supports('mod', 'aidialogue', FEATURE_BACKUP_MOODLE2));
    }
}
