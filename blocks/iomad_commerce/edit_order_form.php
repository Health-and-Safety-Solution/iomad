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
 * @package   block_iomad_commerce
 * @copyright 2021 Derick Turner
 * @author    Derick Turner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Script to let a user create a course for a particular company.
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/../iomad_company_admin/lib.php');
require_once(dirname(__FILE__) . '/../../course/lib.php');
require_once($CFG->dirroot.'/blocks/iomad_ecommerce/lib.php');
require_once($CFG->dirroot.'/local/iomad_xero/lib.php');
require_once($CFG->dirroot . '/local/iomad/lib/user.php');
require_once($CFG->dirroot . '/local/iomad/lib/company.php');

function iomad_order_get_cancellable_items(int $invoiceid, string $reference): array {
    global $DB;

    $sql = "SELECT ii.id,
                   ii.invoiceid,
                   ii.invoiceableitemid,
                   ii.invoiceableitemtype,
                   ii.license_allocation,
                   ii.quantity,
                   ii.price,
                   ii.currency,
                   css.name,
                   csc.courseid,
                   c.fullname AS coursefullname,
                   c.shortname AS courseshortname,
                   c.idnumber AS courseidnumber,
                   cl.id AS licenseid,
                   cl.companyid AS licensecompanyid,
                   cl.allocation,
                   cl.humanallocation,
                   cl.used
              FROM {invoiceitem} ii
         LEFT JOIN {course_shopsettings} css
                ON css.id = ii.invoiceableitemid
         LEFT JOIN {course_shopsettings_courses} csc
                ON csc.itemid = css.id
         LEFT JOIN {course} c
                ON c.id = csc.courseid
         LEFT JOIN {companylicense_courses} clc
                ON clc.courseid = csc.courseid
         LEFT JOIN {companylicense} cl
                ON cl.id = clc.licenseid
               AND cl.reference = :reference
             WHERE ii.invoiceid = :invoiceid
               AND ii.invoiceableitemtype NOT IN ('refundadjustment', 'creditnote')
               AND csc.courseid IS NOT NULL
          ORDER BY ii.id ASC";

    $items = $DB->get_records_sql($sql, ['invoiceid' => $invoiceid, 'reference' => $reference]);
    foreach ($items as $item) {
        $item->isinhouse = !empty($item->courseidnumber) && strpos((string)$item->courseidnumber, 'INH:') === 0;
        $item->assignedusers = [];
        if (!empty($item->licenseid) && !empty($item->courseid)) {
            $assignedsql = "SELECT clu.id,
                                   clu.userid,
                                   u.firstname,
                                   u.lastname,
                                   u.email
                              FROM {companylicense_users} clu
                              JOIN {user} u
                                ON u.id = clu.userid
                             WHERE clu.licenseid = :licenseid
                               AND clu.licensecourseid = :courseid
                               AND clu.userid > 0
                               AND (clu.timecompleted IS NULL OR clu.timecompleted = 0)
                          ORDER BY u.firstname ASC, u.lastname ASC";
            $item->assignedusers = $DB->get_records_sql($assignedsql, [
                'licenseid' => $item->licenseid,
                'courseid' => $item->courseid,
            ]);
        }
    }

    return $items;
}

