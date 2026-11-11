import { Navigate, Route, Routes } from "react-router-dom";

import { navigationConfig } from "../config/navigation";

import DashboardLayout from "../assets/components/layouts/DashboardLayout";
import LoginPage from "../assets/components/pages/auth/LoginPage";
import ForgotPasswordPage from "../assets/components/pages/auth/ForgotPasswordPage";

import AdminDashboard from "../assets/components/pages/admin/AdminDashboard";
import UserManagement from "../assets/components/pages/admin/UserManagement";
import UserArchive from "../assets/components/pages/admin/UserArchive";
import StudentMaster from "../assets/components/pages/admin/StudentMaster";
import CustomerAccount from "../assets/components/pages/admin/CustomerAccount";
import AdminAccountingJournal from "../pages/admin/AccountingJournal";
import TransactionReports from "../assets/components/pages/admin/TransactionReports";
import TellerDashboard from "../assets/components/pages/teller/TellerDashboard";
import SupervisorDashboard from "../assets/components/pages/supervisor/SupervisorDashboard";
import ReviewReport from "../assets/components/pages/supervisor/ReviewReport";
import ActivityLog from "../assets/components/pages/supervisor/ActivityLog";
import CustomerFind from "../assets/components/pages/teller/CustomerFind";
import CustomerDetail from "../assets/components/pages/teller/CustomerDetail";
import DailyReport from "../assets/components/pages/teller/DailyReport";
//
import CashDeposit from "../assets/components/pages/teller/CashDeposit";
import CashWithdrawal from "../assets/components/pages/teller/CashWithdrawal";
import TellerCashReconciliation from "../assets/components/pages/teller/TellerCashReconciliation";
import ProtectedRoute from "../ProtectedRoute";

function AppRoutes() {
    return (
        <Routes>
            {/* Halaman login */}
            <Route
                path="/login"
                element={<LoginPage />}
            />

            {/* Halaman lupa password */}
            <Route
                path="/forgot-password"
                element={<ForgotPasswordPage />}
            />

            {/* Halaman khusus Administrator */}
            <Route
                element={
                    <ProtectedRoute
                        allowedRoles={["administrator"]}
                    />
                }
            >
                <Route
                    path="/admin"
                    element={
                        <DashboardLayout
                            navConfig={navigationConfig.admin}
                        />
                    }
                >
                    <Route
                        index
                        element={<Navigate to="dashboard" replace />}
                    />

                    <Route
                        path="dashboard"
                        element={<AdminDashboard />}
                    />

                    <Route
                        path="users"
                        element={<UserManagement />}
                    />

                    <Route
                        path="user-management"
                        element={<UserManagement />}
                    />

                    <Route
                        path="user-archive"
                        element={<UserArchive />}
                    />

                    <Route
                        path="students"
                        element={<StudentMaster />}
                    />

                    <Route
                        path="master-students"
                        element={<StudentMaster />}
                    />

                    <Route
                        path="accounts"
                        element={<CustomerAccount />}
                    />

                    <Route
                        path="customer-import"
                        element={<Navigate to="/admin/accounts" replace />}
                    />

                    <Route
                        path="transaction-reports"
                        element={<TransactionReports />}
                    />

                    <Route
                        path="customer-accounts"
                        element={<CustomerAccount />}
                    />

                    <Route
                        path="journal"
                        element={<AdminAccountingJournal />}
                    />

                    <Route
                        path="accounting-journal"
                        element={<AdminAccountingJournal />}
                    />

                    <Route
                        path="jurnal-akuntansi"
                        element={<AdminAccountingJournal />}
                    />
                    <Route
                        path="activity-log"
                        element={<ActivityLog />}
                    />
                </Route>
            </Route>

            {/* Halaman khusus Teller */}
            <Route
                element={
                    <ProtectedRoute
                        allowedRoles={["teller"]}
                    />
                }
            >
                <Route
                    path="/teller"
                    element={
                        <DashboardLayout
                            navConfig={navigationConfig.teller}
                        />
                    }
                >
                    <Route
                        index
                        element={<Navigate to="dashboard" replace />}
                    />

                    <Route
                        path="dashboard"
                        element={<TellerDashboard />}
                    />

                    <Route path="find-customer"
                        element={<CustomerFind />}
                    />

                    <Route path="customers/:accountNumber"
                        element={<CustomerDetail />}
                    />

                    <Route path="deposit"
                        element={<CashDeposit />}
                    />
                    <Route path="withdrawal"
                        element={<CashWithdrawal />}
                    />
                    <Route
                        path="cash-reconciliation"
                        element={<TellerCashReconciliation />}
                    />
                    <Route
                        path="daily-report"
                        element={<DailyReport />}
                    />

                </Route>


            </Route>

            {/* Halaman khusus Supervisor */}
            <Route
                element={
                    <ProtectedRoute
                        allowedRoles={["supervisor"]}
                    />
                }
            >
                <Route
                    path="/supervisor"
                    element={
                        <DashboardLayout
                            navConfig={navigationConfig.supervisor}
                        />
                    }
                >
                    <Route
                        index
                        element={<Navigate to="dashboard" replace />}
                    />

                    <Route
                        path="dashboard"
                        element={<SupervisorDashboard />}
                    />

                    <Route
                        path="journal"
                        element={<AdminAccountingJournal />}
                    />

                    <Route
                        path="jurnal-akuntansi"
                        element={<AdminAccountingJournal />}
                    />
                    <Route
                        path="review-report"
                        element={<ReviewReport />}
                    />
                    <Route
                        path="transaction-reports"
                        element={
                            <TransactionReports endpointBase="/supervisor/reports/transactions" />
                        }
                    />
                    <Route
                        path="activity-log"
                        element={<ActivityLog />}
                    />
                </Route>
            </Route>

            {/* Halaman awal selalu diarahkan ke login */}
            <Route
                path="/"
                element={<Navigate to="/login" replace />}
            />

            {/* URL yang tidak dikenal */}
            <Route
                path="*"
                element={<Navigate to="/login" replace />}
            />
        </Routes>
    );
}

export default AppRoutes;