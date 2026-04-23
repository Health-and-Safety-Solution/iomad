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
        $refundtype = optional_param('refundtype', '', PARAM_ALPHA);
        $refundamount = optional_param('refundamount', 0, PARAM_FLOAT);
} else {
        $editmode = 0;
        $cancelmode = 0;
        $cancelinvoice = 0;
        $refundtype = '';
        $refundamount = 0;
}

$company = new company($companyid);

$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);
if ($invoice->status == 'c') {
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

$porecord = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
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

	redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));

} else {

	echo $OUTPUT->header();

	if(iomad::has_capability('block/iomad_ecommerce:editQuotation', $companycontext)) {
	echo '<div class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap">';
	if ($editmode && !$cancelmode) {
		echo '<button type="submit" form="order-edit-form" class="btn btn-primary">' . get_string('savechanges') . '</button>';
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]))->out() . '" class="btn btn-secondary">' . get_string('cancel') . '</a>';
	} else if ($cancelmode) {
		echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => $editmode ? 1 : 0]))->out() . '" class="btn btn-secondary">' . get_string('back') . '</a>';
	} else {
		if ($invoice->status !== 'c') {
			echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => 1]))->out() . '" class="btn btn-secondary">Edit</a>';
		}
	}

	if (!$editmode && !$cancelmode) {
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
	}

	if (!$cancelmode) {
		$mform->display();
	}
	echo $OUTPUT->footer();
}
