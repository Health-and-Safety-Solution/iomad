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
$PAGE->set_url($linkurl);
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

$showaccount = false;
if (iomad::has_capability('block/iomad_company_admin:company_add', $companycontext)) {
    $showaccount = true;
}
$mform = new \block_iomad_commerce\forms\order_edit_form($PAGE->url, $invoiceid, $showaccount);
$mform->set_data($invoice);

if ($mform->is_cancelled()) {
    redirect($companylist);

} else if ($data = $mform->get_data()) {
    redirect($companylist);

} else {

    echo $OUTPUT->header();

if (!$invoice->paymentid) {
	echo "<a href='../../blocks/iomad_ecommerce/checkout.php?invoiceid=".$invoiceid."' target='_blank'><button class='btn btn-primary'>Make Payment</button></a>";
}

    $mform->display();
    echo $OUTPUT->footer();
}
