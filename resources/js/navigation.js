import {
    LayoutDashboard,
    Hourglass,
    Users,
    UserRound,
    ShieldCheck,
    ScrollText,
    Package,
    Tags,
    Ruler,
    ShoppingCart,
    Receipt,
    RotateCcw,
    Undo2,
    Scale,
    ArrowLeftRight,
    ArrowRightLeft,
    Truck,
    Warehouse,
    FileBarChart,
    Settings,
    Wallet,
    DatabaseBackup,
    HandCoins,
    CalendarClock,
    ListChecks,
    ClipboardCheck,
    PackageX,
    ClipboardList,
    FileText,
    Landmark,
    BookOpenCheck,
    Building2,
    MapPin,
    FilePen,
    PackageOpen,
} from '@lucide/vue'

/**
 * The sidebar menu.
 *
 * `component` is the Inertia page name. The active highlight compares it with
 * the page on screen, so no route-name package is needed.
 *
 * `permission` hides the item when the user does not hold it. An item with no
 * permission is visible to everyone who is signed in. `disabled: true` marks
 * a module that belongs to a later phase.
 */
export const navigation = [
    {
        group: 'Overview',
        items: [
            {
                label: 'Dashboard',
                href: '/dashboard',
                component: 'Dashboard',
                icon: LayoutDashboard,
            },
        ],
    },
    {
        group: 'Operations',
        items: [
            {
                label: 'Purchase Requests',
                href: '/purchase-requests',
                component: 'PurchaseRequests',
                icon: ClipboardList,
                permission: 'purchase_requests.view',
                badge: 'purchase_requests',
            },
            {
                label: 'Purchase Orders',
                href: '/purchase-orders',
                component: 'PurchaseOrders',
                icon: FileText,
                permission: 'purchase_orders.view',
                badge: 'purchase_orders',
            },
            {
                label: 'Purchases',
                href: '/purchases',
                component: 'Purchases',
                icon: ShoppingCart,
                permission: 'purchases.view',
            },
            {
                label: 'Quotations',
                href: '/quotations',
                component: 'Quotations',
                icon: FilePen,
                permission: 'quotations.view',
            },
            {
                label: 'Sales Orders',
                href: '/sales-orders',
                component: 'SalesOrders',
                icon: PackageOpen,
                permission: 'sales_orders.view',
                badge: 'sales_orders',
            },
            {
                // CR-2: orders the kiosk sent to the cashier, waiting to be paid.
                label: 'Pending Payments',
                href: '/pending-payments',
                component: 'PendingPayments',
                icon: Hourglass,
                permission: 'pending_payments.view',
                // A red count while orders wait to be paid (shared `badges`).
                badge: 'pending_payments',
            },
            {
                label: 'Sales',
                href: '/sales',
                component: 'Sales',
                icon: Receipt,
                permission: 'sales.view',
            },
            {
                label: 'Sales Returns',
                href: '/sales-returns',
                component: 'SalesReturns',
                icon: RotateCcw,
                permission: 'sales_returns.view',
            },
            {
                label: 'Customer Payments',
                href: '/customer-payments',
                component: 'CustomerPayments',
                icon: HandCoins,
                permission: 'customer_payments.view',
            },
            {
                label: 'Supplier Payments',
                href: '/supplier-payments',
                component: 'SupplierPayments',
                icon: Landmark,
                permission: 'supplier_payments.view',
            },
            {
                label: 'Purchase Returns',
                href: '/purchase-returns',
                component: 'PurchaseReturns',
                icon: Undo2,
                permission: 'purchase_returns.view',
            },
            {
                label: 'Adjustments',
                href: '/adjustments',
                component: 'Adjustments',
                icon: Scale,
                permission: 'adjustments.view',
            },
            {
                label: 'Transfers',
                href: '/transfers',
                component: 'Transfers',
                icon: ArrowRightLeft,
                permission: 'transfers.view',
            },
            {
                label: 'Stock Counts',
                href: '/stock-counts',
                component: 'StockCounts',
                icon: ClipboardCheck,
                permission: 'stock_counts.view',
                badge: 'stock_counts',
            },
            {
                label: 'Loss Reports',
                href: '/loss-reports',
                component: 'LossReports',
                icon: PackageX,
                permission: 'losses.view',
                badge: 'loss_reports',
            },
            {
                label: 'Movements',
                href: '/movements',
                component: 'Movements',
                icon: ArrowLeftRight,
                permission: 'movements.view',
            },
            {
                label: 'Stock',
                href: '/stock',
                component: 'Stock',
                icon: Warehouse,
                permission: 'stock.view',
            },
        ],
    },
    {
        group: 'Masters',
        items: [
            {
                label: 'Products',
                href: '/products',
                component: 'Products',
                icon: Package,
                permission: 'products.view',
            },
            {
                label: 'Categories',
                href: '/categories',
                component: 'Categories',
                icon: Tags,
                permission: 'categories.view',
            },
            {
                label: 'Departments',
                href: '/departments',
                component: 'Departments',
                icon: Building2,
                permission: 'departments.view',
            },
            {
                label: 'Locations',
                href: '/locations',
                component: 'Locations',
                icon: MapPin,
                permission: 'locations.view',
            },
            {
                label: 'Units',
                href: '/units',
                component: 'Units',
                icon: Ruler,
                permission: 'units.view',
            },
            {
                label: 'Suppliers',
                href: '/suppliers',
                component: 'Suppliers',
                icon: Truck,
                permission: 'suppliers.view',
            },
            {
                label: 'Customers',
                href: '/customers',
                component: 'Customers',
                icon: UserRound,
                permission: 'customers.view',
            },
        ],
    },
    {
        group: 'Insights',
        items: [
            {
                label: 'Reports',
                href: '/reports',
                component: 'Reports',
                icon: FileBarChart,
                permission: 'reports.view',
            },
            {
                label: 'Collectibles',
                href: '/collectibles',
                component: 'Collectibles',
                icon: ListChecks,
                permission: 'customer_payments.view',
            },
            {
                label: 'Aging',
                href: '/aging',
                component: 'Aging',
                icon: CalendarClock,
                permission: 'customer_payments.view',
            },
            {
                label: 'Payables',
                href: '/payables',
                component: 'Payables',
                icon: BookOpenCheck,
                permission: 'supplier_payments.view',
            },
            {
                label: 'Settings',
                href: '/settings',
                component: 'Settings',
                icon: Settings,
                permission: 'settings.view',
            },
            {
                label: 'Payment Methods',
                href: '/payment-methods',
                component: 'PaymentMethods',
                icon: Wallet,
                permission: 'payment_methods.view',
            },
            {
                label: 'Backups',
                href: '/backups',
                component: 'Backups',
                icon: DatabaseBackup,
                permission: 'backups.view',
            },
        ],
    },
    {
        group: 'Administration',
        items: [
            {
                label: 'Users',
                href: '/users',
                component: 'Users',
                icon: Users,
                permission: 'users.view',
            },
            {
                label: 'Roles & Permissions',
                href: '/roles',
                component: 'Roles',
                icon: ShieldCheck,
                permission: 'roles.view',
            },
            {
                label: 'Audit Trail',
                href: '/audit',
                component: 'Audit',
                icon: ScrollText,
                permission: 'audit.view',
            },
        ],
    },
]

/**
 * Is this menu item the page on screen?
 *
 * @param {string} current  The Inertia page name, e.g. "Users/Edit".
 * @param {string} target   The item's page name, e.g. "Users".
 */
export function isActive(current, target) {
    if (!current || !target) return false

    return current === target || current.startsWith(`${target}/`)
}

/**
 * Drop the items the user may not open, then drop any group left empty.
 *
 * @param {(permission: string) => boolean} can
 */
export function visibleNavigation(can) {
    return navigation
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.permission || can(item.permission)),
        }))
        .filter((section) => section.items.length > 0)
}
