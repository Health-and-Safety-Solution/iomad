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

namespace local_iomad_learningpath\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context_system;
use context_user;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for local_iomad_learningpath.
 *
 * @package    local_iomad_learningpath
 * @copyright  2024 Iomad
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'iomad_learningpathuser',
            [
                'pathid' => 'privacy:metadata:iomad_learningpathuser:pathid',
                'userid' => 'privacy:metadata:iomad_learningpathuser:userid',
            ],
            'privacy:metadata:iomad_learningpathuser'
        );
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {iomad_learningpathuser} lpu
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel
                 WHERE lpu.userid = :userid";
        $params = ['contextlevel' => CONTEXT_SYSTEM, 'userid' => $userid];
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', "SELECT userid FROM {iomad_learningpathuser}", []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $records = $DB->get_records('iomad_learningpathuser', ['userid' => $user->id]);
            if ($records) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:iomad_learningpathuser', 'local_iomad_learningpath')],
                    (object) ['paths' => array_values($records)]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('iomad_learningpathuser');
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('iomad_learningpathuser', ['userid' => $user->id]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('iomad_learningpathuser', "userid $insql", $inparams);
    }
}
