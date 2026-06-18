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
 * Assign grade merge tests.
 *
 * @package    tool_iomadmerge
 * @copyright  Derick Turner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/fixtures/testable_assign.php');

/**
 * Class tool_iomadmerge_assign_testcase
 */
class tool_iomadmerge_assign_testcase extends \advanced_testcase {

    /** @var int */
    const DEFAULT_STUDENT_COUNT = 3;
    /** @var int */
    const DEFAULT_TEACHER_COUNT = 2;
    /** @var int */
    const DEFAULT_EDITING_TEACHER_COUNT = 2;
    /** @var int */
    const GROUP_COUNT = 6;

    /** @var \stdClass */
    protected $course = null;
    /** @var array */
    protected $teachers = null;
    /** @var array */
    protected $editingteachers = null;
    /** @var array */
    protected $students = null;
    /** @var array */
    protected $groups = null;

    /**
     * Create a course with teachers, editing teachers and students enrolled.
     */
    public function setUp(): void {
        global $CFG, $DB;
        require_once("$CFG->dirroot/admin/tool/iomadmerge/lib/iomadmergetool.php");

        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));

        $this->teachers = array();
        for ($i = 0; $i < self::DEFAULT_TEACHER_COUNT; $i++) {
            array_push($this->teachers, $this->getDataGenerator()->create_user());
        }
        $this->editingteachers = array();
        for ($i = 0; $i < self::DEFAULT_EDITING_TEACHER_COUNT; $i++) {
            array_push($this->editingteachers, $this->getDataGenerator()->create_user());
        }
        $this->students = array();
        for ($i = 0; $i < self::DEFAULT_STUDENT_COUNT; $i++) {
            array_push($this->students, $this->getDataGenerator()->create_user());
        }
        $this->groups = array();
        for ($i = 0; $i < self::GROUP_COUNT; $i++) {
            array_push($this->groups, $this->getDataGenerator()->create_group(array('courseid' => $this->course->id)));
        }

        $teacherrole = $DB->get_record('role', array('shortname' => 'teacher'));
        foreach ($this->teachers as $i => $teacher) {
            $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, $teacherrole->id);
            groups_add_member($this->groups[$i % self::GROUP_COUNT], $teacher);
        }
        $editingteacherrole = $DB->get_record('role', array('shortname' => 'editingteacher'));
        foreach ($this->editingteachers as $i => $editingteacher) {
            $this->getDataGenerator()->enrol_user($editingteacher->id, $this->course->id, $editingteacherrole->id);
            groups_add_member($this->groups[$i % self::GROUP_COUNT], $editingteacher);
        }
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        foreach ($this->students as $i => $student) {
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, $studentrole->id);
            groups_add_member($this->groups[$i % self::GROUP_COUNT], $student);
        }
    }

    /**
     * Convenience function to create a testable instance of an assignment.
     *
     * @param array $params parameters to pass to the generator.
     * @return \mod_assign_testable_assign testable wrapper around the assign class.
     */
    protected function create_instance($params = array()) {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        if (!isset($params['course'])) {
            $params['course'] = $this->course->id;
        }
        $instance = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = \context_module::instance($cm->id);
        return new \mod_assign_testable_assign($context, $cm, $this->course);
    }

    /**
     * Test merging two users where one has submitted an assignment and the other
     * has no.
     * @group tool_iomadmerge
     * @group tool_iomadmerge_assign
     */
    public function test_mergenonconflictingassigngrades() {
        global $DB;

        $this->setUser($this->editingteachers[0]);
        $assign = $this->create_instance();

        $this->setUser($this->teachers[0]);

        // Give a grade to student 1.
        $data = new stdClass();
        $data->grade = '75.0';
        $assign->testable_apply_grade_to_user($data, $this->students[1]->id, 0);

        // Check initial state - student 0 has no grade, student 1 has 75.00.
        $this->assertEquals(false, $assign->testable_is_graded($this->students[0]->id));
        $this->assertEquals(true, $assign->testable_is_graded($this->students[1]->id));
        $this->assertEquals('75.00', $this->get_user_assign_grade($this->students[1], $assign, $this->course));
        $this->assertEquals('-', $this->get_user_assign_grade($this->students[0], $assign, $this->course));

        // Merge student 1 into student 0.
        $mut = new IomadMergeTool();
        $mut->merge($this->students[0]->id, $this->students[1]->id);

        // Student 0 should now have a grade of 75.00.
        $this->assertEquals(true, $assign->testable_is_graded($this->students[0]->id));
        $this->assertEquals('75.00', $this->get_user_assign_grade($this->students[0], $assign, $this->course));

        // Student 1 should now be suspended.
        $user_remove = $DB->get_record('user', array('id' => $this->students[1]->id));
        $this->assertEquals(1, $user_remove->suspended);
    }

    /**
     * Utility method to get the grade for a user.
     * @param \stdClass $user
     * @param \mod_assign_testable_assign $assign
     * @param \stdClass $course
     * @return string
     */
    private function get_user_assign_grade($user, $assign, $course) {
        $gradebookgrades = \grade_get_grades($course->id, 'mod', 'assign', $assign->get_instance()->id, $user->id);
        $gradebookitem   = array_shift($gradebookgrades->items);
        $grade     = $gradebookitem->grades[$user->id];
        return $grade->str_grade;
    }
}
