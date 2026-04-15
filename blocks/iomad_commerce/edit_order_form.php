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

\block_iomad_commerce\helper::require_commerce_enabled();

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$invoiceid = required_param('id', PARAM_INTEGER);
$editmode = optional_param('editmode', 0, PARAM_BOOL);

require_login();

$systemcontext = context_system::instance();

// Set the companyid
$companyid = iomad::get_my_companyid($systemcontext);
$companycontext = \core\context\company::instance($companyid);
$company = new company($companyid);

$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);
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

// --- Handle invoice cancellation (AFTER security checks) ---
$cancelinvoice = optional_param('cancelinvoice', 0, PARAM_BOOL);
if ($cancelinvoice) {
    require_sesskey();
    $invoice->status = 'c';
    $DB->update_record('invoice', $invoice);

    $ecomm = $DB->get_record('blocks_ecommerce_status', ['invoiceid' => $invoiceid]);
    if ($ecomm) {
        $ecomm->status = 'c';
        $DB->update_record('blocks_ecommerce_status', $ecomm);
    } else {
        $insert = new stdClass();
        $insert->invoiceid = $invoiceid;
        $insert->status = 'c';
        $DB->insert_record('blocks_ecommerce_status', $insert);
    }

    $map = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid]);
    if ($map) {
        $map->modified_date = time();
        $DB->update_record('iomad_xero_invoice', $map);
    }

    redirect(
        new moodle_url('/blocks/iomad_ecommerce/order.php'),
        "Invoice cancelled successfully",
        3
    );
    exit;
}

// --- Handle immediate Xero invoice generation (AFTER security checks) ---
$generateinvoice = optional_param('generateinvoice', 0, PARAM_BOOL);
if ($generateinvoice) {
    require_sesskey();

    if ($invoice->status === 'c') {
        redirect(
            new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
            'Cancelled invoices cannot be generated',
            3,
            \core\output\notification::NOTIFY_ERROR
        );
        exit;
    }

    require_once($CFG->dirroot . '/local/iomad_xero/lib.php');
    ob_start();
    task_xero($invoiceid);
    ob_end_clean();

    redirect(
        new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]),
        'Invoice generation triggered successfully',
        3,
        \core\output\notification::NOTIFY_SUCCESS
    );
    exit;
}

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
$PAGE->navbar->add($linktext, $companylist);
$PAGE->navbar->add(get_string('edit_invoice', 'block_iomad_commerce'));

