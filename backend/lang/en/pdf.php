<?php

/*
|--------------------------------------------------------------------------
| Quote PDF — static labels (Phase 14 / Track D + Track E, shared task)
|--------------------------------------------------------------------------
|
| See lang/tr/pdf.php for the full rationale (locale decision, why `status.*`
| duplicates the enum labels for the PDF specifically).
|
*/

return [

    'quote_label' => 'Quote',
    'title_label' => 'Title',
    'date_label' => 'Date',
    'validity_label' => 'Valid Until',
    'status_label' => 'Status',

    'customer_info' => 'Customer Information',
    'phone_label' => 'Phone',
    'email_label' => 'Email',

    'items_section' => 'Quote Items',
    'col_no' => '#',
    'col_description' => 'Description',
    'col_quantity' => 'Quantity',
    'col_unit_price' => 'Unit Price',
    'col_discount' => 'Discount %',
    'col_tax' => 'Tax %',
    'col_amount' => 'Amount',
    'unit_piece' => 'pcs',

    'subtotal' => 'Subtotal',
    'discount' => 'Discount',
    'tax' => 'Tax',
    'grand_total' => 'GRAND TOTAL',

    'notes_section' => 'Notes',
    'terms_section' => 'Terms and Conditions',

    'page_indicator' => 'Page :page / :total',

    'exchange_rate_line' => '1 :currency = :rate :base (:date)',

    'status' => [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ],

];
