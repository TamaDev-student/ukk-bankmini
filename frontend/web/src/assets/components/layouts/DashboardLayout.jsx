import { useEffect, useState } from "react";
import { Outlet } from "react-router-dom";
import Sidebar from "../common/Sidebar";
import Topbar from "../common/Topbar";

/**
 * DashboardLayout — Layout bersama untuk Admin, Teller, dan Supervisor.
 *
 * Menyatukan Sidebar + Topbar + area konten (Outlet).
 * Menerima navConfig sebagai props untuk menentukan navigasi
 * dan identitas role yang sedang aktif.
 *
 * Props:
 *   - navConfig : objek dari navigation.js (satu role)
 */
function DashboardLayout({ navConfig }) {
    const [navigationOpen, setNavigationOpen] = useState(false);

    useEffect(() => {
        const closeOnEscape = (event) => {
            if (event.key === "Escape") setNavigationOpen(false);
        };

        window.addEventListener("keydown", closeOnEscape);
        return () => window.removeEventListener("keydown", closeOnEscape);
    }, []);

    return (
        <div className="bg-surface font-body-md text-body-md font-w-body-md text-on-surface antialiased">
            <Sidebar
                navConfig={navConfig}
                isOpen={navigationOpen}
                onClose={() => setNavigationOpen(false)}
            />

            <div className="min-w-0 pl-0 lg:pl-64">
                <Topbar
                    navConfig={navConfig}
                    onMenuToggle={() => setNavigationOpen((open) => !open)}
                    navigationOpen={navigationOpen}
                />

                <main className="relative min-h-screen w-full min-w-0 bg-surface px-3 pt-16 sm:px-4 lg:px-gutter-desktop">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

export default DashboardLayout;
