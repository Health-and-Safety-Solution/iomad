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
require_once($CFG->dirroot . '/local/iomad/lib/user.php');
require_once($CFG->dirroot . '/local/iomad/lib/company.php');
require_once($CFG->dirroot . '/local/iomad_xero/lib.php');

\block_iomad_commerce\helper::require_commerce_enabled();

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
        $cancelinvoice = optional_param('cancelinvoice', 0, PARAM_BOOL);
        $cancellineitems = optional_param('cancellineitems', 0, PARAM_BOOL);
        $refundtype = optional_param('refundtype', '', PARAM_ALPHA);
        $refundamount = optional_param('refundamount', 0, PARAM_FLOAT);
} else {
        $editmode = 0;
        $cancelmode = 0;
        $cancelinvoice = 0;
        $cancellineitems = 0;
        $refundtype = '';
        $refundamount = 0;
}

$company = new company($companyid);

$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);
$ecommercestatus = $DB->get_record('blocks_ecommerce_status', ['invoiceid' => $invoiceid]);
$porecord = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
// Edit allowed only when order was placed via PO and is not yet marked paid.
$canedit = $porecord && (!$ecommercestatus || $ecommercestatus->status !== 'p');
if ($invoice->status == 'c' || !$canedit) {
	$editmode = 0;
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

if ($porecord) {
    $invoice->po_ref = $porecord->po;
}

$showaccount = false;
if (iomad::has_capability('block/iomad_company_admin:company_add', $companycontext)) {
    $showaccount = true;
}
$mform = new \block_iomad_commerce\forms\order_edit_form($PAGE->url, $invoiceid, $showaccount, $editmode);
$mform->set_data($invoice);

if (!empty(optional_param('generateinvoice', 0, PARAM_BOOL)) && confirm_sesskey()) {
    $xeroinvoice = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid]);
    if ($xeroinvoice && property_exists($xeroinvoice, 'modified_date')) {
        $xeroinvoice->modified_date = time();
        $DB->update_record('iomad_xero_invoice', $xeroinvoice);
    }

    if (function_exists('task_inhouse_xero')) {
        ob_start();
        task_inhouse_xero($invoiceid);
        ob_end_clean();
    }

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
}