function iomad_order_force_remove_user_from_course(int $userid, int $courseid): void {
    global $DB;

    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if ($coursecontext) {
        $DB->delete_records('role_assignments', [
            'contextid' => $coursecontext->id,
            'userid' => $userid,
        ]);
    }

    $enrolids = $DB->get_fieldset_select('enrol', 'id', 'courseid = ?', [$courseid]);
    if (!empty($enrolids)) {
        [$insql, $params] = $DB->get_in_or_equal($enrolids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $DB->delete_records_select('user_enrolments', "enrolid $insql AND userid = :userid", $params);
    }

    $DB->delete_records('email', [
        'userid' => $userid,
        'courseid' => $courseid,
        'templatename' => 'user_added_to_course',
        'sent' => null,
    ]);
}

function iomad_order_unassign_license_user(int $licenseid, int $courseid, int $userid, ?int $companyid = null): void {
    global $DB;

    iomad_order_force_remove_user_from_course($userid, $courseid);
    $DB->delete_records('companylicense_users', [
        'licenseid' => $licenseid,
        'licensecourseid' => $courseid,
        'userid' => $userid,
    ]);
    $DB->delete_records('local_iomad_track', [
        'licenseid' => $licenseid,
        'courseid' => $courseid,
        'userid' => $userid,
    ]);
    company::update_license_usage($licenseid);
}

function iomad_order_cancel_inhouse_course(stdClass $item, string $invoiceReference): void {
    global $DB, $CFG;

    if (empty($item->courseid)) {
        return;
    }

    $prefix = '[CANCELLED] ';
    if ($course = $DB->get_record('course', ['id' => $item->courseid])) {
        if (strpos((string)$course->fullname, $prefix) !== 0) {
            $course->fullname = $prefix . $course->fullname;
        }
        if (strpos((string)$course->shortname, $prefix) !== 0) {
            $course->shortname = $prefix . $course->shortname;
        }
        $DB->update_record('course', $course);
    }

    if ($shopitem = $DB->get_record('course_shopsettings', ['id' => $item->invoiceableitemid])) {
        if (strpos((string)$shopitem->name, $prefix) !== 0) {
            $shopitem->name = $prefix . $shopitem->name;
        }
        if (!empty($shopitem->short_description) && strpos((string)$shopitem->short_description, $prefix) !== 0) {
            $shopitem->short_description = $prefix . $shopitem->short_description;
        }
        $DB->update_record('course_shopsettings', $shopitem);
    }

    $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $coursecontext = context_course::instance($item->courseid);
    if (!empty($editingteacherroleid)) {
        $trainerids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
              WHERE ra.contextid = :contextid
                AND ra.roleid = :roleid",
            ['contextid' => $coursecontext->id, 'roleid' => $editingteacherroleid]
        );
        foreach ($trainerids as $trainerid) {
            $trainer = get_complete_user_data('id', $trainerid);
            if ($trainer) {
                iomad_order_force_remove_user_from_course($trainerid, (int)$item->courseid);
                $subject = 'In-house course cancelled: ' . ($item->coursefullname ?: $item->name);
                $message = 'The in-house course "' . ($item->coursefullname ?: $item->name) . '" linked to order reference '
                    . $invoiceReference . ' has been cancelled, so the trainer assignment has been removed.';
                email_to_user($trainer, core_user::get_support_user(), $subject, $message);
            }
        }
    }

    $trainingevents = $DB->get_records('trainingevent', ['course' => $item->courseid], 'id ASC');
    foreach ($trainingevents as $trainingevent) {
        $cm = get_coursemodule_from_instance('trainingevent', $trainingevent->id, $item->courseid, false, IGNORE_MISSING);
        if ($cm) {
            course_delete_module($cm->id);
        } else {
            $DB->delete_records('trainingevent', ['id' => $trainingevent->id]);
        }
    }

    if (!empty($item->licenseid)) {
        if (!empty($item->assignedusers)) {
            foreach ($item->assignedusers as $assigneduser) {
                iomad_order_unassign_license_user((int)$item->licenseid, (int)$item->courseid, (int)$assigneduser->userid, $item->licensecompanyid ? (int)$item->licensecompanyid : null);
            }
        }
        $DB->delete_records('local_iomad_track', ['licenseid' => $item->licenseid, 'courseid' => $item->courseid]);
        $DB->delete_records('companylicense_courses', ['licenseid' => $item->licenseid, 'courseid' => $item->courseid]);
        $DB->delete_records('companylicense', ['id' => $item->licenseid]);
    }
}

function iomad_order_reduce_open_course_place(stdClass $item, int $selecteduserid = 0): string {
    global $DB;

    if (empty($item->licenseid) || empty($item->courseid)) {
        return 'No active course place was found for this order item.';
    }

    $currentallocation = max(0, (int)$item->humanallocation);
    $assignedcount = is_array($item->assignedusers) ? count($item->assignedusers) : 0;

    if ($currentallocation <= 0) {
        return 'There are no remaining course places to cancel for this item.';
    }

    if ($selecteduserid > 0) {
        $validselection = false;
        foreach ($item->assignedusers as $assigneduser) {
            if ((int)$assigneduser->userid === $selecteduserid) {
                $validselection = true;
                break;
            }
        }
        if (!$validselection) {
            return 'Please choose a valid assigned delegate to unassign.';
        }
        iomad_order_unassign_license_user((int)$item->licenseid, (int)$item->courseid, $selecteduserid, $item->licensecompanyid ? (int)$item->licensecompanyid : null);
        $assignedcount--;
    } else if ($assignedcount >= $currentallocation) {
        return 'All course places are currently assigned. Please choose which delegate should be unassigned.';
    }

    $newallocation = $currentallocation - 1;
    if ($newallocation < $assignedcount) {
        return 'Unable to cancel this course place until a delegate has been unassigned.';
    }

    if ($newallocation <= 0) {
        $DB->delete_records('companylicense_courses', ['licenseid' => $item->licenseid, 'courseid' => $item->courseid]);
        $DB->delete_records('companylicense', ['id' => $item->licenseid]);
        $DB->delete_records('invoiceitem', ['id' => $item->id]);
    } else {
        $license = $DB->get_record('companylicense', ['id' => $item->licenseid], '*', MUST_EXIST);
        $license->allocation = $newallocation;
        $license->humanallocation = $newallocation;
        $license->used = $assignedcount;
        $DB->update_record('companylicense', $license);

        $invoiceitem = $DB->get_record('invoiceitem', ['id' => $item->id], '*', MUST_EXIST);
        $invoiceitem->license_allocation = $newallocation;
        $DB->update_record('invoiceitem', $invoiceitem);
    }

    return '';
}