if (empty($invoice->paymentid)) {
    $porecord = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid], 'id', IGNORE_MISSING);
    if ($porecord) {
        $invoice->checkout_method = get_string('pluginname', 'paygw_po');
    } else {
        $invoice->checkout_method = get_string('status_u', 'block_iomad_commerce');
    }

    $currentstatus = $DB->get_field('blocks_ecommerce_status', 'status', ['invoiceid' => $invoiceid]);
    if (empty($currentstatus)) {
        $currentstatus = \block_iomad_commerce\helper::INVOICESTATUS_UNPAID;
    }
    $invoice->pp_account = get_string('status_' . $currentstatus, 'block_iomad_commerce');
} else {
    $payment = $DB->get_record('payments', ['id' => $invoice->paymentid]);
    $invoice->checkout_method = get_string('pluginname', 'paygw_' . $payment->gateway);
    if ($payment->gateway === 'po') {
        $currentstatus = $DB->get_field('blocks_ecommerce_status', 'status', ['invoiceid' => $invoiceid]);
        if (empty($currentstatus)) {
            $currentstatus = \block_iomad_commerce\helper::INVOICESTATUS_UNPAID;
        }
        $invoice->pp_account = get_string('status_' . $currentstatus, 'block_iomad_commerce');
    } else {
        $accounts = \core_payment\helper::get_payment_accounts_menu($systemcontext);
        $invoice->pp_account = $accounts[$payment->accountid] ?? get_string('notapplicable', 'local_report_completion');
    }
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

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
} else if ($data = $mform->get_data()) {
    $updatedinvoice = new stdClass();
    $updatedinvoice->id = $invoiceid;
    $updatedinvoice->firstname = $data->firstname;
    $updatedinvoice->lastname = $data->lastname;
    $updatedinvoice->company = $data->company;
    $updatedinvoice->address = $data->address;
    $updatedinvoice->city = $data->city;
    $updatedinvoice->postcode = $data->postcode;
    $updatedinvoice->state = $data->state;
    $updatedinvoice->country = $data->country;
    $updatedinvoice->email = $data->email;
    $updatedinvoice->phone1 = $data->phone1;

    $DB->update_record('invoice', $updatedinvoice);

    if (isset($data->po_ref)) {
        $poRef = trim((string)$data->po_ref);
        $existingpo = $DB->get_record('paygw_po', ['invoiceid' => $invoiceid]);
        if (!empty($poRef)) {
            if ($existingpo) {
                $existingpo->po = $poRef;
                $DB->update_record('paygw_po', $existingpo);
            } else {
                $newpo = new stdClass();
                $newpo->po = $poRef;
                $newpo->invoiceid = $invoiceid;
                $newpo->customerid = $invoice->companyid;
                $newpo->userid = $invoice->userid;
                $newpo->status = '';
                $newpo->created_date = time();
                $DB->insert_record('paygw_po', $newpo);
            }
        } else if ($existingpo) {
            $existingpo->po = '';
            $DB->update_record('paygw_po', $existingpo);
        }
    }

    // Update status if present in form data
    if (isset($data->status) && $data->status !== '') {
        $invoiceupdate = new stdClass();
        $invoiceupdate->invoiceid = $invoiceid;
        $invoiceupdate->status = $data->status;

        $existingstatus = $DB->get_record('blocks_ecommerce_status', ['invoiceid' => $invoiceid]);
        if ($existingstatus) {
            $invoiceupdate->id = $existingstatus->id;
            $DB->update_record('blocks_ecommerce_status', $invoiceupdate);
        } else {
            $DB->insert_record('blocks_ecommerce_status', $invoiceupdate);
        }
    }

    $map = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid]);
    if ($map) {
        $map->modified_date = time();
        $DB->update_record('iomad_xero_invoice', $map);
    }

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
} else {

    echo $OUTPUT->header();

    echo '<div class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap">';
    echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => 1]))->out() . '" class="btn btn-secondary">Edit</a>';
    // Cancel invoice as POST form with sesskey (CSRF protected).
    $xeroinvoice = $DB->get_record('iomad_xero_invoice', ['invoiceid' => $invoiceid], 'invoiceid, xeroinvoiceid');
    $hasxeroinvoice = !empty($xeroinvoice) && !empty($xeroinvoice->xeroinvoiceid)
        && $xeroinvoice->xeroinvoiceid !== '00000000-0000-0000-0000-000000000000';

    if ($invoice->status === 'c' || !$hasxeroinvoice) {
        $title = ($invoice->status === 'c')
            ? 'Invoice is already cancelled'
            : 'Invoice not created in Xero yet';
        echo '<button type="button" class="btn btn-danger" disabled title="' . s($title) . '">Cancel Invoice</button>';
    } else {
        echo '<form method="post" style="display:inline;">';
        echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
        echo '<input type="hidden" name="cancelinvoice" value="1" />';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
        echo '<button type="submit" class="btn btn-danger">Cancel Invoice</button>';
        echo '</form>';
    }

    if (!$hasxeroinvoice) {
        if ($invoice->status === 'c') {
            echo '<button type="button" class="btn btn-primary" disabled title="Invoice is already cancelled">Generate Invoice</button>';
        } else {
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="id" value="' . $invoiceid . '" />';
            echo '<input type="hidden" name="generateinvoice" value="1" />';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
            echo '<button type="submit" class="btn btn-primary">Generate Invoice</button>';
            echo '</form>';
        }
    }

    if ($hasxeroinvoice) {
        $downloadurl = new moodle_url('/blocks/iomad_ecommerce/downloads.php', [
            'action' => 'downloadinvoice',
            'invoiceid' => $invoiceid,
            'xeroinvoiceid' => $xeroinvoice->xeroinvoiceid,
        ]);
        echo '<a target="_blank" class="btn btn-primary" href="' . $downloadurl->out() . '">Download Invoice</a>';
    }
    echo '</div>';

    if (!$invoice->paymentid) {
        $checkouturl = new moodle_url('/blocks/iomad_ecommerce/checkout.php', ['invoiceid' => $invoiceid]);
        echo '<a href="' . $checkouturl->out() . '" target="_blank"><button class="btn btn-primary">Make Payment</button></a>';
    }

    $mform->display();
    echo $OUTPUT->footer();
}
