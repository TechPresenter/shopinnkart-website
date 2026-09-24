<?php
/**
 * ShopInnKart - Default email templates and invoice/email settings.
 *
 * This file is the single source of truth for "what a stock template looks
 * like". The seeder installs anything missing, and the admin editor's
 * "Reset to default" reads the same array — so the two can never drift.
 *
 * Bodies are stored as HTML fragments; email_layout() wraps them in the
 * branded shell at send time. Placeholders are {{name}} and are substituted by
 * render_template(), a plain strtr() — there is no expression evaluation, so a
 * template can never execute anything.
 *
 * Run from the project root:
 *     php database/seeds/email-templates.php          install missing only
 *     php database/seeds/email-templates.php --force  overwrite every system template
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

// ---------------------------------------------------------------------------
//  Shared fragments
// ---------------------------------------------------------------------------

function tpl_greeting(string $name = '{{customer_name}}'): string
{
    return '<p style="margin:0 0 16px 0">Hi ' . $name . ',</p>';
}

function tpl_signoff(): string
{
    return '<p style="margin:24px 0 0 0">Warm regards,<br><strong>Team {{store_name}}</strong></p>';
}

/** The grey order-summary card used by most order emails. */
function tpl_order_card(): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
        . '<tr><td style="padding:16px 20px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
        . '<tr><td style="padding:4px 0;color:#6b7280">Order number</td><td style="padding:4px 0;text-align:right"><strong>{{order_number}}</strong></td></tr>'
        . '<tr><td style="padding:4px 0;color:#6b7280">Order date</td><td style="padding:4px 0;text-align:right">{{order_date}}</td></tr>'
        . '<tr><td style="padding:4px 0;color:#6b7280">Payment</td><td style="padding:4px 0;text-align:right">{{payment_method}} &middot; {{payment_status}}</td></tr>'
        . '<tr><td style="padding:4px 0;color:#6b7280">Order total</td><td style="padding:4px 0;text-align:right"><strong>{{order_total}}</strong></td></tr>'
        . '</table></td></tr></table>';
}

/** The itemised product table. {{order_items_html}} is pre-escaped server-side. */
function tpl_item_table(): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;margin:16px 0">'
        . '<tr style="color:#6b7280;font-size:12px;text-transform:uppercase">'
        . '<td style="padding:0 0 6px 0">Item</td>'
        . '<td style="padding:0 0 6px 0;text-align:center">Qty</td>'
        . '<td style="padding:0 0 6px 0;text-align:right">Amount</td></tr>'
        . '{{order_items_html}}'
        . '<tr><td style="padding:10px 0 0 0" colspan="2"><strong>Total</strong></td>'
        . '<td style="padding:10px 0 0 0;text-align:right"><strong>{{order_total}}</strong></td></tr>'
        . '</table>';
}

function tpl_button(string $label, string $url): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0">'
        . '<tr><td style="background-color:#F4511E;border-radius:8px">'
        . '<a href="' . $url . '" style="display:inline-block;padding:12px 26px;color:#ffffff;'
        . 'text-decoration:none;font-weight:600;font-size:14px">' . $label . '</a>'
        . '</td></tr></table>';
}

function tpl_support(): string
{
    // The store phone lives in the shared footer that email_layout() adds, so
    // it is deliberately not repeated here — appending {{support_phone}} to
    // this sentence ran the number straight into the address with no separator.
    return '<p style="margin:20px 0 0 0;font-size:13px;color:#6b7280">'
        . 'Questions about this order? Reply to this email or contact us at '
        . '<a href="mailto:{{store_email}}" style="color:#F4511E">{{store_email}}</a>.</p>';
}

function tpl_shipping_block(): string
{
    return '<p style="margin:16px 0 0 0;font-size:14px">'
        . '<span style="color:#6b7280">Delivering to</span><br>{{shipping_address}}<br>'
        . '<span style="color:#6b7280">Expected delivery:</span> {{estimated_delivery}}</p>';
}

// ---------------------------------------------------------------------------
//  Template definitions
// ---------------------------------------------------------------------------

/**
 * @return array<string, array{name:string, description:string, subject:string,
 *                             body:string, variables:string, recipient_type:string,
 *                             attach_invoice:int}>
 */
