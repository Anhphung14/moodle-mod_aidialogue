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

use mod_aidialogue\local\report_access;

/**
 * Tests for which students a teacher may see, depending on the activity's group mode.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(report_access::class)]
final class report_access_test extends \advanced_testcase {
    /**
     * Creates a course with two groups, three students, a teacher in group A and an editing teacher.
     *
     * Student 1 is in group A, student 2 in group B and student 3 in no group. The teacher without
     * editing rights has no moodle/site:accessallgroups, the editing teacher has it.
     *
     * @param int $groupmode The group mode of the activity (NOGROUPS, SEPARATEGROUPS or VISIBLEGROUPS).
     * @return array [course, cm, context, users by label, groups by label]
     */
    private function create_fixture(int $groupmode): array {
        global $DB;
        $this->resetAfterTest();
        set_config('aiurl', 'https://ai.example.invalid/v1', 'mod_aidialogue');
        set_config('aiapikey', 'not-a-real-key', 'mod_aidialogue');
        set_config('aimodel', 'test-model', 'mod_aidialogue');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $users = [
            'student1' => $generator->create_and_enrol($course, 'student'),
            'student2' => $generator->create_and_enrol($course, 'student'),
            'student3' => $generator->create_and_enrol($course, 'student'),
            'teacher' => $generator->create_and_enrol($course, 'teacher'),
            'editingteacher' => $generator->create_and_enrol($course, 'editingteacher'),
        ];
        $groups = [
            'A' => $generator->create_group(['courseid' => $course->id]),
            'B' => $generator->create_group(['courseid' => $course->id]),
        ];
        $generator->create_group_member(['groupid' => $groups['A']->id, 'userid' => $users['student1']->id]);
        $generator->create_group_member(['groupid' => $groups['A']->id, 'userid' => $users['teacher']->id]);
        $generator->create_group_member(['groupid' => $groups['B']->id, 'userid' => $users['student2']->id]);

        $aidialogue = $generator->create_module('aidialogue', ['course' => $course->id]);
        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $aidialogue->cmid]);
        rebuild_course_cache($course->id, true);
        $cm = get_fast_modinfo($course)->get_cm($aidialogue->cmid);

        return [$course, $cm, \context_module::instance($cm->id), $users, $groups];
    }

    /**
     * Data provider: group mode, who looks, which group is selected (A, B or none), and the students expected.
     *
     * @return array
     */
    public static function students_provider(): array {
        return [
            'separate groups: teacher with the group selected' => [SEPARATEGROUPS, 'teacher', 'A', ['student1']],
            'separate groups: teacher with no group selected sees nobody' => [SEPARATEGROUPS, 'teacher', '', []],
            'separate groups: editing teacher sees all groups' => [
                SEPARATEGROUPS, 'editingteacher', '', ['student1', 'student2', 'student3'],
            ],
            'separate groups: editing teacher with a group selected' => [SEPARATEGROUPS, 'editingteacher', 'B', ['student2']],
            'visible groups: teacher sees everybody' => [VISIBLEGROUPS, 'teacher', '', ['student1', 'student2', 'student3']],
            'visible groups: group selected' => [VISIBLEGROUPS, 'teacher', 'B', ['student2']],
            'no groups: teacher sees everybody' => [NOGROUPS, 'teacher', '', ['student1', 'student2', 'student3']],
        ];
    }

    /**
     * The student list follows the group mode, the selected group and the viewer's capabilities.
     *
     * @param int $groupmode Group mode of the activity.
     * @param string $viewer Label of the user who looks at the report.
     * @param string $group Label of the selected group, or '' for none.
     * @param string[] $expected Labels of the students that must be listed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('students_provider')]
    public function test_get_students(int $groupmode, string $viewer, string $group, array $expected): void {
        [, $cm, $context, $users, $groups] = $this->create_fixture($groupmode);
        $this->setUser($users[$viewer]);

        $students = report_access::get_students($cm, $context, $group === '' ? 0 : (int) $groups[$group]->id);

        $expectedids = array_map(fn($label) => (int) $users[$label]->id, $expected);
        $this->assertEqualsCanonicalizing($expectedids, array_map('intval', array_keys($students)));
    }

    /**
     * Teachers are never listed as students.
     */
    public function test_get_students_excludes_teachers(): void {
        [, $cm, $context, $users] = $this->create_fixture(NOGROUPS);
        $this->setUser($users['editingteacher']);

        $students = report_access::get_students($cm, $context);

        $this->assertArrayNotHasKey($users['teacher']->id, $students);
        $this->assertArrayNotHasKey($users['editingteacher']->id, $students);
    }

    /**
     * Data provider: group mode, who looks, whose sessions, and whether that is allowed.
     *
     * @return array
     */
    public static function view_user_provider(): array {
        return [
            'separate groups: student of the same group' => [SEPARATEGROUPS, 'teacher', 'student1', true],
            'separate groups: student of another group' => [SEPARATEGROUPS, 'teacher', 'student2', false],
            'separate groups: student in no group' => [SEPARATEGROUPS, 'teacher', 'student3', false],
            'separate groups: editing teacher sees every group' => [SEPARATEGROUPS, 'editingteacher', 'student2', true],
            'visible groups: student of another group' => [VISIBLEGROUPS, 'teacher', 'student2', true],
            'no groups: student of another group' => [NOGROUPS, 'teacher', 'student2', true],
        ];
    }

    /**
     * Opening another student's session is only allowed when that student is visible to the teacher.
     *
     * @param int $groupmode Group mode of the activity.
     * @param string $viewer Label of the user who looks.
     * @param string $student Label of the student whose session is opened.
     * @param bool $expected Whether it is allowed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('view_user_provider')]
    public function test_can_view_user(int $groupmode, string $viewer, string $student, bool $expected): void {
        [$course, $cm, , $users] = $this->create_fixture($groupmode);
        $this->setUser($users[$viewer]);

        $this->assertSame($expected, report_access::can_view_user($course, $cm, (int) $users[$student]->id));
    }
}
