import DashboardLayout from "./DashboardLayout";
import { navigationConfig } from "../../config/navigation";

/**
 * AdminLayout — Layout untuk halaman Administrator.
 * Menggunakan DashboardLayout dengan konfigurasi navigasi Admin.
 */
function AdminLayout() {
    return <DashboardLayout navConfig={navigationConfig.admin} />;
}

export default AdminLayout;
