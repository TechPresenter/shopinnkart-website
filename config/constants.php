<?php
/**
 * ShopInnKart - Application Constants
 * Enumerations and fixed lookup data shared across frontend, API and admin.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Order lifecycle
// ---------------------------------------------------------------------------
const ORDER_STATUS_PENDING          = 'pending';
const ORDER_STATUS_CONFIRMED        = 'confirmed';
const ORDER_STATUS_PROCESSING       = 'processing';
const ORDER_STATUS_PACKED           = 'packed';
const ORDER_STATUS_SHIPPED          = 'shipped';
const ORDER_STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
const ORDER_STATUS_DELIVERED        = 'delivered';
const ORDER_STATUS_CANCELLED        = 'cancelled';
const ORDER_STATUS_RETURNED         = 'returned';
const ORDER_STATUS_REFUNDED         = 'refunded';

const ORDER_STATUSES = [
    ORDER_STATUS_PENDING          => 'Pending',
    ORDER_STATUS_CONFIRMED        => 'Confirmed',
    ORDER_STATUS_PROCESSING       => 'Processing',
    ORDER_STATUS_PACKED           => 'Packed',
    ORDER_STATUS_SHIPPED          => 'Shipped',
    ORDER_STATUS_OUT_FOR_DELIVERY => 'Out for Delivery',
    ORDER_STATUS_DELIVERED        => 'Delivered',
    ORDER_STATUS_CANCELLED        => 'Cancelled',
    ORDER_STATUS_RETURNED         => 'Returned',
    ORDER_STATUS_REFUNDED         => 'Refunded',
];

/** Colour token per status, used by badges in admin + account pages. */
const ORDER_STATUS_COLORS = [
    ORDER_STATUS_PENDING          => 'amber',
    ORDER_STATUS_CONFIRMED        => 'blue',
    ORDER_STATUS_PROCESSING       => 'indigo',
    ORDER_STATUS_PACKED           => 'violet',
    ORDER_STATUS_SHIPPED          => 'cyan',
    ORDER_STATUS_OUT_FOR_DELIVERY => 'teal',
    ORDER_STATUS_DELIVERED        => 'green',
    ORDER_STATUS_CANCELLED        => 'red',
    ORDER_STATUS_RETURNED         => 'orange',
    ORDER_STATUS_REFUNDED         => 'gray',
];

/** Ordered timeline shown on the order tracking page. */
const ORDER_TIMELINE = [
    ORDER_STATUS_PENDING,
    ORDER_STATUS_CONFIRMED,
    ORDER_STATUS_PROCESSING,
    ORDER_STATUS_PACKED,
    ORDER_STATUS_SHIPPED,
    ORDER_STATUS_OUT_FOR_DELIVERY,
    ORDER_STATUS_DELIVERED,
];

/** Statuses from which a customer may still cancel. */
const CANCELLABLE_STATUSES = [
    ORDER_STATUS_PENDING,
    ORDER_STATUS_CONFIRMED,
    ORDER_STATUS_PROCESSING,
];

/** Statuses that permanently release reserved stock. */
const STOCK_RELEASING_STATUSES = [
    ORDER_STATUS_CANCELLED,
    ORDER_STATUS_RETURNED,
    ORDER_STATUS_REFUNDED,
];

/**
 * The one definition of "this order counts as money we earned".
 *
 * Note it is an allowlist, not "everything except cancelled/returned/refunded":
 * a pending order has been placed but not paid or confirmed, so it belongs to
 * neither set. Stating the earning statuses positively is what stops a new
 * status defaulting into revenue the day it is added.
 *
 * The dashboard, the reports module and the customer screens each used to carry
 * their own copy of this list and had already drifted apart — lifetime spend
 * counted pending orders while the dashboard beside it did not.
 */
const REVENUE_ORDER_STATUSES = [
    ORDER_STATUS_CONFIRMED,
    ORDER_STATUS_PROCESSING,
    ORDER_STATUS_PACKED,
    ORDER_STATUS_SHIPPED,
    ORDER_STATUS_OUT_FOR_DELIVERY,
    ORDER_STATUS_DELIVERED,
];

/** The same list as a quoted SQL fragment for an IN (...) clause. */
const REVENUE_ORDER_STATUSES_SQL = "'confirmed','processing','packed','shipped','out_for_delivery','delivered'";

// ---------------------------------------------------------------------------
// Payment
// ---------------------------------------------------------------------------
const PAYMENT_STATUS_PENDING  = 'pending';
const PAYMENT_STATUS_PAID     = 'paid';
const PAYMENT_STATUS_FAILED   = 'failed';
/**
 * Some of the money came back, not all of it.
 *
 * Without this state a single Rs 1 refund marked a Rs 12,000 order "Refunded"
 * — on the order, on the invoice and in the customer's email. The sum of
 * `payment_refunds` decides which of the two a refund leaves the order in.
 */
const PAYMENT_STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
const PAYMENT_STATUS_REFUNDED = 'refunded';