if ($cancellineitems && confirm_sesskey()) {
    $selecteditemids = optional_param_array('cancelitems', [], PARAM_INT);
    $selecteditemids = array_filter(array_map('intval', $selecteditemids));
    $refundtypes = ['none', 'partial', 'full'];
    $refundtype  = optional_param('refundtype', 'none', PARAM_ALPHA);
    $refundamount = optional_param('refundamount', 0, PARAM_FLOAT);
    if (!in_array($refundtype, $refundtypes, true)) {
        $refundtype = 'none';
    }

    $creditnote_errors = [];
    $hasxeroinvoice = $DB->record_exists('iomad_xero_invoice', ['invoiceid' => $invoiceid]);

    // Sum only the selected, not-yet-cancelled items so the refund value matches the lines being cancelled.
    $selectedtotal = 0.0;
    if (!empty($selecteditemids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($selecteditemids, SQL_PARAMS_QM);
        $inparams[] = $invoiceid;
        $selectedtotal = (float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(ii.price * ii.license_allocation), 0)
             FROM {invoiceitem} ii
             WHERE ii.id $insql
               AND ii.invoiceid = ?
               AND ii.invoiceableitemtype <> 'refundadjustment'
               AND NOT EXISTS (SELECT 1 FROM {invoiceitem_cancelled} ic WHERE ic.invoiceitemid = ii.id)",
            $inparams
        );
    }
    $refundvalue = 0.0;
    if ($refundtype === 'full') {
        $refundvalue = $selectedtotal;
    } else if ($refundtype === 'partial') {
        $refundvalue = max(0, (float)$refundamount);
        if ($selectedtotal > 0) {
            $refundvalue = min($refundvalue, $selectedtotal);
        }
    }

    $firstcancelleditemid = null;

    foreach ($selecteditemids as $itemid) {
        $item = $DB->get_record('invoiceitem', ['id' => $itemid, 'invoiceid' => $invoiceid]);
        if (!$item || $DB->record_exists('invoiceitem_cancelled', ['invoiceitemid' => $itemid])) {
            continue;
        }

        // Mark this line as cancelled.
        $cancelrec = new stdClass();
        $cancelrec->invoiceitemid = $itemid;
        $cancelrec->timecreated   = time();
        $DB->insert_record('invoiceitem_cancelled', $cancelrec);

        if ($firstcancelleditemid === null) {
            $firstcancelleditemid = $itemid;
        }

        // For a full refund create a per-item credit note in Xero for the item's full amount.
        if ($hasxeroinvoice && function_exists('create_xero_credit_note') && $refundtype === 'full') {
            $cn_result = create_xero_credit_note($itemid, $invoiceid);
            if (!$cn_result['success']) {
                $creditnote_errors[] = 'Item ' . $itemid . ': ' . ($cn_result['error'] ?? 'unknown error');
            }
        }

        // Find the course linked to this invoice item.
        $course = $DB->get_record_sql(
            "SELECT c.*,css.companyid FROM {course} c
             LEFT JOIN {course_shopsettings_courses} csc ON csc.courseid = c.id
	     LEFT JOIN {course_shopsettings} css ON css.id = csc.itemid
             WHERE csc.itemid = ?",
            [$item->invoiceableitemid]
        );

        if ($course) {
	    if ($course->companyid == 28) {
		// Append "(Cancelled)" to the course name so it is excluded from future invoice generation.
		if (strpos($course->fullname, 'Cancelled') === false) {
			$course->fullname = '[Cancelled] '.$course->fullname;
			$DB->update_record('course', $course);
		}

		//Delete entry from trainingevent and remove all activties linked in course
		$DB->delete_records('trainingevent', ['course' => $course->id]);
		$DB->delete_records('course_modules', ['course' => $course->id]);
	    }

            // Delete licenses specific to this course.
            $companylicenses = $DB->get_records_sql(
                "SELECT cl.* FROM {companylicense} cl
                 INNER JOIN {companylicense_courses} clc ON clc.licenseid = cl.id
                 WHERE clc.courseid = ? AND cl.reference = ?",
                [$course->id, $invoice->reference]
            );
            foreach ($companylicenses as $companylicense) {
                $licenseusers = $DB->get_records('companylicense_users', [
                    'licenseid'       => $companylicense->id,
                    'licensecourseid' => $course->id,
                ]);
                foreach ($licenseusers as $licenseuser) {
                    if (!empty($licenseuser->userid)) {
                        company_user::unenrol($licenseuser->userid, [$course->id], $companylicense->companyid);
                    }
                    $DB->delete_records('companylicense_users', ['id' => $licenseuser->id]);
                }
                company::update_license_usage($companylicense->id);
		$DB->delete_records('companylicense', ['id' => $companylicense->id]);
            }
        }
    }

    // For a partial refund create a single credit note in Xero for the whole partial amount.
    if ($hasxeroinvoice && function_exists('create_xero_credit_note') && $refundtype === 'partial' && $refundvalue > 0 && $firstcancelleditemid !== null) {
        $cn_result = create_xero_credit_note($firstcancelleditemid, $invoiceid, $refundvalue);
        if (!$cn_result['success']) {
            $creditnote_errors[] = 'Credit note: ' . ($cn_result['error'] ?? 'unknown error');
        }
    }

    // Add refund adjustment line item if a non-zero refund was requested.
    if ($refundvalue > 0) {
        $baseitem = $DB->get_record('course_shopsettings', ['name' => 'Refund after deducting Cancellation Charges'], 'id,single_purchase_currency', IGNORE_MULTIPLE);
        if ($baseitem) {
            $refundline = new stdClass();
            $refundline->invoiceid = $invoiceid;
            $refundline->invoiceableitemid = $baseitem->id;
            $refundline->invoiceableitemtype = 'refundadjustment';
            $refundline->quantity = 1;
            $refundline->currency = $baseitem->single_purchase_currency;
            $refundline->price = -1 * $refundvalue;
            $refundline->license_allocation = 1;
            $refundline->license_validlength = 0;
            $refundline->license_shelflife = 0;
            $refundline->processed = 1;
            $DB->insert_record('invoiceitem', $refundline);
        }
    }

    // If all non-surcharge lines are now cancelled, mark the whole invoice cancelled.
    $activecount = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {invoiceitem} ii
         WHERE ii.invoiceid = ?
           AND ii.invoiceableitemtype <> 'refundadjustment'
           AND NOT EXISTS (SELECT 1 FROM {invoiceitem_cancelled} ic WHERE ic.invoiceitemid = ii.id)",
        [$invoiceid]
    );
    if ($activecount === 0) {
        $invoice->status = 'c';
        $DB->update_record('invoice', $invoice);
        $statusrecord = $DB->get_record('blocks_ecommerce_status', ['invoiceid' => $invoiceid]);
        if ($statusrecord) {
            $statusrecord->status = 'c';
            $DB->update_record('blocks_ecommerce_status', $statusrecord);
        } else {
            $statusrecord = new stdClass();
            $statusrecord->invoiceid = $invoiceid;
            $statusrecord->status    = 'c';
            $DB->insert_record('blocks_ecommerce_status', $statusrecord);
        }
    }

    if (!empty($creditnote_errors)) {
        redirect(
            new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
            'Lines cancelled but some Xero credit notes failed: ' . implode('; ', $creditnote_errors),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
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

    if ($refundvalue > 0) {
        $baseitem = $DB->get_record('course_shopsettings', ['name' => 'Refund after deducting Cancellation Charges'], 'id,single_purchase_currency', IGNORE_MULTIPLE);
        if ($baseitem) {
            $refundline = new stdClass();
            $refundline->invoiceid = $invoiceid;
            $refundline->invoiceableitemid = $baseitem->id;
            $refundline->invoiceableitemtype = 'refundadjustment';
            $refundline->quantity = 1;
            $refundline->currency = $baseitem->single_purchase_currency;
            $refundline->price = -1 * $refundvalue;
            $refundline->license_allocation = 1;
            $refundline->license_validlength = 0;
            $refundline->license_shelflife = 0;
            $refundline->processed = 1;
            $a = $DB->insert_record('invoiceitem', $refundline);
        }
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
        $companylicenses = $DB->get_records('companylicense', ['reference' => $invoice->reference]);
        foreach ($companylicenses as $companylicense) {
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
            $DB->update_record('companylicense', $companylicense);
        }
    }

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
} else if ($mform->is_cancelled()) {
    redirect($companylist);
} else if ($data = $mform->get_data()) {
	$postedprices = optional_param_array('price', [], PARAM_RAW_TRIMMED);
	$xero_sync_needed = false;

	if (isset($data->po_ref)) {
		$poRef = trim((string)$data->po_ref);
		$existingpo = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
		if (!empty($poRef)) {
			if ($existingpo) {
				$existingpo->po = $poRef;
				$DB->update_record('paygw_po', $existingpo);
				$xero_sync_needed = true;
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
			if (!$invoiceitem || $invoiceitem->invoiceableitemtype === 'refundadjustment' || $DB->record_exists('invoiceitem_cancelled', ['invoiceitemid' => $itemid])) {
				continue;
			}

			$newprice = trim((string)$postedprice);
			if ($newprice === '' || !is_numeric($newprice)) {
				continue;
			}

			$invoiceitem->price = round((float)$newprice, 2);
			$DB->update_record('invoiceitem', $invoiceitem);
			$xero_sync_needed = true;
		}
	}

	// Synchronously push updated line items (with current prices and PO) to Xero.
	if ($xero_sync_needed && function_exists('update_xero_invoice_lineitems')) {
		$xero_result = update_xero_invoice_lineitems($invoiceid);
		if ($xero_result === null) {
			// Invoice not yet in Xero — DB changes saved, nothing to push.
			redirect(
				new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
				get_string('changessaved'),
				null,
				\core\output\notification::NOTIFY_SUCCESS
			);
		} else if (!$xero_result['success']) {
			// DB changes saved but Xero push failed — warn the admin.
			redirect(
				new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
				'Changes saved, but Xero could not be updated: ' . $xero_result['error'],
				null,
				\core\output\notification::NOTIFY_WARNING
			);
		} else {
			redirect(
				new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
				'Changes saved and Xero invoice updated successfully.',
				null,
				\core\output\notification::NOTIFY_SUCCESS
			);
		}
	}

	redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));

} else {

	echo $OUTPUT->header();

	if ($invoice->status === 'c') {
	    echo '<div class="alert alert-danger"><strong>This order has been cancelled.</strong></div>';
	}

	if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
	echo '<div class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap">';
	if ($editmode && !$cancelmode) {
		echo '<button type="submit" form="order-edit-form" class="btn btn-primary">' . get_string('savechanges') . '</button>';
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]))->out() . '" class="btn btn-secondary">' . get_string('cancel') . '</a>';
	} else if ($cancelmode) {
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]))->out() . '" class="btn btn-secondary">' . get_string('back') . '</a>';
	} else {
		if ($invoice->status !== 'c' && $canedit) {
			echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => 1]))->out() . '" class="btn btn-secondary">Edit</a>';
		}
	}

	if (!$editmode && !$cancelmode) {
	// Cancel invoice as POST form with sesskey (CSRF protected).
	$xeroinvoice = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid], 'invoiceid, xeroinvoiceid');
	$hasxeroinvoice = !empty($xeroinvoice) && !empty($xeroinvoice->xeroinvoiceid) && $xeroinvoice->xeroinvoiceid !== '00000000-0000-0000-0000-000000000000';
	$isinhouseinvoice = $DB->record_exists_sql(
		"SELECT 1 FROM {invoiceitem} ii
		 INNER JOIN {course_shopsettings} css ON css.id = ii.invoiceableitemid AND css.companyid = 28
		 WHERE ii.invoiceid = ?",
		[$invoiceid]
	);

	if ($invoice->status === 'c') {
		$title = ($invoice->status === 'c') ? 'Invoice is already cancelled' : 'Inhouse course billed after course completion';
	} else {
		if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
			echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'cancelmode' => 1]))->out() . '" class="btn btn-danger">Cancel Order</a>';
		}
	}

	if (!$hasxeroinvoice && $isinhouseinvoice) {
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
	    $xeroinvoice_cancel = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid], 'invoiceid, xeroinvoiceid');
	    $hasgeneratedinvoice = !empty($xeroinvoice_cancel) && !empty($xeroinvoice_cancel->xeroinvoiceid) && $xeroinvoice_cancel->xeroinvoiceid !== '00000000-0000-0000-0000-000000000000';
	    // Fetch all cancellable line items (exclude surcharges and already-cancelled lines).
	    $cancellableitems = $DB->get_records_sql(
	        "SELECT ii.*, css.name AS itemname
	         FROM {invoiceitem} ii
	         INNER JOIN {course_shopsettings} css ON css.id = ii.invoiceableitemid
	         WHERE ii.invoiceid = ?
	           AND ii.invoiceableitemtype <> 'refundadjustment'
	           AND NOT EXISTS (SELECT 1 FROM {invoiceitem_cancelled} ic WHERE ic.invoiceitemid = ii.id)",
	        [$invoiceid]
	    );

	    echo '<div class="card mb-3">';
	    echo '<div class="card-body">';
	    echo '<h5 class="card-title">Cancel lines</h5>';
	    echo '<p class="mb-3">Select which lines to cancel. For lines already invoiced in Xero a credit note will be created automatically. Cancelling all lines will also cancel the order.</p>';

	    if (empty($cancellableitems)) {
	        echo '<p class="text-muted">All lines have already been cancelled.</p>';
	    } else {
	        // Sum only the cancellable items shown so the refund ceiling is correct.
	        $invoicetotal_raw = 0.0;
	        $currency = '';
	        foreach ($cancellableitems as $ci_tmp) {
	            $invoicetotal_raw += (float)$ci_tmp->price * (int)$ci_tmp->license_allocation;
	            if (empty($currency) && !empty($ci_tmp->currency)) {
	                $currency = $ci_tmp->currency;
	            }
	        }
	        $invoicetotal = number_format($invoicetotal_raw, 2, '.', '');

	        echo '<form method="post">';
	        echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
	        echo '<input type="hidden" name="cancelmode" value="1" />';
	        echo '<input type="hidden" name="cancellineitems" value="1" />';
	        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';

	        echo '<table class="table table-sm mb-3">';
	        echo '<thead><tr><th style="width:40px;"></th><th>Course</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Total</th></tr></thead>';
	        echo '<tbody>';
	        foreach ($cancellableitems as $ci) {
	            $course = $DB->get_record_sql(
	                "SELECT c.fullname FROM {course} c LEFT JOIN {course_shopsettings_courses} csc ON csc.courseid = c.id WHERE csc.itemid = ?",
	                [$ci->invoiceableitemid]
	            );
	            $displayname = $course ? $course->fullname : s($ci->itemname);
	            $linetotal_raw = (float)$ci->price * (int)$ci->license_allocation;
	            $linetotal = number_format($linetotal_raw, 2);
	            $unitprice = number_format((float)$ci->price, 2);
	            echo '<tr>';
	            echo '<td><input type="checkbox" name="cancelitems[]" value="' . (int)$ci->id . '" class="cancel-line-check" data-linetotal="' . number_format($linetotal_raw, 2, '.', '') . '" /></td>';
	            echo '<td>' . s($displayname) . '</td>';
	            echo '<td class="text-right">' . (int)$ci->license_allocation . '</td>';
	            echo '<td class="text-right">' . s($ci->currency) . ' ' . $unitprice . '</td>';
	            echo '<td class="text-right">' . s($ci->currency) . ' ' . $linetotal . '</td>';
	            echo '</tr>';
	        }
	        echo '</tbody></table>';

	        echo '<div class="mb-3">';
	        echo '<label for="id_refundtype"><strong>Refund adjustment</strong></label>';
	        echo '<p class="text-muted small mb-1">A refund adjustment line item will be added to the order. For invoices already in Xero, raise a separate credit note manually if needed.</p>';
	        echo '<select id="id_refundtype" name="refundtype" class="form-control" style="max-width:320px;">';
	        if ($hasgeneratedinvoice) {
	            echo '<option value="none">No refund</option>';
	            echo '<option value="partial">Partial refund</option>';
	        }
	        echo '<option value="full" id="opt-full-refund"' . (!$hasgeneratedinvoice ? ' selected' : '') . '>Full refund (' . s($currency) . ' 0.00)</option>';
	        echo '</select>';
	        echo '</div>';
	        echo '<div class="mb-3" id="partial-refund-amount" style="display:none;">';
	        echo '<label for="id_refundamount"><strong>Partial refund amount</strong></label>';
	        echo '<input id="id_refundamount" type="number" step="0.01" min="0" max="0" name="refundamount" value="0" class="form-control" style="max-width:220px;">';
	        echo '</div>';

	        echo '<button type="submit" class="btn btn-danger" id="cancel-lines-btn" disabled>Cancel selected lines</button>';
	        echo '</form>';
	        $js_currency = s($currency);
	        echo '<script>
	        (function() {
	            var checks = document.querySelectorAll(".cancel-line-check");
	            var btn = document.getElementById("cancel-lines-btn");
	            var refundType = document.getElementById("id_refundtype");
	            var partialDiv = document.getElementById("partial-refund-amount");
	            var partialInput = document.getElementById("id_refundamount");
	            var fullOption = document.getElementById("opt-full-refund");
	            var currency = ' . json_encode($currency) . ';

	            function getSelectedTotal() {
	                var total = 0;
	                Array.prototype.forEach.call(checks, function(c) {
	                    if (c.checked) {
	                        total += parseFloat(c.getAttribute("data-linetotal")) || 0;
	                    }
	                });
	                return Math.round(total * 100) / 100;
	            }

	            function updateSelectionState() {
	                var total = getSelectedTotal();
	                btn.disabled = (total === 0);
	                var formatted = total.toFixed(2);
	                fullOption.textContent = "Full refund (" + currency + " " + formatted + ")";
	                partialInput.setAttribute("max", formatted);
	                if (parseFloat(partialInput.value) > total) {
	                    partialInput.value = formatted;
	                }
	            }

	            function toggleRefund() {
	                partialDiv.style.display = refundType.value === "partial" ? "block" : "none";
	            }

	            Array.prototype.forEach.call(checks, function(c) {
	                c.addEventListener("change", function() {
	                    updateSelectionState();
	                });
	            });
	            refundType.addEventListener("change", toggleRefund);
	            updateSelectionState();
	            toggleRefund();
	        })();
	        </script>';
	    }

	    echo '</div>';
	    echo '</div>';
	}
	}

	if (!$cancelmode) {
		$mform->display();
	}
	echo $OUTPUT->footer();
}