function email_template_defaults(): array
{
    $orderVars = '{{customer_name}}, {{customer_email}}, {{order_number}}, {{order_date}}, {{order_total}}, '
        . '{{order_subtotal}}, {{order_discount}}, {{order_shipping}}, {{order_tax}}, {{order_items}}, '
        . '{{order_items_html}}, {{item_count}}, {{payment_method}}, {{payment_status}}, {{order_status}}, '
        . '{{shipping_address}}, {{shipping_city}}, {{estimated_delivery}}, {{tracking_number}}, '
        . '{{courier_name}}, {{tracking_url}}, {{order_url}}, {{invoice_number}}, {{invoice_url}}, '
        . '{{invoice_download_url}}, {{amount_paid}}, {{balance_due}}, {{support_email}}, {{store_name}}';

    $t = [];

    // ---------------------------------------------------------------- ORDERS
    $t['order_placed'] = [
        'name' => 'Order Confirmation & Invoice',
        'description' => 'Sent to the customer the moment an order is placed. Carries the PDF invoice when one has been issued.',
        'subject' => 'Order Confirmation & Invoice #{{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 1,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Thank you for shopping with {{store_name}}. We have received your order and it is now being prepared.</p>'
            . tpl_order_card()
            . tpl_item_table()
            . tpl_shipping_block()
            . '<p style="margin:16px 0 0 0;font-size:14px">Your tax invoice <strong>{{invoice_number}}</strong> is attached to this email as a PDF.</p>'
            . tpl_button('Track your order', '{{order_url}}')
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['order_confirmed'] = [
        'name' => 'Order Confirmed',
        'description' => 'Sent when an order moves to Confirmed.',
        'subject' => 'Your order {{order_number}} is confirmed',
        'recipient_type' => 'customer',
        'attach_invoice' => 1,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Good news — order <strong>{{order_number}}</strong> is confirmed and moving to packing.</p>'
            . tpl_order_card()
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Items:</strong> {{order_items}}</p>'
            . tpl_shipping_block()
            . '<p style="margin:16px 0 0 0;font-size:14px">Nothing further is needed from you. You can still cancel from your order page until the parcel is dispatched.</p>'
            . tpl_button('View order', '{{order_url}}')
            . tpl_signoff(),
    ];

    $t['order_processing'] = [
        'name' => 'Order Processing',
        'description' => 'Sent when an order moves to Processing.',
        'subject' => 'Order {{order_number}} is being prepared',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your order <strong>{{order_number}}</strong> is being picked and prepared in our warehouse.</p>'
            . tpl_order_card()
            . tpl_shipping_block()
            . tpl_button('Track your order', '{{tracking_url}}')
            . tpl_signoff(),
    ];

    $t['order_packed'] = [
        'name' => 'Order Packed',
        'description' => 'Sent when an order is packed and awaiting pickup.',
        'subject' => 'Order {{order_number}} is packed and ready to ship',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Order <strong>{{order_number}}</strong> is packed and waiting for our courier partner to collect it.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">You will get the tracking number as soon as the parcel is handed over.</p>'
            . tpl_button('View order', '{{order_url}}')
            . tpl_signoff(),
    ];

    $t['order_shipped'] = [
        'name' => 'Order Shipped',
        'description' => 'Sent when an order is dispatched, with courier and tracking details.',
        'subject' => 'Order {{order_number}} has been shipped',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your order <strong>{{order_number}}</strong> has left our warehouse and is on its way to {{shipping_city}}.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr><td style="padding:16px 20px;font-size:14px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
            . '<tr><td style="padding:4px 0;color:#6b7280">Courier</td><td style="padding:4px 0;text-align:right">{{courier_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Tracking number</td><td style="padding:4px 0;text-align:right"><strong>{{tracking_number}}</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Expected delivery</td><td style="padding:4px 0;text-align:right">{{estimated_delivery}}</td></tr>'
            . '</table></td></tr></table>'
            . tpl_button('Track your parcel', '{{tracking_url}}')
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">The first courier scan can take up to 12 hours to appear. Please keep your phone reachable — high value orders need a one-time password at the door.</p>'
            . tpl_signoff(),
    ];

    $t['order_out_for_delivery'] = [
        'name' => 'Out for Delivery',
        'description' => 'Sent on the morning the parcel goes out for delivery.',
        'subject' => 'Order {{order_number}} is out for delivery today',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your parcel is out for delivery today and should reach you shortly.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">Delivering to:<br>{{shipping_address}}</p>'
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">Please keep {{tracking_number}} and a photo ID handy. Our delivery partner {{courier_name}} may call before arriving.</p>'
            . tpl_button('Track live', '{{tracking_url}}')
            . tpl_signoff(),
    ];

    $t['order_delivered'] = [
        'name' => 'Order Delivered',
        'description' => 'Sent once the order is marked delivered.',
        'subject' => 'Order {{order_number}} has been delivered',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{delivered_date}}, {{review_url}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your order <strong>{{order_number}}</strong> was delivered on {{delivered_date}}. We hope it is everything you expected.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 8px 0;font-size:14px">A few things worth doing now:</p>'
            . '<ul style="margin:0 0 16px 0;padding-left:20px;font-size:14px">'
            . '<li style="margin-bottom:6px">Download your tax invoice {{invoice_number}} and keep it for warranty</li>'
            . '<li style="margin-bottom:6px">Register the product with the brand if the box asks you to</li>'
            . '<li>Tell other shoppers what you think by leaving a review</li>'
            . '</ul>'
            . tpl_button('Write a review', '{{review_url}}')
            . '<p style="margin:8px 0 0 0;font-size:13px"><a href="{{invoice_download_url}}" style="color:#F4511E">Download invoice (PDF)</a></p>'
            . tpl_signoff(),
    ];

    $t['order_cancelled'] = [
        'name' => 'Order Cancelled',
        'description' => 'Sent when an order is cancelled by the customer or the store.',
        'subject' => 'Order {{order_number}} has been cancelled',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{cancel_reason}}, {{refund_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your order <strong>{{order_number}}</strong> has been cancelled ({{cancel_reason}}).</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">{{refund_note}}</p>'
            . tpl_button('Continue shopping', '{{store_url}}')
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['order_returned'] = [
        'name' => 'Order Returned',
        'description' => 'Sent when a returned order is received back.',
        'subject' => 'We have received your return for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{return_reason}}, {{refund_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your return for order <strong>{{order_number}}</strong> has been received and checked in.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">{{refund_note}}</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['order_refunded'] = [
        'name' => 'Order Refunded',
        'description' => 'Sent when an order is fully refunded.',
        'subject' => 'Refund processed for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">The refund for order <strong>{{order_number}}</strong> has been processed.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">The amount is credited back to your original payment method. Depending on your bank it can take 5-7 working days to appear on your statement.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    // --------------------------------------------------------------- INVOICE
    $t['invoice_generated'] = [
        'name' => 'Invoice Generated',
        'description' => 'Standalone invoice email. Used when an invoice is raised or re-sent on its own.',
        'subject' => 'Invoice {{invoice_number}} for order #{{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 1,
        'variables' => $orderVars . ', {{invoice_date}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Please find attached the tax invoice for your order with {{store_name}}.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr><td style="padding:16px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
            . '<tr><td style="padding:4px 0;color:#6b7280">Invoice number</td><td style="padding:4px 0;text-align:right"><strong>{{invoice_number}}</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Invoice date</td><td style="padding:4px 0;text-align:right">{{invoice_date}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Order number</td><td style="padding:4px 0;text-align:right">{{order_number}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Amount paid</td><td style="padding:4px 0;text-align:right">{{amount_paid}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Balance due</td><td style="padding:4px 0;text-align:right">{{balance_due}}</td></tr>'
            . '</table></td></tr></table>'
            . '<p style="margin:0 0 16px 0;font-size:14px">The PDF is attached. Please keep it for warranty and returns — service centres ask for it as proof of purchase.</p>'
            . tpl_button('Download invoice', '{{invoice_download_url}}')
            . tpl_signoff(),
    ];

    // -------------------------------------------------------------- PAYMENTS
    $t['payment_success'] = [
        'name' => 'Payment Successful',
        'description' => 'Sent when a payment is confirmed by the gateway.',
        'subject' => 'Payment received for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We have received your payment of <strong>{{order_total}}</strong> for order <strong>{{order_number}}</strong>. Thank you.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">Your tax invoice {{invoice_number}} is available from your order page.</p>'
            . tpl_button('View order', '{{order_url}}')
            . tpl_signoff(),
    ];

    $t['payment_failed'] = [
        'name' => 'Payment Failed',
        'description' => 'Sent when a payment attempt is declined.',
        'subject' => 'Payment could not be completed for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{failure_reason}}, {{retry_url}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We were unable to process your payment for order <strong>{{order_number}}</strong>.</p>'
            . '<p style="margin:0 0 16px 0;padding:12px 16px;background-color:#fef2f2;border-left:3px solid #dc2626;font-size:14px;color:#991b1b">{{failure_reason}}</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">No amount has been charged. If your bank shows a debit, it is a temporary hold that is released automatically within 5-7 working days.</p>'
            . tpl_button('Try again', '{{retry_url}}')
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['payment_pending'] = [
        'name' => 'Payment Pending',
        'description' => 'Sent when a payment is awaiting confirmation from the bank or gateway.',
        'subject' => 'Payment pending for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your payment for order <strong>{{order_number}}</strong> is still being confirmed by your bank. This usually settles within a few minutes.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">We will email you the moment it clears. Please do not pay again — a second attempt could be charged separately.</p>'
            . tpl_button('View order', '{{order_url}}')
            . tpl_signoff(),
    ];

    // --------------------------------------------------------------- REFUNDS
    $t['refund_initiated'] = [
        'name' => 'Refund Initiated',
        'description' => 'Sent when a refund has been raised with the payment provider.',
        'subject' => 'Refund initiated for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{refund_amount}}, {{refund_eta}}, {{refund_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We have initiated a refund of <strong>{{refund_amount}}</strong> for order <strong>{{order_number}}</strong>.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">It is credited back to your original payment method and typically appears within {{refund_eta}}.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['refund_completed'] = [
        'name' => 'Refund Completed',
        'description' => 'Sent once the payment provider confirms the refund.',
        'subject' => 'Refund completed for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{refund_amount}}, {{refund_eta}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your refund of <strong>{{refund_amount}}</strong> for order <strong>{{order_number}}</strong> is complete.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">If it has not reached your account yet, please allow up to {{refund_eta}} and quote {{order_number}} to your bank.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    // ------------------------------------------------------ COURIER MILESTONES
    // The shipping hub's own events, for the moments the ORDER status cannot
    // express. A pickup being scheduled, a parcel turning round (RTO) and a
    // reverse pickup being booked all leave the order exactly where it was, so
    // none of them reaches notify_order_status_changed() - and before these the
    // customer heard nothing at all.
    //
    // {{courier_tracking_url}} is the courier's own page when the hub knows one
    // and this store's tracking page otherwise, so the button always goes
    // somewhere real. {{shipment_status}} is the courier's wording.
    $courierVars = $orderVars . ', {{courier_tracking_url}}, {{shipment_status}}, {{pickup_date}}';

    $t['shipment_pickup_scheduled'] = [
        'name' => 'Courier Pickup Scheduled',
        'description' => 'Sent when the courier has been booked to collect the parcel from us.',
        'subject' => 'Your order {{order_number}} is booked for courier pickup',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $courierVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Good news - <strong>{{courier_name}}</strong> is booked to collect order '
            . '<strong>{{order_number}}</strong> from our warehouse. It moves to the courier network next.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr><td style="padding:16px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
            . '<tr><td style="padding:4px 0;color:#6b7280">Courier</td><td style="padding:4px 0;text-align:right">{{courier_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Tracking number</td><td style="padding:4px 0;text-align:right"><strong>{{tracking_number}}</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Pickup booked for</td><td style="padding:4px 0;text-align:right">{{pickup_date}}</td></tr>'
            . '</table></td></tr></table>'
            . tpl_button('Track your parcel', '{{tracking_url}}')
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">The courier\'s own page is at '
            . '<a href="{{courier_tracking_url}}" style="color:#F4511E">{{courier_tracking_url}}</a>. '
            . 'Scans usually start appearing a few hours after collection.</p>'
            . tpl_signoff(),
    ];

    $t['shipment_rto'] = [
        'name' => 'Parcel Returning To Us (RTO)',
        'description' => 'Sent when the courier turns a parcel round and sends it back to the warehouse.',
        'subject' => 'Your order {{order_number}} is on its way back to us',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $courierVars . ', {{refund_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your parcel for order <strong>{{order_number}}</strong> could not be delivered, '
            . 'and <strong>{{courier_name}}</strong> is returning it to our warehouse.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr><td style="padding:16px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
            . '<tr><td style="padding:4px 0;color:#6b7280">Courier</td><td style="padding:4px 0;text-align:right">{{courier_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Tracking number</td><td style="padding:4px 0;text-align:right"><strong>{{tracking_number}}</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Courier status</td><td style="padding:4px 0;text-align:right">{{shipment_status}}</td></tr>'
            . '</table></td></tr></table>'
            . '<p style="margin:16px 0 0 0;font-size:14px">{{refund_note}}</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">If you still want this order, reply to this email and we will '
            . 'arrange a fresh dispatch as soon as the parcel is back with us.</p>'
            . tpl_button('See the order', '{{order_url}}')
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['shipment_return_pickup'] = [
        'name' => 'Return Pickup Booked',
        'description' => 'Sent when a courier has been booked to collect a return from the customer.',
        'subject' => 'A courier is booked to collect your return for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $courierVars . ', {{return_reason}}, {{refund_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We have booked <strong>{{courier_name}}</strong> to collect your return for order '
            . '<strong>{{order_number}}</strong> from your delivery address.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:20px 0">'
            . '<tr><td style="padding:16px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px">'
            . '<tr><td style="padding:4px 0;color:#6b7280">Courier</td><td style="padding:4px 0;text-align:right">{{courier_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Return tracking number</td><td style="padding:4px 0;text-align:right"><strong>{{tracking_number}}</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Collecting from</td><td style="padding:4px 0;text-align:right">{{shipping_city}}</td></tr>'
            . '</table></td></tr></table>'
            . '<p style="margin:16px 0 0 0;font-size:14px">Please keep the item in its original packaging with all accessories '
            . 'and the invoice, and hand the parcel over against the tracking number above.</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">{{refund_note}}</p>'
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">Follow the pickup at '
            . '<a href="{{courier_tracking_url}}" style="color:#F4511E">{{courier_tracking_url}}</a>.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    // --------------------------------------------------------------- RETURNS
    $t['return_requested'] = [
        'name' => 'Return Requested',
        'description' => 'Acknowledges a customer return request.',
        'subject' => 'Return request received for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{return_reason}}, {{return_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We have received your return request for order <strong>{{order_number}}</strong>.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px"><strong>Reason:</strong> {{return_reason}}</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">Our team reviews return requests within one working day and will email you the outcome along with pickup instructions.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['return_approved'] = [
        'name' => 'Return Approved',
        'description' => 'Sent when a return request is approved.',
        'subject' => 'Return approved for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{return_reason}}, {{return_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your return for order <strong>{{order_number}}</strong> has been approved.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">{{return_note}}</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">Please keep the item in its original packaging with all accessories and the invoice. Our courier will collect it from your delivery address.</p>'
            . tpl_signoff(),
    ];

    $t['return_rejected'] = [
        'name' => 'Return Rejected',
        'description' => 'Sent when a return request cannot be accepted.',
        'subject' => 'Update on your return request for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{return_reason}}, {{return_note}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We are unable to accept the return for order <strong>{{order_number}}</strong>.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">{{return_note}}</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    // -------------------------------------------------------------- ACCOUNT
    $t['welcome'] = [
        'name' => 'Welcome / Registration',
        'description' => 'Sent when a customer creates an account.',
        'subject' => 'Welcome to {{store_name}}, {{customer_name}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{customer_email}}, {{login_url}}, {{shop_url}}, {{account_url}}, {{coupon_code}}, {{coupon_value}}, {{store_name}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Welcome to {{store_name}}. Your account is ready and registered to <strong>{{customer_email}}</strong>.</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px">From your account you can track orders, download tax invoices, save addresses and manage your wishlist.</p>'
            . tpl_button('Start shopping', '{{shop_url}}')
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">If you did not create this account, please contact us at {{store_email}}.</p>'
            . tpl_signoff(),
    ];

    $t['email_verify'] = [
        'name' => 'Email Verification',
        'description' => 'Carries the link that proves the customer owns their email address.',
        'subject' => 'Confirm your email address for {{store_name}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{customer_email}}, {{verify_url}}, {{expiry_hours}}, {{store_name}}, {{store_email}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Please confirm that <strong>{{customer_email}}</strong> is your address so we can send you order updates and invoices reliably.</p>'
            . tpl_button('Confirm my email address', '{{verify_url}}')
            . '<p style="margin:0 0 16px 0;font-size:14px">This link expires in {{expiry_hours}} hours. If the button does not work, copy this address into your browser:<br>'
            . '<span style="color:#6b7280;font-size:12px;word-break:break-all">{{verify_url}}</span></p>'
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">You can keep shopping and place orders in the meantime — confirming just makes sure our emails reach you. '
            . 'If you did not create an account with us, you can ignore this message.</p>'
            . tpl_signoff(),
    ];

    $t['password_reset'] = [
        'name' => 'Password Reset',
        'description' => 'Carries the password reset link.',
        'subject' => 'Reset your {{store_name}} password',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{customer_email}}, {{reset_url}}, {{expiry}}, {{expiry_minutes}}, {{request_ip}}, {{store_name}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We received a request to reset the password for <strong>{{customer_email}}</strong>.</p>'
            . tpl_button('Reset my password', '{{reset_url}}')
            . '<p style="margin:0 0 16px 0;font-size:14px">This link expires in {{expiry}}. If the button does not work, copy this address into your browser:<br>'
            . '<span style="color:#6b7280;font-size:12px;word-break:break-all">{{reset_url}}</span></p>'
            . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">If you did not ask for this, you can ignore this email — your password stays unchanged.</p>'
            . tpl_signoff(),
    ];

    $t['newsletter_welcome'] = [
        'name' => 'Newsletter Subscription',
        'description' => 'Confirms a newsletter subscription.',
        'subject' => 'You are subscribed to {{store_name}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{subscriber_email}}, {{shop_url}}, {{unsubscribe_url}}, {{coupon_code}}, {{coupon_value}}, {{store_name}}',
        'body' => '<p style="margin:0 0 16px 0">Hi,</p>'
            . '<p style="margin:0 0 16px 0">Thanks for subscribing with <strong>{{subscriber_email}}</strong>. You will hear from us when something genuinely good goes on sale — not more often.</p>'
            . tpl_button('Browse the store', '{{shop_url}}')
            . '<p style="margin:16px 0 0 0;font-size:12px;color:#6b7280">Changed your mind? <a href="{{unsubscribe_url}}" style="color:#6b7280">Unsubscribe</a> at any time.</p>'
            . tpl_signoff(),
    ];

    $t['review_approved'] = [
        'name' => 'Review Approved',
        'description' => 'Sent when a customer review is published.',
        'subject' => 'Your review of {{product_name}} is live',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{product_name}}, {{product_url}}, {{rating}}, {{store_name}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Your {{rating}} review of <strong>{{product_name}}</strong> has been published. Thank you for helping other shoppers decide.</p>'
            . tpl_button('See your review', '{{product_url}}')
            . tpl_signoff(),
    ];

    $t['back_in_stock'] = [
        'name' => 'Back In Stock Alert',
        'description' => 'Sent to everyone waiting on a product that has been restocked.',
        'subject' => '{{product_name}} is back in stock',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{product_name}}, {{product_url}}, {{product_price}}, {{store_name}}',
        'body' => '<p style="margin:0 0 16px 0">Hi,</p>'
            . '<p style="margin:0 0 16px 0"><strong>{{product_name}}</strong> is available again at {{product_price}}.</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px">Restocks on popular items sell through quickly, so grab it while it lasts.</p>'
            . tpl_button('Buy it now', '{{product_url}}')
            . tpl_signoff(),
    ];

    $returnVars = $orderVars . ', {{return_reason}}, {{return_note}}, {{return_type}}, {{return_id}}';

    $t['return_requested'] = [
        'name' => 'Return Requested',
        'description' => 'Acknowledges a customer return or exchange request.',
        'subject' => 'We have your {{return_type}} request for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $returnVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We have received your {{return_type}} request for order <strong>{{order_number}}</strong>.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px"><strong>Reason:</strong> {{return_reason}}</p>'
            . '<p style="margin:8px 0 0 0;font-size:14px">{{return_note}}</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">Our team reviews requests within one working day and will email you the outcome along with pickup instructions.</p>'
            . tpl_button('View order', '{{order_url}}')
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['return_approved'] = [
        'name' => 'Return Approved',
        'description' => 'Sent when a return or exchange request is approved.',
        'subject' => 'Your {{return_type}} for order {{order_number}} is approved',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $returnVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Good news — your {{return_type}} request for order <strong>{{order_number}}</strong> has been approved.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;font-size:14px">{{return_note}}</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px">Please keep the item in its original packaging with all accessories and the invoice. '
            . 'Our courier will collect it from your delivery address.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['return_rejected'] = [
        'name' => 'Return Rejected',
        'description' => 'Sent when a return or exchange request cannot be accepted.',
        'subject' => 'Update on your {{return_type}} request for order {{order_number}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => $returnVars,
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">We are sorry — we are unable to accept the {{return_type}} for order <strong>{{order_number}}</strong>.</p>'
            . tpl_order_card()
            . '<p style="margin:16px 0 0 0;padding:12px 16px;background-color:#f9fafb;border-left:3px solid #F4511E;font-size:14px">{{return_note}}</p>'
            . '<p style="margin:16px 0 0 0;font-size:14px">If you think this is a mistake, reply to this email and we will take another look.</p>'
            . tpl_support()
            . tpl_signoff(),
    ];

    $t['contact_reply'] = [
        'name' => 'Contact Enquiry Reply',
        'description' => 'The reply an admin sends from Messages.',
        'subject' => 'Re: your enquiry to {{store_name}}',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{subject}}, {{message}}, {{reply}}, {{store_name}}, {{store_email}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Thank you for getting in touch. Here is our reply:</p>'
            . '<div style="margin:0 0 16px 0;padding:14px 18px;background-color:#f9fafb;border-left:3px solid #F4511E;font-size:14px">{{reply}}</div>'
            . '<p style="margin:0 0 8px 0;font-size:13px;color:#6b7280">Your original message:</p>'
            . '<div style="margin:0 0 16px 0;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#6b7280">{{message}}</div>'
            . tpl_signoff(),
    ];

    $t['contact_received'] = [
        'name' => 'Contact Enquiry Acknowledgement',
        'description' => 'Auto-acknowledgement sent to a customer who uses the contact form.',
        'subject' => 'We received your message',
        'recipient_type' => 'customer',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{subject}}, {{message}}, {{store_name}}, {{store_email}}',
        'body' => tpl_greeting()
            . '<p style="margin:0 0 16px 0">Thanks for contacting {{store_name}}. We have your message and will reply within one working day.</p>'
            . '<div style="margin:0 0 16px 0;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#6b7280">'
            . '<strong>{{subject}}</strong><br>{{message}}</div>'
            . tpl_signoff(),
    ];

    // ----------------------------------------------------------- ADMIN ALERTS
    $adminHeader = '<p style="margin:0 0 16px 0;font-size:13px;color:#6b7280">Automated store alert</p>';

    $t['order_placed_admin'] = [
        'name' => 'Admin — New Order',
        'description' => 'Alerts the order desk that a new order has arrived.',
        'subject' => 'New order {{order_number}} — {{order_total}}',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => $orderVars,
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A new order has been placed.</p>'
            . tpl_order_card()
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Customer:</strong> {{customer_name}} &middot; {{customer_email}} &middot; {{customer_phone}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Items ({{item_count}}):</strong> {{order_items}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Ship to:</strong> {{shipping_address}}</p>'
            . tpl_button('Open in admin', '{{order_url}}'),
    ];

    $t['payment_failed_admin'] = [
        'name' => 'Admin — Payment Failed',
        'description' => 'Alerts staff to a declined payment.',
        'subject' => 'Payment failed — order {{order_number}}',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{failure_reason}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A payment attempt failed and needs review.</p>'
            . tpl_order_card()
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Reason:</strong> {{failure_reason}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Customer:</strong> {{customer_name}} &middot; {{customer_email}}</p>',
    ];

    $t['order_cancelled_admin'] = [
        'name' => 'Admin — Order Cancelled',
        'description' => 'Alerts staff that an order was cancelled.',
        'subject' => 'Order {{order_number}} cancelled',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{cancel_reason}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">An order has been cancelled.</p>'
            . tpl_order_card()
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Reason:</strong> {{cancel_reason}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Customer:</strong> {{customer_name}} &middot; {{customer_email}}</p>',
    ];

    $t['customer_registered_admin'] = [
        'name' => 'Admin — New Customer',
        'description' => 'Alerts staff when a customer account is created.',
        'subject' => 'New customer registered — {{customer_name}}',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{customer_email}}, {{signup_date}}, {{customer_url}}, {{store_name}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A new customer account has been created.</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Name:</strong> {{customer_name}}<br>'
            . '<strong>Email:</strong> {{customer_email}}<br>'
            . '<strong>Registered:</strong> {{signup_date}}</p>'
            . tpl_button('View customer', '{{customer_url}}'),
    ];

    $t['return_requested_admin'] = [
        'name' => 'Admin — Return Request',
        'description' => 'Alerts staff to a new return or exchange request awaiting a decision.',
        // {{return_type}} is lower-case for mid-sentence use, so the subject
        // never opens on it.
        'subject' => 'Action needed: {{return_type}} request for order {{order_number}}',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => $orderVars . ', {{return_reason}}, {{return_note}}, {{return_type}}, {{return_id}}, {{return_admin_url}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A customer has raised a {{return_type}} request that needs a decision.</p>'
            . tpl_order_card()
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Reason:</strong> {{return_reason}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Their comment:</strong> {{return_note}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Customer:</strong> {{customer_name}} &middot; {{customer_email}} &middot; {{customer_phone}}</p>'
            . tpl_button('Review the request', '{{return_admin_url}}'),
    ];

    $t['contact_message'] = [
        'name' => 'Admin — Contact Enquiry',
        'description' => 'Alerts staff to a new message from the contact form.',
        'subject' => 'New enquiry: {{subject}}',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => '{{customer_name}}, {{customer_email}}, {{customer_phone}}, {{subject}}, {{message}}, {{store_name}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A new enquiry has arrived through the contact form.</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>From:</strong> {{customer_name}} &middot; {{customer_email}} &middot; {{customer_phone}}</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Subject:</strong> {{subject}}</p>'
            . '<div style="margin:12px 0 0 0;padding:12px 16px;background-color:#f9fafb;border-radius:8px;font-size:14px">{{message}}</div>',
    ];

    $t['low_stock_admin'] = [
        'name' => 'Admin — Low Stock',
        'description' => 'Warns staff when a product falls to or below its low-stock threshold.',
        'subject' => 'Low stock: {{product_name}} ({{stock_quantity}} left)',
        'recipient_type' => 'admin',
        'attach_invoice' => 0,
        'variables' => '{{product_name}}, {{product_sku}}, {{stock_quantity}}, {{threshold}}, {{product_url}}, {{store_name}}',
        'body' => $adminHeader
            . '<p style="margin:0 0 16px 0">A product has dropped to its low-stock threshold.</p>'
            . '<p style="margin:0 0 8px 0;font-size:14px"><strong>Product:</strong> {{product_name}}<br>'
            . '<strong>SKU:</strong> {{product_sku}}<br>'
            . '<strong>Remaining:</strong> {{stock_quantity}} (threshold {{threshold}})</p>'
            . tpl_button('Restock', '{{product_url}}'),
    ];

    return $t;
}

// ---------------------------------------------------------------------------
//  Default settings
// ---------------------------------------------------------------------------

/** @return array<string, array{0:mixed, 1:string, 2:string}> key => [value, group, type] */
function email_invoice_setting_defaults(): array
{
    return [
        // Invoice identity
        'invoice_prefix'          => ['INV', 'invoice', 'text'],
        'invoice_start_number'    => ['1', 'invoice', 'number'],
        'invoice_number_padding'  => ['6', 'invoice', 'number'],
        'invoice_year_mode'       => ['calendar', 'invoice', 'select'],

        // Company block
        'invoice_company_name'    => ['', 'invoice', 'text'],
        'invoice_company_address' => ['', 'invoice', 'textarea'],
        'invoice_seller_state'    => ['', 'invoice', 'text'],
        'invoice_pan'             => ['', 'invoice', 'text'],
        'invoice_signatory'       => ['', 'invoice', 'text'],
        'invoice_terms'           => [invoice_default_terms(), 'invoice', 'textarea'],
        'invoice_footer'          => ['This is a computer generated invoice and does not require a signature.', 'invoice', 'textarea'],
        'invoice_auto_generate'   => ['1', 'invoice', 'boolean'],
        'invoice_attach_to_order_email' => ['1', 'invoice', 'boolean'],

        // Store address parts the invoice header needs
        'store_city'              => ['', 'general', 'text'],
        'store_state'             => ['', 'general', 'text'],
        'store_pincode'           => ['', 'general', 'text'],
        'store_country'           => ['India', 'general', 'text'],

        // SMTP
        'mail_driver'             => ['smtp', 'email', 'select'],
        'smtp_host'               => ['', 'email', 'text'],
        'smtp_port'               => ['587', 'email', 'number'],
        'smtp_user'               => ['', 'email', 'text'],
        'smtp_pass'               => ['', 'email', 'text'],
        'smtp_encryption'         => ['tls', 'email', 'select'],
        'smtp_timeout'            => ['20', 'email', 'number'],
        'smtp_auth'               => ['1', 'email', 'boolean'],
        'smtp_allow_self_signed'  => ['0', 'email', 'boolean'],
        'mail_reply_to'           => ['', 'email', 'text'],

        // Delivery behaviour
        'email_queue_auto_drain'  => ['1', 'email', 'boolean'],
        'email_log_retention_days' => ['90', 'email', 'number'],
        'password_reset_expiry_minutes' => ['60', 'email', 'number'],
        'email_verification_enabled' => ['1', 'email', 'boolean'],
        'email_verify_expiry_hours' => ['48', 'email', 'number'],
        'webhook_timestamp_tolerance' => ['300', 'email', 'number'],

        // Returns
        'returns_enabled'         => ['1', 'general', 'boolean'],
        'return_window_days'      => ['7', 'general', 'number'],
        'exchange_enabled'        => ['1', 'general', 'boolean'],
        'low_stock_threshold'     => ['5', 'email', 'number'],
        'low_stock_alerts_enabled' => ['1', 'email', 'boolean'],
    ];
}

// ---------------------------------------------------------------------------
//  Seeder
// ---------------------------------------------------------------------------

/**
 * Install the default templates and settings.
 *
 * @param bool $force Overwrite the subject/body of templates marked is_system.
 * @return array{templates_added:int, templates_updated:int, settings_added:int}
 */
function seed_email_templates(bool $force = false): array
{
    $added = 0;
    $updated = 0;
    $settingsAdded = 0;

    foreach (email_template_defaults() as $key => $template) {
        $existing = Database::fetch(
            "SELECT * FROM `notification_templates` WHERE `template_key` = :k AND `channel` = 'email' LIMIT 1",
            ['k' => $key]
        );

        $bodyText = html_to_text($template['body']);

        if ($existing === null) {
            Database::insert('notification_templates', [
                'template_key'   => $key,
                'name'           => $template['name'],
                'description'    => $template['description'],
                'channel'        => 'email',
                'recipient_type' => $template['recipient_type'],
                'subject'        => $template['subject'],
                'body'           => $template['body'],
                'body_text'      => $bodyText,
                'variables'      => $template['variables'],
                'attach_invoice' => $template['attach_invoice'],
                'is_system'      => 1,
                'status'         => 'active',
            ]);
            $added++;
            continue;
        }

        // Metadata is always refreshed — it describes the template rather than
        // being the operator's copy. Subject/body are only touched on --force.
        $data = [
            'description'    => $template['description'],
            'variables'      => $template['variables'],
            'recipient_type' => $template['recipient_type'],
            'is_system'      => 1,
        ];

        if ($force) {
            $data['name'] = $template['name'];
            $data['subject'] = $template['subject'];
            $data['body'] = $template['body'];
            $data['body_text'] = $bodyText;
            $data['attach_invoice'] = $template['attach_invoice'];
        } elseif (trim((string) ($existing['body_text'] ?? '')) === '') {
            // Fill in the plain-text part for templates seeded before it existed.
            $data['body_text'] = html_to_text((string) $existing['body']);
            $data['attach_invoice'] = $template['attach_invoice'];
        }

        Database::update('notification_templates', $data, '`id` = :id', ['id' => (int) $existing['id']]);
        $updated++;
    }

    foreach (email_invoice_setting_defaults() as $key => [$value, $group, $type]) {
        $exists = Database::fetchColumn('SELECT `id` FROM `settings` WHERE `setting_key` = :k', ['k' => $key]);
        if ($exists) {
            continue;
        }
        Database::insert('settings', [
            'setting_group' => $group,
            'setting_key'   => $key,
            'setting_value' => $value,
            'setting_type'  => $type,
        ]);
        $settingsAdded++;
    }

    return ['templates_added' => $added, 'templates_updated' => $updated, 'settings_added' => $settingsAdded];
}

/** Restore one system template to its shipped default. */
function reset_email_template(string $key): bool
{
    $defaults = email_template_defaults();
    if (!isset($defaults[$key])) {
        return false;
    }

    $template = $defaults[$key];

    return Database::update('notification_templates', [
        'name'           => $template['name'],
        'description'    => $template['description'],
        'subject'        => $template['subject'],
        'body'           => $template['body'],
        'body_text'      => html_to_text($template['body']),
        'variables'      => $template['variables'],
        'recipient_type' => $template['recipient_type'],
        'attach_invoice' => $template['attach_invoice'],
        'status'         => 'active',
    ], "`template_key` = :k AND `channel` = 'email'", ['k' => $key]) >= 0;
}

// ---------------------------------------------------------------------------
//  CLI entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $force = in_array('--force', $argv, true);
    $result = seed_email_templates($force);

    printf(
        'Templates added: %d, refreshed: %d%s' . PHP_EOL . 'Settings added: %d' . PHP_EOL,
        $result['templates_added'],
        $result['templates_updated'],
        $force ? ' (bodies overwritten)' : '',
        $result['settings_added']
    );
    exit(0);
}
