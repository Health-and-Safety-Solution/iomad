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

namespace block_iomad_commerce\forms;

use \moodleform;
use \context_system;
use \block_iomad_commerce\helper;

class order_edit_form extends moodleform {
    protected $invoiceid = 0;
    protected $showaccount = false;
    protected $context = null;
    protected $editmode = false;
    protected $editline = null;

    public function __construct($actionurl, $invoiceid, $showaccount = false, $editmode = false, $editline = null) {
        global $CFG;

        $this->invoiceid = $invoiceid;
        $this->context = context_system::instance();
        $this->showaccount = $showaccount;
        $this->editmode = $editmode;
        $this->editline = $editline;

        parent::__construct($actionurl);
    }

    public function definition() {
        global $CFG, $DB;

        $mform =& $this->_form;

        $strrequired = get_string('required');

        $mform->addElement('hidden', 'id', $this->invoiceid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('html', "<p style='font-size:16px;'>To enrol delegates scroll to the bottom of this page and select the enrol button next to the relevant course.</p><p style='font-size:13px;'><u>Please note:</u> If you create the login for the delegate or they already exist within your company's user list with the correct email address, you should be able to assign them. If the delegate has created their own account they must click the link in in the confirmation email sent to them before you are able to enrol them on courses.</p>");

        $mform->addElement('header', 'header', get_string('order', 'block_iomad_commerce'));

        if ($this->editmode) {
            $mform->addElement('text', 'reference', get_string('reference', 'block_iomad_commerce'));
            $mform->setType('reference', PARAM_RAW_TRIMMED);
            $mform->addRule('reference', get_string('required'), 'required', null, 'client');
        } else {
            $mform->addElement('static', 'reference', get_string('reference', 'block_iomad_commerce'));
        }

        $po_detail = $DB->get_record_sql("select po,mdl_blocks_ecommerce_status.status from mdl_paygw_po LEFT JOIN mdl_blocks_ecommerce_status ON  mdl_paygw_po.invoiceid = mdl_blocks_ecommerce_status.invoiceid WHERE mdl_paygw_po.invoiceid=" . $this->invoiceid);
        if ($po_detail) {
            $pay_status = ($po_detail->status == 'p' ? 'Paid' : 'Unpaid');
            $mform->addElement('static', 'static', 'On Account booking using PO/Ref# <b>' . $po_detail->po . '</b>. Invoice is ' . $pay_status);
        } else {
            $choices = [];
            foreach ([\block_iomad_commerce\helper::INVOICESTATUS_UNPAID, \block_iomad_commerce\helper::INVOICESTATUS_PAID] as $status) {
                $choices[$status] = get_string('status_' . $status, 'block_iomad_commerce');
            }
            $mform->addElement('select', 'status', get_string('status'), $choices);
            $mform->addRule('status', $strrequired, 'required', null, 'client');
            $mform->disabledIf('status', 'id', 'ne', 0);
        }

        $mform->addElement('header', 'header', get_string('purchaser_details', 'block_iomad_commerce'));

        if ($this->editmode) {
            $mform->addElement('text', 'firstname', get_string('firstname'));
            $mform->setType('firstname', PARAM_TEXT);
            $mform->addRule('firstname', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'lastname', get_string('lastname'));
            $mform->setType('lastname', PARAM_TEXT);
            $mform->addRule('lastname', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'company', get_string('company', 'block_iomad_company_admin'));
            $mform->setType('company', PARAM_TEXT);

            $mform->addElement('text', 'address', get_string('address'));
            $mform->setType('address', PARAM_TEXT);
            $mform->addRule('address', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'city', get_string('city'));
            $mform->setType('city', PARAM_TEXT);
            $mform->addRule('city', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'postcode', get_string('postcode', 'block_iomad_commerce'));
            $mform->setType('postcode', PARAM_TEXT);
            $mform->addRule('postcode', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'state', get_string('state', 'block_iomad_commerce'));
            $mform->setType('state', PARAM_TEXT);

            $mform->addElement('text', 'country', get_string('selectacountry'));
            $mform->setType('country', PARAM_TEXT);

            $mform->addElement('text', 'email', get_string('email'));
            $mform->setType('email', PARAM_EMAIL);
            $mform->addRule('email', $strrequired, 'required', null, 'client');

            $mform->addElement('text', 'phone1', get_string('phone'));
            $mform->setType('phone1', PARAM_TEXT);

            $this->add_action_buttons(true, get_string('savechanges'));
        } else {
            $mform->addElement('static', 'firstname', get_string('firstname'));

            $mform->addElement('static', 'lastname', get_string('lastname'));
            $mform->addElement('static', 'company', get_string('company', 'block_iomad_company_admin'));
            $mform->addElement('static', 'address', get_string('address'));
            $mform->addElement('static', 'city', get_string('city'));
            $mform->addElement('static', 'postcode', get_string('postcode', 'block_iomad_commerce'));
            $mform->addElement('static', 'state', get_string('state', 'block_iomad_commerce'));
            $mform->addElement('static', 'country', get_string('selectacountry'));
            $mform->addElement('static', 'email', get_string('email'));
            $mform->addElement('static', 'phone1', get_string('phone'));

            $mform->addElement('header', 'header', get_string('basket', 'block_iomad_commerce'));

            $editline = $this->editline ?? null;
            $mform->addElement('html', \block_iomad_commerce\helper::get_invoice_html($this->invoiceid, 0, 1, 0));
        }

        $mform->addElement('header', 'header', get_string('paymentprocessing', 'block_iomad_commerce'));

        $mform->addElement('static', 'checkout_method', get_string('paymentprovider', 'block_iomad_commerce'));

        if ($this->showaccount) {
            $mform->addElement('static', 'pp_account', get_string('paymentaccount', 'payment'));
        }

        $this->add_action_buttons(false, get_string('back'));
    }
}