const PAYMENT_STATUSES = [
    PAYMENT_STATUS_PENDING  => 'Pending',
    PAYMENT_STATUS_PAID     => 'Paid',
    PAYMENT_STATUS_FAILED   => 'Failed',
    PAYMENT_STATUS_PARTIALLY_REFUNDED => 'Partially refunded',
    PAYMENT_STATUS_REFUNDED => 'Refunded',
];

/** Payment states in which the customer's money is (still) with us. */
const PAYMENT_STATUSES_SETTLED = [
    PAYMENT_STATUS_PAID,
    PAYMENT_STATUS_PARTIALLY_REFUNDED,
    PAYMENT_STATUS_REFUNDED,
];

const PAYMENT_METHOD_COD = 'cod';

// ---------------------------------------------------------------------------
// Coupons
// ---------------------------------------------------------------------------
const COUPON_TYPE_PERCENTAGE    = 'percentage';
const COUPON_TYPE_FIXED         = 'fixed';
const COUPON_TYPE_FREE_SHIPPING = 'free_shipping';

const COUPON_TYPES = [
    COUPON_TYPE_PERCENTAGE    => 'Percentage Discount',
    COUPON_TYPE_FIXED         => 'Fixed Amount Discount',
    COUPON_TYPE_FREE_SHIPPING => 'Free Shipping',
];

// ---------------------------------------------------------------------------
// Generic status
// ---------------------------------------------------------------------------
const STATUS_ACTIVE   = 'active';
const STATUS_INACTIVE = 'inactive';
const STATUS_DRAFT    = 'draft';

const REVIEW_STATUS_PENDING  = 'pending';
const REVIEW_STATUS_APPROVED = 'approved';
const REVIEW_STATUS_REJECTED = 'rejected';

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------
const STOCK_IN       = 'in_stock';
const STOCK_LOW      = 'low_stock';
const STOCK_OUT      = 'out_of_stock';

// ---------------------------------------------------------------------------
// Admin permission modules -> available actions
//
// This is the registry the role editor is built from. A permission that a
// migration inserts into admin_permissions but that is missing here cannot be
// ticked, and array_intersect() in roles.php would strip it from any role that
// already held it - so the two must never drift. admins_all_permissions()
// unions this list with the table for exactly that reason.
//
// Beyond the usual view/create/edit/delete, four actions name a capability
// rather than a CRUD verb, because each one is a route back to everything
// else and must be grantable on its own:
//
//   security.view/edit      the security screens and the hidden login address
//   settings.scripts        JavaScript and CSS injected into every storefront page
//   system.backup           create and download a full database dump
//   customers.credentials   set a shopper's password or email address
// ---------------------------------------------------------------------------
const PERMISSION_MODULES = [
    'dashboard'   => ['view'],
    'products'    => ['view', 'create', 'edit', 'delete'],
    'categories'  => ['view', 'create', 'edit', 'delete'],
    'brands'      => ['view', 'create', 'edit', 'delete'],
    'attributes'  => ['view', 'create', 'edit', 'delete'],
    'orders'      => ['view', 'edit', 'delete'],
    'customers'   => ['view', 'create', 'edit', 'delete', 'credentials'],
    'coupons'     => ['view', 'create', 'edit', 'delete'],
    'deals'       => ['view', 'create', 'edit', 'delete'],
    'flash_sales' => ['view', 'create', 'edit', 'delete'],
    'banners'     => ['view', 'create', 'edit', 'delete'],
    'homepage'    => ['view', 'edit'],
    'reviews'     => ['view', 'edit', 'delete'],
    'newsletter'  => ['view', 'edit', 'delete'],
    'pages'       => ['view', 'create', 'edit', 'delete'],
    'faq'         => ['view', 'create', 'edit', 'delete'],
    'blog'        => ['view', 'create', 'edit', 'delete'],
    'reports'     => ['view'],
    'settings'    => ['view', 'edit', 'scripts'],
    'system'      => ['backup'],
    'security'    => ['view', 'edit'],
    'admins'      => ['view', 'create', 'edit', 'delete'],
    'logs'        => ['view', 'delete'],
];

/**
 * Column order of the role matrix. Actions outside this list still render,
 * appended in the order the registry declares them.
 */
const PERMISSION_ACTION_ORDER = ['view', 'create', 'edit', 'delete'];

// ---------------------------------------------------------------------------
// Indian states (checkout address dropdown)
// ---------------------------------------------------------------------------
const INDIAN_STATES = [
    'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam',
    'Bihar', 'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu',
    'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir',
    'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh',
    'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
    'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
    'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
];

// ---------------------------------------------------------------------------
// Shipping
// ---------------------------------------------------------------------------
const SHIPPING_STANDARD = 'standard';
const SHIPPING_EXPRESS  = 'express';

// ---------------------------------------------------------------------------
// Sorting options for the shop / search grid
// ---------------------------------------------------------------------------
const PRODUCT_SORT_OPTIONS = [
    'popularity' => 'Popularity',
    'newest'     => 'Newest First',
    'price_asc'  => 'Price: Low to High',
    'price_desc' => 'Price: High to Low',
    'rating'     => 'Customer Rating',
    'discount'   => 'Discount',
];
