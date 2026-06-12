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
 * Create user form for IOMAD company admin block.
 *
 * @package    block_iomad_company_admin
 * @copyright  2021 Derick Turner
 * @author     Derick Turner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_iomad_company_admin\forms;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class user_edit_form extends \moodleform {

    protected function definition() {
        global $DB, $CFG;

        $mform = $this->_form;
        $companyid = $this->_customdata['companyid'] ?? 0;
        $departmentid = $this->_customdata['departmentid'] ?? 0;
        $licenseid = $this->_customdata['licenseid'] ?? 0;

        // Page title
        $mform->addElement('header', 'general', get_string('createuser', 'block_iomad_company_admin'));

        // Intro text explaining what is required
        $introtext = get_string('createuser_intro', 'block_iomad_company_admin',
            '<strong>' . get_string('requiredfields', 'moodle') . '</strong>');
        $mform->addElement('html', '<div class="alert alert-info">' . $introtext . '</div>');

        // REQUIRED INFORMATION SECTION
        $mform->addElement('header', 'required_info', get_string('required_information', 'block_iomad_company_admin'));

        // First Name - Required
        $mform->addElement('text', 'firstname', get_string('firstname'), array('maxlength' => 100, 'size' => 50));
        $mform->setType('firstname', PARAM_TEXT);
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');

        // Last Name - Required
        $mform->addElement('text', 'lastname', get_string('lastname'), array('maxlength' => 100, 'size' => 50));
        $mform->setType('lastname', PARAM_TEXT);
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');

        // Email Address - Required
        $mform->addElement('email', 'email', get_string('email'), array('maxlength' => 100, 'size' => 50));
        $mform->setType('email', PARAM_EMAIL);
        $mform->addRule('email', get_string('required'), 'required', null, 'client');
        $mform->addRule('email', get_string('invalidemail'), 'email', null, 'client');

        // Role to Assign - Optional
        $roles = array(
            0 => get_string('none'),
            1 => get_string('companymanager', 'block_iomad_company_admin'),
            2 => get_string('departmentmanager', 'block_iomad_company_admin'),
            3 => get_string('coursecreator', 'block_iomad_company_admin'),
        );
        $mform->addElement('select', 'managertype', get_string('role_to_assign', 'block_iomad_company_admin'), $roles);
        $mform->setType('managertype', PARAM_INT);
        $mform->setDefault('managertype', 0);

        // Educator checkbox - Optional
        $mform->addElement('checkbox', 'educator', get_string('educator', 'block_iomad_company_admin'));
        $mform->setType('educator', PARAM_INT);

        // COURSE ASSIGNMENT SECTION
        $mform->addElement('header', 'courses_info', get_string('assign_courses', 'block_iomad_company_admin'));
        $mform->addElement('html', '<p class="form-text">' . get_string('courses_select_info', 'block_iomad_company_admin') . '</p>');

        // Get available courses with seat counts
        $courses = $this->get_available_courses($companyid);

        if (!empty($courses)) {
            foreach ($courses as $course) {
                $seatinfo = $course->available_seats . ' / ' . $course->total_seats . ' ' . get_string('seats', 'block_iomad_company_admin');
                $label = $course->fullname . ' (' . $seatinfo . ')';
                $mform->addElement('checkbox', 'currentcourses[' . $course->id . ']', $label);
                $mform->setType('currentcourses[' . $course->id . ']', PARAM_INT);
            }
        } else {
            $mform->addElement('static', 'no_courses', '', get_string('no_courses_available', 'block_iomad_company_admin'));
        }

        // ADVANCED OPTIONAL SECTION - Collapsed by default
        $mform->addElement('header', 'advanced_info', get_string('advanced_optional', 'block_iomad_company_admin'));
        $mform->setExpanded('advanced_info', false);

        // New Password - Optional
        $mform->addElement('password', 'newpassword', get_string('newpassword_optional', 'block_iomad_company_admin'),
            array('maxlength' => 32, 'size' => 12, 'autocomplete' => 'off'));
        $mform->setType('newpassword', PARAM_RAW);
        $mform->addElement('static', 'password_note', '', get_string('password_optional_note', 'block_iomad_company_admin'));

        // Username - Optional
        $mform->addElement('text', 'username', get_string('username_optional', 'block_iomad_company_admin'),
            array('maxlength' => 100, 'size' => 50));
        $mform->setType('username', PARAM_USERNAME);
        $mform->addElement('static', 'username_note', '', get_string('username_optional_note', 'block_iomad_company_admin'));

        // Institution
        $mform->addElement('text', 'institution', get_string('institution'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('institution', PARAM_TEXT);

        // Department
        $mform->addElement('text', 'department', get_string('department'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('department', PARAM_TEXT);

        // City
        $mform->addElement('text', 'city', get_string('city'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('city', PARAM_TEXT);

        // Country
        $countries = get_string_manager()->load_component_strings('core', current_language());
        $countrylist = get_string_manager()->load_component_strings('langconfig', current_language());
        $country_array = array();
        if (!empty($CFG->countries)) {
            $country_array = get_countries();
        }
        $mform->addElement('select', 'country', get_string('country'), $country_array);
        $mform->setType('country', PARAM_ALPHANUMEXT);

        // Hidden fields to preserve context
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);

        $mform->addElement('hidden', 'deptid', $departmentid);
        $mform->setType('deptid', PARAM_INT);

        $mform->addElement('hidden', 'licenseid', $licenseid);
        $mform->setType('licenseid', PARAM_INT);

        // Submit buttons
        $this->add_action_buttons(true, get_string('createuser', 'block_iomad_company_admin'));
    }

    /**
     * Get available courses with seat counts for the company.
     *
     * @param int $companyid Company ID
     * @return array Array of course objects with available seat information
     */
    protected function get_available_courses($companyid) {
        global $DB;

        $sql = "SELECT DISTINCT c.id, c.fullname,
                       COALESCE(cl.allocation - cl.used, 0) as available_seats,
                       COALESCE(cl.allocation, 0) as total_seats
                FROM {course} c
                INNER JOIN {companylicense_courses} clc ON c.id = clc.courseid
                INNER JOIN {companylicense} cl ON clc.licenseid = cl.id
                WHERE cl.companyid = :companyid AND cl.allocation > 0
                ORDER BY c.fullname ASC";

        $courses = $DB->get_records_sql($sql, array('companyid' => $companyid));
        return $courses ?: array();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Additional validation for email uniqueness can be added if needed
        if (!empty($data['email'])) {
            global $DB;
            if ($DB->record_exists('user', array('email' => $data['email'], 'deleted' => 0))) {
                $errors['email'] = get_string('emailexists', 'block_iomad_company_admin');
            }
        }

        return $errors;
    }
}