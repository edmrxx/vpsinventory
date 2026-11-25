import {
    Activity,
    Banknote,
    BellRing,
    Building2,
    CircleHelp,
    ClipboardList,
    Clock,
    DatabaseBackup,
    Hash,
    Landmark,
    PackageMinus,
    PackageX,
    Palette,
    Percent,
    Plug,
    Printer,
    Receipt,
    ReceiptText,
    RotateCcw,
    Settings,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    TriangleAlert,
    UserCheck,
    Users,
    Wallet,
    Warehouse,
} from '@lucide/vue'

/**
 * Icons the server can ask for by name.
 *
 * config/settings.php and the dashboard metrics send an icon as a string, so
 * this is the one place that turns a name into a component. Add the import
 * and the entry here to use a new one.
 */
const icons = {
    Activity,
    Banknote,
    BellRing,
    Building2,
    ClipboardList,
    Clock,
    DatabaseBackup,
    Hash,
    Landmark,
    PackageMinus,
    PackageX,
    Palette,
    Percent,
    Plug,
    Printer,
    Receipt,
    ReceiptText,
    RotateCcw,
    Settings,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    TriangleAlert,
    UserCheck,
    Users,
    Wallet,
    Warehouse,
}

/** The named icon, or a neutral one so a typo never breaks the page. */
export function icon(name) {
    return icons[name] ?? CircleHelp
}

export default icons
