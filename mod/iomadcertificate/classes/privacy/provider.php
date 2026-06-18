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

namespace mod_iomadcertificate\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for mod_iomadcertificate.
 *
 * @package    mod_iomadcertificate
 * @copyright  2024 Iomad
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'iomadcertificate_issues',
            [
                'userid' => 'privacy:metadata:iomadcertificate_issues:userid',
                'iomadcertificateid' => 'privacy:metadata:iomadcertificate_issues:iomadcertificateid',
                'code' => 'privacy:metadata:iomadcertificate_issues:code',
                'timecreated' => 'privacy:metadata:iomadcertificate_issues:timecreated',
            ],
            'privacy:metadata:iomadcertificate_issues'
        );
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {iomadcertificate} ic ON ic.id = cm.instance
                  JOIN {iomadcertificate_issues} ici ON ici.iomadcertificateid = ic.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                 WHERE ici.userid = :userid";
        $params = [
            'modname' => 'iomadcertificate',
            'modlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT ici.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {iomadcertificate} ic ON ic.id = cm.instance
                  JOIN {iomadcertificate_issues} ici ON ici.iomadcertificateid = ic.id
                 WHERE cm.id = :cmid";
        $params = ['modname' => 'iomadcertificate', 'cmid' => $context->instanceid];
        $userlist->add_from_sql('userid', $sql, $params);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('iomadcertificate', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $issues = $DB->get_records('iomadcertificate_issues',
                ['iomadcertificateid' => $cm->instance, 'userid' => $user->id]);
            foreach ($issues as $issue) {
                $issue->timecreated = transform::datetime($issue->timecreated);
                $data = helper::get_context_data($context, $user);
                writer::with_context($context)->export_data([], $data);
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:iomadcertificate_issues', 'mod_iomadcertificate')],
                    $issue
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('iomadcertificate', $context->instanceid);
        if ($cm) {
            $DB->delete_records('iomadcertificate_issues', ['iomadcertificateid' => $cm->instance]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('iomadcertificate', $context->instanceid);
            if ($cm) {
                $DB->delete_records('iomadcertificate_issues',
                    ['iomadcertificateid' => $cm->instance, 'userid' => $user->id]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('iomadcertificate', $context->instanceid);
        if (!$cm) {
            return;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge(['iomadcertificateid' => $cm->instance], $inparams);
        $DB->delete_records_select('iomadcertificate_issues',
            "iomadcertificateid = :iomadcertificateid AND userid $insql", $params);
    }
}
