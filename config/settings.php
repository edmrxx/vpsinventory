<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Every setting is read from one cached array, so a page that reads ten
    | settings still costs one query at most. The cache is cleared whenever a
    | setting is saved.
    |
    */

    'cache_key' => 'settings.all',
    'cache_ttl' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Groups and fields
    |--------------------------------------------------------------------------
    |
    | This is the whole settings module. To add a setting, add a field here
    | and it appears on the page, is validated, and can be read with
    | `settings('your.key')`. Nothing else to change.
    |
    | Field keys:
    |   label        shown next to the input
    |   type         text | textarea | email | tel | url | number | toggle
    |                | select | color
    |   default      used until somebody saves a value
    |   rules        Laravel validation rules
    |   hint         small grey line under the input
    |   options      for `select`, as value => label
    |   width        'full' to take the whole row
    |
    */

    'groups' => [

        'company' => [
            'label' => 'Company Information',
            'description' => 'The name and contact details used across the app.',
            'icon' => 'Building2',
            'fields' => [
                'company.name' => [
                    'label' => 'Company name',
                    'type' => 'text',
                    'default' => 'MAF Inventory',
                    'rules' => ['required', 'string', 'max:120'],
                    'hint' => 'Shown in the sidebar, the top bar and the browser tab.',
                ],
                'company.legal_name' => [
                    'label' => 'Registered name',
                    'type' => 'text',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:160'],
                    'hint' => 'The full name on official papers, if it differs.',
                ],
                // Uploaded through its own route (SettingsController::
                // uploadImage), never by the Save button, so it carries no
                // rules here. The browser tab icons are cut from it too.
                'company.logo' => [
                    'label' => 'Logo',
                    'type' => 'image',
                    'default' => '/images/brand-mark.png',
                    'rules' => [],
                    'makes_icons' => true,
                    'hint' => 'JPG, PNG or WebP, up to 2 MB. A square picture with a see-through background looks best. Saved as soon as it is uploaded. Removed, the initials are shown instead.',
                ],
                'company.short_name' => [
                    'label' => 'Short name',
                    'type' => 'text',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:40'],
                    'hint' => 'Shown beside the logo at the top of the sign-in screen. Left empty, the company name is used.',
                ],
                'company.tagline' => [
                    'label' => 'Tagline',
                    'type' => 'text',
                    'default' => 'Operations',
                    'rules' => ['nullable', 'string', 'max:80'],
                    'hint' => 'The small line under the name in the sidebar.',
                ],
                'company.email' => [
                    'label' => 'Email',
                    'type' => 'email',
                    'default' => '',
                    'rules' => ['nullable', 'email', 'max:160'],
                ],
                'company.phone' => [
                    'label' => 'Phone',
                    'type' => 'tel',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
                'company.website' => [
                    'label' => 'Website',
                    'type' => 'url',
                    'default' => '',
                    'rules' => ['nullable', 'url', 'max:160'],
                ],
                'company.tin' => [
                    'label' => 'TIN',
                    'type' => 'text',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
                'company.address' => [
                    'label' => 'Address',
                    'type' => 'textarea',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:400'],
                    'width' => 'full',
                ],
            ],
        ],

        'theme' => [
            'label' => 'Theme Settings',
            'description' => 'How the app looks. A change takes effect at once.',
            'icon' => 'Palette',
            'fields' => [
                'theme.brand_color' => [
                    'label' => 'Brand colour',
                    'type' => 'color',
                    'default' => '#5b3df5',
                    'rules' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    'hint' => 'Buttons, links and the active menu follow this colour.',
                ],
                'theme.radius' => [
                    'label' => 'Corner style',
                    'type' => 'select',
                    'default' => 'rounded',
                    'options' => [
                        'sharp' => 'Sharp',
                        'rounded' => 'Rounded',
                        'soft' => 'Soft',
                    ],
                    'rules' => ['required', 'in:sharp,rounded,soft'],
                ],
                'theme.density' => [
                    'label' => 'Spacing',
                    'type' => 'select',
                    'default' => 'comfortable',
                    'options' => [
                        'comfortable' => 'Comfortable',
                        'compact' => 'Compact',
                    ],
                    'rules' => ['required', 'in:comfortable,compact'],
                    'hint' => 'Compact fits more rows on the screen.',
                ],
                'theme.sidebar_collapsed' => [
                    'label' => 'Start with the menu folded',
                    'type' => 'toggle',
                    'default' => false,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'Used until the person folds or unfolds it themselves.',
                ],
                'theme.show_page_transitions' => [
                    'label' => 'Animate page changes',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                ],
            ],
        ],

        /*
        | The three groups below are declared and ready. Add fields to any of
        | them and its page starts working, with no other change.
        */

        'system' => [
            'label' => 'System Preferences',
            'description' => 'How the app behaves day to day.',
            'icon' => 'SlidersHorizontal',
            'fields' => [
                'system.currency_symbol' => [
                    'label' => 'Currency symbol',
                    'type' => 'text',
                    'default' => '₱',
                    'rules' => ['required', 'string', 'max:5'],
                    'hint' => 'Shown before every amount, on screen and on documents.',
                ],
                'system.currency_code' => [
                    'label' => 'Currency code',
                    'type' => 'text',
                    'default' => 'PHP',
                    'rules' => ['required', 'string', 'size:3', 'alpha'],
                    'hint' => 'The three-letter code, for example PHP or USD.',
                ],
                'system.dead_stock_days' => [
                    'label' => 'Dead stock after (days)',
                    'type' => 'number',
                    'default' => 60,
                    'rules' => ['required', 'integer', 'min:7', 'max:730'],
                    'hint' => 'A product holding stock that has not sold in this many days is listed as dead stock on the dashboard. What counts as slow differs by trade, so it is yours to set.',
                ],
                'system.high_return_rate' => [
                    'label' => 'High return rate (%)',
                    'type' => 'number',
                    'default' => 10,
                    'rules' => ['required', 'integer', 'min:1', 'max:100'],
                    'hint' => 'A product with more than this share of its units coming back is flagged on the dashboard.',
                ],
            ],
        ],

        'tax' => [
            'label' => 'Tax Settings',
            'description' => 'Whether the business is VAT-registered, and at what rate.',
            'icon' => 'Percent',
            'fields' => [
                'tax.vat_registered' => [
                    'label' => 'VAT-registered',
                    'type' => 'toggle',
                    'default' => false,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'Whether THIS business charges VAT. On: a sale, a sales return and the till show what is net of VAT and what is VAT. Off: one plain amount. It is a view for comparing the two ways of trading and changes nothing that is stored. Purchases do not follow this switch — what a supplier charges is set on the supplier, because you cannot claim back VAT from a supplier who charges none.',
                ],
                'tax.vat_rate' => [
                    'label' => 'VAT rate (%)',
                    'type' => 'number',
                    'default' => 12,
                    'rules' => ['required', 'numeric', 'min:0', 'max:100'],
                    'hint' => 'The rate the split is worked out at. 12 in the Philippines.',
                ],
            ],
        ],

        /*
        | Buying (stage 5). Who answers a purchase request is a permission,
        | `purchase_requests.approve`; this says whether that person may
        | answer one they raised themselves.
        */
        'purchasing' => [
            'label' => 'Purchasing',
            'description' => 'How purchase requests are approved.',
            'icon' => 'ClipboardList',
            'fields' => [
                'purchasing.self_approval' => [
                    'label' => 'An approver may approve their own request or order',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'On: somebody who may approve can approve a purchase request they raised, and a purchase order they place is approved as it is placed; the audit trail says so. Off: somebody else must approve either one — turn this off once a second person may approve.',
                ],
            ],
        ],

        'losses' => [
            'label' => 'Losses',
            'description' => 'What a loss report needs before it can wait for approval.',
            'icon' => 'PackageX',
            'fields' => [
                'losses.photo_required' => [
                    'label' => 'A photo is required when the goods are there to photograph',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'On: a loss reported as damaged, expired, rusted or written off needs at least one photo. A theft or a missing item never does — there is nothing to show. Off: a photo is always optional.',
                ],
            ],
        ],

        'documents' => [
            'label' => 'Document Numbers',
            'description' => 'How a quotation, sales order, purchase request, purchase order, purchase, sale, return, adjustment, transfer, customer payment or supplier payment is numbered, and what a printed sale slip carries.',
            'icon' => 'Hash',
            'fields' => [
                'documents.purchase_prefix' => [
                    'label' => 'Purchase prefix',
                    'type' => 'text',
                    'default' => 'PR',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'Capitals, numbers and dashes. For example PR-2026-0001.',
                ],
                'documents.purchase_request_prefix' => [
                    'label' => 'Purchase request prefix',
                    'type' => 'text',
                    'default' => 'PRQ',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'For example PRQ-2026-0001. Keep it different from the purchase prefix, or the two share one counter.',
                ],
                'documents.purchase_order_prefix' => [
                    'label' => 'Purchase order prefix',
                    'type' => 'text',
                    'default' => 'PO',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'For example PO-2026-0001. Keep it different from the other prefixes, or two documents share one counter.',
                ],
                'documents.quotation_prefix' => [
                    'label' => 'Quotation prefix',
                    'type' => 'text',
                    'default' => 'QT',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'For example QT-2026-0001.',
                ],
                'documents.quotation_validity_days' => [
                    'label' => 'Quotation valid for (days)',
                    'type' => 'number',
                    'default' => 15,
                    'rules' => ['required', 'integer', 'min:1', 'max:365'],
                    'hint' => 'How long a new quotation\'s prices hold, from the day it is made. It can be changed on each quotation.',
                ],
                'documents.sales_order_prefix' => [
                    'label' => 'Sales order prefix',
                    'type' => 'text',
                    'default' => 'SO',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'For example SO-2026-0001.',
                ],
                'documents.sales_order_reserve_days' => [
                    'label' => 'Sales order holds stock for (days)',
                    'type' => 'number',
                    'default' => 7,
                    'rules' => ['required', 'integer', 'min:1', 'max:90'],
                    'hint' => 'How long an approved order keeps its stock reserved by default. It can be changed on each order.',
                ],
                'documents.pending_payment_prefix' => [
                    'label' => 'Pending payment prefix',
                    'type' => 'text',
                    'default' => 'PP',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'An order the kiosk sends to the cashier. For example PP-2026-0001.',
                ],
                'documents.pending_payment_hold_days' => [
                    'label' => 'Pending payment holds its goods for (days)',
                    'type' => 'number',
                    'default' => 0,
                    'rules' => ['required', 'integer', 'min:0', 'max:7'],
                    'hint' => '0 holds them until closing on the day it is sent. Unpaid after that, it expires and the goods are free again.',
                ],
                'documents.sale_prefix' => [
                    'label' => 'Sale prefix',
                    'type' => 'text',
                    'default' => 'SI',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                ],
                'documents.sales_return_prefix' => [
                    'label' => 'Sales return prefix',
                    'type' => 'text',
                    'default' => 'SR',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                ],
                'documents.purchase_return_prefix' => [
                    'label' => 'Purchase return prefix',
                    'type' => 'text',
                    'default' => 'PRR',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                ],
                'documents.adjustment_prefix' => [
                    'label' => 'Adjustment prefix',
                    'type' => 'text',
                    'default' => 'ADJ',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                ],
                'documents.transfer_prefix' => [
                    'label' => 'Transfer prefix',
                    'type' => 'text',
                    'default' => 'TR',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'Stock moved from one location to another. For example TR-2026-0001.',
                ],
                'documents.stock_count_prefix' => [
                    'label' => 'Stock count prefix',
                    'type' => 'text',
                    'default' => 'CNT',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'A count sheet. For example CNT-2026-0001. Keep it different from the other prefixes, or two documents share one counter.',
                ],
                'documents.loss_report_prefix' => [
                    'label' => 'Loss report prefix',
                    'type' => 'text',
                    'default' => 'LR',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'A loss waiting for approval. For example LR-2026-0001. Keep it different from the other prefixes, or two documents share one counter.',
                ],
                'documents.customer_payment_prefix' => [
                    'label' => 'Customer payment prefix',
                    'type' => 'text',
                    'default' => 'CP',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'Money a customer pays against their invoices. Not an official receipt.',
                ],
                'documents.supplier_payment_prefix' => [
                    'label' => 'Supplier payment prefix',
                    'type' => 'text',
                    'default' => 'SP',
                    'rules' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9-]+$/'],
                    'hint' => 'Money paid to a supplier against its bills. For example SP-2026-0001.',
                ],
                'documents.number_padding' => [
                    'label' => 'Number length',
                    'type' => 'number',
                    'default' => 4,
                    'rules' => ['required', 'integer', 'min:1', 'max:10'],
                    'hint' => 'How many digits the running number is padded to.',
                ],
                'documents.reset_yearly' => [
                    'label' => 'Start again each year',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'On: the number returns to 1 every January, and the year is part of the reference.',
                ],
                // The slip printed for a sale, from the till or the sale's
                // own page. It always says it is not an official receipt;
                // that line is not a setting, so it cannot be cleared.
                'documents.slip_width' => [
                    'label' => 'Slip paper width',
                    'type' => 'select',
                    'default' => '80',
                    'options' => [
                        '80' => '80 mm — most receipt printers',
                        '58' => '58 mm — small printers',
                    ],
                    'rules' => ['required', 'in:58,80'],
                    'hint' => 'The width of the roll in the receipt printer. An ordinary printer prints the same slip as a narrow column.',
                ],
                'documents.slip_footer' => [
                    'label' => 'Slip closing line',
                    'type' => 'textarea',
                    'default' => 'Thank you for your purchase!',
                    'rules' => ['nullable', 'string', 'max:200'],
                    'hint' => 'Printed at the foot of every sale slip, above the line saying it is not an official receipt.',
                    'width' => 'full',
                ],
            ],
        ],

        // How the full-page documents print (phase 35): the quotation, the
        // purchase order, the statement of account, Aging and Reports. The
        // sale slip keeps its own two settings under Document Numbers. Every
        // default prints the page exactly as it printed before this group
        // existed. The lines saying a page is not an official receipt or an
        // invoice are written in the pages, not here, so none can be cleared.
        'printing' => [
            'label' => 'Print Layouts',
            'description' => 'Paper, margins and what every printed page carries.',
            'icon' => 'Printer',
            'fields' => [
                'printing.paper_size' => [
                    'label' => 'Paper size',
                    'type' => 'select',
                    'default' => 'auto',
                    'options' => [
                        'auto' => 'The printer\'s own setting',
                        'a4' => 'A4 — 210 × 297 mm',
                        'letter' => 'Letter — 8.5 × 11 in',
                        'long' => 'Long bond — 8.5 × 13 in',
                        'legal' => 'Legal — 8.5 × 14 in',
                    ],
                    'rules' => ['required', 'in:auto,a4,letter,long,legal'],
                    'hint' => 'For the quotation, purchase order, statement, Aging and Reports. The sale slip has its own width under Document Numbers.',
                ],
                'printing.margins' => [
                    'label' => 'Margins',
                    'type' => 'select',
                    'default' => 'auto',
                    'options' => [
                        'auto' => 'The printer\'s own setting',
                        'narrow' => 'Narrow — 8 mm',
                        'normal' => 'Normal — 12 mm',
                        'wide' => 'Wide — 18 mm',
                    ],
                    'rules' => ['required', 'in:auto,narrow,normal,wide'],
                ],
                'printing.show_logo' => [
                    'label' => 'Print the logo',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'Off: the company name heads the page without the logo — for paper that already carries it.',
                ],
                'printing.show_company_details' => [
                    'label' => 'Print the address, TIN and phone',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'Under the company name, on every printed page. Taken from Company Information.',
                ],
                'printing.quotation_terms' => [
                    'label' => 'Terms on a quotation',
                    'type' => 'textarea',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:1000'],
                    'hint' => 'Printed at the foot of every quotation — delivery, payment, how long prices hold. Left empty, nothing is printed.',
                    'width' => 'full',
                ],
                'printing.purchase_order_terms' => [
                    'label' => 'Terms on a purchase order',
                    'type' => 'textarea',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:1000'],
                    'hint' => 'Printed at the foot of every purchase order sent to a supplier. Left empty, nothing is printed.',
                    'width' => 'full',
                ],
                'printing.footer_note' => [
                    'label' => 'Closing line on every page',
                    'type' => 'textarea',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:300'],
                    'hint' => 'One line at the very bottom of every printed page, for example a website or a thank-you. Left empty, nothing is printed.',
                    'width' => 'full',
                ],
            ],
        ],

        // Backups (phase 35): what one carries, how often the scheduler
        // makes one, and how many stay on the server. Made, downloaded and
        // deleted on the Backups screen, behind `backups.*`.
        'backup' => [
            'label' => 'Backup and Export',
            'description' => 'What a backup carries, how often one is made, and how many are kept.',
            'icon' => 'DatabaseBackup',
            'fields' => [
                'backup.frequency' => [
                    'label' => 'Make a backup by itself',
                    'type' => 'select',
                    'default' => 'off',
                    'options' => [
                        'off' => 'Off — only when somebody presses Make a backup now',
                        'daily' => 'Every day at 2:00 AM',
                        'weekly' => 'Every Sunday at 2:00 AM',
                    ],
                    'rules' => ['required', 'in:off,daily,weekly'],
                    'hint' => 'Needs the server\'s scheduler: one cron job running "php artisan schedule:run" every minute. Without it, nothing is made by itself.',
                ],
                'backup.keep_copies' => [
                    'label' => 'Backups kept on the server',
                    'type' => 'number',
                    'default' => 7,
                    'rules' => ['required', 'integer', 'min:1', 'max:60'],
                    'hint' => 'The oldest is deleted once a new one is made. Download a copy and keep it off the server too.',
                ],
                'backup.include_files' => [
                    'label' => 'Include product photos and the logo',
                    'type' => 'toggle',
                    'default' => true,
                    'rules' => ['required', 'boolean'],
                    'hint' => 'They are files, not rows, so a database export alone would restore broken pictures.',
                ],
            ],
        ],

        'notifications' => [
            'label' => 'Notification Settings',
            'description' => 'Who is told, and how.',
            'icon' => 'BellRing',
            'fields' => [],
        ],

        'api' => [
            'label' => 'API Settings',
            'description' => 'Keys and limits for other systems talking to this one.',
            'icon' => 'Plug',
            'fields' => [
                'api.token_lifetime_days' => [
                    'label' => 'Token lifetime (days)',
                    'type' => 'number',
                    'default' => 30,
                    'rules' => ['required', 'integer', 'min:1', 'max:365'],
                    'hint' => 'How long a phone stays signed in before it has to sign in again. A stolen token stops working after this.',
                ],
            ],
        ],

    ],

];
