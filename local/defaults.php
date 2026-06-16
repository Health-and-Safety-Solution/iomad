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

// Force login to the site to be required.
// Skip in automated-test environments: core PHPUnit/Behat tests exercise
// anonymous (not-logged-in) access and assume Moodle's stock forcelogin=0
// default. Applying forcelogin=1 to the test site makes has_capability()
// short-circuit to false for the guest/not-logged-in user, breaking ~21 core
// course/category visibility tests. Production installs are unaffected.
if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING)) {
    $defaults['moodle']['forcelogin'] = 1;
}
