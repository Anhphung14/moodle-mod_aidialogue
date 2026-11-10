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

namespace mod_aidialogue\local;

/**
 * Decides which students a teacher may see in the class report and the session review page.
 *
 * Follows the activity's group mode: with separate groups, a teacher without the
 * moodle/site:accessallgroups capability only sees the students of their own groups.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_access {
    /** @var string User fields the class report needs to list a student. */
    private const STUDENT_FIELDS = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, '
        . 'u.middlename, u.alternatename';

    /**
     * Return the enrolled students the current user may see, optionally limited to one group.
     *
     * Students are the users who can open the activity but cannot view its report.
     *
     * @param \cm_info $cm The activity.
     * @param \context_module $context Its context.
     * @param int $groupid Group to limit the list to, or 0 for all groups the user may see.
     * @return \stdClass[] Users keyed by id, ordered by last name.
     */
    public static function get_students(\cm_info $cm, \context_module $context, int $groupid = 0): array {
        // With separate groups, no group means no students, unless the user may access all groups.
        $separate = groups_get_activity_groupmode($cm) == SEPARATEGROUPS;
        if ($separate && !$groupid && !has_capability('moodle/site:accessallgroups', $context)) {
            return [];
        }

        $users = get_enrolled_users(
            $context,
            'mod/aidialogue:view',
            $groupid,
            self::STUDENT_FIELDS,
            'u.lastname ASC, u.firstname ASC',
        );
        $reporters = get_enrolled_users($context, 'mod/aidialogue:viewreport', 0, 'u.id');

        return array_filter($users, fn($user) => !isset($reporters[$user->id]));
    }

    /**
     * Whether the current user may see the sessions of a student.
     *
     * @param \stdClass $course The course.
     * @param \cm_info $cm The activity.
     * @param int $userid The student.
     * @return bool
     */
    public static function can_view_user(\stdClass $course, \cm_info $cm, int $userid): bool {
        return groups_user_groups_visible($course, $userid, $cm);
    }
}
