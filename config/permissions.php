<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permission registry
    |--------------------------------------------------------------------------
    |
    | Every permission in the app is listed here, grouped by module. This file
    | is the only place you edit to add one.
    |
    | To add a module:
    |   1. Add a group below with its permissions.
    |   2. Run `php artisan permissions:sync`.
    |
    | The key is the permission name used in code and in middleware, for
    | example `can:users.create`. The value is the label shown on the role
    | form.
    |
    */

    'groups' => [

        'users' => [
            'label' => 'User Management',
            'description' => 'Who may open and change user accounts.',
            'permissions' => [
                'users.view' => 'View users',
                'users.create' => 'Create users',
                'users.update' => 'Edit users',
                'users.delete' => 'Delete users',
                'users.status' => 'Activate and deactivate users',
            ],
        ],

        'roles' => [
            'label' => 'Roles & Permissions',
            'description' => 'Who may change what other people can do.',
            'permissions' => [
                'roles.view' => 'View roles',
                'roles.create' => 'Create roles',
                'roles.update' => 'Edit roles',
                'roles.delete' => 'Delete roles',
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'description' => 'Who may read and change the app settings.',
            'permissions' => [
                'settings.view' => 'View settings',
                'settings.update' => 'Change settings',
            ],
        ],

        // A backup is the whole books and every password hash (phase 35).
        // Admin holds these through '*'; no other role is given them.
        'backups' => [
            'label' => 'Backups',
            'description' => 'Who may make, download and delete a backup of the whole system.',
            'permissions' => [
                'backups.view' => 'See the list of backups',
                'backups.create' => 'Make a backup',
                'backups.download' => 'Download a backup',
                'backups.delete' => 'Delete a backup',
            ],
        ],

        /*
        | Opt-in only.
        |
        | A permission here is never handed out by the `*` that Admin
        | carries. Everything else in this file answers "what may this
        | person do", and an administrator may do everything — that rule
        | is worth keeping simple. This one answers a different question:
        | "where is this person expected to work from". Granting it to
        | Admin along with the rest would defeat the only thing it is for,
        | which is keeping the account that can do everything off a tablet
        | somebody left on a bench.
        |
        | It is ticked by hand on the Roles screen, for the roles that
        | belong at the tablet.
        */
        'kiosk' => [
            'label' => 'Kiosk',
            'description' => 'Who may sign in from the shared tablet on the floor.',
            'opt_in' => true,
            'permissions' => [
                'kiosk.use' => 'Sign in from the kiosk screen',
            ],
        ],

        /*
        | Whose figures the dashboard adds up. Without this, a user sees
        | only the documents they posted themselves, and the Staff filter
        | offers nobody else. Admin has it through `*`.
        */
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Whose sales, purchases and losses the dashboard may show.',
            'permissions' => [
                'dashboard.view_all_staff' => 'See every staff member\'s figures on the dashboard, and narrow Reports to any cashier',
            ],
        ],

        'audit' => [
            'label' => 'Audit Trail',
            'description' => 'Who may read the record of what everybody did.',
            'permissions' => [
                'audit.view' => 'View the audit trail',
            ],
        ],

        'products' => [
            'label' => 'Product Master',
            'description' => 'Who may open and change the product list.',
            'permissions' => [
                'products.view' => 'View products',
                'products.create' => 'Create products',
                'products.update' => 'Edit products',
                'products.delete' => 'Delete products',
                'products.status' => 'Activate and deactivate products',
            ],
        ],

        'categories' => [
            'label' => 'Categories',
            'description' => 'Who may change the groups a product is filed under.',
            'permissions' => [
                'categories.view' => 'View categories',
                'categories.create' => 'Create categories',
                'categories.update' => 'Edit categories',
                'categories.delete' => 'Delete categories',
                'categories.status' => 'Activate and deactivate categories',
            ],
        ],

        // Phase 24.1: the parts of the business a purchase request is
        // raised for. Admin keeps the list; Staff pick from it.
        'departments' => [
            'label' => 'Departments',
            'description' => 'Who may change the departments a purchase request is raised for.',
            'permissions' => [
                'departments.view' => 'View departments',
                'departments.create' => 'Create departments',
                'departments.update' => 'Edit departments',
                'departments.delete' => 'Delete departments',
                'departments.status' => 'Activate and deactivate departments',
            ],
        ],

        // Phase 31.1: the places that hold stock — the store and the
        // warehouse. Admin keeps the list; Staff read it.
        'locations' => [
            'label' => 'Locations',
            'description' => 'Who may change the stores and warehouses that hold stock.',
            'permissions' => [
                'locations.view' => 'View locations',
                'locations.create' => 'Create locations',
                'locations.update' => 'Edit locations',
                'locations.delete' => 'Delete locations',
                'locations.status' => 'Activate and deactivate locations',
            ],
        ],

        'units' => [
            'label' => 'Units of Measure',
            'description' => 'Who may change the units a product is counted in.',
            'permissions' => [
                'units.view' => 'View units',
                'units.create' => 'Create units',
                'units.update' => 'Edit units',
                'units.delete' => 'Delete units',
                'units.status' => 'Activate and deactivate units',
            ],
        ],

        'suppliers' => [
            'label' => 'Suppliers',
            'description' => 'Who may open and change the people goods are bought from.',
            'permissions' => [
                'suppliers.view' => 'View suppliers',
                'suppliers.create' => 'Create suppliers',
                'suppliers.update' => 'Edit suppliers',
                'suppliers.delete' => 'Delete suppliers',
                'suppliers.status' => 'Activate and deactivate suppliers',
            ],
        ],

        'payment_methods' => [
            'label' => 'Payment Methods',
            'description' => 'Who may change the ways a customer may pay.',
            'permissions' => [
                'payment_methods.view' => 'View payment methods',
                'payment_methods.create' => 'Create payment methods',
                'payment_methods.update' => 'Edit payment methods',
                'payment_methods.delete' => 'Delete payment methods',
                'payment_methods.status' => 'Activate and deactivate payment methods',
            ],
        ],

        /*
        | Prices offered to customers (phase 28). A quotation moves no stock
        | and owes nothing, so Staff hold all three; turning one into a sale
        | needs `sales.create` as any sale does.
        */
        'quotations' => [
            'label' => 'Quotations',
            'description' => 'Who may offer customers a price until a date, and withdraw one.',
            'permissions' => [
                'quotations.view' => 'View quotations',
                'quotations.create' => 'Make a quotation',
                'quotations.cancel' => 'Cancel a quotation',
            ],
        ],

        /*
        | Orders customers place, holding stock for them (phase 29). Approving
        | reserves stock, which turns other customers away, so it stays with
        | the approver (Admin, through `*`) until the client names one
        | (decision 2). Staff take orders and may cancel them.
        */
        'sales_orders' => [
            'label' => 'Sales Orders',
            'description' => 'Who may take a customer order, approve it to reserve its stock, and cancel or release one.',
            'permissions' => [
                'sales_orders.view' => 'View sales orders',
                'sales_orders.create' => 'Take a sales order',
                'sales_orders.approve' => 'Approve a sales order, reserving its stock',
                'sales_orders.cancel' => 'Cancel or release a sales order',
            ],
        ],

        // CR-2: at the kiosk, Pending payment comes with the kiosk itself —
        // anyone who signs in there may send an order to the cashier, and
        // anyone who may record a sale there may take its money or cancel
        // one. Only the back-office list needs a tick of its own.
        'pending_payments' => [
            'label' => 'Pending Payments',
            'description' => 'Who may open Sales → Pending Payments, the list of orders the kiosk sent to the cashier. At the kiosk itself, anyone who signs in may send one, and anyone who may record a sale may take its money or cancel it.',
            'permissions' => [
                'pending_payments.view' => 'View the Pending Payments list',
            ],
        ],

        'sales' => [
            'label' => 'Sales',
            'description' => 'Who may record goods sold, see the margin, and void a sale.',
            'permissions' => [
                'sales.view' => 'View sales',
                'sales.create' => 'Record a sale',
                'sales.void' => 'Void a posted sale',
                'sales.view_cost' => 'See the cost and profit on a sale',
            ],
        ],

        'sales_returns' => [
            'label' => 'Sales Returns',
            'description' => 'Who may take goods back from a customer.',
            'permissions' => [
                'sales_returns.view' => 'View sales returns',
                'sales_returns.create' => 'Record a sales return',
            ],
        ],

        'purchase_returns' => [
            'label' => 'Purchase Returns',
            'description' => 'Who may send goods back to a supplier.',
            'permissions' => [
                'purchase_returns.view' => 'View purchase returns',
                'purchase_returns.create' => 'Record a purchase return',
            ],
        ],

        'reports' => [
            'label' => 'Reports',
            'description' => 'Who may read the sales and purchase summaries.',
            'permissions' => [
                'reports.view' => 'View reports',
            ],
        ],

        'movements' => [
            'label' => 'Inventory Movement',
            'description' => 'Who may read the record of every time stock moved.',
            'permissions' => [
                'movements.view' => 'View inventory movement',
            ],
        ],

        'stock' => [
            'label' => 'Stock on Hand',
            'description' => 'Who may see what is on the shelf and what it is worth.',
            'permissions' => [
                'stock.view' => 'View stock and its value',
            ],
        ],

        'adjustments' => [
            'label' => 'Stock Adjustments',
            'description' => 'Who may correct stock that no purchase or sale explains.',
            'permissions' => [
                'adjustments.view' => 'View adjustments',
                'adjustments.create' => 'Record an adjustment',
                'adjustments.void' => 'Void a posted adjustment',
            ],
        ],

        /*
        | Stock moved between locations (phase 31.5). A transfer changes no
        | total and no value, so Staff may record one; voiding one stays with
        | Admin, like every void. No approval step — decision 4 still waits.
        */
        'transfers' => [
            'label' => 'Stock Transfers',
            'description' => 'Who may move stock from one location to another, and who may undo a transfer.',
            'permissions' => [
                'transfers.view' => 'View transfers',
                'transfers.create' => 'Move stock between locations',
                'transfers.void' => 'Void a posted transfer',
            ],
        ],

        /*
        | The count sheet (phase 32). Counting is ordinary work. Approving
        | posts what was over and short as adjustments, so it stays with the
        | approver, as recording an adjustment does.
        */
        'stock_counts' => [
            'label' => 'Stock Counts',
            'description' => 'Who may count the stock, and who may approve a count, which posts its differences.',
            'permissions' => [
                'stock_counts.view' => 'View stock counts',
                'stock_counts.create' => 'Start a count, enter figures and hand it in',
                'stock_counts.approve' => 'Approve a count (posts its differences) or send it back',
            ],
        ],

        /*
        | Loss reports (phase 33). Anybody who finds a loss reports it, and
        | the goods are held aside; writing it off stays with the approver,
        | as recording a decrease adjustment does.
        */
        'losses' => [
            'label' => 'Loss Reports',
            'description' => 'Who may report damaged, expired, rusted, stolen or missing goods, and who may write them off.',
            'permissions' => [
                'losses.view' => 'View loss reports and their photos',
                'losses.create' => 'Report a loss, and withdraw one they reported',
                'losses.approve' => 'Approve a loss (writes it off) or reject it',
            ],
        ],

        /*
        | Asking to buy, before anything is bought (phase 21). Whoever holds
        | `approve` answers a request — never their own. Admin has it through
        | `*`; giving it to another role is how the client names a different
        | approver (decision 2) without a change to the code.
        */
        'purchase_requests' => [
            'label' => 'Purchase Requests',
            'description' => 'Who may ask for goods to be bought, and who may approve or reject what others ask for.',
            'permissions' => [
                'purchase_requests.view' => 'View purchase requests',
                'purchase_requests.create' => 'Raise a purchase request',
                'purchase_requests.approve' => 'Approve or reject purchase requests',
            ],
        ],

        // The canvass on an approved request (phase 24.2): suppliers'
        // prices, the Abstract of Quotation, and the award. The in-charge
        // records the prices; the owner chooses the supplier for each item.
        'canvass' => [
            'label' => 'Canvass',
            'description' => 'Who may record suppliers\' prices on an approved request, and who chooses the supplier for each item.',
            'permissions' => [
                'canvass.create' => 'Record and remove supplier quotes',
                'canvass.award' => 'Choose the supplier for each item, and reopen an award',
            ],
        ],

        /*
        | Orders placed with suppliers (phase 22). Placing one commits the
        | shop to a supplier, so Staff only read them.
        */
        'purchase_orders' => [
            'label' => 'Purchase Orders',
            'description' => 'Who may place orders with suppliers, and who may cancel one or close it short.',
            'permissions' => [
                'purchase_orders.view' => 'View purchase orders',
                'purchase_orders.create' => 'Place a purchase order',
                'purchase_orders.approve' => 'Approve or reject a purchase order',
                'purchase_orders.cancel' => 'Cancel a purchase order, or close one short',
            ],
        ],

        /*
        | Money paid to suppliers, and what the shop still owes them (phase
        | 24). Paying suppliers is the owner's job, so Staff hold none of
        | these. The Payables list sits behind `.view`.
        */
        'supplier_payments' => [
            'label' => 'Supplier Payments',
            'description' => 'Who may see what is owed to suppliers, record a payment to one, and void a payment.',
            'permissions' => [
                'supplier_payments.view' => 'View supplier payments and payables',
                'supplier_payments.create' => 'Record a supplier payment',
                'supplier_payments.void' => 'Void a supplier payment',
            ],
        ],

        'purchases' => [
            'label' => 'Purchases',
            'description' => 'Who may record goods bought in, and who may void one.',
            'permissions' => [
                'purchases.view' => 'View purchases',
                'purchases.create' => 'Record a purchase',
                'purchases.void' => 'Void a posted purchase',
            ],
        ],

        'customers' => [
            'label' => 'Customers',
            'description' => 'Who may open and change the people goods are sold to.',
            'permissions' => [
                'customers.view' => 'View customers',
                'customers.create' => 'Create customers',
                'customers.update' => 'Edit customers',
                'customers.delete' => 'Delete customers',
                'customers.status' => 'Activate and deactivate customers',
                'customers.credit' => 'Set a customer\'s credit limit and usual payment terms, and give a sale on account more days than usual',
            ],
        ],

        /*
        | Money a customer brings in against what they owe (phase 19). The
        | statement of account and the aging report read the same figures,
        | so they sit behind `customer_payments.view` as well.
        */
        'customer_payments' => [
            'label' => 'Customer Payments',
            'description' => 'Who may record what a customer pays against their invoices, read the statement and the aging report, and void a payment.',
            'permissions' => [
                'customer_payments.view' => 'View customer payments, statements and aging',
                'customer_payments.create' => 'Record a customer payment',
                'customer_payments.void' => 'Void a customer payment',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded roles
    |--------------------------------------------------------------------------
    |
    | Roles created by the seeder. Use '*' to grant every permission in the
    | registry, so a new permission reaches that role on the next sync.
    |
    | These two roles are protected: they cannot be renamed or deleted in the
    | app. Any other role an admin creates is free to change.
    |
    */

    'roles' => [
        'Admin' => '*',
        'Staff' => [
            'users.view',
            'roles.view',
            'products.view',
            'categories.view',
            'departments.view',
            'locations.view',
            'units.view',
            'payment_methods.view',
            'suppliers.view',
            'customers.view',
            // Staff may buy goods in, but voiding a posted document undoes
            // stock that is already on the shelf, so that stays with Admin.
            'purchases.view',
            'purchases.create',
            // Staff may ask for goods and close a request of their own.
            // Answering one stays with Admin until the client names an
            // approver (decision 2).
            'purchase_requests.view',
            'purchase_requests.create',
            // Phase 24.2: the in-charge enters the suppliers' prices; the
            // award stays with the owner.
            'canvass.create',
            // Read only: placing an order commits the shop to a supplier.
            'purchase_orders.view',
            // Staff may sell, but not undo a posted sale. What a sale cost
            // the business, and so what it earned, is held back as well.
            'sales.view',
            'sales.create',
            // Quoting is ordinary selling work, and moves nothing.
            'quotations.view',
            'quotations.create',
            'quotations.cancel',
            // Staff take orders; approving — reserving stock — stays with
            // the approver.
            'sales_orders.view',
            'sales_orders.create',
            'sales_orders.cancel',
            // CR-2: the list of orders the kiosk sent to the cashier.
            'pending_payments.view',
            'sales_returns.view',
            'sales_returns.create',
            'purchase_returns.view',
            'purchase_returns.create',
            // Read only. A decrease adjustment is the quietest way to make
            // shrinkage disappear, so recording one stays with Admin.
            'adjustments.view',
            // Phase 31.5: moving goods from the warehouse to the store is
            // daily work, and changes no total. Voiding stays with Admin.
            'transfers.view',
            'transfers.create',
            // Phase 32: Staff count the shelves and hand the sheet in. They
            // see no book figure while counting (a blind count); approving,
            // which posts the differences, stays with the approver.
            'stock_counts.view',
            'stock_counts.create',
            // Phase 33: whoever finds a loss reports it, with photos; the
            // goods are held aside until the approver writes it off.
            'losses.view',
            'losses.create',
            // Both of these only read. Anybody recording a document needs
            // to see what is on the shelf and where it came from.
            'movements.view',
            'stock.view',
            // The summaries only add up documents Staff can already open
            // one by one. Profit stays behind sales.view_cost.
            'reports.view',
            // Staff may take a payment at the counter and read what a
            // customer owes. Voiding one puts a debt back, so that stays
            // with Admin, like every other void.
            'customer_payments.view',
            'customer_payments.create',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected names
    |--------------------------------------------------------------------------
    |
    | Roles that must keep working for the app to be usable.
    |
    */

    'protected_roles' => ['Admin', 'Staff'],

    /*
    | The role that always holds every permission.
    */
    'admin_role' => 'Admin',

];
