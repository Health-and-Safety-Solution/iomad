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
$editline = optional_param('editline', null, PARAM_INT);
$addline = optional_param('addline', null, PARAM_INT);

require_login();

$systemcontext = context_system::instance();

// Set the companyid
$companyid = iomad::get_my_companyid($systemcontext);
$companycontext = \core\context\company::instance($companyid);
$company = new company($companyid);

$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);

if ($invoice->companyid != $companyid) {
    $SESSION->basketid = null;
    redirect($CFG->wwwroot . '/my', get_string('invoiceblongsToanotherCompany', 'block_iomad_ecommerce'), '', 'error');
}

if (!iomad::has_capability('block/iomad_commerce:admin_view', $companycontext)) {
    if ($companyid) {
        $companycontext = \core\context\company::instance($companyid);
        $permissiontoview = iomad::has_capability('block/iomad_ecommerce:userorder_view', $companycontext);
    } else {
        $permissiontoview = iomad::has_capability('block/iomad_ecommerce:userorder_view', $context);
    }

    if ($permissiontoview) {
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

$urlparams = [];
if ($returnurl) {
    $urlparams['returnurl'] = $returnurl;
}
//$companylist = new moodle_url('/blocks/iomad_commerce/orderlist.php', $urlparams);
$companylist = new moodle_url('/blocks/iomad_ecommerce/order.php', $urlparams);
//$invoice = \block_iomad_commerce\helper::get_invoice($invoiceid);

// Set the name for the page.
$linktext = get_string('orders', 'block_iomad_commerce');
$PAGE->set_context($companycontext);

$PAGE->set_url(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => $editmode]));
$PAGE->set_pagelayout('base');
$PAGE->set_title($linktext);
$PAGE->set_heading(get_string('edit_invoice', 'block_iomad_commerce'));
$PAGE->navbar->add($linktext, $companylist);
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

$showaccount = false;
if (iomad::has_capability('block/iomad_company_admin:company_add', $companycontext)) {
    $showaccount = true;
}

// --- Handle basket line edit submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_line'])) {
    $editline = required_param('editline', PARAM_INT);
    $quantity = required_param('quantity', PARAM_INT);
    $price = required_param('price', PARAM_FLOAT);

    // Update your invoice item in DB here
    \block_iomad_commerce\helper::update_invoice_line($invoiceid, $editline, $quantity, $price);

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
    exit;
}

// --- Handle add line submission with plain text course name ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['add_line_submit'])) {
    $coursename = required_param('new_course_name', PARAM_RAW_TRIMMED);
    $quantity   = required_param('new_quantity', PARAM_INT);
    $price      = required_param('new_price', PARAM_FLOAT);

    $courserecord = $DB->get_record('course_shopsettings', array('name' => $coursename));
    if (!$courserecord) {
        \core\notification::error('Course "' . s($coursename) . '" does not exist. Please enter a valid course name.');
    } else {
        $newitem = new stdClass();
        $newitem->invoiceid            = $invoiceid;
        $newitem->invoiceableitemid    = $courserecord->id;
        $newitem->license_allocation   = $quantity;
        $newitem->price                = $price;
        $newitem->invoiceableitemtype  = 'standard';
        $newitem->currency             = 'GBP';

        $DB->insert_record('invoiceitem', $newitem);

        redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
        exit;
    }
}

$mform = new \block_iomad_commerce\forms\order_edit_form($PAGE->url, $invoiceid, $showaccount, $editmode, $editline);
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
    $updatedinvoice->reference = $data->reference;

    $DB->update_record('invoice', $updatedinvoice);

    redirect(new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid]));
} else {

    echo $OUTPUT->header();

    echo '<div class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap">';
    echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'editmode' => 1]))->out() . '" class="btn btn-secondary">Edit</a>';
    echo '<a href="' . (new moodle_url('/blocks/iomad_commerce/edit_order_form.php', ['id' => $invoiceid, 'addline' => 1]))->out() . '" class="btn btn-secondary">Add Line</a>';
    echo '<a href="#" class="btn btn-secondary" onclick="return false;">Cancel</a>';
    echo '<a href="#" class="btn btn-primary" onclick="return false;">Generate Invoice</a>';
    echo '</div>';

    if (!$invoice->paymentid) {
        echo "<a href='../../blocks/iomad_ecommerce/checkout.php?invoiceid=" . $invoiceid . "' target='_blank'><button class='btn btn-primary'>Make Payment</button></a>";
    }

    $mform->display();
    echo $OUTPUT->footer();
}
