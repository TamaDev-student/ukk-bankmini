import { NavLink } from "react-router-dom";
import { Landmark } from "lucide-react";

/**
 * Sidebar — Komponen sidebar bersama untuk Admin, Teller, dan Supervisor.
 *
 * Props:
 *   - navConfig : objek dari navigation.js (satu role)
 *                 berisi { basePath, brand, items }
 *
 * Desain diambil 1:1 dari Google Stitch AdminDashboard asli.
 * Gunakan NavLink dari React Router agar menu aktif otomatis
 * mengikuti URL yang sedang dibuka.
 */
function Sidebar({ navConfig, isOpen, onClose }) {
    const { basePath, items } = navConfig;

    return (
        <>
            {isOpen && (
                <button
                    type="button"
                    aria-label="Tutup navigasi"
                    onClick={onClose}
                    className="fixed inset-0 z-40 bg-slate-950/40 lg:hidden"
                />
            )}
            <aside
                id="role-navigation"
                aria-label="Navigasi utama"
                className={`fixed left-0 top-0 z-50 flex h-dvh w-[min(16rem,calc(100vw-2.5rem))] flex-col justify-between border-r border-outline-variant/30 bg-surface-container-lowest transition-transform duration-200 lg:visible lg:w-64 lg:translate-x-0 ${
                    isOpen
                        ? "visible translate-x-0"
                        : "invisible -translate-x-full"
                }`}
            >
                <div className="flex flex-col">
                    <div className="flex h-16 items-center gap-3 border-b border-outline-variant/30 px-space-md">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#003FB3] text-white">
                            <Landmark size={22} strokeWidth={2} aria-hidden="true" />
                        </span>
                        <span className="min-w-0 truncate font-label-md text-label-md font-semibold text-on-surface">
                            E-Teller Bank Mini
                        </span>
                    </div>

                    <div className="px-space-md pb-space-xs pt-space-md">
                        <span className="font-label-sm text-label-sm font-semibold uppercase tracking-wider text-secondary">
                            Navigasi
                        </span>
                    </div>

                    <nav className="flex flex-col gap-space-xs px-space-sm">
                        {items.map((item) => (
                            <NavLink
                                key={item.path}
                                to={`${basePath}/${item.path}`}
                                end={item.path === "dashboard"}
                                onClick={onClose}
                                className={({ isActive }) =>
                                    `flex min-h-11 items-center gap-space-sm rounded-lg px-space-md py-space-sm transition-colors ${isActive
                                        ? "bg-surface-container-high font-semibold text-primary"
                                        : "font-label-md text-label-md font-w-label-md text-on-surface-variant hover:bg-surface-container hover:text-on-surface"
                                    }`
                                }
                            >
                                <span className="material-symbols-outlined text-[20px]">
                                    {item.icon}
                                </span>
                                <span className="min-w-0 break-words">{item.label}</span>
                            </NavLink>
                        ))}
                    </nav>
                </div>
            </aside>
        </>
    );
}

export default Sidebar;