\block_iomad_commerce\helper::require_commerce_enabled();
global $SESSION;

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$invoiceid = required_param('id', PARAM_INTEGER);

require_login();

$systemcontext = context_system::instance();

// Set the companyid
$companyid = iomad::get_my_companyid($systemcontext);
$companycontext = \core\context\company::instance($companyid);

if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
        $editmode = optional_param('editmode', 0, PARAM_BOOL);
        $cancelmode = optional_param('cancelmode', 0, PARAM_BOOL);
        $partialcancelmode = optional_param('partialcancelmode', 0, PARAM_BOOL);
        $cancelinvoice = optional_param('cancelinvoice', 0, PARAM_BOOL);
        $partialcancel = optional_param('partialcancel', 0, PARAM_BOOL);
        $partialcancelitemid = optional_param('partial_cancel_itemid', 0, PARAM_INT);
        $partialcanceluserid = optional_param('partial_cancel_userid', 0, PARAM_INT);
        $refundtype = optional_param('refundtype', '', PARAM_ALPHA);
        $refundamount = optional_param('refundamount', 0, PARAM_FLOAT);
} else {
        $editmode = 0;
        $cancelmode = 0;
        $partialcancelmode = 0;
        $cancelinvoice = 0;
        $partialcancel = 0;
        $partialcancelitemid = 0;
        $partialcanceluserid = 0;
        $refundtype = '';
        $refundamount = 0;
}

$company = new company($companyid);

$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);
if ($invoice->status == 'c') {
	$editmode = 0;
    $partialcancelmode = 0;
}
if($invoice->companyid != $companyid) {
        $SESSION->basketid = NULL;
        redirect($CFG->wwwroot . '/my', get_string('invoiceblongsToanotherCompany', 'block_iomad_ecommerce'), '', 'error');
}

if(iomad::has_capability('block/iomad_commerce:admin_view', $companycontext) == false) {
	if($companyid) {
		$companycontext = \core\context\company::instance($companyid);
		$permissiontoview = iomad::has_capability('block/iomad_ecommerce:userorder_view', $companycontext);
	} else {
		$permissiontoview = iomad::has_capability('block/iomad_ecommerce:userorder_view', $context);
	}

	if($permissiontoview) {
		$myorder = new moodle_url('/blocks/iomad_ecommerce/user_order.php');
		redirect($myorder);
	} else {
		echo $OUTPUT->header();
		echo $OUTPUT->notification('Thank you for your order. Your order has been received and our team will process the order and get back to you.', 'notifysuccess');
	        echo $OUTPUT->footer();
        	exit;
	}
}

iomad::require_capability('block/iomad_commerce:admin_view', $companycontext);
licenseUpdate();
$urlparams = array();
if ($returnurl) {
    $urlparams['returnurl'] = $returnurl;
}
//$companylist = new moodle_url('/blocks/iomad_commerce/orderlist.php', $urlparams);
$companylist = new moodle_url('/blocks/iomad_ecommerce/order.php', $urlparams);
//$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);

// Set the name for the page.
$linktext = get_string('orders', 'block_iomad_commerce');

