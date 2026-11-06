export const roleHomePaths = {
    administrator: "/admin/dashboard",
    teller: "/teller/dashboard",
    supervisor: "/supervisor/dashboard",
};

export const roleLabels = {
    administrator: "Administrator",
    teller: "Teller",
    supervisor: "Supervisor",
};

export const getRoleHomePath = (role) => {
    return roleHomePaths[role] ?? null;
};

export const getRoleLabel = (role) => {
    return roleLabels[role] ?? "Pengguna";
};