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

/**
 * Tests for how the activity declares itself to Moodle (features and capabilities).
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_supports')]
final class lib_test extends \advanced_testcase {
    /**
     * The activity is listed as an assessment, because it has criteria, grades and reports.
     */
    public function test_purpose_is_assessment(): void {
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, plugin_supports('mod', 'aidialogue', FEATURE_MOD_PURPOSE));
    }

    /**
     * Data provider: capability and whether it must carry the personal data risk flag.
     *
     * @return array
     */
    public static function capability_risk_provider(): array {
        return [
            'viewreport shows private student conversations' => ['mod/aidialogue:viewreport', true],
            'view only opens the activity' => ['mod/aidialogue:view', false],
        ];
    }

    /**
     * Capabilities that expose private student data carry RISK_PERSONAL in db/access.php.
     *
     * @param string $capability Capability name.
     * @param bool $expectrisk Whether RISK_PERSONAL is expected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('capability_risk_provider')]
    public function test_capability_risk_flag(string $capability, bool $expectrisk): void {
        global $CFG;

        $capabilities = [];
        require($CFG->dirroot . '/mod/aidialogue/db/access.php');

        $this->assertArrayHasKey($capability, $capabilities);
        $risk = $capabilities[$capability]['riskbitmask'] ?? 0;
        $this->assertSame($expectrisk, (bool) ($risk & RISK_PERSONAL));
    }
}