// Set the url.
//$linkurl = new moodle_url('/blocks/iomad_commerce/orderlist.php');
$linkurl = new moodle_url('/blocks/iomad_ecommerce/order.php');
// Print the page header.
$PAGE->set_context($companycontext);
$PAGE->set_url(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => $editmode]));
$PAGE->set_pagelayout('base');
$PAGE->set_title($linktext);
$PAGE->set_heading(get_string('edit_invoice', 'block_iomad_commerce'));
$PAGE->navbar->add($linktext, $linkurl);
$PAGE->navbar->add(get_string('edit_invoice', 'block_iomad_commerce'));

if (empty($invoice->paymentid)) {
    $invoice->checkout_method = get_string('pp_historic', 'block_iomad_commerce');
    $invoice->pp_account = get_string('notapplicable', 'local_report_completion');
} else {
    $payment = $DB->get_record('payments', ['id' => $invoice->paymentid]);
    $invoice->checkout_method = get_string('pluginname', 'paygw_' . $payment->gateway);
    $accounts = \core_payment\helper::get_payment_accounts_menu($systemcontext);
    $invoice->pp_account = $accounts[$payment->accountid];

}

$porecord = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
if ($porecord) {
    $invoice->po_ref = $porecord->po;
}

$partialcancelitems = iomad_order_get_cancellable_items($invoiceid, (string)$invoice->reference);

if ($editmode) {
    if (empty($SESSION->order_edit_quantity_limits) || !is_array($SESSION->order_edit_quantity_limits)) {
        $SESSION->order_edit_quantity_limits = [];
    }
    if (empty($SESSION->order_edit_quantity_limits[$invoiceid]) || !is_array($SESSION->order_edit_quantity_limits[$invoiceid])) {
        $SESSION->order_edit_quantity_limits[$invoiceid] = [];
    }
    if ($invoiceitems = $DB->get_records('invoiceitem', ['invoiceid' => $invoiceid], 'id', 'id, quantity, invoiceableitemtype')) {
        foreach ($invoiceitems as $invoiceitem) {
            if (in_array($invoiceitem->invoiceableitemtype, ['refundadjustment', 'creditnote'], true)) {
                continue;
            }
            if (!isset($SESSION->order_edit_quantity_limits[$invoiceid][$invoiceitem->id])) {
                $SESSION->order_edit_quantity_limits[$invoiceid][$invoiceitem->id] = max(1, (int)$invoiceitem->quantity);
            }
        }
    }
}

$showaccount = false;
if (iomad::has_capability('block/iomad_company_admin:company_add', $companycontext)) {
    $showaccount = true;
}
$mform = new \block_iomad_commerce\forms\order_edit_form($PAGE->url, $invoiceid, $showaccount, $editmode);
$mform->set_data($invoice);

if ($partialcancel && confirm_sesskey()) {
    $selecteditem = null;
    foreach ($partialcancelitems as $candidateitem) {
        if ((int)$candidateitem->id === $partialcancelitemid) {
            $selecteditem = $candidateitem;
            break;
        }
    }

    if (!$selecteditem) {
        redirect(
            new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'partialcancelmode' => 1]),
            'Please choose a valid order item to partially cancel.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $error = '';
    if (!empty($selecteditem->isinhouse)) {
        iomad_order_cancel_inhouse_course($selecteditem, (string)$invoice->reference);
    } else {
        $error = iomad_order_reduce_open_course_place($selecteditem, $partialcanceluserid);
    }

    if ($error !== '') {
        redirect(
            new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'partialcancelmode' => 1]),
            $error,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    redirect(
        new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
        'Partial cancellation has been applied successfully.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if (!empty(optional_param('generateinvoice', 0, PARAM_BOOL)) && confirm_sesskey()) {
    $xeroinvoice = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid]);
    if ($xeroinvoice && property_exists($xeroinvoice, 'modified_date')) {
        $xeroinvoice->modified_date = time();
        $DB->update_record('iomad_xero_invoice', $xeroinvoice);
    }

    if (function_exists('task_xero')) {
        ob_start();
        task_xero($invoiceid);
        ob_end_clean();
    }

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
}

if ($cancelinvoice && confirm_sesskey()) {
    $refundtypes = ['none', 'partial', 'full'];
    if (!in_array($refundtype, $refundtypes, true)) {
        $refundtype = 'none';
    }

    $basket = \block_iomad_commerce\helper::get_basket_by_id($invoiceid, $invoice->status);
    $invoicetotal = $basket ? (float)$basket->total : 0.0;
    $refundvalue = 0.0;

    if ($refundtype === 'full') {
        $refundvalue = $invoicetotal;
    } else if ($refundtype === 'partial') {
        $refundvalue = max(0, (float)$refundamount);
        if ($invoicetotal > 0) {
            $refundvalue = min($refundvalue, $invoicetotal);
        }
    }

    $refundline = null;
    if ($refundvalue > 0) {
        $baseitem = $DB->get_record('course_shopsettings', ['name' => 'Refund after deducting Cancellation Charges'], 'id,single_purchase_currency', IGNORE_MULTIPLE);
        if (!$baseitem) {
            $baseinvoiceitem = $DB->get_record('invoiceitem', ['invoiceid' => $invoiceid], 'invoiceableitemid,currency,license_allocation,license_validlength,license_shelflife', IGNORE_MULTIPLE);
            if ($baseinvoiceitem) {
                $baseitem = (object)[
                    'id' => $baseinvoiceitem->invoiceableitemid,
                    'single_purchase_currency' => $baseinvoiceitem->currency,
                    'license_allocation' => $baseinvoiceitem->license_allocation,
                    'license_validlength' => $baseinvoiceitem->license_validlength,
                    'license_shelflife' => $baseinvoiceitem->license_shelflife,
                ];
            }
        }
        if ($baseitem) {
            $refundline = new stdClass();
            $refundline->invoiceid = $invoiceid;
            $refundline->invoiceableitemid = $baseitem->id;
            $refundline->invoiceableitemtype = ($refundtype === 'partial') ? 'creditnote' : 'refundadjustment';
            $refundline->quantity = 1;
            $refundline->currency = $baseitem->single_purchase_currency;
            $refundline->price = -1 * $refundvalue;
            $refundline->license_allocation = !empty($baseitem->license_allocation) ? $baseitem->license_allocation : 1;
            $refundline->license_validlength = !empty($baseitem->license_validlength) ? $baseitem->license_validlength : 0;
            $refundline->license_shelflife = !empty($baseitem->license_shelflife) ? $baseitem->license_shelflife : 0;
            $refundline->processed = 1;
        }
    }

    $hasxeroinvoice = $DB->record_exists_select(
        'iomad_xero_invoice',
        'invoiceid = :invoiceid AND xeroinvoiceid IS NOT NULL AND xeroinvoiceid <> :emptyid',
        ['invoiceid' => $invoiceid, 'emptyid' => '00000000-0000-0000-0000-000000000000']
    );
    if ($refundtype === 'partial' && $refundvalue > 0 && $hasxeroinvoice) {
        [$creditnotesuccess, $creditnoteerror, $creditnoteid, $creditnotenumber] = iomad_xero_create_credit_note_for_refund($invoiceid, $refundvalue);
        if (!$creditnotesuccess) {
            redirect(
                new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'cancelmode' => 1]),
                'Unable to create the Xero credit note for this partial refund. ' . $creditnoteerror,
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        if (!empty($creditnoteid)) {
            $DB->set_field('iomad_xero_invoice', 'xero_creditnoteid', $creditnoteid, ['invoiceid' => $invoiceid]);
            if (!empty($creditnotenumber)) {
                $DB->set_field('iomad_xero_invoice', 'xero_creditnotenumber', $creditnotenumber, ['invoiceid' => $invoiceid]);
            }
        }
    }

    if ($refundline) {
        $DB->insert_record('invoiceitem', $refundline);
    }

    $invoice->status = 'c';
    $DB->update_record('invoice', $invoice);

    $statusrecord = $DB->get_record('blocks_ecommerce_status', ['invoiceid' => $invoiceid]);
    if ($statusrecord) {
        $statusrecord->status = 'c';
        $DB->update_record('blocks_ecommerce_status', $statusrecord);
    } else {
        $statusrecord = new stdClass();
        $statusrecord->invoiceid = $invoiceid;
        $statusrecord->status = 'c';
        $DB->insert_record('blocks_ecommerce_status', $statusrecord);
    }

    if (!empty($invoice->reference)) {
        foreach ($partialcancelitems as $cancelitem) {
            if (!empty($cancelitem->isinhouse)) {
                iomad_order_cancel_inhouse_course($cancelitem, (string)$invoice->reference);
            }
        }

        $companylicenses = $DB->get_records('companylicense', ['reference' => $invoice->reference]);
        foreach ($companylicenses as $companylicense) {
            if (!$DB->record_exists('companylicense', ['id' => $companylicense->id])) {
                continue;
            }
            $licenseusers = $DB->get_records('companylicense_users', ['licenseid' => $companylicense->id]);
            foreach ($licenseusers as $licenseuser) {
                if (!empty($licenseuser->userid) && !empty($licenseuser->licensecourseid)) {
                    company_user::unenrol($licenseuser->userid, [$licenseuser->licensecourseid], $companylicense->companyid);
                }
                $DB->delete_records('companylicense_users', ['id' => $licenseuser->id]);
            }
            company::update_license_usage($companylicense->id);
            $companylicense->expirydate = time();
            $companylicense->cutoffdate = time();
            $companylicense->used = 0;
	    $companylicense->allocation = 0;
            $companylicense->humanallocation = 0;
            $DB->update_record('companylicense', $companylicense);
        }
    }

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
} else if ($mform->is_cancelled()) {
    redirect($companylist);
} else if ($data = $mform->get_data()) {
	$postedprices = optional_param_array('price', [], PARAM_RAW_TRIMMED);
	$postedclasses = optional_param_array('numberofclass', [], PARAM_INT);
	if (isset($data->po_ref)) {
		$poRef = trim((string)$data->po_ref);
		$existingpo = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
		if (!empty($poRef)) {
			if ($existingpo) {
				$existingpo->po = $poRef;
				$DB->update_record('paygw_po', $existingpo);
			}
		}
	}
	if (!empty($postedprices)) {
		foreach ($postedprices as $itemid => $postedprice) {
			$itemid = (int)$itemid;
			if ($itemid <= 0) {
				continue;
			}

			$invoiceitem = $DB->get_record('invoiceitem', ['id' => $itemid, 'invoiceid' => $invoiceid]);
			if (!$invoiceitem || in_array($invoiceitem->invoiceableitemtype, ['refundadjustment', 'creditnote'], true)) {
				continue;
			}

			$newprice = trim((string)$postedprice);
			if ($newprice === '' || !is_numeric($newprice)) {
				continue;
			}

			$invoiceitem->price = round((float)$newprice, 2);
			$DB->update_record('invoiceitem', $invoiceitem);
		}
	}
	if (!empty($postedclasses)) {
		foreach ($postedclasses as $itemid => $postedclasscount) {
			$itemid = (int)$itemid;
			if ($itemid <= 0) {
				continue;
			}

			$invoiceitem = $DB->get_record('invoiceitem', ['id' => $itemid, 'invoiceid' => $invoiceid]);
			if (!$invoiceitem || in_array($invoiceitem->invoiceableitemtype, ['refundadjustment', 'creditnote'], true)) {
				continue;
			}

			$newclasscount = max(1, (int)$postedclasscount);
			$currentclasscount = max(1, (int)$invoiceitem->quantity);
			$originallimit = !empty($SESSION->order_edit_quantity_limits[$invoiceid][$itemid])
				? max(1, (int)$SESSION->order_edit_quantity_limits[$invoiceid][$itemid])
				: $currentclasscount;
			$maxeditableclasscount = max(1, $originallimit - 1);
			if ($newclasscount > $maxeditableclasscount) {
				$newclasscount = $maxeditableclasscount;
			}

			if ($newclasscount !== $currentclasscount) {
				$invoiceitem->quantity = $newclasscount;
				$DB->update_record('invoiceitem', $invoiceitem);
			}
		}
	}

	redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));

} else {

	echo $OUTPUT->header();

	if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
	echo '<div class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap">';
	if ($editmode && !$cancelmode && !$partialcancelmode) {
		echo '<button type="submit" form="order-edit-form" class="btn btn-primary">' . get_string('savechanges') . '</button>';
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]))->out() . '" class="btn btn-secondary">' . get_string('cancel') . '</a>';
	} else if ($cancelmode || $partialcancelmode) {
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => $editmode ? 1 : 0]))->out() . '" class="btn btn-secondary">' . get_string('back') . '</a>';
	} else {
		if ($invoice->status !== 'c') {
			echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => 1]))->out() . '" class="btn btn-secondary">Edit</a>';
            if (!empty($partialcancelitems)) {
                echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'partialcancelmode' => 1]))->out() . '" class="btn btn-secondary">Partial Cancel</a>';
            }
		}
	}

	if (!$editmode && !$cancelmode && !$partialcancelmode) {
	// Cancel invoice as POST form with sesskey (CSRF protected).
	$xeroinvoice = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid], 'invoiceid, xeroinvoiceid');
	$hasxeroinvoice = !empty($xeroinvoice) && !empty($xeroinvoice->xeroinvoiceid) && $xeroinvoice->xeroinvoiceid !== '00000000-0000-0000-0000-000000000000';

	if ($invoice->status === 'c') {
		$title = ($invoice->status === 'c') ? 'Invoice is already cancelled' : 'Inhouse course billed after course completion';
	} else {
		if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
			echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'cancelmode' => 1]))->out() . '" class="btn btn-danger">Cancel Order</a>';
		}
	}

	if (!$hasxeroinvoice) {
		if ($invoice->status === 'c') {
		} else {
			if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
				echo '<form method="post" style="display:inline;">';
				echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
				echo '<input type="hidden" name="generateinvoice" value="1" />';
				echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
				echo '<button type="submit" class="btn btn-primary">Generate Invoice</button>';
				echo '</form>';
			}
		}
	}

	if (!$invoice->paymentid) {
		echo "<a href='../../blocks/iomad_ecommerce/checkout.php?invoiceid=".$invoiceid."' target='_blank'><button class='btn btn-primary'>Make Payment</button></a>";
	}
	}

	echo '</div>';

	if ($cancelmode) {
	    $basket = \block_iomad_commerce\helper::get_basket_by_id($invoiceid, $invoice->status);
	    $invoicetotal = $basket ? number_format((float)$basket->total, 2, '.', '') : '0.00';
	    $currency = $basket && !empty($basket->currency) ? $basket->currency : '';

	    echo '<div class="card mb-3">';
	    echo '<div class="card-body">';
	    echo '<h5 class="card-title">Cancel order</h5>';
	    echo '<p class="mb-3">Choose whether this cancellation should include a refund adjustment. Full and partial refunds will be added as a negative line item before the order is marked cancelled.</p>';
	    echo '<form method="post">';
	    echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
	    echo '<input type="hidden" name="editmode" value="1" />';
	    echo '<input type="hidden" name="cancelmode" value="1" />';
	    echo '<input type="hidden" name="cancelinvoice" value="1" />';
	    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
	    echo '<div class="mb-3">';
	    echo '<label for="id_refundtype"><strong>Refund type</strong></label>';
	    echo '<select id="id_refundtype" name="refundtype" class="form-control" style="max-width:320px;">';
	    echo '<option value="none">No refund</option>';
	    echo '<option value="partial">Partial refund</option>';
	    echo '<option value="full">Full refund (' . s(trim($currency . ' ' . $invoicetotal)) . ')</option>';
	    echo '</select>';
	    echo '</div>';
	    echo '<div class="mb-3" id="partial-refund-amount" style="display:none;">';
	    echo '<label for="id_refundamount"><strong>Partial refund amount</strong></label>';
	    echo '<input id="id_refundamount" type="number" step="0.01" min="0" max="' . s($invoicetotal) . '" name="refundamount" value="' . s($invoicetotal) . '" class="form-control" style="max-width:220px;">';
	    echo '<div class="form-text">Only used when "Partial refund" is selected.</div>';
	    echo '</div>';
	    echo '<button type="submit" class="btn btn-danger">Confirm cancellation</button>';
	    echo '</form>';
	    echo '</div>';
	    echo '</div>';
	    echo '<script>
	    (function() {
	        var refundType = document.getElementById("id_refundtype");
        	var partialRefund = document.getElementById("partial-refund-amount");
	        if (!refundType || !partialRefund) {
        	    return;
	        }
        	var togglePartialRefund = function() {
	            partialRefund.style.display = refundType.value === "partial" ? "block" : "none";
        	};
	        refundType.addEventListener("change", togglePartialRefund);
        	togglePartialRefund();
	    })();
	    </script>';
	}

    if ($partialcancelmode) {
        echo '<div class="card mb-3">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">Partial cancel</h5>';
        echo '<p class="mb-3">Use this when you only need to cancel part of the order. Open-course items will remove one course place. In-house items will cancel the selected training class and clean up its course place and trainer assignment.</p>';

        if (empty($partialcancelitems)) {
            echo '<div class="alert alert-info mb-0">There are no active course items available for partial cancellation on this order.</div>';
        } else {
            foreach ($partialcancelitems as $partialitem) {
                $assignedusers = !empty($partialitem->assignedusers) ? $partialitem->assignedusers : [];
                echo '<div style="border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:14px;">';
                echo '<div style="font-weight:600; margin-bottom:6px;">' . s($partialitem->name) . '</div>';
                echo '<div style="font-size:13px; color:#555; margin-bottom:12px;">';
                if (!empty($partialitem->isinhouse)) {
                    echo 'In-house class cancellation. This will prefix the course name with [CANCELLED], remove the training class activity, unassign the trainer, and delete the related course place.';
                } else {
                    echo 'Open course place cancellation. Available places: <strong>' . (int)$partialitem->humanallocation . '</strong>';
                    echo ' | Assigned delegates: <strong>' . count($assignedusers) . '</strong>';
                }
                echo '</div>';
                echo '<form method="post" style="margin:0;">';
                echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
                echo '<input type="hidden" name="partialcancelmode" value="1" />';
                echo '<input type="hidden" name="partialcancel" value="1" />';
                echo '<input type="hidden" name="partial_cancel_itemid" value="' . (int)$partialitem->id . '" />';
                echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';

                if (empty($partialitem->isinhouse)) {
                    if (!empty($assignedusers)) {
                        echo '<label style="display:block; font-weight:600; margin-bottom:6px;" for="partial_cancel_user_' . (int)$partialitem->id . '">If this place is already assigned, choose the delegate to unassign</label>';
                        echo '<select id="partial_cancel_user_' . (int)$partialitem->id . '" name="partial_cancel_userid" class="form-control" style="max-width:360px; margin-bottom:10px;">';
                        if ((int)$partialitem->humanallocation > count($assignedusers)) {
                            echo '<option value="0">Cancel an unassigned place</option>';
                        } else {
                            echo '<option value="0">Select a delegate</option>';
                        }
                        foreach ($assignedusers as $assigneduser) {
                            echo '<option value="' . (int)$assigneduser->userid . '">' . s(fullname($assigneduser) . ' (' . $assigneduser->email . ')') . '</option>';
                        }
                        echo '</select>';
                    } else {
                        echo '<div style="font-size:13px; color:#555; margin-bottom:10px;">No delegate is currently assigned, so this will cancel an unassigned place.</div>';
                    }
                }

                echo '<button type="submit" class="btn btn-secondary">Confirm Partial Cancel</button>';
                echo '</form>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '</div>';
    }
	}

	if (!$cancelmode && !$partialcancelmode) {
		$mform->display();
		if ($editmode) {
			echo '<script>
			(function() {
				var form = document.getElementById("order-edit-form");
				if (!form) {
					return;
				}

				var priceInputs = form.querySelectorAll(".js-order-price");
				var qtyInputs = form.querySelectorAll(".js-order-qty");
				if (!priceInputs.length && !qtyInputs.length) {
					return;
				}

				var formatAmount = function(currency, amount) {
					return currency + " " + amount.toLocaleString(undefined, {
						minimumFractionDigits: 2,
						maximumFractionDigits: 2
					});
				};

				var recalcTotals = function() {
					var grandTotal = 0;
					var grandCurrency = "";
					var rowTotals = form.querySelectorAll(".js-order-rowtotal");
					rowTotals.forEach(function(rowTotal) {
						var itemId = rowTotal.getAttribute("data-itemid");
						var priceInput = form.querySelector(".js-order-price[data-itemid=\'" + itemId + "\']");
						var qtyInput = form.querySelector(".js-order-qty[data-itemid=\'" + itemId + "\']");
						if (!priceInput || !qtyInput) {
							return;
						}

						var price = parseFloat(priceInput.value || "0");
						var qty = parseInt(qtyInput.value || "0", 10);
						if (isNaN(price)) {
							price = 0;
						}
						if (isNaN(qty) || qty < 1) {
							qty = 1;
						}

						var currency = rowTotal.getAttribute("data-currency") || "";
						var rowAmount = price * qty;
						rowTotal.textContent = formatAmount(currency, rowAmount);
						grandTotal += rowAmount;
						if (!grandCurrency) {
							grandCurrency = currency;
						}
					});

					var grandTotalEl = document.getElementById("js-order-grand-total");
					if (grandTotalEl) {
						grandTotalEl.textContent = formatAmount(grandCurrency, grandTotal);
					}
				};

				priceInputs.forEach(function(input) {
					input.addEventListener("input", recalcTotals);
				});
				qtyInputs.forEach(function(input) {
					input.addEventListener("change", recalcTotals);
				});
				recalcTotals();
			})();
			</script>';
		}
	}
	echo $OUTPUT->footer();
}
